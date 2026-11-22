<?php
/**
 * Activation, deactivation and upgrade routines.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle handler.
 */
class GEO_Activator {

	/**
	 * Option holding the installed version.
	 */
	const VERSION_OPTION = 'geo_engine_version';

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		GEO_Logger::install();

		$existing = get_option( GEO_Settings::OPTION, array() );

		if ( ! is_array( $existing ) || empty( $existing ) ) {
			add_option( GEO_Settings::OPTION, GEO_Settings::defaults(), '', false );
		} else {
			// Merge in keys introduced by a newer version without losing values.
			update_option( GEO_Settings::OPTION, array_merge( GEO_Settings::defaults(), $existing ), false );
		}

		update_option( self::VERSION_OPTION, GEO_ENGINE_VERSION, false );

		self::add_rewrite_rules();
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( 'geo_engine_prune_log' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'geo_engine_prune_log' );
		}

		GEO_Settings::flush_feed_cache();
	}

	/**
	 * Runs on plugin deactivation. Data and settings are preserved.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();

		wp_clear_scheduled_hook( 'geo_engine_prune_log' );

		GEO_Settings::flush_feed_cache();
	}

	/**
	 * Register the A-layer rewrite rules.
	 *
	 * Kept static so both the runtime module and the activator can call it.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?geo_engine_feed=llms', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?geo_engine_feed=llms_full', 'top' );
		add_rewrite_rule( '^geo-md/([^/]+)/([^/]+?)\.md$', 'index.php?geo_engine_feed=md&geo_engine_pt=$matches[1]&geo_engine_slug=$matches[2]', 'top' );
	}

	/**
	 * Re-run install steps when the plugin files were updated in place.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::VERSION_OPTION, '' );

		if ( GEO_ENGINE_VERSION === $installed ) {
			return;
		}

		GEO_Logger::install();

		$existing = get_option( GEO_Settings::OPTION, array() );
		$existing = is_array( $existing ) ? $existing : array();

		update_option( GEO_Settings::OPTION, array_merge( GEO_Settings::defaults(), $existing ), false );
		update_option( self::VERSION_OPTION, GEO_ENGINE_VERSION, false );

		if ( ! wp_next_scheduled( 'geo_engine_prune_log' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'geo_engine_prune_log' );
		}

		// Endpoint rules may have changed between versions. Register them now
		// and refresh the stored rule set; otherwise /geo-md/… keeps returning
		// 404 until someone re-saves the permalink settings by hand.
		self::add_rewrite_rules();
		flush_rewrite_rules( false );
	}
}
