<?php
/**
 * Bulk generation with batching, resume and retry.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Batch screen + AJAX endpoints. State lives in one option so a reload or a
 * closed browser never loses progress.
 */
class GEO_Bulk {

	/**
	 * State option.
	 */
	const STATE_OPTION = 'geo_engine_batch_state';

	/**
	 * How many consecutive auth errors abort the run.
	 */
	const AUTH_FAILURE_LIMIT = 3;

	/**
	 * Seconds one AJAX request may spend before it hands control back to the
	 * browser. Keeps every request far below the gateway timeout, so a large
	 * batch size can never produce a 504.
	 */
	const TIME_BUDGET = 30;

	/**
	 * Minimum seconds left in the budget before another article may be started.
	 *
	 * Starting an article with less runway than this is what turned a slow model
	 * into a 504: the request outlived the gateway, the browser received FPM's
	 * HTML error page, and the UI reported a bare "network error".
	 */
	const MIN_POST_SECONDS = 8;

	/**
	 * Hard ceiling for a single article, derived from the budget above.
	 */
	const MAX_POST_TIMEOUT = 30;

	/**
	 * Seconds a lock is considered stale. Guards against two batches running
	 * at once (double-click, or a reloaded page resuming too early).
	 */
	const LOCK_TTL = 90;

	/**
	 * Retrying a failed article gets a wider runway than a normal batch pass.
	 *
	 * Failures are usually the slow articles — the ones that needed more than
	 * the 30 second batch cap — so clipping the retry at the same 30 seconds
	 * would make those items fail forever.
	 *
	 * The retry endpoint answers the browser *before* it starts working
	 * (see finish_early()), so the gateway is no longer part of this budget:
	 * the only hard limits left are PHP's max_execution_time (disabled here)
	 * and php-fpm's request_terminate_timeout, usually well above this.
	 */
	const RETRY_BUDGET = 75;

