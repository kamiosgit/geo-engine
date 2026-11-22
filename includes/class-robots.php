<?php
/**
 * robots.txt rules and the Markdown alternate link.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Controls how AI crawlers see the site.
 */
class GEO_Robots {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_filter( 'robots_txt', array( $this, 'filter_robots' ), 20, 2 );
		add_action( 'wp_head', array( $this, 'output_meta' ), 6 );
	}

	/**
	 * Build the robots.txt block for the configured crawlers.
	 *
	 * @param array<string,string> $crawlers UA => allow|disallow.
	 * @return string
	 */
	public static function build_rules( array $crawlers ) {
		$blocks = array();

		foreach ( $crawlers as $agent => $policy ) {
			$agent = trim( (string) $agent );

			if ( '' === $agent || ! preg_match( '/^[A-Za-z0-9\-_]+$/', $agent ) ) {
				continue;
			}

			$directive = ( 'disallow' === $policy ) ? 'Disallow' : 'Allow';

			$blocks[] = 'User-agent: ' . $agent . "\n" . $directive . ': /';
		}

		if ( empty( $blocks ) ) {
			return '';
		}

		return implode( "\n\n", $blocks ) . "\n";
	}

	/**
	 * Append our block to the generated robots.txt.
	 *
	 * @param string $output Existing content.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public function filter_robots( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}

		$rules = self::build_rules( (array) GEO_Settings::get( 'crawlers', array() ) );

		if ( '' === trim( $rules ) ) {
			return $output;
		}

		return rtrim( (string) $output ) . "\n\n# GEO Engine — AI crawler policy\n" . $rules;
	}

	/**
	 * Advertise the Markdown variant of the current page.
	 *
	 * @return void
	 */
	public function output_meta() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post || '' !== (string) $post->post_password ) {
			return;
		}

		$allowed = (array) GEO_Settings::get( 'post_types', array( 'post', 'page' ) );

		if ( ! in_array( $post->post_type, $allowed, true ) ) {
			return;
		}

		$url = GEO_LLMS_Feed::markdown_url( $post );

		if ( '' === $url ) {
			return;
		}

		printf(
			'<link rel="alternate" type="text/markdown" href="%s" title="%s" />' . "\n",
			esc_url( $url ),
			esc_attr( get_the_title( $post ) )
		);
	}
}
