<?php
/**
 * Settings storage and the admin screens.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings facade plus the admin UI shell.
 */
class GEO_Settings {

	/**
	 * Single option holding the whole settings array.
	 */
	const OPTION = 'geo_engine_settings';

	/**
	 * Admin page slug.
	 */
	const PAGE = 'geo-engine';

	/**
	 * Valid values for schema output modes.
	 */
	const SCHEMA_MODES = array( 'auto', 'force', 'off' );

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_geo_engine_export', array( $this, 'handle_export' ) );
		add_filter( 'plugin_action_links_' . GEO_ENGINE_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		$presets = GEO_Settings_LLM::presets();
		$default = isset( $presets['siliconflow'] ) ? $presets['siliconflow'] : array();

		return array(
			// Content scope.
			'post_types'               => array( 'post', 'page' ),
			'faq_heading'              => __( '常见问题', 'geo-engine' ),
			'faq_count'                => 3,
			'min_content_chars'        => 200,
			'auto_generate'            => 0,
			'faq_style_enabled'        => 1,
			'uninstall_remove_faqs'    => 0,

			// LLM connection.
			'provider'                 => 'siliconflow',
			'api_base'                 => isset( $default['api_base'] ) ? $default['api_base'] : '',
			'model'                    => isset( $default['model'] ) ? $default['model'] : '',
			'auth_scheme'              => 'bearer',
			'auth_header_name'         => 'Authorization',
			'auth_header_value'        => 'Bearer {key}',
			'extra_headers'            => '',
			'protocol'                 => 'openai-chat',
			'response_format'          => 'auto',
			'temperature'              => 0.2,
			'max_tokens'               => 1500,
			'enable_thinking'          => 0,
			'timeout'                  => 60,
			'max_retries'              => 2,
			'allow_local_endpoint'     => 0,
			'allow_custom_endpoint'    => 0,
			'prompt_template'          => '',

			// Structured data.
			'schema_website'           => 'auto',
			'schema_organization'      => 'auto',
			'schema_article'           => 'auto',
			'schema_webpage'           => 'auto',
			'schema_breadcrumb'        => 'auto',
			'schema_faq'               => 'auto',
			'org_name'                 => '',
			'org_logo'                 => '',
			'org_same_as'              => '',

			// llms.txt.
			'llms_full_chars'          => 1500,
			'llms_full_max'            => 500,

			// Crawlers (allow unless changed).
			'crawlers'                 => array(
				'GPTBot'          => 'allow',
				'ClaudeBot'       => 'allow',
				'PerplexityBot'   => 'allow',
				'Google-Extended' => 'allow',
				'CCBot'           => 'allow',
				'Bytespider'      => 'allow',
			),

			// Batch.
			'batch_size'               => 5,
			'batch_delay'              => 2,
			'retry_timeout'            => 60,
		);
	}

	/**
	 * All settings, merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$settings = array_merge( self::defaults(), $stored );

		if ( ! is_array( $settings['post_types'] ) || empty( $settings['post_types'] ) ) {
			$settings['post_types'] = array( 'post', 'page' );
		}

		if ( ! is_array( $settings['crawlers'] ) ) {
			$settings['crawlers'] = self::defaults()['crawlers'];
		}

		return $settings;
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is missing.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::all();

		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		return $default;
	}

	/**
	 * Persist a partial update.
	 *
	 * @param array<string,mixed> $patch Values to merge.
	 * @return void
	 */
	public static function update( array $patch ) {
		$settings = array_merge( self::all(), $patch );

		update_option( self::OPTION, $settings, false );

		self::flush_feed_cache();
	}

