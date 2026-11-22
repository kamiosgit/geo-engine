<?php
/**
 * Writes generated FAQs back into post_content.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the write path: block construction, insertion, recursion protection and
 * per-post status meta. Original content is never rewritten, only appended to.
 */
class GEO_Writeback {

	/**
	 * Posts currently being written, keyed by ID.
	 *
	 * @var array<int,bool>
	 */
	private static $writing = array();

	/**
	 * Is this post being written right now (recursion guard)?
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_writing( $post_id ) {
		return ! empty( self::$writing[ (int) $post_id ] );
	}

	/**
	 * Escape a value so it survives shortcode attribute parsing.
	 *
	 * Brackets are escaped too: a literal `]` would otherwise terminate the
	 * shortcode and corrupt the block.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function encode_attr( $value ) {
		$value = (string) $value;

		return str_replace(
			array( '"', '[', ']', "\r\n", "\r", "\n" ),
			array( '&quot;', '&#91;', '&#93;', ' ', ' ', ' ' ),
			$value
		);
	}

	/**
	 * Escape answer text so brackets cannot break the enclosing shortcode.
	 *
	 * @param string $value Raw answer.
	 * @return string
	 */
	public static function encode_body( $value ) {
		$value = trim( (string) $value );

		return str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $value );
	}

	/**
	 * Build the shortcode block (without editor delimiters).
	 *
	 * @param array<int,array<string,string>> $items Items with q/a keys.
	 * @return string
	 */
	public static function build_block( array $items ) {
		$lines = array( '[geo_faqs]' );

		foreach ( $items as $item ) {
			$question = isset( $item['q'] ) ? $item['q'] : ( isset( $item['question'] ) ? $item['question'] : '' );
			$answer   = isset( $item['a'] ) ? $item['a'] : ( isset( $item['answer'] ) ? $item['answer'] : '' );

			if ( '' === trim( (string) $question ) || '' === trim( (string) $answer ) ) {
				continue;
			}

			$lines[] = '[geo_faq q="' . self::encode_attr( $question ) . '"]' . self::encode_body( $answer ) . '[/geo_faq]';
		}

		$lines[] = '[/geo_faqs]';

		return implode( "\n", $lines );
	}

	/**
	 * Wrap a block in Gutenberg shortcode delimiters when the post is block based.
	 *
	 * @param string $block      Shortcode block.
	 * @param bool   $use_blocks Whether the post uses the block editor.
	 * @return string
	 */
	public static function wrap_for_editor( $block, $use_blocks ) {
		if ( ! $use_blocks ) {
			return $block;
		}

		return "<!-- wp:shortcode -->\n" . $block . "\n<!-- /wp:shortcode -->";
	}

	/**
	 * Item lines only (no wrapper), used when appending to an existing wrapper.
	 *
	 * @param array<int,array<string,string>> $items Items.
	 * @return string
	 */
	public static function build_items_only( array $items ) {
		$block = self::build_block( $items );
		$lines = explode( "\n", $block );

		array_shift( $lines );
		array_pop( $lines );

		return implode( "\n", $lines );
	}

	/**
	 * Insert (or replace) the block inside a content string.
	 *
	 * @param string                          $content Raw content.
	 * @param array<int,array<string,string>> $items   Items to write.
	 * @param array<string,mixed>             $args    replace|cursor.
	 * @return string
	 */
	public static function apply( $content, array $items, array $args = array() ) {
		$content      = (string) $content;
		$replace      = ! empty( $args['replace'] );
		$use_blocks   = false !== strpos( $content, '<!-- wp:' );
		$block        = self::wrap_for_editor( self::build_block( $items ), $use_blocks );

		$has_open  = false !== strpos( $content, '[geo_faqs' );
		$has_close = false !== strrpos( $content, '[/geo_faqs]' );

		if ( $has_open && $has_close ) {
			if ( $replace ) {
				$pattern = '/<!--\s*wp:shortcode\s*-->\s*\[geo_faqs\b[^\]]*\].*?\[\/geo_faqs\]\s*<!--\s*\/wp:shortcode\s*-->|\[geo_faqs\b[^\]]*\].*?\[\/geo_faqs\]/is';

				$replaced = preg_replace( $pattern, $block, $content, 1 );

				if ( is_string( $replaced ) && $replaced !== $content ) {
					return $replaced;
				}
			}

			$right = strrpos( $content, '[/geo_faqs]' );
			$items_only = self::build_items_only( $items );

			// Keep a blank line before the closing tag for readability.
			return substr( $content, 0, $right ) . $items_only . "\n" . substr( $content, $right );
		}

		if ( $has_open && ! $has_close ) {
			return $content . "\n[/geo_faqs]\n\n" . $block . "\n";
		}

		// Append only: not a single character of the original content is removed.
		$separator = ( "\n" === substr( $content, -1 ) ) ? "\n" : "\n\n";

		return $content . $separator . $block . "\n";
	}

	/**
	 * Persist a generated FAQ block into a post.
	 *
	 * @param int                             $post_id Post ID.
	 * @param array<int,array<string,string>> $items   Items.
	 * @param array<string,mixed>             $args    Optional replace flag.
	 * @return true|WP_Error
	 */
	public static function write( $post_id, array $items, array $args = array() ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'missing_post', __( '文章不存在。', 'geo-engine' ) );
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return new WP_Error( 'is_revision', __( '目标是一篇修订版本或自动草稿，已跳过。', 'geo-engine' ) );
		}

		if ( self::is_writing( $post_id ) ) {
			return new WP_Error( 'already_writing', __( '该文章正在写入中。', 'geo-engine' ) );
		}

		$content = self::apply( (string) $post->post_content, $items, $args );

		if ( $content === $post->post_content ) {
			return true;
		}

		self::$writing[ $post_id ] = true;

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			),
			true
		);

		unset( self::$writing[ $post_id ] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Remove every FAQ block from a content string (uninstall path).
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function strip_blocks( $content ) {
		$content = (string) $content;

		$content = preg_replace(
			'/<!--\s*wp:shortcode\s*-->\s*\[geo_faqs\b[^\]]*\].*?\[\/geo_faqs\]\s*<!--\s*\/wp:shortcode\s*-->/is',
			'',
			$content
		);

		$content = preg_replace( '/\[geo_faqs\b[^\]]*\].*?\[\/geo_faqs\]\s*/is', '', (string) $content );

		return trim( (string) $content );
	}

	/**
	 * Mark a post as successfully generated.
	 *
	 * @param int $post_id Post ID.
	 * @param int $count   Item count.
	 * @return void
	 */
	public static function mark_success( $post_id, $count ) {
		update_post_meta( $post_id, '_geo_engine_faq_generated', 1 );
		update_post_meta( $post_id, '_geo_engine_faq_count', (int) $count );
		update_post_meta( $post_id, '_geo_engine_generated_at', time() );
		delete_post_meta( $post_id, '_geo_engine_last_error' );
		delete_post_meta( $post_id, '_geo_engine_skip_reason' );
	}

	/**
	 * Record the last failure for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $message Error message.
	 * @return void
	 */
	public static function mark_failure( $post_id, $message ) {
		$message = GEO_Logger::sanitize_message( $message );

		update_post_meta( $post_id, '_geo_engine_last_error', $message );
	}

	/**
	 * Record why a post was skipped.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $reason  Enum: has_faq|seo_plugin|too_short|not_ready.
	 * @return void
	 */
	public static function mark_skip( $post_id, $reason ) {
		update_post_meta( $post_id, '_geo_engine_skip_reason', $reason );
	}
}
