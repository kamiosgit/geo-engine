<?php
/**
 * Processing log storage.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the custom log table.
 *
 * The log deliberately stores no API key, no request headers and no post body.
 */
class GEO_Logger {

	/**
	 * Log table suffix.
	 */
	const TABLE = 'geo_engine_log';

	/**
	 * Rows older than this are pruned opportunistically.
	 */
	const MAX_ROWS = 5000;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'geo_engine_prune_log', array( __CLASS__, 'prune' ) );
	}

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create or upgrade the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(20) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT '',
			message varchar(500) NOT NULL DEFAULT '',
			tokens int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Write one row.
	 *
	 * @param int    $post_id Post ID (0 for global events).
	 * @param string $action  generate|batch|auto|test.
	 * @param string $status  success|fail|skip.
	 * @param string $message Human readable message, truncated to 500 chars.
	 * @param int    $tokens  Token usage when known.
	 * @return void
	 */
	public static function log( $post_id, $action, $status, $message = '', $tokens = 0 ) {
		global $wpdb;

		$message = self::sanitize_message( $message );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table_name(),
			array(
				'post_id'    => absint( $post_id ),
				'action'     => substr( (string) $action, 0, 20 ),
				'status'     => substr( (string) $status, 0, 20 ),
				'message'    => $message,
				'tokens'     => absint( $tokens ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Trim a message and make sure no secret leaks in by accident.
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	public static function sanitize_message( $message ) {
		$message = wp_strip_all_tags( (string) $message );
		$message = preg_replace( '/\s+/u', ' ', $message );
		$message = trim( (string) $message );

		// Belt and braces: scrub anything that looks like a bearer token or a
		// provider style secret, even though callers should never pass one.
		$message = preg_replace( '/\b(sk|sk-proj|sk-ant)-[A-Za-z0-9_\-]{8,}/', '[redacted]', $message );

		if ( function_exists( 'mb_substr' ) ) {
			$message = mb_substr( $message, 0, 500 );
		} else {
			$message = substr( $message, 0, 500 );
		}

		return $message;
	}

	/**
	 * Fetch recent rows.
	 *
	 * @param int    $limit  Row cap.
	 * @param string $status Optional status filter.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( $limit = 50, $status = '' ) {
		global $wpdb;

		$limit = max( 1, min( 500, (int) $limit ) );
		$table = self::table_name();

		if ( '' !== $status ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Aggregate counters.
	 *
	 * @return array<string,int>
	 */
	public static function summary() {
		global $wpdb;

		$table = self::table_name();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status",
			ARRAY_A
		);

		$summary = array(
			'success' => 0,
			'fail'    => 0,
			'skip'    => 0,
			'tokens'  => 0,
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$key = isset( $row['status'] ) ? (string) $row['status'] : '';

				if ( isset( $summary[ $key ] ) ) {
					$summary[ $key ] = (int) $row['total'];
				}
			}
		}

		$summary['tokens'] = (int) $wpdb->get_var( "SELECT COALESCE(SUM(tokens),0) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $summary;
	}

	/**
	 * Delete every row.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		$table = self::table_name();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Drop the table (uninstall path only).
	 *
	 * @return void
	 */
	public static function drop_table() {
		global $wpdb;

		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Keep the table bounded.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;

		$table = self::table_name();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $count <= self::MAX_ROWS ) {
			return;
		}

		$cutoff = $count - self::MAX_ROWS;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "DELETE FROM {$table} ORDER BY id ASC LIMIT %d", $cutoff )
		);
	}
}
