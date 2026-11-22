<?php
/**
 * Minimal WordPress stubs so the pure-logic classes can be unit tested without
 * a full WordPress install.
 *
 * These stubs implement only what the classes under test touch. Anything that
 * needs a database (options, transients) is kept in memory.
 *
 * @package GEO_Engine
 */

define( 'ABSPATH', __DIR__ . '/fake-root/' );
define( 'GEO_ENGINE_VERSION', '1.0.6' );

// Deterministic timestamps regardless of the host timezone.
date_default_timezone_set( 'UTC' );

// Salts let the key vault derive a real encryption key in tests.
define( 'LOGGED_IN_KEY', 'test-logged-in-key-0123456789' );
define( 'LOGGED_IN_SALT', 'test-logged-in-salt-0123456789' );
define( 'AUTH_KEY', 'test-auth-key-0123456789' );
define( 'GEO_ENGINE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'GEO_ENGINE_PLUGIN_URL', 'https://example.test/wp-content/plugins/geo-engine/' );
define( 'GEO_ENGINE_PLUGIN_BASENAME', 'geo-engine/geo-engine.php' );
define( 'GEO_ENGINE_FILE', dirname( __DIR__ ) . '/geo-engine.php' );

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['geo_test_options']    = array();
$GLOBALS['geo_test_transients'] = array();

/* --------------------------------------------------------------- WP classes */

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error.
	 */
	class WP_Error {

		/** @var array<string,array<int,string>> */
		public $errors = array();

		/** @var array<string,mixed> */
		public $error_data = array();

		/**
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' === $code ) {
				return;
			}

			$this->errors[ $code ][] = $message;

			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		/**
		 * @param string $code    Code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 * @return void
		 */
		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ][] = $message;

			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		/**
		 * @param mixed  $data Data.
		 * @param string $code Code.
		 * @return void
		 */
		public function add_data( $data, $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}

			$this->error_data[ $code ] = $data;
		}

		/**
		 * @param string $code Code.
		 * @return string
		 */
		public function get_error_code( $code = '' ) {
			$codes = array_keys( $this->errors );

			return empty( $codes ) ? '' : (string) $codes[0];
		}

		/**
		 * @param string $code Code.
		 * @return string
		 */
		public function get_error_message( $code = '' ) {
			$current = $this->get_error_code();

			return isset( $this->errors[ $current ][0] ) ? $this->errors[ $current ][0] : '';
		}

		/**
		 * @param string $code Code.
		 * @return mixed
		 */
		public function get_error_data( $code = '' ) {
			$current = $this->get_error_code();

			return isset( $this->error_data[ $current ] ) ? $this->error_data[ $current ] : null;
		}

		/**
		 * @return array<string,array<int,string>>
		 */
		public function get_error_codes() {
			return array_keys( $this->errors );
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal WP_Post.
	 */
	class WP_Post {

		/** @var int */
		public $ID = 0;

		/** @var int */
		public $post_author = 1;

		/** @var string */
		public $post_date = '2026-01-01 10:00:00';

		/** @var string */
		public $post_date_gmt = '2026-01-01 02:00:00';

		/** @var string */
		public $post_content = '';

		/** @var string */
		public $post_title = '';

		/** @var string */
		public $post_excerpt = '';

		/** @var string */
		public $post_status = 'publish';

		/** @var string */
		public $post_password = '';

		/** @var string */
		public $post_name = '';

		/** @var string */
		public $post_modified = '2026-01-02 10:00:00';

		/** @var string */
		public $post_modified_gmt = '2026-01-02 02:00:00';

		/** @var string */
		public $post_type = 'post';

		/**
		 * @param array<string,mixed> $props Properties.
		 */
		public function __construct( array $props = array() ) {
			foreach ( $props as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

/* ------------------------------------------------------------ WP functions */

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Thing.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( '_e' ) ) {
	/**
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return void
	 */
	function _e( $text, $domain = 'default' ) {
		echo $text; // phpcs:ignore
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) {
		return $url;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return $url;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( $text );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function esc_attr__( $text, $domain = 'default' ) {
		return esc_attr( $text );
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_textarea( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param string $text           Text.
	 * @param bool   $remove_breaks  Whether to remove line breaks.
	 * @return string
	 */
	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		$text = (string) $text;
		$text = preg_replace( '/<(script|style)[^>]*>.*?<\/\1>/is', '', $text );
		$text = strip_tags( (string) $text );

		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}

		return trim( (string) $text );
	}
}

if ( ! function_exists( 'strip_shortcodes' ) ) {
	/**
	 * @param string $content Content.
	 * @return string
	 */
	function strip_shortcodes( $content ) {
		return (string) $content;
	}
}

if ( ! function_exists( 'add_shortcode' ) ) {
	/**
	 * @param string   $tag      Tag.
	 * @param callable $callback Callback.
	 * @return void
	 */
	function add_shortcode( $tag, $callback ) {
		$GLOBALS['geo_test_shortcodes'][ $tag ] = $callback;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Args.
	 * @return void
	 */
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['geo_test_hooks'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Args.
	 * @return void
	 */
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['geo_test_filters'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $hook  Hook.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * @param string $hook Hook.
	 * @return void
	 */
	function do_action( $hook ) {
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $name    Name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['geo_test_options'] ) ? $GLOBALS['geo_test_options'][ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $name    Name.
	 * @param mixed  $value   Value.
	 * @param mixed  $autoload Autoload.
	 * @return bool
	 */
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['geo_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * @param string $name    Name.
	 * @param mixed  $value   Value.
	 * @param string $deprecated Deprecated.
	 * @param mixed  $autoload Autoload.
	 * @return bool
	 */
	function add_option( $name, $value, $deprecated = '', $autoload = null ) {
		$GLOBALS['geo_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * @param string $name Name.
	 * @return bool
	 */
	function delete_option( $name ) {
		unset( $GLOBALS['geo_test_options'][ $name ] );

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * @param string $name Name.
	 * @return mixed
	 */
	function get_transient( $name ) {
		return array_key_exists( $name, $GLOBALS['geo_test_transients'] ) ? $GLOBALS['geo_test_transients'][ $name ] : false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * @param string $name  Name.
	 * @param mixed  $value Value.
	 * @param int    $ttl   TTL.
	 * @return bool
	 */
	function set_transient( $name, $value, $ttl = 0 ) {
		$GLOBALS['geo_test_transients'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * @param string $name Name.
	 * @return bool
	 */
	function delete_transient( $name ) {
		unset( $GLOBALS['geo_test_transients'][ $name ] );

		return true;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function sanitize_text_field( $text ) {
		return trim( preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags( (string) $text ) ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function sanitize_textarea_field( $text ) {
		return trim( wp_strip_all_tags( (string) $text ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * @param string $title Title.
	 * @return string
	 */
	function sanitize_title( $title ) {
		return trim( preg_replace( '/[^a-z0-9\-]/', '-', strtolower( (string) $title ) ), '-' );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data    Data.
	 * @param int   $options Options.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0 ) {
		return json_encode( $data, $options );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * @param string $data Data.
	 * @return string
	 */
	function wp_kses_post( $data ) {
		return (string) $data;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * @param string $path Path.
	 * @return string
	 */
	function home_url( $path = '' ) {
		return 'https://example.test' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * @param string $path Path.
	 * @return string
	 */
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * @param string $value Value.
	 * @return string
	 */
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * @param string $value Value.
	 * @return string
	 */
	function trailingslashit( $value ) {
		return untrailingslashit( $value ) . '/';
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * @param string $show Show.
	 * @return string
	 */
	function get_bloginfo( $show = '' ) {
		$map = array(
			'name'        => 'Example Site',
			'description' => 'An example site',
			'language'    => 'zh-CN',
		);

		return isset( $map[ $show ] ) ? $map[ $show ] : '';
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	/**
	 * @param array  $args     Args.
	 * @param string $output   Output.
	 * @param string $operator Operator.
	 * @return array
	 */
	function get_post_types( $args = array(), $output = 'names', $operator = 'and' ) {
		return array();
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	/**
	 * @param string $type Type.
	 * @return object|null
	 */
	function get_post_type_object( $type ) {
		$labels = array(
			'post' => (object) array( 'name' => '文章' ),
			'page' => (object) array( 'name' => '页面' ),
		);

		if ( ! isset( $labels[ $type ] ) ) {
			return null;
		}

		return (object) array( 'labels' => $labels[ $type ] );
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * @param mixed $post Post.
	 * @return WP_Post|null
	 */
	function get_post( $post ) {
		if ( $post instanceof WP_Post ) {
			return $post;
		}

		return null;
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	/**
	 * @param mixed $post Post.
	 * @return string
	 */
	function get_the_title( $post = 0 ) {
		return $post instanceof WP_Post ? (string) $post->post_title : '';
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * @param mixed $post Post.
	 * @return string
	 */
	function get_permalink( $post = 0 ) {
		$slug = $post instanceof WP_Post ? $post->post_name : 'sample';

		return 'https://example.test/' . $slug;
	}
}

if ( ! function_exists( 'get_the_date' ) ) {
	/**
	 * @param string $format Format.
	 * @param mixed  $post   Post.
	 * @return string
	 */
	function get_the_date( $format = '', $post = null ) {
		return gmdate( '' === $format ? 'Y-m-d' : $format, strtotime( $post instanceof WP_Post ? $post->post_date_gmt : 'now' ) );
	}
}

if ( ! function_exists( 'get_the_modified_date' ) ) {
	/**
	 * @param string $format Format.
	 * @param mixed  $post   Post.
	 * @return string
	 */
	function get_the_modified_date( $format = '', $post = null ) {
		return gmdate( '' === $format ? 'Y-m-d' : $format, strtotime( $post instanceof WP_Post ? $post->post_modified_gmt : 'now' ) );
	}
}

if ( ! function_exists( 'get_the_author_meta' ) ) {
	/**
	 * @param string $field Field.
	 * @param int    $user  User.
	 * @return string
	 */
	function get_the_author_meta( $field, $user = 0 ) {
		return 'Author';
	}
}

if ( ! function_exists( 'get_author_posts_url' ) ) {
	/**
	 * @param int $id ID.
	 * @return string
	 */
	function get_author_posts_url( $id ) {
		return 'https://example.test/author/1';
	}
}

if ( ! function_exists( 'get_the_category' ) ) {
	/**
	 * @param int $id ID.
	 * @return array
	 */
	function get_the_category( $id = 0 ) {
		return array();
	}
}

if ( ! function_exists( 'get_category_link' ) ) {
	/**
	 * @param int $id ID.
	 * @return string
	 */
	function get_category_link( $id ) {
		return 'https://example.test/category';
	}
}

if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
	/**
	 * @param mixed  $post Post.
	 * @param string $size Size.
	 * @return string|false
	 */
	function get_the_post_thumbnail_url( $post = null, $size = 'full' ) {
		return false;
	}
}

if ( ! function_exists( 'do_blocks' ) ) {
	/**
	 * @param string $content Content.
	 * @return string
	 */
	function do_blocks( $content ) {
		return (string) $content;
	}
}

if ( ! function_exists( 'esc_js' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_js( $text ) {
		return addslashes( (string) $text );
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * @param float $number Number.
	 * @param int   $decimals Decimals.
	 * @return string
	 */
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * @param string $type Type.
	 * @return string
	 */
	function current_time( $type = 'mysql' ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'is_singular' ) ) {
	/**
	 * @return bool
	 */
	function is_singular() {
		return false;
	}
}

if ( ! function_exists( 'get_queried_object' ) ) {
	/**
	 * @return null
	 */
	function get_queried_object() {
		return null;
	}
}


if ( ! function_exists( 'wp_enqueue_style' ) ) {
	/**
	 * @param string $handle Handle.
	 * @return void
	 */
	function wp_enqueue_style( $handle ) {
		$GLOBALS['geo_test_enqueued'][] = $handle;
	}
}

if ( ! function_exists( 'wp_register_style' ) ) {
	/**
	 * @param string $handle Handle.
	 * @param string $src    Source.
	 * @param array  $deps   Dependencies.
	 * @param string $ver    Version.
	 * @return void
	 */
	function wp_register_style( $handle, $src = '', $deps = array(), $ver = '' ) {
		$GLOBALS['geo_test_registered'][ $handle ] = $src;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	/**
	 * @param string $handle Handle.
	 * @return void
	 */
	function wp_enqueue_script( $handle ) {
	}

}

if ( ! function_exists( 'wp_localize_script' ) ) {
	/**
	 * @param string $handle Handle.
	 * @param string $name   Name.
	 * @param array  $data   Data.
	 * @return void
	 */
	function wp_localize_script( $handle, $name, $data ) {
		$GLOBALS['geo_test_localized'][ $name ] = $data;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * @return bool
	 */
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( 'is_feed' ) ) {
	/**
	 * @return bool
	 */
	function is_feed() {
		return false;
	}
}

if ( ! function_exists( 'is_robots' ) ) {
	/**
	 * @return bool
	 */
	function is_robots() {
		return false;
	}
}

if ( ! function_exists( 'is_trackback' ) ) {
	/**
	 * @return bool
	 */
	function is_trackback() {
		return false;
	}
}


if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * @param array|string $args Arguments.
	 * @param string       $url  Base URL.
	 * @return string
	 */
	function add_query_arg( $args, $url = '' ) {
		if ( ! is_array( $args ) ) {
			return $url;
		}

		$separator = ( false === strpos( $url, '?' ) ) ? '?' : '&';

		return $url . $separator . http_build_query( $args );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param string|array $value Value.
	 * @return string|array
	 */
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'get_page_by_path' ) ) {
	/**
	 * @param string $page_path Path.
	 * @param string $output    Output type.
	 * @param string $post_type Post type.
	 * @return object|null
	 */
	function get_page_by_path( $page_path, $output = OBJECT, $post_type = 'page' ) {
		$key = $post_type . '|' . $page_path;

		return isset( $GLOBALS['geo_test_page_by_path'][ $key ] ) ? $GLOBALS['geo_test_page_by_path'][ $key ] : null;
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal WP_Query stand-in: returns whatever the test queued.
	 */
	class WP_Query {
		/**
		 * @var array<int,object>
		 */
		public $posts = array();

		/**
		 * @param array<string,mixed> $args Query args.
		 */
		public function __construct( $args = array() ) {
			$name = isset( $args['name'] ) ? (string) $args['name'] : '';

			$this->posts = isset( $GLOBALS['geo_test_query_by_name'][ $name ] ) ? $GLOBALS['geo_test_query_by_name'][ $name ] : array();
		}
	}
}

/* ------------------------------------------------------------ plugin files */

require_once GEO_ENGINE_PLUGIN_DIR . 'includes/class-key-vault.php';
require_once GEO_ENGINE_PLUGIN_DIR . 'includes/class-logger.php';
require_once GEO_ENGINE_PLUGIN_DIR . 'includes/class-settings.php';
require_once GEO_ENGINE_PLUGIN_DIR . 'includes/class-settings-llm.php';
require_once GEO_ENGINE_PLUGIN_DIR . 'includes/class-llm-driver-interface.php';
require_once GEO_ENGINE_PLUGIN_DIR . 'includes/class-driver-base.php';
require_once GEO_ENGINE_PLUGIN_DIR . 'includes/class-driver-openai-chat.php';
require_once GEO_ENGINE_PLUGIN_DIR . 'includes/class-driver-anthropic.php';
require_once GEO_ENGINE_PLUGIN_DIR . 'includes/class-llm-client.php';
require_once GEO_ENGINE_PLUGIN_DIR . 'includes/class-faq-generator.php';
require_once GEO_ENGINE_PLUGIN_DIR . 'includes/class-shortcode.php';
require_once GEO_ENGINE_PLUGIN_DIR . 'includes/class-writeback.php';
require_once GEO_ENGINE_PLUGIN_DIR . 'includes/class-bulk.php';
require_once GEO_ENGINE_PLUGIN_DIR . 'includes/class-schema.php';
require_once GEO_ENGINE_PLUGIN_DIR . 'includes/class-llms-feed.php';