	/**
	 * Connection config handed to the LLM client, including the decrypted key.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_llm_config() {
		$settings = self::all();

		$keys = array(
			'provider',
			'api_base',
			'model',
			'auth_scheme',
			'auth_header_name',
			'auth_header_value',
			'extra_headers',
			'protocol',
			'response_format',
			'temperature',
			'max_tokens',
			'enable_thinking',
			'timeout',
			'max_retries',
			'allow_local_endpoint',
			'allow_custom_endpoint',
		);

		$config = array();

		foreach ( $keys as $key ) {
			$config[ $key ] = $settings[ $key ];
		}

		$config['api_key'] = GEO_Key_Vault::get_key();

		return $config;
	}

	/**
	 * Whether the LLM layer is configured well enough to attempt a call.
	 *
	 * @return bool
	 */
	public static function llm_ready() {
		$config = self::get_llm_config();

		return '' !== trim( (string) $config['api_base'] )
			&& '' !== trim( (string) $config['model'] )
			&& '' !== trim( (string) $config['api_key'] );
	}

	/**
	 * Post types the plugin may operate on.
	 *
	 * @return array<string,string> slug => label
	 */
	public static function available_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		$out   = array();

		foreach ( $types as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}

			$out[ $type->name ] = $type->labels->singular_name . ' (' . $type->name . ')';
		}

		return $out;
	}

	/**
	 * Crawler catalogue used by the robots screen.
	 *
	 * @return array<string,string>
	 */
	public static function crawler_choices() {
		return array(
			'GPTBot'          => 'GPTBot (OpenAI)',
			'ClaudeBot'       => 'ClaudeBot (Anthropic)',
			'PerplexityBot'   => 'PerplexityBot',
			'Google-Extended' => 'Google-Extended (Gemini)',
			'CCBot'           => 'CCBot (Common Crawl)',
			'Bytespider'      => 'Bytespider (ByteDance)',
		);
	}

	/**
	 * Drop the A-layer transient caches.
	 *
	 * @return void
	 */
	public static function flush_feed_cache() {
		delete_transient( 'geo_engine_llms_cache' );
		delete_transient( 'geo_engine_llms_full_cache' );
	}

	/**
	 * Add a settings shortcut on the plugins screen.
	 *
	 * @param array<int,string> $links Existing links.
	 * @return array<int,string>
	 */
	public function plugin_action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( '设置', 'geo-engine' ) . '</a>' );

		return $links;
	}

	/**
	 * Register the settings page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_options_page(
			__( 'GEO Engine', 'geo-engine' ),
			__( 'GEO Engine', 'geo-engine' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Load assets only on our screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		$is_settings = ( 'settings_page_' . self::PAGE === $hook );
		$screen      = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_editor   = $screen && in_array( $screen->base, array( 'post', 'edit' ), true );

		if ( ! $is_settings && ! $is_editor ) {
			return;
		}

		wp_enqueue_style( 'geo-engine-admin', GEO_ENGINE_PLUGIN_URL . 'assets/css/admin.css', array(), GEO_ENGINE_VERSION );
		wp_enqueue_script( 'geo-engine-admin', GEO_ENGINE_PLUGIN_URL . 'assets/js/admin.js', array(), GEO_ENGINE_VERSION, true );

		wp_localize_script(
			'geo-engine-admin',
			'GeoEngine',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => array(
					'test'     => wp_create_nonce( 'geo_engine_test' ),
					'generate' => wp_create_nonce( 'geo_engine_generate' ),
					'bulk'     => wp_create_nonce( 'geo_engine_bulk' ),
					'logs'     => wp_create_nonce( 'geo_engine_logs' ),
				),
				'presets' => GEO_Settings_LLM::presets(),
				'strings' => array(
					'testing'       => __( '正在测试…', 'geo-engine' ),
					'generating'    => __( '正在生成…', 'geo-engine' ),
					'confirmRegen'  => __( '该文章已有 FAQ，确定要强制重新生成吗？', 'geo-engine' ),
					'confirmReset'  => __( '确定要恢复默认配置吗？API Key 会被清除。', 'geo-engine' ),
					'confirmClear'  => __( '确定要清空全部日志吗？', 'geo-engine' ),
					'networkError'  => __( '网络错误，请重试。', 'geo-engine' ),
					'savedPreset'   => __( '已套用预设，请确认后保存。', 'geo-engine' ),
					'batchDone'     => __( '全部处理完成。', 'geo-engine' ),
					'batchStopped'  => __( '已停止，进度已保留。', 'geo-engine' ),
					'progressCount' => __( '%s / %s 篇', 'geo-engine' ),
					'progressRemain' => __( '剩余 %s 篇', 'geo-engine' ),
					'processing'    => __( '正在处理…已用时 %s 秒', 'geo-engine' ),
					'processingTitle' => __( '正在处理《%s》…已用时 %s 秒', 'geo-engine' ),
					'waiting'       => __( '下一批将在 %s 秒后开始', 'geo-engine' ),
					'speed'         => __( '约 %s 秒/篇', 'geo-engine' ),
					'eta'           => __( '预计剩余 %s', 'geo-engine' ),
					'resumed'       => __( '检测到进行中的任务，正在接续…', 'geo-engine' ),
					'interrupted'   => __( '上次任务已中断，点「继续」从断点接着跑。', 'geo-engine' ),
					'busy'          => __( '上一批仍在处理中，稍后自动重试…', 'geo-engine' ),
					'serverBusy'    => __( '服务端仍在处理《%s》，等待它收尾…', 'geo-engine' ),
					'recovering'    => __( '%s，正在自动接续…', 'geo-engine' ),
					'gaveUp'        => __( '连续 %s 次连接失败（%s）。进度已保留，点「继续」可接着跑。', 'geo-engine' ),
					'stalled'       => __( '服务端长时间没有响应（%s）。进度已保留，点「继续」可接着跑。', 'geo-engine' ),
					'detached'      => __( '服务端已在后台处理，等待结果…', 'geo-engine' ),
					'confirmBatch'  => __( '将处理全部可处理内容，确定开始吗？', 'geo-engine' ),
					'confirmResetBatch' => __( '重置会清空进度统计（不会删除已生成的 FAQ），确定吗？', 'geo-engine' ),
					'retryButton'   => __( '重试失败项（%s）', 'geo-engine' ),
					'retryCount'    => __( '已重试 %s / %s 篇', 'geo-engine' ),
					'waitingRetry'  => __( '重试中，下一批将在 %s 秒后开始', 'geo-engine' ),
					'confirmRetry'  => __( '将重试 %s 篇失败项，期间会逐篇重新生成，确定吗？', 'geo-engine' ),
				),
			)
		);
	}

	/**
	 * Handle settings form submissions.
	 *
	 * @return void
	 */
	public function handle_post() {
		if ( ! isset( $_POST['geo_engine_action'] ) ) {
			return;
		}

		if ( ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'geo-engine' ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['geo_engine_action'] ) );

		check_admin_referer( 'geo_engine_' . $action );

		switch ( $action ) {
			case 'save_general':
				$this->save_general( wp_unslash( $_POST ) );
				break;

			case 'save_llm':
				$this->save_llm( wp_unslash( $_POST ) );
				break;

			case 'import_llm':
				$this->import_llm();
				break;

			case 'save_bulk':
				$this->save_bulk( wp_unslash( $_POST ) );
				break;

			case 'clear_logs':
				GEO_Logger::clear();
				$this->redirect( 'logs', 'logs_cleared' );
				break;

			case 'reset_settings':
				delete_option( self::OPTION );
				GEO_Key_Vault::delete_key();
				delete_option( 'geo_engine_batch_state' );
				self::flush_feed_cache();
				$this->redirect( 'general', 'reset' );
				break;
		}
	}

	/**
	 * Redirect back to a tab with a notice code.
	 *
	 * @param string $tab    Tab slug.
	 * @param string $notice Notice code.
	 * @return void
	 */
	private function redirect( $tab, $notice ) {
		$url = add_query_arg(
			array(
				'page'        => self::PAGE,
				'tab'         => $tab,
				'geo_notice'  => $notice,
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Persist the general tab.
	 *
	 * @param array<string,mixed> $post Raw POST data.
	 * @return void
	 */
	private function save_general( array $post ) {
		$available = array_keys( self::available_post_types() );
		$types     = array();

		if ( isset( $post['post_types'] ) && is_array( $post['post_types'] ) ) {
			foreach ( $post['post_types'] as $type ) {
				$type = sanitize_key( $type );

				if ( in_array( $type, $available, true ) ) {
					$types[] = $type;
				}
			}
		}

		if ( empty( $types ) ) {
			$types = array( 'post', 'page' );
		}

		$crawlers = array();

		foreach ( self::crawler_choices() as $ua => $label ) {
			$crawlers[ $ua ] = ( isset( $post['crawlers'][ $ua ] ) && 'disallow' === $post['crawlers'][ $ua ] ) ? 'disallow' : 'allow';
		}

		$schema = array();

		foreach ( array( 'website', 'organization', 'article', 'webpage', 'breadcrumb', 'faq' ) as $type ) {
			$value            = isset( $post[ 'schema_' . $type ] ) ? sanitize_key( $post[ 'schema_' . $type ] ) : 'auto';
			$schema[ $type ]  = in_array( $value, self::SCHEMA_MODES, true ) ? $value : 'auto';
		}

		$patch = array(
			'post_types'               => array_values( array_unique( $types ) ),
			'faq_heading'              => sanitize_text_field( isset( $post['faq_heading'] ) ? $post['faq_heading'] : '' ),
			'faq_count'                => max( 2, min( 5, isset( $post['faq_count'] ) ? (int) $post['faq_count'] : 3 ) ),
			'min_content_chars'        => max( 0, min( 5000, isset( $post['min_content_chars'] ) ? (int) $post['min_content_chars'] : 200 ) ),
			'auto_generate'            => empty( $post['auto_generate'] ) ? 0 : 1,
			'faq_style_enabled'        => empty( $post['faq_style_enabled'] ) ? 0 : 1,
			'uninstall_remove_faqs'    => empty( $post['uninstall_remove_faqs'] ) ? 0 : 1,
			'schema_website'           => $schema['website'],
			'schema_organization'      => $schema['organization'],
			'schema_article'           => $schema['article'],
			'schema_webpage'           => $schema['webpage'],
			'schema_breadcrumb'        => $schema['breadcrumb'],
			'schema_faq'               => $schema['faq'],
			'org_name'                 => sanitize_text_field( isset( $post['org_name'] ) ? $post['org_name'] : '' ),
			'org_logo'                 => esc_url_raw( isset( $post['org_logo'] ) ? $post['org_logo'] : '' ),
			'org_same_as'              => sanitize_textarea_field( isset( $post['org_same_as'] ) ? $post['org_same_as'] : '' ),
			'llms_full_chars'          => max( 200, min( 20000, isset( $post['llms_full_chars'] ) ? (int) $post['llms_full_chars'] : 1500 ) ),
			'llms_full_max'            => max( 10, min( 5000, isset( $post['llms_full_max'] ) ? (int) $post['llms_full_max'] : 500 ) ),
			'crawlers'                 => $crawlers,
		);

		if ( '' === $patch['faq_heading'] ) {
			$patch['faq_heading'] = __( '常见问题', 'geo-engine' );
		}

		self::update( $patch );

		$this->redirect( 'general', 'saved' );
	}

	/**
	 * Persist the LLM tab.
	 *
	 * @param array<string,mixed> $post Raw POST data.
	 * @return void
	 */
	private function save_llm( array $post ) {
		$saved = GEO_Settings_LLM::save( $post );

		if ( is_wp_error( $saved ) ) {
			set_transient( 'geo_engine_settings_error', $saved->get_error_message(), 60 );
			$this->redirect( 'llm', 'error' );
		}

		$this->redirect( 'llm', 'saved' );
	}

	/**
	 * Persist the bulk tab.
	 *
	 * @param array<string,mixed> $post Raw POST data.
	 * @return void
	 */
	private function save_bulk( array $post ) {
		self::update(
			array(
				'batch_size'  => max( 1, min( 20, isset( $post['batch_size'] ) ? (int) $post['batch_size'] : 5 ) ),
				'batch_delay' => max( 0, min( 30, isset( $post['batch_delay'] ) ? (int) $post['batch_delay'] : 2 ) ),
				'retry_timeout' => max( 20, min( 180, isset( $post['retry_timeout'] ) ? (int) $post['retry_timeout'] : 60 ) ),
			)
		);

		$this->redirect( 'bulk', 'saved' );
	}

	/**
	 * Import a previously exported JSON config (key excluded by design).
	 *
	 * @return void
	 */
	private function import_llm() {
		if ( empty( $_FILES['geo_engine_import']['tmp_name'] ) || ! is_uploaded_file( $_FILES['geo_engine_import']['tmp_name'] ) ) {
			set_transient( 'geo_engine_settings_error', __( '未收到上传文件。', 'geo-engine' ), 60 );
			$this->redirect( 'llm', 'error' );
		}

		$raw  = file_get_contents( $_FILES['geo_engine_import']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$data = json_decode( (string) $raw, true );

		if ( ! is_array( $data ) ) {
			set_transient( 'geo_engine_settings_error', __( '文件不是合法的 JSON。', 'geo-engine' ), 60 );
			$this->redirect( 'llm', 'error' );
		}

		$result = GEO_Settings_LLM::import( $data );

		if ( is_wp_error( $result ) ) {
			set_transient( 'geo_engine_settings_error', $result->get_error_message(), 60 );
			$this->redirect( 'llm', 'error' );
		}

		$this->redirect( 'llm', 'imported' );
	}

	/**
	 * Stream the export JSON.
	 *
	 * @return void
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'geo-engine' ) );
		}

		check_admin_referer( 'geo_engine_export' );

		$payload = GEO_Settings_LLM::export();

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=geo-engine-config.json' );

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Render the settings shell.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$tabs     = array(
			'general' => __( '常规设置', 'geo-engine' ),
			'llm'     => __( 'LLM 配置', 'geo-engine' ),
			'bulk'    => __( '批量生成', 'geo-engine' ),
			'logs'    => __( '日志', 'geo-engine' ),
		);

		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}

		echo '<div class="wrap geo-engine-wrap">';
		echo '<h1>' . esc_html__( 'GEO Engine', 'geo-engine' ) . ' <span class="geo-version">v' . esc_html( GEO_ENGINE_VERSION ) . '</span></h1>';

		$this->render_notice();

		echo '<nav class="nav-tab-wrapper geo-tabs">';

		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg(
				array(
					'page' => self::PAGE,
					'tab'  => $slug,
				),
				admin_url( 'options-general.php' )
			);

			printf(
				'<a href="%1$s" class="nav-tab %2$s">%3$s</a>',
				esc_url( $url ),
				$tab === $slug ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';

		switch ( $tab ) {
			case 'llm':
				GEO_Settings_LLM::render();
				break;

			case 'bulk':
				GEO_Bulk::render_admin();
				break;

			case 'logs':
				$this->render_logs();
				break;

			default:
				$this->render_general();
				break;
		}

		echo '</div>';
	}

	/**
	 * Notices for save results.
	 *
	 * @return void
	 */
	private function render_notice() {
		$notice = isset( $_GET['geo_notice'] ) ? sanitize_key( wp_unslash( $_GET['geo_notice'] ) ) : '';
		$error  = get_transient( 'geo_engine_settings_error' );

		if ( $error ) {
			delete_transient( 'geo_engine_settings_error' );
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		$messages = array(
			'saved'        => __( '设置已保存。', 'geo-engine' ),
			'imported'     => __( '配置已导入。API Key 不会随文件导入，请在下方重新填写并保存。', 'geo-engine' ),
			'logs_cleared' => __( '日志已清空。', 'geo-engine' ),
			'reset'        => __( '已恢复默认配置，API Key 已清除。', 'geo-engine' ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
		}
	}

	/**
	 * Render the general tab.
	 *
	 * @return void
	 */
	private function render_general() {
		$s         = self::all();
		$post_types = self::available_post_types();
		$crawlers   = self::crawler_choices();
		$post_types_available = ! empty( $post_types );

		?>
		<form method="post" class="geo-form">
			<?php wp_nonce_field( 'geo_engine_save_general' ); ?>
			<input type="hidden" name="geo_engine_action" value="save_general" />

			<h2><?php esc_html_e( '内容范围', 'geo-engine' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '启用的内容类型', 'geo-engine' ); ?></th>
					<td>
						<?php if ( $post_types_available ) : ?>
							<fieldset>
								<?php foreach ( $post_types as $slug => $label ) : ?>
									<label class="geo-inline">
										<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $s['post_types'], true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						<?php else : ?>
							<p class="description"><?php esc_html_e( '未检测到公开的内容类型。', 'geo-engine' ); ?></p>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'llms.txt、schema 与批量生成均以此为准。', 'geo-engine' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="geo-faq-heading"><?php esc_html_e( 'FAQ 区块标题', 'geo-engine' ); ?></label></th>
					<td>
						<input type="text" id="geo-faq-heading" class="regular-text" name="faq_heading" value="<?php echo esc_attr( $s['faq_heading'] ); ?>" />
						<p class="description"><?php esc_html_e( '前台渲染 FAQ 手风琴时使用的标题，不影响 JSON-LD。', 'geo-engine' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="geo-faq-count"><?php esc_html_e( '每篇 FAQ 条数', 'geo-engine' ); ?></label></th>
					<td>
						<input type="number" id="geo-faq-count" min="2" max="5" step="1" name="faq_count" value="<?php echo esc_attr( $s['faq_count'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( '下限为 2 条：少于 2 条不会写回正文（FAQPage 需要多条目才完整）。', 'geo-engine' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="geo-min-chars"><?php esc_html_e( '最短正文长度', 'geo-engine' ); ?></label></th>
					<td>
						<input type="number" id="geo-min-chars" min="0" max="5000" step="10" name="min_content_chars" value="<?php echo esc_attr( $s['min_content_chars'] ); ?>" class="small-text" />
						<span class="description"><?php esc_html_e( '字符。正文短于此值的内容会被跳过，避免为无信息量的页面生成 FAQ。', 'geo-engine' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '自动化', 'geo-engine' ); ?></th>
					<td>
						<label class="geo-inline">
							<input type="checkbox" name="auto_generate" value="1" <?php checked( $s['auto_generate'], 1 ); ?> />
							<?php esc_html_e( '新内容首次发布时自动生成 FAQ', 'geo-engine' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '延迟 10 秒后台执行，不阻塞发布；生成失败不影响文章状态。', 'geo-engine' ); ?></p>
						<label class="geo-inline">
							<input type="checkbox" name="faq_style_enabled" value="1" <?php checked( $s['faq_style_enabled'], 1 ); ?> />
							<?php esc_html_e( '加载插件自带的 FAQ 轻量样式', 'geo-engine' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( '结构化数据（JSON-LD）', 'geo-engine' ); ?></h2>
			<p class="description">
				<?php esc_html_e( '「自动」= 检测到同名 schema 由 SEO 插件输出时跳过；「强制」= 始终输出；「关闭」= 不输出。', 'geo-engine' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '输出类型', 'geo-engine' ); ?></th>
					<td>
						<?php
						$schema_rows = array(
							'website'      => __( 'WebSite', 'geo-engine' ),
							'organization' => __( 'Organization', 'geo-engine' ),
							'article'      => __( 'Article / BlogPosting', 'geo-engine' ),
							'webpage'      => __( 'WebPage', 'geo-engine' ),
							'breadcrumb'   => __( 'BreadcrumbList', 'geo-engine' ),
							'faq'          => __( 'FAQPage', 'geo-engine' ),
						);

						foreach ( $schema_rows as $key => $label ) :
							?>
							<label class="geo-schema-row">
								<span class="geo-schema-label"><?php echo esc_html( $label ); ?></span>
								<select name="schema_<?php echo esc_attr( $key ); ?>">
									<option value="auto" <?php selected( $s[ 'schema_' . $key ], 'auto' ); ?>><?php esc_html_e( '自动', 'geo-engine' ); ?></option>
									<option value="force" <?php selected( $s[ 'schema_' . $key ], 'force' ); ?>><?php esc_html_e( '强制输出', 'geo-engine' ); ?></option>
									<option value="off" <?php selected( $s[ 'schema_' . $key ], 'off' ); ?>><?php esc_html_e( '关闭', 'geo-engine' ); ?></option>
								</select>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="geo-org-name"><?php esc_html_e( '组织名称', 'geo-engine' ); ?></label></th>
					<td>
						<input type="text" id="geo-org-name" class="regular-text" name="org_name" value="<?php echo esc_attr( $s['org_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="geo-org-logo"><?php esc_html_e( '组织 Logo URL', 'geo-engine' ); ?></label></th>
					<td>
						<input type="url" id="geo-org-logo" class="regular-text" name="org_logo" value="<?php echo esc_attr( $s['org_logo'] ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="geo-org-same"><?php esc_html_e( '官方主页 / 社交账号', 'geo-engine' ); ?></label></th>
					<td>
						<textarea id="geo-org-same" class="large-text code" rows="3" name="org_same_as"><?php echo esc_textarea( $s['org_same_as'] ); ?></textarea>
						<p class="description"><?php esc_html_e( '每行一个 URL，用于 Organization.sameAs。', 'geo-engine' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'llms.txt 与 Markdown 端点', 'geo-engine' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="geo-full-chars"><?php esc_html_e( 'llms-full.txt 每篇正文长度', 'geo-engine' ); ?></label></th>
					<td>
						<input type="number" id="geo-full-chars" min="200" max="20000" step="100" name="llms_full_chars" value="<?php echo esc_attr( $s['llms_full_chars'] ); ?>" class="small-text" />
						<span class="description"><?php esc_html_e( '字符，超出部分截断。', 'geo-engine' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="geo-full-max"><?php esc_html_e( 'llms-full.txt 条数上限', 'geo-engine' ); ?></label></th>
					<td>
						<input type="number" id="geo-full-max" min="10" max="5000" step="10" name="llms_full_max" value="<?php echo esc_attr( $s['llms_full_max'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( '仅限制 llms-full.txt，llms.txt 不设条数上限。', 'geo-engine' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'AI 爬虫规则', 'geo-engine' ); ?></th>
					<td>
						<?php foreach ( $crawlers as $ua => $label ) : ?>
							<label class="geo-crawler-row">
								<span class="geo-crawler-label"><?php echo esc_html( $label ); ?></span>
								<select name="crawlers[<?php echo esc_attr( $ua ); ?>]">
									<option value="allow" <?php selected( isset( $s['crawlers'][ $ua ] ) ? $s['crawlers'][ $ua ] : 'allow', 'allow' ); ?>><?php esc_html_e( 'Allow', 'geo-engine' ); ?></option>
									<option value="disallow" <?php selected( isset( $s['crawlers'][ $ua ] ) ? $s['crawlers'][ $ua ] : 'allow', 'disallow' ); ?>><?php esc_html_e( 'Disallow', 'geo-engine' ); ?></option>
								</select>
							</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( '规则会追加到 WordPress 生成的 robots.txt。默认全部 Allow，以利于被 AI 检索引擎抓取。', 'geo-engine' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( '卸载行为', 'geo-engine' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( '删除插件时', 'geo-engine' ); ?></th>
					<td>
						<label class="geo-inline">
							<input type="checkbox" name="uninstall_remove_faqs" value="1" <?php checked( $s['uninstall_remove_faqs'], 1 ); ?> />
							<?php esc_html_e( '同时从所有文章正文中移除已生成的 FAQ 区块', 'geo-engine' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '默认不勾选：FAQ 属于站点内容资产，卸载插件后仍会作为短代码文本保留。设置、日志与统计在卸载时总会删除。', 'geo-engine' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( '保存设置', 'geo-engine' ); ?></button>
			</p>
		</form>

		<form method="post" class="geo-danger-zone" onsubmit="return confirm('<?php echo esc_js( __( '确定要恢复默认配置吗？API Key 会被清除。', 'geo-engine' ) ); ?>');">
			<?php wp_nonce_field( 'geo_engine_reset_settings' ); ?>
			<input type="hidden" name="geo_engine_action" value="reset_settings" />
			<button type="submit" class="button button-link-delete"><?php esc_html_e( '恢复默认配置', 'geo-engine' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render the log tab.
	 *
	 * @return void
	 */
	private function render_logs() {
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$allowed = array( 'success', 'fail', 'skip' );

		if ( ! in_array( $status, $allowed, true ) ) {
			$status = '';
		}

		$rows    = GEO_Logger::recent( 50, $status );
		$summary = GEO_Logger::summary();

		?>
		<div class="geo-log-summary">
			<div class="geo-stat"><span class="geo-stat-value"><?php echo esc_html( number_format_i18n( $summary['success'] ) ); ?></span><span class="geo-stat-label"><?php esc_html_e( '成功', 'geo-engine' ); ?></span></div>
			<div class="geo-stat"><span class="geo-stat-value"><?php echo esc_html( number_format_i18n( $summary['fail'] ) ); ?></span><span class="geo-stat-label"><?php esc_html_e( '失败', 'geo-engine' ); ?></span></div>
			<div class="geo-stat"><span class="geo-stat-value"><?php echo esc_html( number_format_i18n( $summary['skip'] ) ); ?></span><span class="geo-stat-label"><?php esc_html_e( '跳过', 'geo-engine' ); ?></span></div>
			<div class="geo-stat"><span class="geo-stat-value"><?php echo esc_html( number_format_i18n( $summary['tokens'] ) ); ?></span><span class="geo-stat-label"><?php esc_html_e( '累计 tokens', 'geo-engine' ); ?></span></div>
		</div>

		<ul class="subsubsub">
			<?php
			$filters = array(
				''        => __( '全部', 'geo-engine' ),
				'success' => __( '成功', 'geo-engine' ),
				'fail'    => __( '失败', 'geo-engine' ),
				'skip'    => __( '跳过', 'geo-engine' ),
			);

			foreach ( $filters as $key => $label ) :
				$url = add_query_arg(
					array(
						'page'   => self::PAGE,
						'tab'    => 'logs',
						'status' => $key,
					),
					admin_url( 'options-general.php' )
				);
				?>
				<li>
					<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $status === $key ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>

		<table class="widefat striped geo-log-table">
			<thead>
				<tr>
					<th><?php esc_html_e( '时间', 'geo-engine' ); ?></th>
					<th><?php esc_html_e( '文章', 'geo-engine' ); ?></th>
					<th><?php esc_html_e( '动作', 'geo-engine' ); ?></th>
					<th><?php esc_html_e( '结果', 'geo-engine' ); ?></th>
					<th><?php esc_html_e( '信息', 'geo-engine' ); ?></th>
					<th><?php esc_html_e( 'tokens', 'geo-engine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( '暂无日志。', 'geo-engine' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['created_at'] ); ?></td>
							<td>
								<?php
								$post_id = (int) $row['post_id'];

								if ( $post_id > 0 ) {
									printf(
										'<a href="%1$s">%2$s</a>',
										esc_url( (string) get_edit_post_link( $post_id ) ),
										esc_html( get_the_title( $post_id ) ? get_the_title( $post_id ) : '#' . $post_id )
									);
								} else {
									echo '—';
								}
								?>
							</td>
							<td><?php echo esc_html( $row['action'] ); ?></td>
							<td><span class="geo-badge geo-badge-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $row['status'] ); ?></span></td>
							<td><?php echo esc_html( $row['message'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $row['tokens'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<form method="post" class="geo-log-actions" onsubmit="return confirm('<?php echo esc_js( __( '确定要清空全部日志吗？', 'geo-engine' ) ); ?>');">
			<?php wp_nonce_field( 'geo_engine_clear_logs' ); ?>
			<input type="hidden" name="geo_engine_action" value="clear_logs" />
			<button type="submit" class="button"><?php esc_html_e( '清空日志', 'geo-engine' ); ?></button>
		</form>
		<?php
	}
}
