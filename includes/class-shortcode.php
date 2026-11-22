<?php
/**
 * Shortcode registration and visible FAQ rendering.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Own shortcode namespace: [geo_faqs] wrapper + [geo_faq q="..."] items.
 *
 * Only the wrapper is registered as a WordPress shortcode. Items are parsed by
 * this class, which keeps rendering identical in the_content, widgets and
 * blocks, and avoids double-processing of nested shortcodes.
 */
class GEO_Shortcode {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_shortcode( 'geo_faqs', array( $this, 'render_shortcode' ) );
		add_filter( 'the_content', array( $this, 'filter_content' ), 8 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Enqueue the (optional) front-end stylesheet when the post has a FAQ block.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets() {
		if ( ! GEO_Settings::get( 'faq_style_enabled', 1 ) ) {
			return;
		}

		wp_register_style( 'geo-engine-front', GEO_ENGINE_PLUGIN_URL . 'assets/css/front.css', array(), GEO_ENGINE_VERSION );

		$post = get_post();

		if ( $post && false !== strpos( (string) $post->post_content, '[geo_faqs' ) ) {
			wp_enqueue_style( 'geo-engine-front' );
		}
	}

	/**
	 * Does the content contain our wrapper?
	 *
	 * @param string $content Content.
	 * @return bool
	 */
	public static function has_block( $content ) {
		return false !== strpos( (string) $content, '[geo_faqs' );
	}

	/**
	 * Exactly one opening and one closing wrapper, and matching item counts.
	 *
	 * @param string $content Content.
	 * @return bool
	 */
	public static function is_balanced( $content ) {
		$content = (string) $content;

		$open  = preg_match_all( '/\[geo_faqs\b[^\]]*\]/i', $content );
		$close = preg_match_all( '/\[\/geo_faqs\]/i', $content );

		if ( 1 !== (int) $open || 1 !== (int) $close ) {
			return false;
		}

		$item_open  = preg_match_all( '/\[geo_faq\b[^\]]*\]/i', $content );
		$item_close = preg_match_all( '/\[\/geo_faq\]/i', $content );

		return (int) $item_open === (int) $item_close && (int) $item_open > 0;
	}

	/**
	 * Parse every [geo_faq] item out of a content string.
	 *
	 * @param string $content Raw content.
	 * @return array<int,array{question:string,answer:string}>
	 */
	public static function parse_items( $content ) {
		$content = (string) $content;
		$items   = array();

		if ( '' === $content || false === strpos( $content, '[geo_faq' ) ) {
			return $items;
		}

		if ( ! preg_match_all( '/\[geo_faq\b([^\]]*)\](.*?)\[\/geo_faq\]/is', $content, $matches, PREG_SET_ORDER ) ) {
			return $items;
		}

		foreach ( $matches as $match ) {
			$atts = self::parse_atts( isset( $match[1] ) ? $match[1] : '' );

			$question = isset( $atts['q'] ) ? (string) $atts['q'] : '';

			// Attribute values may carry HTML entities (see GEO_Writeback::encode_attr).
			$question = html_entity_decode( $question, ENT_QUOTES, 'UTF-8' );
			$question = GEO_FAQ_Generator::clean( $question );

			$answer = GEO_FAQ_Generator::clean( isset( $match[2] ) ? $match[2] : '' );

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$items[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}

		return $items;
	}

	/**
	 * Parse shortcode attributes without depending on WP internals.
	 *
	 * @param string $text Attribute string.
	 * @return array<string,string>
	 */
	public static function parse_atts( $text ) {
		$text = (string) $text;
		$atts = array();

		if ( ! preg_match_all(
			'/([A-Za-z0-9_\-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))/',
			$text,
			$matches,
			PREG_SET_ORDER
		) ) {
			return $atts;
		}

		foreach ( $matches as $match ) {
			$name  = strtolower( $match[1] );
			$value = '';

			if ( isset( $match[2] ) && '' !== $match[2] ) {
				$value = $match[2];
			} elseif ( isset( $match[3] ) && '' !== $match[3] ) {
				$value = $match[3];
			} elseif ( isset( $match[4] ) ) {
				$value = $match[4];
			}

			$atts[ $name ] = $value;
		}

		return $atts;
	}

	/**
	 * Render the visible accordion.
	 *
	 * @param array<int,array{question:string,answer:string}> $items Parsed items.
	 * @param bool                                            $with_heading Whether to print the block heading.
	 * @return string
	 */
	public static function render_block( array $items, $with_heading = true ) {
		if ( empty( $items ) ) {
			return '';
		}

		$heading = (string) GEO_Settings::get( 'faq_heading', __( '常见问题', 'geo-engine' ) );

		$html  = '<section class="geo-faq">';
		$html .= '<h2 class="geo-faq-title">' . esc_html( $heading ) . '</h2>';
		$html .= '<div class="geo-faq-list">';

		foreach ( $items as $item ) {
			$html .= '<details class="geo-faq-item">';
			$html .= '<summary class="geo-faq-q">' . esc_html( $item['question'] ) . '</summary>';
			$html .= '<div class="geo-faq-a"><p>' . esc_html( $item['answer'] ) . '</p></div>';
			$html .= '</details>';
		}

		$html .= '</div></section>';

		return $html;
	}

	/**
	 * Shortcode callback (widgets, blocks, page builders, unclosed wrappers).
	 *
	 * @param array|string $atts    Attributes (unused).
	 * @param string|null  $content Inner content.
	 * @return string
	 */
	public function render_shortcode( $atts = array(), $content = '' ) {
		$items = self::parse_items( (string) $content );

		if ( empty( $items ) ) {
			return '';
		}

		if ( GEO_Settings::get( 'faq_style_enabled', 1 ) ) {
			wp_enqueue_style( 'geo-engine-front' );
		}

		return self::render_block( $items, true );
	}

	/**
	 * Replace the wrapper with rendered HTML before wpautop/do_shortcode run.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function filter_content( $content ) {
		if ( ! is_string( $content ) || ! self::has_block( $content ) ) {
			return $content;
		}

		$pattern = '/\[geo_faqs\b[^\]]*\](.*?)\[\/geo_faqs\]/is';

		if ( ! preg_match( $pattern, $content ) ) {
			return $content;
		}

		$heading   = (string) GEO_Settings::get( 'faq_heading', __( '常见问题', 'geo-engine' ) );
		$suppress  = false;
		$block_at  = strpos( $content, '[geo_faqs' );

		if ( false !== $block_at && $block_at > 0 ) {
			$before = substr( $content, max( 0, $block_at - 300 ), min( 300, $block_at ) );

			if ( preg_match( '/<h[1-3][^>]*>\s*' . preg_quote( $heading, '/' ) . '\s*<\/h[1-3]>\s*$/u', $before ) ) {
				$suppress = true;
			}
		}

		$rendered = self::replace_blocks( $content, $pattern, $suppress );

		if ( ! is_string( $rendered ) ) {
			return $content;
		}

		if ( false !== strpos( $rendered, 'class="geo-faq"' ) && GEO_Settings::get( 'faq_style_enabled', 1 ) ) {
			wp_enqueue_style( 'geo-engine-front' );
		}

		return $rendered;
	}

	/**
	 * Swap every wrapper occurrence for rendered HTML.
	 *
	 * @param string $content  Content.
	 * @param string $pattern  Wrapper regex with one capture group.
	 * @param bool   $suppress Whether to omit the block heading.
	 * @return string
	 */
	private static function replace_blocks( $content, $pattern, $suppress ) {
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $content;
		}

		$out    = '';
		$offset = 0;

		foreach ( $matches[0] as $index => $match ) {
			$block_text = $match[0];
			$start      = (int) $match[1];

			if ( $start < $offset ) {
				continue;
			}

			$inner = isset( $matches[1][ $index ][0] ) ? $matches[1][ $index ][0] : '';
			$items = self::parse_items( '[geo_faqs]' . $inner . '[/geo_faqs]' );

			$out .= substr( $content, $offset, $start - $offset );

			if ( empty( $items ) ) {
				$out .= $block_text;
			} else {
				$out .= self::render_block( $items, ! $suppress );
			}

			$offset = $start + strlen( $block_text );
		}

		$out .= substr( $content, $offset );

		return $out;
	}
}