	/**
	 * Hard ceiling for a single article while retrying.
	 */
	const RETRY_MAX_POST = 60;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_geo_engine_bulk_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_geo_engine_bulk_batch', array( $this, 'ajax_batch' ) );
		add_action( 'wp_ajax_geo_engine_bulk_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_geo_engine_bulk_resume', array( $this, 'ajax_resume' ) );
		add_action( 'wp_ajax_geo_engine_bulk_stop', array( $this, 'ajax_stop' ) );
		add_action( 'wp_ajax_geo_engine_bulk_retry', array( $this, 'ajax_retry' ) );
		add_action( 'wp_ajax_geo_engine_bulk_retry_batch', array( $this, 'ajax_retry_batch' ) );
		add_action( 'wp_ajax_geo_engine_bulk_reset', array( $this, 'ajax_reset' ) );
	}

	/**
	 * Initial batch state.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_state() {
		return array(
			'status'        => 'idle',
			'mode'          => 'batch',
			'cursor'        => 0,
			'total'         => 0,
			'processed'     => 0,
			'success'       => 0,
			'failed'        => 0,
			'skipped'       => 0,
			'auth_failures' => 0,
			'failed_ids'    => array(),
			'retry_queue'   => array(),
			'retry_total'   => 0,
			'retry_done'    => 0,
			'retry_ok'      => 0,
			'retry_fail'    => 0,
			'retry_skip'    => 0,
			'retry_log'     => array(),
			'message'       => '',
			'force'         => false,
			'current_id'    => 0,
			'current_title' => '',
			'lock'          => 0,
			'started_at'    => 0,
			'updated_at'    => 0,
		);
	}

	/**
	 * Whether the current run is a retry pass over the failed queue.
	 *
	 * @param array<string,mixed> $state State.
	 * @return bool
	 */
	public static function is_retrying( array $state ) {
		return isset( $state['mode'] ) && 'retry' === $state['mode'];
	}

	/**
	 * Progress in whole percent.
	 *
	 * @param array<string,mixed> $state State.
	 * @return int
	 */
	public static function progress_percent( array $state ) {
		$retrying  = self::is_retrying( $state );
		$total     = $retrying ? (int) ( isset( $state['retry_total'] ) ? $state['retry_total'] : 0 ) : (int) ( isset( $state['total'] ) ? $state['total'] : 0 );
		$processed = $retrying ? (int) ( isset( $state['retry_done'] ) ? $state['retry_done'] : 0 ) : (int) ( isset( $state['processed'] ) ? $state['processed'] : 0 );

		if ( $total <= 0 ) {
			return 0;
		}

		return (int) min( 100, max( 0, round( ( $processed / $total ) * 100 ) ) );
	}

	/**
	 * How many posts are still queued.
	 *
	 * @param array<string,mixed> $state State.
	 * @return int
	 */
	public static function remaining( array $state ) {
		$retrying  = self::is_retrying( $state );
		$total     = $retrying ? (int) ( isset( $state['retry_total'] ) ? $state['retry_total'] : 0 ) : (int) ( isset( $state['total'] ) ? $state['total'] : 0 );
		$processed = $retrying ? (int) ( isset( $state['retry_done'] ) ? $state['retry_done'] : 0 ) : (int) ( isset( $state['processed'] ) ? $state['processed'] : 0 );

		return max( 0, $total - $processed );
	}

	/**
	 * Re-read the state bypassing the in-request option cache.
	 *
	 * The stop button lands in a separate request, so the running batch has to
	 * look at the database to notice it. Without the cache flush `get_option()`
	 * replays the copy this request already wrote and the stop is ignored until
	 * the whole batch finishes.
	 *
	 * @return array<string,mixed>
	 */
	private static function fresh_state() {
		wp_cache_delete( self::STATE_OPTION, 'options' );

		return self::get_state();
	}

	/**
	 * Read the state.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		$state = array_merge( self::default_state(), $state );

		if ( ! is_array( $state['failed_ids'] ) ) {
			$state['failed_ids'] = array();
		}

		if ( ! is_array( $state['retry_queue'] ) ) {
			$state['retry_queue'] = array();
		}

		if ( ! is_array( $state['retry_log'] ) ) {
			$state['retry_log'] = array();
		}

		$state['failed_ids']  = array_values( array_filter( array_map( 'intval', $state['failed_ids'] ) ) );
		$state['retry_queue'] = array_values( array_filter( array_map( 'intval', $state['retry_queue'] ) ) );
		$state['mode']        = ( 'retry' === $state['mode'] ) ? 'retry' : 'batch';
		$state['retry_total'] = (int) $state['retry_total'];
		$state['retry_done']  = (int) $state['retry_done'];
		$state['retry_ok']    = (int) $state['retry_ok'];
		$state['retry_fail']  = (int) $state['retry_fail'];
		$state['retry_skip']  = (int) $state['retry_skip'];
		$state['force']       = ! empty( $state['force'] );

		return $state;
	}

	/**
	 * Persist the state.
	 *
	 * @param array<string,mixed> $state State.
	 * @return void
	 */
	public static function save_state( array $state ) {
		$state['updated_at'] = time();

		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Send the JSON response now and keep running afterwards.
	 *
	 * A 504 is not the plugin timing out — it is the gateway giving up on a
	 * request that is still alive. PHP-FPM (and LiteSpeed) can flush the
	 * response and detach the connection with finish_request(), after which
	 * nginx has nothing left to wait for. The work continues under
	 * set_time_limit(0), and the browser follows it through the status
	 * endpoint. That is what lets a single slow article take 60 seconds
	 * instead of being killed at the gateway's 30 second limit.
	 *
	 * @param array<string,mixed> $payload Response data.
	 * @return bool True when the connection was handed back.
	 */
	private static function finish_early( array $payload ) {
		$finisher = '';

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			$finisher = 'fastcgi_finish_request';
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			$finisher = 'litespeed_finish_request';
		}

		if ( '' === $finisher ) {
			return false;
		}

		// Drop anything a plugin may have echoed already, then send a clean
		// JSON body: the front end parses it exactly like wp_send_json_success.
		while ( ob_get_level() > 0 ) {
			@ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'X-Accel-Buffering: no' );
			header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		}

		echo wp_json_encode( array( 'success' => true, 'data' => $payload ) );

		$finisher();

		return true;
	}

	/**
	 * Post types the batch may touch.
	 *
	 * @return array<int,string>
	 */
	private static function post_types() {
		$types = (array) GEO_Settings::get( 'post_types', array( 'post', 'page' ) );

		return array_values( array_filter( array_map( 'sanitize_key', $types ) ) );
	}

	/**
	 * Count eligible posts.
	 *
	 * @return int
	 */
	public static function count_eligible() {
		global $wpdb;

		$types = self::post_types();

		if ( empty( $types ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status = 'publish' AND post_password = ''";

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $types ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}

	/**
	 * Next page of IDs after the cursor.
	 *
	 * @param int $cursor Last processed ID.
	 * @param int $limit  Batch size.
	 * @return array<int,int>
	 */
	public static function next_ids( $cursor, $limit ) {
		global $wpdb;

		$types = self::post_types();

		if ( empty( $types ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$params       = array_merge( $types, array( (int) $cursor, (int) $limit ) );

		$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status = 'publish' AND post_password = '' AND ID > %d ORDER BY ID ASC LIMIT %d";

		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * AJAX: start (or restart) a run.
	 *
	 * @return void
	 */
	public function ajax_start() {
		$this->guard();

		if ( ! GEO_Settings::llm_ready() ) {
			wp_send_json_error( array( 'message' => __( '尚未配置 LLM，无法开始批量生成。', 'geo-engine' ) ) );
		}

		$state = self::default_state();

		$state['status']     = 'running';
		$state['total']      = self::count_eligible();
		$state['started_at'] = time();
		$state['force']      = ! empty( $_POST['force'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guarded above.

		if ( $state['force'] ) {
			$state['message'] = __( '已开启强制重新生成：已有 FAQ 的文章也会被覆盖。', 'geo-engine' );
		}

		self::save_state( $state );

		wp_send_json_success( array( 'state' => $state ) );
	}

	/**
	 * AJAX: process one slice of the queue.
	 *
	 * A "batch" is bounded by two things: the configured batch size and a hard
	 * time budget. Whichever runs out first ends the request, and the browser
	 * immediately asks for the next slice. That keeps each request well inside
	 * the gateway timeout and gives the UI a fresh progress update every few
	 * seconds instead of one frozen request for ten minutes.
	 *
	 * @return void
	 */
	public function ajax_batch() {
		$this->guard();

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		ignore_user_abort( true );

		$state = self::get_state();

		if ( 'running' !== $state['status'] ) {
			wp_send_json_error( array( 'message' => __( '当前没有进行中的任务。', 'geo-engine' ), 'state' => $state ) );
		}

		// A retry pass owns the queue until it finishes; the batch endpoint must
		// not nibble at the cursor in the meantime.
		if ( self::is_retrying( $state ) ) {
			wp_send_json_error( array( 'message' => __( '当前正在进行重试任务。', 'geo-engine' ), 'state' => $state, 'busy' => true ) );
		}

		// Do not stack a second batch on top of a live one. Another request may
		// hold the queue: a double click, or a page that resumed too eagerly.
		if ( ! empty( $state['lock'] ) && ( time() - (int) $state['lock'] ) < self::LOCK_TTL ) {
			wp_send_json_error(
				array(
					'message' => __( '上一批仍在处理中，请稍候。', 'geo-engine' ),
					'state'   => $state,
					'busy'    => true,
				)
			);
		}

		$state['lock'] = time();

		$size  = max( 1, min( 20, (int) GEO_Settings::get( 'batch_size', 5 ) ) );
		$delay = max( 0, min( 30, (int) GEO_Settings::get( 'batch_delay', 2 ) ) );
		$force = ! empty( $state['force'] );
		$ids   = self::next_ids( (int) $state['cursor'], $size );

		if ( empty( $ids ) ) {
			$state['status']  = 'done';
			$state['lock']    = 0;
			$state['message'] = __( '全部处理完成。', 'geo-engine' );

			self::save_state( $state );

			wp_send_json_success( array( 'state' => $state, 'log' => array(), 'handled' => 0 ) );
		}

		$log     = array();
		$started = microtime( true );
		$handled = 0;
		$count   = count( $ids );
		$aborted = false;

		foreach ( $ids as $index => $post_id ) {
			$left = self::TIME_BUDGET - ( microtime( true ) - $started );

			// Do not open a new article unless it has real runway left. This is
			// the guard that keeps a single slow generation from outliving the
			// gateway and turning the response into a 504 page.
			if ( $index > 0 && $left < self::MIN_POST_SECONDS ) {
				break;
			}

			// Publish what is being worked on so the status endpoint can show it.
			$state['current_id']    = (int) $post_id;
			$state['current_title'] = (string) get_the_title( $post_id );
			$state['lock']          = time(); // keep the lock fresh on long runs

			self::save_state( $state );

			$per_timeout = (int) max(
				self::MIN_POST_SECONDS + 4,
				min( self::MAX_POST_TIMEOUT, $left + 4 )
			);

			$result = GEO_Automation::process_post(
				$post_id,
				array(
					'action'         => 'batch',
					'force'          => $force,
					// One attempt, no transport retry storm: a retried 503 would
					// blow the budget and the request would die with the batch.
					'timeout'        => $per_timeout,
					'max_retries'    => 0,
					'retry_on_parse' => false,
				)
			);

			$state['processed']++;
			$state['cursor'] = (int) $post_id;
			$handled++;

			if ( 'success' === $result['status'] ) {
				$state['success']++;
			} elseif ( 'skip' === $result['status'] ) {
				$state['skipped']++;
			} else {
				$state['failed']++;
				$state['failed_ids'][] = $post_id;

				if ( count( $state['failed_ids'] ) > 200 ) {
					$state['failed_ids'] = array_slice( $state['failed_ids'], -200 );
				}
			}

			// Circuit breaker: a wrong key would otherwise burn through the queue.
			if ( in_array( $result['code'], array( 'invalid_key', 'forbidden' ), true ) ) {
				$state['auth_failures']++;
			} else {
				$state['auth_failures'] = 0;
			}

			$log[] = array(
				'post_id' => $post_id,
				'title'   => $state['current_title'],
				'status'  => $result['status'],
				'message' => $result['message'],
			);

			if ( $state['auth_failures'] >= self::AUTH_FAILURE_LIMIT ) {
				$state['status']  = 'error';
				$state['message'] = __( '连续 3 次鉴权失败，任务已自动中止。请检查 API Key 后重试。', 'geo-engine' );
				$aborted          = true;

				break;
			}

			// A stop request lands in another PHP process; the cached option here
			// still says "running", so read the row back before continuing.
			$fresh = self::fresh_state();

			if ( 'running' !== $fresh['status'] ) {
				$state['status']  = $fresh['status'];
				$state['message'] = $fresh['message'];
				$aborted          = true;

				break;
			}

			// Out of budget: hand back to the browser, it will ask for more.
			if ( $index < $count - 1 && ( microtime( true ) - $started ) >= self::TIME_BUDGET ) {
				break;
			}
		}

		$state['current_id']    = 0;
		$state['current_title'] = '';
		$state['lock']          = 0;

		if ( ! $aborted ) {
			if ( empty( self::next_ids( (int) $state['cursor'], 1 ) ) ) {
				$state['status'] = 'done';

				if ( '' === $state['message'] ) {
					$state['message'] = __( '全部处理完成。', 'geo-engine' );
				}
			} else {
				$state['message'] = sprintf(
					/* translators: 1: processed count, 2: total count, 3: remaining count */
					__( '已处理 %1$d / %2$d 篇，剩余 %3$d 篇。', 'geo-engine' ),
					(int) $state['processed'],
					(int) $state['total'],
					self::remaining( $state )
				);
			}
		}

		self::save_state( $state );

		wp_send_json_success(
			array(
				'state'   => $state,
				'log'     => $log,
				'handled' => $handled,
				'delay'   => $delay,
				'elapsed' => round( microtime( true ) - $started, 2 ),
			)
		);
	}

	/**
	 * AJAX: lightweight state read for the live progress display.
	 *
	 * @return void
	 */
	public function ajax_status() {
		$this->guard();

		$state    = self::fresh_state();
		$lock_age = empty( $state['lock'] ) ? 0 : max( 0, time() - (int) $state['lock'] );
		$busy     = ( ! empty( $state['lock'] ) && $lock_age < self::LOCK_TTL );

		wp_send_json_success(
			array(
				'state'     => $state,
				'percent'   => self::progress_percent( $state ),
				'remaining' => self::remaining( $state ),
				'age'       => max( 0, time() - (int) $state['updated_at'] ),
				'busy'      => $busy,
				'lock_age'  => $lock_age,
			)
		);
	}

	/**
	 * AJAX: resume a paused run.
	 *
	 * The batch endpoint refuses to work unless the state says "running", so the
	 * continue button has to flip it back first. Without this, stopping once
	 * left the queue unusable until someone pressed "start" and lost the cursor.
	 *
	 * @return void
	 */
	public function ajax_resume() {
		$this->guard();

		if ( ! GEO_Settings::llm_ready() ) {
			wp_send_json_error( array( 'message' => __( '尚未配置 LLM。', 'geo-engine' ) ) );
		}

		$state = self::get_state();

		// The checkbox may be flipped between runs; keep the queue in sync.
		if ( isset( $_POST['force'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guarded above.
			$state['force'] = ! empty( $_POST['force'] );
		}

		if ( 'paused' === $state['status'] ) {
			$state['status']  = 'running';
			$state['lock']    = 0;
			$state['message'] = __( '已从断点继续。', 'geo-engine' );
		} elseif ( 'running' === $state['status'] && ! empty( $state['lock'] ) ) {
			// A 504 kills the PHP worker but leaves its lock behind. Once the
			// lock is older than the TTL nothing can be running any more, so
			// clear it instead of making the user wait out the TTL.
			$lock_age = max( 0, time() - (int) $state['lock'] );

			if ( $lock_age >= self::LOCK_TTL ) {
				$state['lock']          = 0;
				$state['current_id']    = 0;
				$state['current_title'] = '';
			}
		}

		self::save_state( $state );

		wp_send_json_success( array( 'state' => $state ) );
	}

	/**
	 * AJAX: stop and keep progress.
	 *
	 * @return void
	 */
	public function ajax_stop() {
		$this->guard();

		$state = self::get_state();

		if ( 'running' === $state['status'] ) {
			$state['status']  = 'paused';
			$state['message'] = __( '已停止，进度已保留。当前正在处理的那一篇会先完成。', 'geo-engine' );

			self::save_state( $state );
		}

		wp_send_json_success( array( 'state' => $state ) );
	}

	/**
	 * AJAX: start a retry pass over the failed queue.
	 *
	 * This endpoint only prepares the run; the browser drives it through
	 * ajax_retry_batch exactly like a normal batch. That is what makes one
	 * click retry every failed article with a moving progress bar, and what
	 * lets a 504 in the middle recover instead of stranding the queue.
	 *
	 * @return void
	 */
	public function ajax_retry() {
		$this->guard();

		if ( ! GEO_Settings::llm_ready() ) {
			wp_send_json_error( array( 'message' => __( '尚未配置 LLM。', 'geo-engine' ) ) );
		}

		$state = self::get_state();

		// One run at a time: never stack a retry on a live batch.
		if ( 'running' === $state['status'] ) {
			$lock_age = empty( $state['lock'] ) ? self::LOCK_TTL : max( 0, time() - (int) $state['lock'] );

			if ( $lock_age < self::LOCK_TTL ) {
				wp_send_json_error(
					array(
						'message' => __( '当前有任务正在运行，请先停止或等它结束，再重试失败项。', 'geo-engine' ),
						'state'   => $state,
						'busy'    => true,
					)
				);
			}
		}

		$queue = array_values( array_unique( array_filter( array_map( 'intval', (array) $state['failed_ids'] ) ) ) );

		if ( empty( $queue ) ) {
			wp_send_json_error( array( 'message' => __( '没有需要重试的文章。', 'geo-engine' ), 'state' => $state ) );
		}

		$state['mode']          = 'retry';
		$state['status']        = 'running';
		$state['retry_queue']   = $queue;
		$state['retry_total']   = count( $queue );
		$state['retry_done']    = 0;
		$state['retry_ok']      = 0;
		$state['retry_fail']    = 0;
		$state['retry_skip']    = 0;
		$state['retry_log']     = array();
		$state['auth_failures'] = 0;
		$state['lock']          = 0;
		$state['current_id']    = 0;
		$state['current_title'] = '';
		$state['message']       = sprintf(
			/* translators: %d: number of failed posts. */
			__( '开始重试 %d 篇失败项…', 'geo-engine' ),
			count( $queue )
		);

		self::save_state( $state );

		wp_send_json_success(
			array(
				'state' => $state,
				'delay' => self::delay_seconds(),
			)
		);
	}

	/**
	 * AJAX: process one slice of the retry queue.
	 *
	 * Retries get a wider per-article runway (RETRY_BUDGET) than a batch pass:
	 * the failed items are usually the slow ones, so clipping them at the same
	 * 30 seconds would keep them failing forever.
	 *
	 * @return void
	 */
	public function ajax_retry_batch() {
		$this->guard();

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		ignore_user_abort( true );

		$state = self::get_state();

		if ( ! self::is_retrying( $state ) || 'running' !== $state['status'] ) {
			wp_send_json_error( array( 'message' => __( '当前没有进行中的重试任务。', 'geo-engine' ), 'state' => $state ) );
		}

		if ( ! empty( $state['lock'] ) && ( time() - (int) $state['lock'] ) < self::LOCK_TTL ) {
			wp_send_json_error(
				array(
					'message' => __( '上一批仍在处理中，请稍候。', 'geo-engine' ),
					'state'   => $state,
					'busy'    => true,
				)
			);
		}

		$state['lock'] = time();

		// Exactly one article per retry slice. Failed items are the slow ones
		// by definition, so a 60 second call already uses most of the budget —
		// starting a second article would only shrink its runway.
		$size = 1;
		$ids  = array_slice( array_values( (array) $state['retry_queue'] ), 0, $size );

		if ( empty( $ids ) ) {
			self::finish_retry( $state );
			self::save_state( $state );

			wp_send_json_success( array( 'state' => $state, 'log' => array(), 'handled' => 0, 'delay' => 0 ) );
		}

		// Publish the first item before the response leaves, so the progress
		// panel has something to show during the first (possibly slow) call.
		$state['current_id']    = (int) $ids[0];
		$state['current_title'] = (string) get_the_title( $ids[0] );
		$state['message']       = sprintf(
			/* translators: %s: post title. */
			__( '正在重试《%s》…', 'geo-engine' ),
			$state['current_title']
		);

		self::save_state( $state );

		// Answer the browser now and keep working. The gateway stops counting
		// the moment the response is flushed, so a slow model can no longer be
		// turned into a 504 — the browser follows the run through the status
		// endpoint instead of waiting on this connection.
		$detached = self::finish_early(
			array(
				'state'    => $state,
				'accepted' => true,
				'delay'    => 0,
			)
		);

		$log     = array();
		$started = microtime( true );
		$handled = 0;
		$count   = count( $ids );
		$aborted = false;

		// Per-article ceiling is a setting: a very long article on a slow
		// provider may legitimately need more than a minute, and the detached
		// response means the gateway is no longer the limit.
		$ceiling = (int) max( 20, min( 180, (int) GEO_Settings::get( 'retry_timeout', self::RETRY_MAX_POST ) ) );

		if ( ! $detached ) {
			// No fastcgi/litespeed finisher on this SAPI (plain Apache module,
			// for instance): the gateway is still waiting on us, so stay well
			// inside its usual 30 second limit instead of trading a 504 for a
			// slightly larger timeout.
			$ceiling = min( $ceiling, 25 );
		}

		$budget = $ceiling + 15;

		foreach ( $ids as $index => $post_id ) {
			$left = $budget - ( microtime( true ) - $started );

			if ( $index > 0 && $left < self::MIN_POST_SECONDS ) {
				break;
			}

			$state['current_id']    = (int) $post_id;
			$state['current_title'] = (string) get_the_title( $post_id );
			$state['lock']          = time(); // keep the lock fresh on long runs

			self::save_state( $state );

			$per_timeout = (int) max(
				self::MIN_POST_SECONDS + 4,
				min( $ceiling, $left + 4 )
			);

			$result = GEO_Automation::process_post(
				$post_id,
				array(
					'action'         => 'retry',
					// A retry always overwrites: that is the whole point.
					'force'          => true,
					'timeout'        => $per_timeout,
					'max_retries'    => 0,
					'retry_on_parse' => false,
				)
			);

			self::apply_result( $state, $post_id, $result );

			$handled++;

			// Circuit breaker: a wrong key must not burn the whole queue.
			if ( in_array( $result['code'], array( 'invalid_key', 'forbidden' ), true ) ) {
				$state['auth_failures']++;
			} else {
				$state['auth_failures'] = 0;
			}

			$log[] = array(
				'post_id' => (int) $post_id,
				'title'   => $state['current_title'],
				'status'  => $result['status'],
				'message' => $result['message'],
			);

			// Also keep the row in the state: when the response was detached,
			// this array is the only channel the browser can read the per-post
			// outcome from. seq grows monotonically so a client can pick up
			// just the rows it has not rendered yet.
			$state['retry_log'][] = array(
				'seq'     => (int) $state['retry_done'],
				'post_id' => (int) $post_id,
				'title'   => (string) $state['current_title'],
				'status'  => (string) $result['status'],
				'message' => (string) $result['message'],
			);

			if ( count( $state['retry_log'] ) > 50 ) {
				$state['retry_log'] = array_slice( $state['retry_log'], -50 );
			}

			// Persist immediately: the status poller reads this option, and a
			// detached request has no other way to report progress mid-slice.
			self::save_state( $state );

			if ( $state['auth_failures'] >= self::AUTH_FAILURE_LIMIT ) {
				$state['status']  = 'error';
				$state['message'] = __( '连续 3 次鉴权失败，重试已中止。请检查 API Key。', 'geo-engine' );
				$aborted          = true;

				break;
			}

			$fresh = self::fresh_state();

			if ( 'running' !== $fresh['status'] ) {
				$state['status']  = $fresh['status'];
				$state['message'] = $fresh['message'];
				$aborted          = true;

				break;
			}

			if ( $index < $count - 1 && ( microtime( true ) - $started ) >= $budget ) {
				break;
			}
		}

		$state['current_id']    = 0;
		$state['current_title'] = '';
		$state['lock']          = 0;

		if ( ! $aborted ) {
			if ( empty( $state['retry_queue'] ) ) {
				self::finish_retry( $state );
			} else {
				$state['message'] = sprintf(
					/* translators: 1: retried count, 2: total retry count, 3: remaining count */
					__( '已重试 %1$d / %2$d 篇失败项，剩余 %3$d 篇。', 'geo-engine' ),
					(int) $state['retry_done'],
					(int) $state['retry_total'],
					self::remaining( $state )
				);
			}
		}

		self::save_state( $state );

		// The response already went out; nothing may be echoed after this.
		if ( $detached ) {
			exit;
		}

		wp_send_json_success(
			array(
				'state'   => $state,
				'log'     => $log,
				'handled' => $handled,
				'delay'   => self::delay_seconds(),
				'elapsed' => round( microtime( true ) - $started, 2 ),
			)
		);
	}

	/**
	 * Apply one result to the counters of the active run (batch or retry).
	 *
	 * @param array<string,mixed> $state   State, by reference.
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $result  Result of GEO_Automation::process_post().
	 * @return void
	 */
	private static function apply_result( array &$state, $post_id, array $result ) {
		if ( self::is_retrying( $state ) ) {
			$state['retry_done']++;
			$state['retry_queue'] = array_values( array_diff( (array) $state['retry_queue'], array( (int) $post_id ) ) );

			if ( 'skip' === $result['status'] ) {
				// Nothing to generate any more (short content, disabled type…):
				// leaving it in the failed list would retry it on every click.
				$state['retry_skip']++;
				$state['failed']     = max( 0, (int) $state['failed'] - 1 );
				$state['failed_ids'] = array_values( array_diff( (array) $state['failed_ids'], array( (int) $post_id ) ) );

				return;
			}

			if ( 'success' === $result['status'] ) {
				$state['retry_ok']++;
				$state['success']++;
				$state['failed']     = max( 0, (int) $state['failed'] - 1 );
				$state['failed_ids'] = array_values( array_diff( (array) $state['failed_ids'], array( (int) $post_id ) ) );

				return;
			}

			$state['retry_fail']++;

			if ( ! in_array( (int) $post_id, array_map( 'intval', (array) $state['failed_ids'] ), true ) ) {
				$state['failed_ids'][] = (int) $post_id;
			}

			return;
		}

		$state['processed']++;
		$state['cursor'] = (int) $post_id;

		if ( 'success' === $result['status'] ) {
			$state['success']++;
		} elseif ( 'skip' === $result['status'] ) {
			$state['skipped']++;
		} else {
			$state['failed']++;
			$state['failed_ids'][] = (int) $post_id;

			if ( count( $state['failed_ids'] ) > 200 ) {
				$state['failed_ids'] = array_slice( $state['failed_ids'], -200 );
			}
		}
	}

	/**
	 * Leave retry mode and settle the run.
	 *
	 * @param array<string,mixed> $state State, by reference.
	 * @return void
	 */
	private static function finish_retry( array &$state ) {
		$summary = sprintf(
			/* translators: 1: succeeded count, 2: still failing count */
			__( '重试完成：%1$d 篇成功，%2$d 篇仍失败。', 'geo-engine' ),
			(int) $state['retry_ok'],
			(int) $state['retry_fail']
		);

		if ( (int) $state['retry_skip'] > 0 ) {
			$summary .= ' ' . sprintf(
				/* translators: %d: skipped count */
				__( '另有 %d 篇被跳过（内容不满足生成条件）。', 'geo-engine' ),
				(int) $state['retry_skip']
			);
		}

		$state['mode']          = 'batch';
		$state['retry_queue']   = array();
		$state['current_id']    = 0;
		$state['current_title'] = '';
		$state['lock']          = 0;

		// A retry can run on top of an unfinished batch: hand the batch back as
		// "paused" so the continue button keeps working.
		if ( ! empty( self::next_ids( (int) $state['cursor'], 1 ) ) ) {
			$state['status']  = 'paused';
			$state['message'] = $summary . ' ' . __( '批量队列尚未跑完，点「继续」接着处理。', 'geo-engine' );
		} else {
			$state['status']  = 'done';
			$state['message'] = $summary;
		}
	}

	/**
	 * Configured pause between slices, in seconds.
	 *
	 * @return int
	 */
	private static function delay_seconds() {
		return max( 0, min( 30, (int) GEO_Settings::get( 'batch_delay', 2 ) ) );
	}

	/**
	 * AJAX: reset counters.
	 *
	 * @return void
	 */
	public function ajax_reset() {
		$this->guard();

		self::save_state( self::default_state() );

		wp_send_json_success( array( 'state' => self::get_state() ) );
	}

	/**
	 * Shared AJAX guard.
	 *
	 * @return void
	 */
	private function guard() {
		// Must run before anything is echoed, including the nonce failure path.
		GEO_Plugin::clean_output();

		check_ajax_referer( 'geo_engine_bulk', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'geo-engine' ) ), 403 );
		}
	}

	/**
	 * Render the bulk tab.
	 *
	 * @return void
	 */
	public static function render_admin() {
		$state    = self::get_state();
		$total    = self::count_eligible();
		$ready    = GEO_Settings::llm_ready();
		$percent  = self::progress_percent( $state );
		$running  = ( 'running' === $state['status'] );
		$retrying = self::is_retrying( $state );
		$left     = self::remaining( $state );
		$delay    = self::delay_seconds();
		$stale    = ( (int) $state['updated_at'] > 0 ) ? max( 0, time() - (int) $state['updated_at'] ) : 0;
		$force    = ! empty( $state['force'] );
		$fails    = count( (array) $state['failed_ids'] );
		$detach   = function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );

		// While a retry is running the bar follows the retry queue, not the
		// batch cursor, so the numbers match what is actually happening.
		$shown_done  = $retrying ? (int) $state['retry_done'] : (int) $state['processed'];
		$shown_total = $retrying ? (int) $state['retry_total'] : (int) $state['total'];

		?>
		<div class="geo-bulk" data-state="<?php echo esc_attr( wp_json_encode( $state ) ); ?>" data-delay="<?php echo esc_attr( (string) $delay ); ?>" data-age="<?php echo esc_attr( (string) $stale ); ?>" data-total="<?php echo esc_attr( (string) $total ); ?>">
			<div class="geo-bulk-head">
				<div>
					<h2><?php esc_html_e( '存量批量生成', 'geo-engine' ); ?></h2>
					<p class="description">
						<?php
						printf(
							/* translators: %d: eligible post count. */
							esc_html__( '当前可处理内容：%d 篇（已发布、未加密、属于启用的内容类型）。', 'geo-engine' ),
							(int) $total
						);
						?>
					</p>
				</div>
				<div class="geo-bulk-actions">
					<button type="button" class="button button-primary" id="geo-bulk-start" <?php disabled( ! $ready || $running ); ?>><?php esc_html_e( '开始 / 重新开始', 'geo-engine' ); ?></button>
					<button type="button" class="button" id="geo-bulk-continue" <?php disabled( 'paused' !== $state['status'] ); ?>><?php esc_html_e( '继续', 'geo-engine' ); ?></button>
					<button type="button" class="button" id="geo-bulk-stop" <?php disabled( ! $running ); ?>><?php esc_html_e( '停止', 'geo-engine' ); ?></button>
					<button type="button" class="button" id="geo-bulk-retry" <?php disabled( $running || 0 === $fails ); ?>>
						<?php
						printf(
							/* translators: %d: number of failed posts queued for retry. */
							esc_html__( '重试失败项（%d）', 'geo-engine' ),
							(int) $fails
						);
						?>
					</button>
					<button type="button" class="button button-link-delete" id="geo-bulk-reset" <?php disabled( $running ); ?>><?php esc_html_e( '重置统计', 'geo-engine' ); ?></button>
				</div>
			</div>

			<div class="geo-bulk-options">
				<label for="geo-bulk-force">
					<input type="checkbox" id="geo-bulk-force" value="1" <?php checked( $force ); ?> />
					<strong><?php esc_html_e( '强制重新生成', 'geo-engine' ); ?></strong>
				</label>
				<span class="description"><?php esc_html_e( '勾选后，正文里已有 FAQ 的文章会被重新生成并覆盖；不勾选则跳过这些文章。点「开始」或「继续」时生效。', 'geo-engine' ); ?></span>
			</div>

			<p class="description geo-bulk-hint">
				<?php esc_html_e( '「重试失败项（N）」会一次跑完当前所有失败项，进度条改为显示重试进度，单篇超时放宽到 40 秒；任务进行中该按钮不可点。', 'geo-engine' ); ?>
			</p>

			<?php if ( ! $ready ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( '尚未配置 LLM，批量生成不可用。', 'geo-engine' ); ?></p></div>
			<?php endif; ?>

			<div class="geo-bulk-live" data-bulk-live hidden>
				<span class="geo-bulk-dot" aria-hidden="true"></span>
				<span data-bulk-live-text></span>
			</div>

			<div class="geo-bulk-stats">
				<div class="geo-stat"><span class="geo-stat-value" data-stat="processed"><?php echo esc_html( (int) $state['processed'] ); ?></span><span class="geo-stat-label"><?php esc_html_e( '已处理', 'geo-engine' ); ?></span></div>
				<div class="geo-stat"><span class="geo-stat-value" data-stat="success"><?php echo esc_html( (int) $state['success'] ); ?></span><span class="geo-stat-label"><?php esc_html_e( '成功', 'geo-engine' ); ?></span></div>
				<div class="geo-stat"><span class="geo-stat-value" data-stat="failed"><?php echo esc_html( (int) $state['failed'] ); ?></span><span class="geo-stat-label"><?php esc_html_e( '失败', 'geo-engine' ); ?></span></div>
				<div class="geo-stat"><span class="geo-stat-value" data-stat="skipped"><?php echo esc_html( (int) $state['skipped'] ); ?></span><span class="geo-stat-label"><?php esc_html_e( '跳过', 'geo-engine' ); ?></span></div>
				<div class="geo-stat"><span class="geo-stat-value" data-stat="total"><?php echo esc_html( (int) $state['total'] ); ?></span><span class="geo-stat-label"><?php esc_html_e( '总数', 'geo-engine' ); ?></span></div>
			</div>

			<div class="geo-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $percent ); ?>">
				<div class="geo-progress-bar" style="width:<?php echo esc_attr( (string) $percent ); ?>%"></div>
			</div>
			<div class="geo-progress-meta">
				<span><strong data-progress-percent><?php echo esc_html( (string) $percent ); ?>%</strong></span>
				<span data-progress-count>
					<?php
					if ( $retrying ) {
						printf(
							/* translators: 1: retried count, 2: total retry count */
							esc_html__( '已重试 %1$d / %2$d 篇', 'geo-engine' ),
							(int) $shown_done,
							(int) $shown_total
						);
					} else {
						printf(
							/* translators: 1: processed count, 2: total count */
							esc_html__( '%1$d / %2$d 篇', 'geo-engine' ),
							(int) $shown_done,
							(int) $shown_total
						);
					}
					?>
				</span>
				<span data-progress-remain>
					<?php
					printf(
						/* translators: %d: remaining count */
						esc_html__( '剩余 %d 篇', 'geo-engine' ),
						(int) $left
					);
					?>
				</span>
				<span data-progress-speed></span>
				<span data-progress-eta></span>
			</div>
			<p class="geo-bulk-message" data-bulk-message><?php echo esc_html( $state['message'] ); ?></p>

			<div class="geo-bulk-log" data-bulk-log></div>

			<h2><?php esc_html_e( '批处理参数', 'geo-engine' ); ?></h2>
			<form method="post" class="geo-form">
				<?php wp_nonce_field( 'geo_engine_save_bulk' ); ?>
				<input type="hidden" name="geo_engine_action" value="save_bulk" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="geo-batch-size"><?php esc_html_e( '每批篇数', 'geo-engine' ); ?></label></th>
						<td>
							<input type="number" id="geo-batch-size" name="batch_size" min="1" max="20" step="1" class="small-text" value="<?php echo esc_attr( (string) GEO_Settings::get( 'batch_size', 5 ) ); ?>" />
							<p class="description"><?php esc_html_e( '单个请求最多处理几篇。每个请求在 30 秒时一定会收尾（单篇最多占用 30 秒），剩下的自动交给下一批，所以设大也不会超时；设小一点，进度条动得更勤。建议 1–5 篇。', 'geo-engine' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="geo-batch-delay"><?php esc_html_e( '批次间隔', 'geo-engine' ); ?></label></th>
						<td>
							<input type="number" id="geo-batch-delay" name="batch_delay" min="0" max="30" step="1" class="small-text" value="<?php echo esc_attr( (string) GEO_Settings::get( 'batch_delay', 2 ) ); ?>" />
							<span class="description"><?php esc_html_e( '秒。每批结束后等待多久再请求下一批，用于降低触发服务商限流的概率。等待发生在浏览器端，不占用服务器。', 'geo-engine' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="geo-retry-timeout"><?php esc_html_e( '重试超时', 'geo-engine' ); ?></label></th>
						<td>
							<input type="number" id="geo-retry-timeout" name="retry_timeout" min="20" max="180" step="5" class="small-text" value="<?php echo esc_attr( (string) GEO_Settings::get( 'retry_timeout', 60 ) ); ?>" />
							<span class="description">
								<?php esc_html_e( '秒。「重试失败项」单篇最多等待多久。重试会先把响应返回给浏览器再在后台处理，所以不受网关超时限制，可以放心设大。正文很长或模型较慢时建议 90–120 秒。', 'geo-engine' ); ?>
								<?php if ( $detach ) : ?>
									<?php esc_html_e( '当前服务器支持后台执行（PHP-FPM / LiteSpeed），此上限不会被网关截断。', 'geo-engine' ); ?>
								<?php else : ?>
									<strong style="color:#b32d2e"><?php esc_html_e( '当前 PHP 运行方式不支持「先响应后执行」，重试会自动把上限压到 25 秒以避免 504；慢文章仍可能失败，建议改用 PHP-FPM。', 'geo-engine' ); ?></strong>
								<?php endif; ?>
							</span>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button"><?php esc_html_e( '保存参数', 'geo-engine' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
