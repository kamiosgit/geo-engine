<?php
/**
 * Uninstall routine.
 *
 * Runs only when the user deletes the plugin from the Plugins screen.
 * Settings, options, transients, post meta and the log table are always
 * removed; the FAQ shortcodes in post content are kept unless the site owner
 * explicitly opted in to removing them.
 *
 * @package GEO_Engine
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 *
 * @return void
 */
function geo_engine_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'geo_engine_settings', array() );
	$settings = is_array( $settings ) ? $settings : array();

	$remove_faqs = ! empty( $settings['uninstall_remove_faqs'] );

	// Optionally strip generated FAQ blocks from content (opt-in).
	if ( $remove_faqs ) {
		$types = isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? $settings['post_types'] : array( 'post', 'page' );

		$placeholders = implode( ',', array_fill( 0, max( 1, count( $types ) ), '%s' ) );

		$posts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_content LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( $types, array( '%[geo_faqs%' ) )
			)
		);

		if ( is_array( $posts ) ) {
			foreach ( $posts as $row ) {
				$clean = preg_replace(
					'/<!--\s*wp:shortcode\s*-->\s*\[geo_faqs\b[^\]]*\].*?\[\/geo_faqs\]\s*<!--\s*\/wp:shortcode\s*-->|\[geo_faqs\b[^\]]*\].*?\[\/geo_faqs\]\s*/is',
					'',
					(string) $row->post_content
				);

				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->posts,
					array( 'post_content' => trim( (string) $clean ) ),
					array( 'ID' => (int) $row->ID ),
					array( '%s' ),
					array( '%d' )
				);

				clean_post_cache( (int) $row->ID );
			}
		}
	}

	// Post meta.
	$meta_keys = array(
		'_geo_engine_faq_generated',
		'_geo_engine_faq_count',
		'_geo_engine_generated_at',
		'_geo_engine_last_error',
		'_geo_engine_skip_reason',
	);

	foreach ( $meta_keys as $key ) {
		delete_post_meta_by_key( $key );
	}

	// Options.
	delete_option( 'geo_engine_settings' );
	delete_option( 'geo_engine_version' );
	delete_option( 'geo_engine_batch_state' );
	delete_option( 'geo_engine_api_key_cipher' );

	// Transients.
	delete_transient( 'geo_engine_test_result' );
	delete_transient( 'geo_engine_llms_cache' );
	delete_transient( 'geo_engine_llms_full_cache' );
	delete_transient( 'geo_engine_settings_error' );

	// Cron.
	wp_clear_scheduled_hook( 'geo_engine_prune_log' );
	wp_clear_scheduled_hook( 'geo_engine_generate_single' );

	// Log table.
	$table = $wpdb->prefix . 'geo_engine_log';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

	// Per-post processing locks.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_geo_engine_processing_%' OR option_name LIKE '_transient_timeout_geo_engine_processing_%'"
	);

	flush_rewrite_rules();
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		geo_engine_uninstall_site();
		restore_current_blog();
	}
} else {
	geo_engine_uninstall_site();
}
