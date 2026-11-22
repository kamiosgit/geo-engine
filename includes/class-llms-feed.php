<?php
/**
 * A layer: /llms.txt, /llms-full.txt and per-post Markdown endpoints.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves machine-readable indexes from rewrite endpoints (no static files).
 */
class GEO_LLMS_Feed {

	/**
	 * Cache TTL in seconds.
	 */
	const CACHE_TTL = 3600;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		// Priority 1: answer before redirect_canonical() (priority 10) can touch
		// our endpoint URLs, and before the 404 template is chosen.
		add_action( 'template_redirect', array( $this, 'handle_request' ), 1 );
		add_filter( 'redirect_canonical', array( $this, 'guard_canonical' ) );
	}

	/**
	 * Register rewrite rules.
	 *
	 * @return void
	 */
	public function register_rewrite() {
		GEO_Activator::add_rewrite_rules();
	}

	/**
	 * Register the query vars used by the endpoints.
	 *
	 * @param array<int,string> $vars Existing vars.
	 * @return array<int,string>
	 */
	public function register_query_vars( $vars ) {
		$vars[] = 'geo_engine_feed';
		$vars[] = 'geo_engine_pt';
		$vars[] = 'geo_engine_slug';

		return $vars;
	}

	/**
	 * Do not let canonical redirection rewrite our endpoints.
	 *
	 * @param string|false $redirect_url Redirect target.
	 * @return string|false
	 */
	public function guard_canonical( $redirect_url ) {
		if ( get_query_var( 'geo_engine_feed' ) ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Public URL of an endpoint, with a fallback for plain permalinks.
	 *
	 * @param string $kind llms|llms_full.
	 * @return string
	 */
	public static function feed_url( $kind = 'llms' ) {
		$file = ( 'llms_full' === $kind ) ? 'llms-full.txt' : 'llms.txt';

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( 'geo_engine_feed', $kind, home_url( '/' ) );
		}

		return home_url( '/' . $file );
	}

	/**
	 * Markdown URL for a post.
	 *
	 * @param WP_Post|int $post Post.
	 * @return string
	 */
	public static function markdown_url( $post ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return '';
		}

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg(
				array(
					'geo_engine_feed' => 'md',
					'geo_engine_pt'   => $post->post_type,
					'geo_engine_slug' => $post->post_name,
				),
				home_url( '/' )
			);
		}

		return home_url( '/geo-md/' . $post->post_type . '/' . $post->post_name . '.md' );
	}

	/**
	 * Fetch every indexable post.
	 *
	 * @return array<int,WP_Post>
	 */
	public static function query_posts() {
		$types = (array) GEO_Settings::get( 'post_types', array( 'post', 'page' ) );

		$posts = get_posts(
			array(
				'post_type'           => $types,
				'post_status'         => 'publish',
				'posts_per_page'      => -1,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'has_password'        => false,
				'suppress_filters'    => false,
			)
		);

		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * Human label for a post type.
	 *
	 * @param string $type Post type slug.
	 * @return string
	 */
	public static function post_type_label( $type ) {
		$object = get_post_type_object( $type );

		if ( $object && isset( $object->labels->name ) ) {
			return (string) $object->labels->name;
		}

		return ucfirst( (string) $type );
	}

	/**
	 * One-line description for an index entry.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function entry_description( $post ) {
		$source = trim( (string) $post->post_excerpt );

		if ( '' === $source ) {
			$source = (string) $post->post_content;
		}

		$text = self::plain_text( $source );
		$text = GEO_FAQ_Generator::truncate( $text, 120 );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		// Never advertise a fragment that is only leftover markup.
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );

		if ( $length < 12 ) {
			return '';
		}

		return $text;
	}

	/**
	 * Strip shortcodes, markup and entities from a fragment of content.
	 *
	 * Page builders leave unregistered shortcodes behind (WPBakery, Elementor
	 * widgets, `[newsletter]` …). They are noise for an LLM reading the index.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private static function plain_text( $html ) {
		$html = (string) $html;
		$html = strip_shortcodes( $html );
		$html = preg_replace( '/\[[^\]\r\n]*\]/', ' ', $html );
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = wp_strip_all_tags( $html );
		$html = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
		$html = str_replace( "\xC2\xA0", ' ', $html );

		return trim( (string) preg_replace( '/\s+/u', ' ', $html ) );
	}

	/**
	 * Escape square brackets so a title cannot break the Markdown link syntax.
	 *
	 * @param string $text Title.
	 * @return string
	 */
	public static function escape_link_text( $text ) {
		return str_replace( array( '[', ']' ), array( '\\[', '\\]' ), (string) $text );
	}

	/**
	 * Build /llms.txt content.
	 *
	 * @param array<int,WP_Post> $posts Posts.
	 * @param string             $site  Site name.
	 * @param string             $desc  Site description.
	 * @return string
	 */
	public static function build_llms( array $posts, $site, $desc = '' ) {
		$groups = array();

		foreach ( $posts as $post ) {
			$label = self::post_type_label( $post->post_type );

			if ( ! isset( $groups[ $label ] ) ) {
				$groups[ $label ] = array();
			}

			$groups[ $label ][] = array(
				'title' => self::escape_link_text( get_the_title( $post ) ),
				'url'   => get_permalink( $post ),
				'desc'  => self::entry_description( $post ),
			);
		}

		$out = '# ' . $site . "\n\n";

		if ( '' !== trim( (string) $desc ) ) {
			$out .= '> ' . preg_replace( '/\s+/u', ' ', (string) $desc ) . "\n\n";
		}

		$out .= '> Markdown versions follow the pattern: ' . self::markdown_url_pattern() . "\n\n";

		foreach ( $groups as $label => $entries ) {
			$out .= '## ' . $label . "\n\n";

			foreach ( $entries as $entry ) {
				$out .= '- [' . $entry['title'] . '](' . $entry['url'] . ')';

				if ( '' !== $entry['desc'] ) {
					$out .= ': ' . $entry['desc'];
				}

				$out .= "\n";
			}

			$out .= "\n";
		}

		return rtrim( $out ) . "\n";
	}

	/**
	 * Markdown endpoint pattern shown in the index header.
	 *
	 * @return string
	 */
	private static function markdown_url_pattern() {
		return home_url( '/geo-md/{post_type}/{slug}.md' );
	}

	/**
	 * Build /llms-full.txt content.
	 *
	 * @param array<int,WP_Post> $posts    Posts.
	 * @param string             $site     Site name.
	 * @param string             $desc     Site description.
	 * @param int                $chars    Body characters per entry.
	 * @param int                $max      Entry cap.
	 * @return string
	 */
	public static function build_llms_full( array $posts, $site, $desc = '', $chars = 1500, $max = 500 ) {
		$total   = count( $posts );
		$truncated = false;

		if ( $max > 0 && $total > $max ) {
			$posts     = array_slice( $posts, 0, $max );
			$truncated = true;
		}

		$out = '# ' . $site . "\n\n";

		if ( '' !== trim( (string) $desc ) ) {
			$out .= '> ' . preg_replace( '/\s+/u', ' ', (string) $desc ) . "\n\n";
		}

		$out .= '> Full-text index. ' . $total . " entries total.\n\n";

		foreach ( $posts as $post ) {
			$body = GEO_FAQ_Generator::truncate( GEO_FAQ_Generator::build_body_text( $post ), (int) $chars );

			$out .= '## ' . get_the_title( $post ) . "\n\n";
			$out .= '- URL: ' . get_permalink( $post ) . "\n";
			$out .= '- Published: ' . get_the_date( 'Y-m-d', $post ) . "\n";
			$out .= '- Updated: ' . get_the_modified_date( 'Y-m-d', $post ) . "\n";
			$out .= '- Type: ' . $post->post_type . "\n\n";
			$out .= $body . "\n\n---\n\n";
		}

		if ( $truncated ) {
			$out .= '> Truncated at ' . (int) $max . ' entries. See /llms.txt for the complete index.' . "\n";
		}

		return rtrim( $out ) . "\n";
	}

	/**
	 * Build the Markdown document for one post.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function build_markdown( $post ) {
		$out  = '# ' . get_the_title( $post ) . "\n\n";
		$out .= '- URL: ' . get_permalink( $post ) . "\n";
		$out .= '- Published: ' . get_the_date( 'c', $post ) . "\n";
		$out .= '- Updated: ' . get_the_modified_date( 'c', $post ) . "\n";
		$out .= '- Author: ' . get_the_author_meta( 'display_name', (int) $post->post_author ) . "\n\n";
		$out .= "---\n\n";

		// Drop our own block first: it is re-rendered as a Markdown section below.
		$content = GEO_Writeback::strip_blocks( (string) $post->post_content );
		$content = strip_shortcodes( $content );

		if ( function_exists( 'do_blocks' ) ) {
			$content = do_blocks( $content );
		}

		$out .= self::html_to_markdown( $content ) . "\n";

		$faqs = GEO_Shortcode::parse_items( (string) $post->post_content );

		if ( ! empty( $faqs ) ) {
			$heading = (string) GEO_Settings::get( 'faq_heading', __( '常见问题', 'geo-engine' ) );

			$out .= "\n## " . $heading . "\n\n";

			foreach ( $faqs as $faq ) {
				$out .= '### ' . $faq['question'] . "\n\n" . $faq['answer'] . "\n\n";
			}
		}

		return rtrim( $out ) . "\n";
	}

	/**
	 * Convert rendered HTML into Markdown.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function html_to_markdown( $html ) {
		$html = trim( (string) $html );

		if ( '' === $html ) {
			return '';
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			return GEO_FAQ_Generator::to_plain_text( $html );
		}

		$document = new DOMDocument();

		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $document->documentElement ) {
			return GEO_FAQ_Generator::to_plain_text( $html );
		}

		$markdown = self::walk( $document->documentElement );
		$markdown = preg_replace( '/[ \t]+\n/u', "\n", (string) $markdown );
		$markdown = preg_replace( '/\n{3,}/u', "\n\n", (string) $markdown );

		return trim( (string) $markdown );
	}

	/**
	 * Recursive DOM → Markdown walker.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private static function walk( $node ) {
		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			return preg_replace( '/\s+/u', ' ', (string) $node->nodeValue );
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		$tag = strtolower( $node->nodeName );

		if ( in_array( $tag, array( 'script', 'style', 'noscript', 'svg' ), true ) ) {
			return '';
		}

		if ( 'br' === $tag ) {
			return "\n";
		}

		if ( 'hr' === $tag ) {
			return "\n\n---\n\n";
		}

		if ( 'img' === $tag ) {
			return '![' . $node->getAttribute( 'alt' ) . '](' . $node->getAttribute( 'src' ) . ')';
		}

		if ( 'pre' === $tag ) {
			return "\n\n```\n" . trim( (string) $node->textContent ) . "\n```\n\n";
		}

		if ( 'table' === $tag ) {
			return "\n\n" . self::table_to_markdown( $node ) . "\n\n";
		}

		$inner = '';

		foreach ( $node->childNodes as $child ) {
			$inner .= self::walk( $child );
		}

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $tag, 1 );

				return "\n\n" . str_repeat( '#', $level ) . ' ' . trim( $inner ) . "\n\n";

			case 'p':
				return "\n\n" . trim( $inner ) . "\n\n";

			case 'strong':
			case 'b':
				return '' === trim( $inner ) ? '' : '**' . trim( $inner ) . '**';

			case 'em':
			case 'i':
				return '' === trim( $inner ) ? '' : '*' . trim( $inner ) . '*';

			case 'code':
				return '`' . trim( $inner ) . '`';

			case 'a':
				$href = trim( $node->getAttribute( 'href' ) );

				if ( '' === $href ) {
					return $inner;
				}

				return '[' . trim( $inner ) . '](' . $href . ')';

			case 'ul':
				return "\n\n" . self::list_to_markdown( $node, false ) . "\n\n";

			case 'ol':
				return "\n\n" . self::list_to_markdown( $node, true ) . "\n\n";

			case 'blockquote':
				$text = trim( $inner );

				return "\n\n" . preg_replace( '/^/m', '> ', $text ) . "\n\n";

			case 'li':
				return "\n- " . trim( $inner );

			default:
				return $inner;
		}
	}

	/**
	 * Convert a ul/ol element.
	 *
	 * @param DOMNode $node List node.
	 * @param bool    $ordered Ordered list?
	 * @return string
	 */
	private static function list_to_markdown( $node, $ordered ) {
		$lines = array();
		$index = 1;

		foreach ( $node->childNodes as $child ) {
			if ( ! $child instanceof DOMElement || 'li' !== strtolower( $child->nodeName ) ) {
				continue;
			}

			$inner = '';

			// Walk the children, not the <li> itself: walk() prefixes every
			// list item with "- ", which would yield "- - item" here.
			foreach ( $child->childNodes as $grand ) {
				$inner .= self::walk( $grand );
			}

			$text = trim( preg_replace( '/[ \t]*\n[ \t]*/', ' ', trim( $inner ) ) );
			$text = trim( preg_replace( '/\s{2,}/u', ' ', $text ) );

			$prefix = $ordered ? $index . '. ' : '- ';

			$lines[] = $prefix . $text;
			$index++;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Convert a table element into a pipe table.
	 *
	 * @param DOMNode $node Table node.
	 * @return string
	 */
	private static function table_to_markdown( $node ) {
		$rows = array();

		foreach ( $node->getElementsByTagName( 'tr' ) as $tr ) {
			$cells = array();

			foreach ( $tr->childNodes as $cell ) {
				if ( ! $cell instanceof DOMElement ) {
					continue;
				}

				$name = strtolower( $cell->nodeName );

				if ( 'td' !== $name && 'th' !== $name ) {
					continue;
				}

				$cells[] = trim( preg_replace( '/\s+/u', ' ', trim( self::walk( $cell ) ) ) );
			}

			if ( ! empty( $cells ) ) {
				$rows[] = $cells;
			}
		}

		if ( empty( $rows ) ) {
			return '';
		}

		$columns = count( $rows[0] );
		$out     = array();
		$out[]   = '| ' . implode( ' | ', $rows[0] ) . ' |';
		$out[]   = '|' . str_repeat( ' --- |', $columns );

		for ( $i = 1; $i < count( $rows ); $i++ ) {
			$row = array_pad( $rows[ $i ], $columns, '' );
			$out[] = '| ' . implode( ' | ', array_slice( $row, 0, $columns ) ) . ' |';
		}

		return implode( "\n", $out );
	}

	/**
	 * Serve the requested endpoint.
	 *
	 * @return void
	 */
	public function handle_request() {
		$feed = get_query_var( 'geo_engine_feed' );
		$path = self::request_md_parts();

		// Fall back to the raw request URI: some hosts / caching layers mangle
		// percent-encoded (non-ASCII) rewrite matches, and a stale rule cache
		// leaves geo_engine_feed unset. The URI is always available here.
		if ( ! $feed && $path ) {
			$feed = 'md';
		}

		if ( ! $feed ) {
			return;
		}

		switch ( $feed ) {
			case 'llms':
				$this->serve( 'text/plain; charset=utf-8', $this->get_llms() );
				break;

			case 'llms_full':
				$this->serve( 'text/plain; charset=utf-8', $this->get_llms_full() );
				break;

			case 'md':
				$this->serve_markdown( $path );
				break;
		}
	}

	/**
	 * Read post type and slug straight from the request URI.
	 *
	 * @return array{0:string,1:string}|null
	 */
	private static function request_md_parts() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		if ( '' === $uri ) {
			return null;
		}

		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( '' === $path ) {
			return null;
		}

		// Strip the sub-directory when WordPress lives in one.
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = '/' . ltrim( substr( $path, strlen( $home ) ), '/' );
		}

		if ( ! preg_match( '#^/geo-md/([^/]+)/([^/]+)\.md$#', $path, $matches ) ) {
			return null;
		}

		return array(
			sanitize_key( urldecode( $matches[1] ) ),
			rawurldecode( $matches[2] ),
		);
	}

	/**
	 * Cached /llms.txt body.
	 *
	 * @return string
	 */
	public function get_llms() {
		$cached = get_transient( 'geo_engine_llms_cache' );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$body = self::build_llms( self::query_posts(), get_bloginfo( 'name' ), get_bloginfo( 'description' ) );

		set_transient( 'geo_engine_llms_cache', $body, self::CACHE_TTL );

		return $body;
	}

	/**
	 * Cached /llms-full.txt body.
	 *
	 * @return string
	 */
	public function get_llms_full() {
		$cached = get_transient( 'geo_engine_llms_full_cache' );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$body = self::build_llms_full(
			self::query_posts(),
			get_bloginfo( 'name' ),
			get_bloginfo( 'description' ),
			(int) GEO_Settings::get( 'llms_full_chars', 1500 ),
			(int) GEO_Settings::get( 'llms_full_max', 500 )
		);

		set_transient( 'geo_engine_llms_full_cache', $body, self::CACHE_TTL );

		return $body;
	}

	/**
	 * Serve one post as Markdown.
	 *
	 * @param array{0:string,1:string}|null $path Optional (type, slug) taken from the request URI.
	 * @return void
	 */
	private function serve_markdown( $path = null ) {
		if ( is_array( $path ) && 2 === count( $path ) ) {
			$raw_type = (string) $path[0];
			$raw_slug = (string) $path[1];
		} else {
			$raw_type = (string) get_query_var( 'geo_engine_pt' );
			$raw_slug = (string) get_query_var( 'geo_engine_slug' );
		}

		$type    = sanitize_key( urldecode( $raw_type ) );
		$allowed = (array) GEO_Settings::get( 'post_types', array( 'post', 'page' ) );

		if ( '' === $type || ! in_array( $type, $allowed, true ) ) {
			$this->serve( 'text/plain; charset=utf-8', "Not found\n", 404 );
			return;
		}

		$post = self::find_post_by_slug( $raw_slug, $type );

		if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password || ! in_array( $post->post_type, $allowed, true ) ) {
			// Leave a trace so a broken endpoint can be diagnosed without SSH.
			if ( class_exists( 'GEO_Logger' ) ) {
				GEO_Logger::log( 0, 'markdown', 'error', 'Markdown 404 · type=' . $type . ' · slug=' . substr( $raw_slug, 0, 120 ) );
			}

			$this->serve( 'text/plain; charset=utf-8', "Not found\n", 404 );
			return;
		}

		$this->serve( 'text/markdown; charset=utf-8', self::build_markdown( $post ) );
	}

	/**
	 * Resolve a post from a slug that may arrive percent-encoded more than once.
	 *
	 * @param string $slug Raw slug.
	 * @param string $type Post type.
	 * @return WP_Post|null
	 */
	private static function find_post_by_slug( $slug, $type ) {
		$slug = trim( (string) $slug );

		if ( '' === $slug || '.' === $slug || '..' === $slug || false !== strpos( $slug, '/' ) ) {
			return null;
		}

		foreach ( self::slug_candidates( $slug ) as $candidate ) {
			$found = get_page_by_path( $candidate, OBJECT, $type );

			if ( ! $found ) {
				$query = new WP_Query(
					array(
						'name'                   => $candidate,
						'post_type'              => $type,
						'post_status'            => 'publish',
						'posts_per_page'         => 1,
						'no_found_rows'          => true,
						'ignore_sticky_posts'    => true,
						'update_post_term_cache' => false,
						'update_post_meta_cache' => false,
					)
				);

				if ( ! empty( $query->posts ) ) {
					$found = $query->posts[0];
				}
			}

			if ( $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Every plausible decoding of a slug coming from a rewrite match.
	 *
	 * WordPress hands rewrite matches over in the encoded shape found in the
	 * request; depending on the host the value can arrive once or twice
	 * percent-encoded, while CJK post_name values live in the database
	 * URL-encoded. Trying each variant keeps the endpoint working everywhere.
	 *
	 * @param string $slug Raw slug.
	 * @return array<int,string>
	 */
	private static function slug_candidates( $slug ) {
		$values = array();
		$value  = (string) $slug;

		for ( $i = 0; $i < 3; $i++ ) {
			$values[] = $value;
			$decoded  = urldecode( $value );

			if ( $decoded === $value ) {
				break;
			}

			$value = $decoded;
		}

		foreach ( $values as $decoded_value ) {
			$values[] = strtolower( rawurlencode( $decoded_value ) );
			$values[] = rawurlencode( $decoded_value );
		}

		$values = array_values( array_unique( array_filter( $values, 'strlen' ) ) );

		return $values;
	}

	/**
	 * Send a plain response and stop.
	 *
	 * @param string $content_type Content type header.
	 * @param string $body         Body.
	 * @param int    $status       HTTP status.
	 * @return void
	 */
	private function serve( $content_type, $body, $status = 200 ) {
		// Discard anything other code already buffered (a stray UTF-8 BOM, a
		// debug echo): these endpoints must start at byte 0.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( $status );
		nocache_headers();

		header( 'Content-Type: ' . $content_type );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: all' );

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text output.

		exit;
	}
}
