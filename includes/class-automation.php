<?php
/**
 * Generation triggers: manual, automatic on publish, scheduled runner.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single entry point for "generate FAQ for this post".
 *
 * Manual AJAX, the auto-on-publish cron job and the bulk screen all call
 * process_post() so skip rules and logging stay identical everywhere.
 */
class GEO_Automation {

	/**
	 * Per-post lock transient prefix.
	 */
	const LOCK_PREFIX = 'geo_engine_processing_';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_geo_engine_test', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_geo_engine_generate', array( $this, 'ajax_generate' ) );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 20, 3 );
		add_action( 'geo_engine_generate_single', array( $this, 'run_scheduled' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'invalidate_cache' ) );
		add_action( 'delete_post', array( $this, 'invalidate_cache' ) );
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
	}

	/**
	 * Add a "generate" link to the list table row actions.
	 *
	 * @param array<string,string> $actions Existing actions.
	 * @param WP_Post              $post    Post.
	 * @return array<string,string>
	 */
	public function row_action( $actions, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return $actions;
		}

		if ( ! in_array( $post->post_type, (array) GEO_Settings::get( 'post_types', array() ), true ) ) {
			return $actions;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$count = count( GEO_Shortcode::parse_items( (string) $post->post_content ) );

		$actions['geo_engine_generate'] = sprintf(
			'<a href="#" class="geo-generate-row" data-post-id="%1$d" data-force="%2$d">%3$s</a>',
			(int) $post->ID,
			$count > 0 ? 1 : 0,
			esc_html( $count > 0 ? __( '重新生成 GEO FAQ', 'geo-engine' ) : __( '生成 GEO FAQ', 'geo-engine' ) )
		);

		return $actions;
	}

	/**
	 * AJAX: test the LLM connection with the values currently in the form.
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		GEO_Plugin::clean_output();

		check_ajax_referer( 'geo_engine_test', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足。', 'geo-engine' ) ), 403 );
		}

		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$config = GEO_Settings_LLM::config_from_form( is_array( $post ) ? $post : array() );

		$check = GEO_LLM_Client::validate_endpoint( isset( $config['api_base'] ) ? $config['api_base'] : '', $config );

		if ( is_wp_error( $check ) ) {
			wp_send_json_error(
				array(
					'ok'      => false,
					'message' => $check->get_error_message(),
					'code'    => $check->get_error_code(),
				)
			);
		}

		$result = GEO_LLM_Client::test_connection( $config );

		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result );
	}

	/**
	 * AJAX: generate FAQ for one post.
	 *
	 * @return void
	 */
	public function ajax_generate() {
		GEO_Plugin::clean_output();

		check_ajax_referer( 'geo_engine_generate', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( '权限不足或文章不存在。', 'geo-engine' ) ), 403 );
		}

		$force  = ! empty( $_POST['force'] );
		$result = self::process_post(
			$post_id,
			array(
				'action' => 'generate',
				'force'  => $force,
			)
		);

		if ( 'success' === $result['status'] ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result );
	}

	/**
	 * Queue generation when a post becomes published for the first time.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Previous status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public function on_transition( $new_status, $old_status, $post ) {
		if ( ! GEO_Settings::get( 'auto_generate', 0 ) ) {
			return;
		}

		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( wp_is_post_revision( $post->ID ) || GEO_Writeback::is_writing( $post->ID ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, (array) GEO_Settings::get( 'post_types', array() ), true ) ) {
			return;
		}

		wp_schedule_single_event( time() + 10, 'geo_engine_generate_single', array( (int) $post->ID ) );
	}

	/**
	 * Cron callback for the auto path.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function run_scheduled( $post_id ) {
		self::process_post(
			(int) $post_id,
			array(
				'action' => 'auto',
				'force'  => false,
			)
		);
	}

	/**
	 * Run every check, generate, write back and log.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $args    action|force|timeout|max_retries|retry_on_parse.
	 * @return array<string,mixed> {status, message, count, tokens, code}
	 */
	public static function process_post( $post_id, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'action'         => 'generate',
				'force'          => false,
				'timeout'        => 0,
				'max_retries'    => -1,
				'retry_on_parse' => true,
			)
		);

		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return self::result( 'fail', __( '文章不存在。', 'geo-engine' ), 0, 0, 'missing_post' );
		}

		if ( in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return self::result( 'skip', __( '文章状态不允许生成。', 'geo-engine' ), 0, 0, 'post_status' );
		}

		if ( ! in_array( $post->post_type, (array) GEO_Settings::get( 'post_types', array() ), true ) ) {
			return self::result( 'skip', __( '该内容类型未启用。', 'geo-engine' ), 0, 0, 'post_type' );
		}

		$lock = self::LOCK_PREFIX . $post_id;

		if ( get_transient( $lock ) ) {
			return self::result( 'skip', __( '该文章正在处理中。', 'geo-engine' ), 0, 0, 'locked' );
		}

		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$result = self::run_checks_and_generate( $post, $args );
		} finally {
			delete_transient( $lock );
		}

		return $result;
	}

	/**
	 * Inner pipeline (separated so the lock is always released).
	 *
	 * @param WP_Post $post Post.
	 * @param array   $args Args.
	 * @return array<string,mixed>
	 */
	private static function run_checks_and_generate( $post, array $args ) {
		$post_id  = (int) $post->ID;
		$action   = (string) $args['action'];
		$force    = ! empty( $args['force'] );
		$existing = GEO_Shortcode::parse_items( (string) $post->post_content );

		if ( count( $existing ) >= GEO_FAQ_Generator::MIN_ITEMS && ! $force ) {
			GEO_Writeback::mark_skip( $post_id, 'has_faq' );

			return self::result( 'skip', __( '已有 FAQ，已跳过（勾选「强制重新生成」后会覆盖）。', 'geo-engine' ), count( $existing ), 0, 'has_faq' );
		}

		if ( self::has_seo_faq_block( (string) $post->post_content ) ) {
			GEO_Writeback::mark_skip( $post_id, 'seo_plugin' );

			return self::result( 'skip', __( '检测到 SEO 插件的 FAQ 区块，为避免重复已跳过。', 'geo-engine' ), 0, 0, 'seo_plugin' );
		}

		$min_chars = (int) GEO_Settings::get( 'min_content_chars', 200 );

		if ( GEO_FAQ_Generator::content_length( $post ) < $min_chars ) {
			GEO_Writeback::mark_skip( $post_id, 'too_short' );

			return self::result(
				'skip',
				/* translators: %d: minimum character count. */
				sprintf( __( '正文短于 %d 字，已跳过。', 'geo-engine' ), $min_chars ),
				0,
				0,
				'too_short'
			);
		}

		if ( ! GEO_Settings::llm_ready() ) {
			$message = __( '尚未配置 LLM（API Base / 模型 / 密钥）。请先在「LLM 配置」页完成设置。', 'geo-engine' );

			GEO_Writeback::mark_failure( $post_id, $message );
			GEO_Logger::log( $post_id, $action, 'fail', $message, 0 );

			return self::result( 'fail', $message, 0, 0, 'not_configured' );
		}

		$generator = new GEO_FAQ_Generator();
		$generated = $generator->generate(
			$post_id,
			array(
				'force'          => $force,
				'timeout'        => (int) $args['timeout'],
				'max_retries'    => (int) $args['max_retries'],
				'retry_on_parse' => ! empty( $args['retry_on_parse'] ),
			)
		);

		if ( is_wp_error( $generated ) ) {
			$data   = $generated->get_error_data();
			$tokens = is_array( $data ) && isset( $data['tokens'] ) ? (int) $data['tokens'] : 0;

			GEO_Writeback::mark_failure( $post_id, $generated->get_error_message() );
			GEO_Logger::log( $post_id, $action, 'fail', $generated->get_error_message(), $tokens );

			return self::result( 'fail', $generated->get_error_message(), 0, $tokens, $generated->get_error_code() );
		}

		$written = GEO_Writeback::write(
			$post_id,
			$generated['items'],
			array( 'replace' => $force || count( $existing ) >= 1 )
		);

		if ( is_wp_error( $written ) ) {
			GEO_Writeback::mark_failure( $post_id, $written->get_error_message() );
			GEO_Logger::log( $post_id, $action, 'fail', $written->get_error_message(), $generated['tokens'] );

			return self::result( 'fail', $written->get_error_message(), 0, $generated['tokens'], $written->get_error_code() );
		}

		$count = count( $generated['items'] );

		GEO_Writeback::mark_success( $post_id, $count );

		GEO_Logger::log(
			$post_id,
			$action,
			'success',
			/* translators: %d: number of FAQ items. */
			sprintf( __( '已生成 %d 条 FAQ 并写回正文。', 'geo-engine' ), $count ),
			$generated['tokens']
		);

		GEO_Settings::flush_feed_cache();

		return self::result(
			'success',
			/* translators: %d: number of FAQ items. */
			sprintf( __( '已生成 %d 条 FAQ。', 'geo-engine' ), $count ),
			$count,
			$generated['tokens'],
			''
		);
	}

	/**
	 * Canonical result array.
	 *
	 * @param string $status  success|fail|skip.
	 * @param string $message Message.
	 * @param int    $count   Item count.
	 * @param int    $tokens  Token usage.
	 * @param string $code    Machine code.
	 * @return array<string,mixed>
	 */
	public static function result( $status, $message, $count = 0, $tokens = 0, $code = '' ) {
		return array(
			'status'  => $status,
			'message' => $message,
			'count'   => (int) $count,
			'tokens'  => (int) $tokens,
			'code'    => (string) $code,
		);
	}

	/**
	 * Detect FAQ blocks owned by common SEO plugins.
	 *
	 * @param string $content Raw content.
	 * @return bool
	 */
	public static function has_seo_faq_block( $content ) {
		$markers = array(
			'wp:yoast/faq-block',
			'wp:rank-math/faq-block',
			'wp:aioseo/faq',
			'aioseo/faq',
			'[aioseo_faq',
			'wp:seopress/faq',
		);

		foreach ( $markers as $marker ) {
			if ( false !== strpos( (string) $content, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register the editor side box.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		$types = (array) GEO_Settings::get( 'post_types', array( 'post', 'page' ) );

		if ( empty( $types ) || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		add_meta_box(
			'geo_engine_faq',
			__( 'GEO FAQ', 'geo-engine' ),
			array( $this, 'render_meta_box' ),
			$types,
			'side',
			'default'
		);
	}

	/**
	 * Render the side box.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$items  = GEO_Shortcode::parse_items( (string) $post->post_content );
		$count  = count( $items );
		$last   = get_post_meta( $post->ID, '_geo_engine_last_error', true );
		$skip   = get_post_meta( $post->ID, '_geo_engine_skip_reason', true );
		$ready  = GEO_Settings::llm_ready();

		echo '<div class="geo-metabox" data-post-id="' . esc_attr( (string) $post->ID ) . '">';

		if ( $count > 0 ) {
			printf(
				'<p class="geo-metabox-state is-ok">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: item count. */
						__( '正文中已有 %d 条 FAQ。', 'geo-engine' ),
						$count
					)
				)
			);
		} else {
			echo '<p class="geo-metabox-state">' . esc_html__( '正文中暂无 FAQ。', 'geo-engine' ) . '</p>';
		}

		if ( $last ) {
			echo '<p class="geo-metabox-error">' . esc_html( $last ) . '</p>';
		}

		if ( $skip && ! $last ) {
			echo '<p class="geo-metabox-note">' . esc_html( sprintf( /* translators: %s: skip reason. */ __( '上次跳过原因：%s', 'geo-engine' ), $skip ) ) . '</p>';
		}

		if ( ! $ready ) {
			echo '<p class="geo-metabox-note">' . esc_html__( '尚未配置 LLM，请先到设置页填写。', 'geo-engine' ) . '</p>';
		}

		echo '<p>';
		printf(
			'<button type="button" class="button button-secondary geo-generate" data-post-id="%1$d" data-force="%2$d" %3$s>%4$s</button>',
			(int) $post->ID,
			$count > 0 ? 1 : 0,
			$ready ? '' : 'disabled="disabled"',
			esc_html( $count > 0 ? __( '重新生成 FAQ', 'geo-engine' ) : __( '生成 FAQ', 'geo-engine' ) )
		);
		echo '</p>';

		echo '<div class="geo-metabox-result" aria-live="polite"></div>';
		echo '</div>';
	}

	/**
	 * Drop caches whenever content changes.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function invalidate_cache( $post_id ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		GEO_Settings::flush_feed_cache();
	}
}
