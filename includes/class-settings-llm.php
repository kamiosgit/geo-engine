<?php
/**
 * LLM configuration screen and config (de)serialisation.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provider presets, form handling, export/import.
 */
class GEO_Settings_LLM {

	/**
	 * Supported protocol drivers.
	 *
	 * @return array<string,string>
	 */
	public static function protocols() {
		return array(
			'openai-chat'        => 'OpenAI Chat Completions (/chat/completions)',
			'anthropic-messages' => 'Anthropic Messages (/messages)',
		);
	}

	/**
	 * Supported auth schemes.
	 *
	 * @return array<string,string>
	 */
	public static function auth_schemes() {
		return array(
			'bearer'    => 'Authorization: Bearer {key}',
			'x-api-key' => 'x-api-key: {key}',
			'custom'    => __( '自定义请求头', 'geo-engine' ),
		);
	}

	/**
	 * Built-in provider presets.
	 *
	 * Endpoint/model values are starting points, not hard limits: every field
	 * stays editable so a provider rename never requires a code change.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function presets() {
		return array(
			'siliconflow' => array(
				'label'        => '硅基流动 SiliconFlow',
				'api_base'     => 'https://api.siliconflow.cn/v1',
				'model'        => 'deepseek-ai/DeepSeek-V3.2',
				'protocol'     => 'openai-chat',
				'auth_scheme'  => 'bearer',
				'extra_headers'=> '',
				'hosts'        => array( 'api.siliconflow.cn' ),
				'note'         => __( '支持 response_format 与 enable_thinking。模型名可自由改为控制台中的任意模型。', 'geo-engine' ),
			),
			'deepseek'    => array(
				'label'        => 'DeepSeek',
				'api_base'     => 'https://api.deepseek.com/v1',
				'model'        => 'deepseek-chat',
				'protocol'     => 'openai-chat',
				'auth_scheme'  => 'bearer',
				'extra_headers'=> '',
				'hosts'        => array( 'api.deepseek.com' ),
				'note'         => __( '推理模型（deepseek-reasoner）会返回 reasoning_content，本插件只取最终回答。', 'geo-engine' ),
			),
			'openai'      => array(
				'label'        => 'OpenAI',
				'api_base'     => 'https://api.openai.com/v1',
				'model'        => 'gpt-4o-mini',
				'protocol'     => 'openai-chat',
				'auth_scheme'  => 'bearer',
				'extra_headers'=> '',
				'hosts'        => array( 'api.openai.com' ),
				'note'         => __( '如所在网络无法直连，请改用自建代理并开启「允许自定义端点」。', 'geo-engine' ),
			),
			'moonshot'    => array(
				'label'        => '月之暗面 Moonshot',
				'api_base'     => 'https://api.moonshot.cn/v1',
				'model'        => 'moonshot-v1-8k',
				'protocol'     => 'openai-chat',
				'auth_scheme'  => 'bearer',
				'extra_headers'=> '',
				'hosts'        => array( 'api.moonshot.cn' ),
				'note'         => '',
			),
			'zhipu'       => array(
				'label'        => '智谱 GLM',
				'api_base'     => 'https://open.bigmodel.cn/api/paas/v4',
				'model'        => 'glm-4-flash',
				'protocol'     => 'openai-chat',
				'auth_scheme'  => 'bearer',
				'extra_headers'=> '',
				'hosts'        => array( 'open.bigmodel.cn' ),
				'note'         => __( '部分模型默认开启思考过程，若返回内容为空请关闭「思考模式」。', 'geo-engine' ),
			),
			'qwen'        => array(
				'label'        => '阿里通义千问（兼容模式）',
				'api_base'     => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
				'model'        => 'qwen-plus',
				'protocol'     => 'openai-chat',
				'auth_scheme'  => 'bearer',
				'extra_headers'=> '',
				'hosts'        => array( 'dashscope.aliyuncs.com' ),
				'note'         => '',
			),
			'anthropic'   => array(
				'label'        => 'Anthropic Claude',
				'api_base'     => 'https://api.anthropic.com/v1',
				'model'        => 'claude-3-5-haiku-latest',
				'protocol'     => 'anthropic-messages',
				'auth_scheme'  => 'x-api-key',
				'extra_headers'=> 'anthropic-version: 2023-06-01',
				'hosts'        => array( 'api.anthropic.com' ),
				'note'         => __( '使用 Messages 协议，不支持 response_format，靠提示词与解析器保证 JSON。', 'geo-engine' ),
			),
			'ollama'      => array(
				'label'        => '本地 Ollama',
				'api_base'     => 'http://localhost:11434/v1',
				'model'        => 'qwen2.5:7b',
				'protocol'     => 'openai-chat',
				'auth_scheme'  => 'bearer',
				'extra_headers'=> '',
				'hosts'        => array( 'localhost', '127.0.0.1', '::1' ),
				'note'         => __( '本地地址默认被拦截，需勾选下方「允许本地/内网端点」。密钥可留空。', 'geo-engine' ),
			),
			'custom'      => array(
				'label'        => '自定义 / 自建代理',
				'api_base'     => '',
				'model'        => '',
				'protocol'     => 'openai-chat',
				'auth_scheme'  => 'bearer',
				'extra_headers'=> '',
				'hosts'        => array(),
				'note'         => __( '任何 OpenAI 兼容网关。需勾选「允许自定义端点」。', 'geo-engine' ),
			),
		);
	}

	/**
	 * Hosts considered trusted without the custom-endpoint switch.
	 *
	 * @return array<int,string>
	 */
	public static function allowed_hosts() {
		$hosts = array();

		foreach ( self::presets() as $preset ) {
			if ( ! empty( $preset['hosts'] ) ) {
				$hosts = array_merge( $hosts, $preset['hosts'] );
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Parse a "Name: Value" per line textarea into an array.
	 *
	 * @param string $raw Raw textarea value.
	 * @return array<string,string>
	 */
	public static function parse_extra_headers( $raw ) {
		$headers = array();
		$blocked = array( 'host', 'content-length', 'connection', 'transfer-encoding', 'expect' );

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $name, $value ) = explode( ':', $line, 2 );

			$name  = trim( $name );
			$value = trim( $value );

			if ( '' === $name || '' === $value ) {
				continue;
			}

			if ( ! preg_match( '/^[A-Za-z0-9\-_]+$/', $name ) ) {
				continue;
			}

			if ( in_array( strtolower( $name ), $blocked, true ) ) {
				continue;
			}

			$headers[ $name ] = $value;
		}

		return $headers;
	}

	/**
	 * Normalise a user supplied API base.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	public static function normalize_api_base( $raw ) {
		$raw = trim( (string) $raw );
		$raw = rtrim( $raw, '/' );

		// Users often paste the full completion path; strip it so we never double it.
		$raw = preg_replace( '#/(chat/completions|messages)$#i', '', $raw );

		return (string) $raw;
	}

	/**
	 * Validate and persist the LLM form.
	 *
	 * @param array<string,mixed> $post Raw POST data.
	 * @return true|WP_Error
	 */
	public static function save( array $post ) {
		$presets  = self::presets();
		$provider = isset( $post['provider'] ) ? sanitize_key( $post['provider'] ) : 'custom';

		if ( ! isset( $presets[ $provider ] ) ) {
			$provider = 'custom';
		}

		$api_base = self::normalize_api_base( isset( $post['api_base'] ) ? $post['api_base'] : '' );
		$model    = sanitize_text_field( isset( $post['model'] ) ? $post['model'] : '' );
		$protocol = isset( $post['protocol'] ) ? sanitize_key( $post['protocol'] ) : 'openai-chat';

		if ( ! array_key_exists( $protocol, self::protocols() ) ) {
			$protocol = 'openai-chat';
		}

		$auth_scheme = isset( $post['auth_scheme'] ) ? sanitize_key( $post['auth_scheme'] ) : 'bearer';

		if ( ! array_key_exists( $auth_scheme, self::auth_schemes() ) ) {
			$auth_scheme = 'bearer';
		}

		if ( '' === $api_base ) {
			return new WP_Error( 'geo_empty_base', __( 'API Base 不能为空。', 'geo-engine' ) );
		}

		if ( '' === $model ) {
			return new WP_Error( 'geo_empty_model', __( '模型名不能为空。', 'geo-engine' ) );
		}

		$allow_local  = empty( $post['allow_local_endpoint'] ) ? 0 : 1;
		$allow_custom = empty( $post['allow_custom_endpoint'] ) ? 0 : 1;

		$check = GEO_LLM_Client::validate_endpoint(
			$api_base,
			array(
				'allow_local_endpoint'  => $allow_local,
				'allow_custom_endpoint' => $allow_custom,
			)
		);

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$response_format = isset( $post['response_format'] ) ? sanitize_key( $post['response_format'] ) : 'auto';

		if ( ! in_array( $response_format, array( 'auto', 'force', 'off' ), true ) ) {
			$response_format = 'auto';
		}

		$prompt = isset( $post['prompt_template'] ) ? (string) $post['prompt_template'] : '';

		if ( ! empty( $post['restore_prompt'] ) ) {
			$prompt = '';
		}

		if ( '' !== trim( $prompt ) ) {
			$prompt = wp_kses_post( $prompt );
		}

		$patch = array(
			'provider'              => $provider,
			'api_base'              => $api_base,
			'model'                 => $model,
			'auth_scheme'           => $auth_scheme,
			'auth_header_name'      => sanitize_text_field( isset( $post['auth_header_name'] ) ? $post['auth_header_name'] : 'Authorization' ),
			'auth_header_value'     => sanitize_text_field( isset( $post['auth_header_value'] ) ? $post['auth_header_value'] : 'Bearer {key}' ),
			'extra_headers'         => self::sanitize_headers_text( isset( $post['extra_headers'] ) ? $post['extra_headers'] : '' ),
			'protocol'              => $protocol,
			'response_format'       => $response_format,
			'temperature'           => max( 0, min( 2, (float) ( isset( $post['temperature'] ) ? $post['temperature'] : 0.2 ) ) ),
			'max_tokens'            => max( 256, min( 8192, (int) ( isset( $post['max_tokens'] ) ? $post['max_tokens'] : 1500 ) ) ),
			'enable_thinking'       => empty( $post['enable_thinking'] ) ? 0 : 1,
			'timeout'               => max( 10, min( 180, (int) ( isset( $post['timeout'] ) ? $post['timeout'] : 60 ) ) ),
			'max_retries'           => max( 0, min( 5, (int) ( isset( $post['max_retries'] ) ? $post['max_retries'] : 2 ) ) ),
			'allow_local_endpoint'  => $allow_local,
			'allow_custom_endpoint' => $allow_custom,
			'prompt_template'       => $prompt,
		);

		// Key handling: blank keeps the stored secret, explicit clear removes it.
		if ( ! empty( $post['clear_key'] ) ) {
			GEO_Key_Vault::delete_key();
		} elseif ( isset( $post['api_key'] ) && '' !== trim( (string) $post['api_key'] ) ) {
			$stored = GEO_Key_Vault::set_key( trim( (string) $post['api_key'] ) );

			if ( ! $stored ) {
				return new WP_Error( 'geo_key_store_failed', __( 'API Key 无法加密存储，请检查服务器 openssl 扩展。', 'geo-engine' ) );
			}
		}

		GEO_Settings::update( $patch );

		// Cached test result belongs to the old config.
		delete_transient( 'geo_engine_test_result' );

		return true;
	}

	/**
	 * Keep only well-formed header lines.
	 *
	 * @param string $raw Raw textarea.
	 * @return string
	 */
	private static function sanitize_headers_text( $raw ) {
		$lines = array();

		foreach ( self::parse_extra_headers( $raw ) as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Build a runtime config from unsaved form values (used by "test connection").
	 *
	 * @param array<string,mixed> $post Raw POST data.
	 * @return array<string,mixed>
	 */
	public static function config_from_form( array $post ) {
		$saved  = GEO_Settings::get_llm_config();
		$config = $saved;

		$map = array(
			'provider'              => 'sanitize_key',
			'protocol'              => 'sanitize_key',
			'auth_scheme'           => 'sanitize_key',
			'response_format'       => 'sanitize_key',
			'model'                 => 'sanitize_text_field',
			'auth_header_name'      => 'sanitize_text_field',
			'auth_header_value'     => 'sanitize_text_field',
		);

		foreach ( $map as $key => $sanitizer ) {
			if ( isset( $post[ $key ] ) && '' !== (string) $post[ $key ] ) {
				$config[ $key ] = call_user_func( $sanitizer, (string) $post[ $key ] );
			}
		}

		if ( isset( $post['api_base'] ) ) {
			$config['api_base'] = self::normalize_api_base( $post['api_base'] );
		}

		if ( isset( $post['extra_headers'] ) ) {
			$config['extra_headers'] = self::sanitize_headers_text( $post['extra_headers'] );
		}

		foreach ( array( 'temperature', 'max_tokens', 'timeout', 'max_retries' ) as $key ) {
			if ( isset( $post[ $key ] ) && '' !== (string) $post[ $key ] ) {
				$config[ $key ] = (float) $post[ $key ];

				if ( 'temperature' !== $key ) {
					$config[ $key ] = (int) $post[ $key ];
				}
			}
		}

		$config['enable_thinking']       = empty( $post['enable_thinking'] ) ? 0 : 1;
		$config['allow_local_endpoint']  = empty( $post['allow_local_endpoint'] ) ? 0 : 1;
		$config['allow_custom_endpoint'] = empty( $post['allow_custom_endpoint'] ) ? 0 : 1;

		if ( isset( $post['api_key'] ) && '' !== trim( (string) $post['api_key'] ) ) {
			$config['api_key'] = trim( (string) $post['api_key'] );
		}

		return $config;
	}

	/**
	 * Exportable configuration (never includes the secret).
	 *
	 * @return array<string,mixed>
	 */
	public static function export() {
		$config = GEO_Settings::get_llm_config();

		$config['api_key']     = '';
		$config['prompt_template'] = GEO_Settings::get( 'prompt_template', '' );

		return array(
			'format'      => 'geo-engine-config',
			'version'     => GEO_ENGINE_VERSION,
			'exported_at' => gmdate( 'c' ),
			'site'        => home_url(),
			'config'      => $config,
		);
	}

	/**
	 * Import a configuration payload.
	 *
	 * @param array<string,mixed> $data Decoded JSON.
	 * @return true|WP_Error
	 */
	public static function import( array $data ) {
		$config = isset( $data['config'] ) && is_array( $data['config'] ) ? $data['config'] : $data;

		if ( ! isset( $config['api_base'] ) && ! isset( $config['model'] ) ) {
			return new WP_Error( 'geo_import_shape', __( '文件格式不符：缺少 config.api_base / config.model。', 'geo-engine' ) );
		}

		$whitelist = array(
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
			'prompt_template',
		);

		$patch = array();

		foreach ( $whitelist as $key ) {
			if ( ! array_key_exists( $key, $config ) ) {
				continue;
			}

			$value = $config[ $key ];

			switch ( $key ) {
				case 'temperature':
					$patch[ $key ] = max( 0, min( 2, (float) $value ) );
					break;

				case 'max_tokens':
					$patch[ $key ] = max( 256, min( 8192, (int) $value ) );
					break;

				case 'timeout':
					$patch[ $key ] = max( 10, min( 180, (int) $value ) );
					break;

				case 'max_retries':
					$patch[ $key ] = max( 0, min( 5, (int) $value ) );
					break;

				case 'enable_thinking':
				case 'allow_local_endpoint':
				case 'allow_custom_endpoint':
					$patch[ $key ] = empty( $value ) ? 0 : 1;
					break;

				case 'api_base':
					$patch[ $key ] = self::normalize_api_base( $value );
					break;

				case 'extra_headers':
					$patch[ $key ] = self::sanitize_headers_text( $value );
					break;

				case 'prompt_template':
					$patch[ $key ] = wp_kses_post( (string) $value );
					break;

				case 'provider':
				case 'protocol':
				case 'auth_scheme':
				case 'response_format':
					$patch[ $key ] = sanitize_key( $value );
					break;

				default:
					$patch[ $key ] = sanitize_text_field( (string) $value );
					break;
			}
		}

		if ( isset( $patch['protocol'] ) && ! array_key_exists( $patch['protocol'], self::protocols() ) ) {
			$patch['protocol'] = 'openai-chat';
		}

		if ( isset( $patch['auth_scheme'] ) && ! array_key_exists( $patch['auth_scheme'], self::auth_schemes() ) ) {
			$patch['auth_scheme'] = 'bearer';
		}

		$presets = self::presets();

		if ( isset( $patch['provider'] ) && ! isset( $presets[ $patch['provider'] ] ) ) {
			$patch['provider'] = 'custom';
		}

		GEO_Settings::update( $patch );
		delete_transient( 'geo_engine_test_result' );

		return true;
	}

	/**
	 * Render the LLM tab.
	 *
	 * @return void
	 */
	public static function render() {
		$s        = GEO_Settings::all();
		$presets  = self::presets();
		$has_key  = GEO_Key_Vault::has_key();
		$masked   = GEO_Key_Vault::mask( GEO_Key_Vault::get_key() );
		$ready    = GEO_Settings::llm_ready();
		$test     = get_transient( 'geo_engine_test_result' );
		$protocol = $s['protocol'];

		?>
		<div class="geo-status-banner <?php echo $ready ? 'is-ok' : 'is-warn'; ?>" id="geo-llm-status">
			<div class="geo-status-text">
				<?php if ( is_array( $test ) && ! empty( $test['ok'] ) ) : ?>
					<strong><?php esc_html_e( '连接成功', 'geo-engine' ); ?></strong>
					<span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: provider label, 2: model name, 3: latency in ms */
								__( '服务商 %1$s · 模型 %2$s · 延迟 %3$d ms', 'geo-engine' ),
								isset( $test['provider'] ) ? $test['provider'] : '',
								isset( $test['model'] ) ? $test['model'] : '',
								isset( $test['latency'] ) ? (int) $test['latency'] : 0
							)
						);
						?>
					</span>
				<?php elseif ( is_array( $test ) && empty( $test['ok'] ) ) : ?>
					<strong><?php esc_html_e( '连接失败', 'geo-engine' ); ?></strong>
					<span><?php echo esc_html( isset( $test['message'] ) ? $test['message'] : '' ); ?></span>
				<?php elseif ( $ready ) : ?>
					<strong><?php esc_html_e( '已配置', 'geo-engine' ); ?></strong>
					<span><?php esc_html_e( '尚未测试连接。建议先点右侧按钮验证一次。', 'geo-engine' ); ?></span>
				<?php else : ?>
					<strong><?php esc_html_e( '未配置', 'geo-engine' ); ?></strong>
					<span><?php esc_html_e( '填写服务商与 API Key 后才能生成 FAQ。A 层与 C 层功能不受影响。', 'geo-engine' ); ?></span>
				<?php endif; ?>
			</div>
			<button type="button" class="button" id="geo-test-connection"><?php esc_html_e( '测试连接', 'geo-engine' ); ?></button>
		</div>

		<div id="geo-test-result" class="geo-test-result" aria-live="polite"></div>

		<?php if ( ! GEO_Key_Vault::encryption_available() ) : ?>
			<div class="notice notice-warning inline">
				<p><strong><?php esc_html_e( '当前环境缺少 openssl 扩展。', 'geo-engine' ); ?></strong>
				<?php esc_html_e( 'API Key 将以可逆编码（base64）存储，任何能读取数据库的人都能还原明文。请让主机启用 openssl，或改用环境变量注入。', 'geo-engine' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" class="geo-form" id="geo-llm-form">
			<?php wp_nonce_field( 'geo_engine_save_llm' ); ?>
			<input type="hidden" name="geo_engine_action" value="save_llm" />

			<h2><?php esc_html_e( '服务商', 'geo-engine' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="geo-provider"><?php esc_html_e( '预设', 'geo-engine' ); ?></label></th>
					<td>
						<select id="geo-provider" name="provider">
							<?php foreach ( $presets as $id => $preset ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['provider'], $id ); ?>><?php echo esc_html( $preset['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description" id="geo-provider-note">
							<?php
							$current = isset( $presets[ $s['provider'] ] ) ? $presets[ $s['provider'] ] : $presets['custom'];
							echo esc_html( $current['note'] );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="geo-api-base"><?php esc_html_e( 'API Base', 'geo-engine' ); ?></label></th>
					<td>
						<input type="url" id="geo-api-base" class="regular-text code" name="api_base" value="<?php echo esc_attr( $s['api_base'] ); ?>" placeholder="https://api.example.com/v1" />
						<p class="description"><?php esc_html_e( '不含 /chat/completions，插件会自动拼接；填写完整路径也会被自动纠正。', 'geo-engine' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="geo-model"><?php esc_html_e( '模型', 'geo-engine' ); ?></label></th>
					<td>
						<input type="text" id="geo-model" class="regular-text code" name="model" value="<?php echo esc_attr( $s['model'] ); ?>" list="geo-model-presets" />
						<datalist id="geo-model-presets">
							<?php foreach ( $presets as $preset ) : ?>
								<option value="<?php echo esc_attr( $preset['model'] ); ?>"></option>
							<?php endforeach; ?>
						</datalist>
						<p class="description"><?php esc_html_e( '可直接输入任意模型名，不受预设限制。', 'geo-engine' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="geo-api-key"><?php esc_html_e( 'API Key', 'geo-engine' ); ?></label></th>
					<td>
						<input type="password" id="geo-api-key" class="regular-text" name="api_key" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $has_key ? $masked : __( '未设置', 'geo-engine' ) ); ?>" />
						<p class="description">
							<?php
							printf(
								/* translators: %s: masked key hint. */
								esc_html__( '当前：%s。留空表示保持现有密钥不变。', 'geo-engine' ),
								esc_html( $has_key ? $masked : __( '未设置', 'geo-engine' ) )
							);
							?>
						</p>
						<?php if ( $has_key ) : ?>
							<label class="geo-inline">
								<input type="checkbox" name="clear_key" value="1" />
								<?php esc_html_e( '清除已保存的密钥', 'geo-engine' ); ?>
							</label>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( '请求参数', 'geo-engine' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="geo-protocol"><?php esc_html_e( '协议驱动', 'geo-engine' ); ?></label></th>
					<td>
						<select id="geo-protocol" name="protocol">
							<?php foreach ( self::protocols() as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $protocol, $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( '决定请求体格式与解析方式。OpenAI 兼容服务一律用第一项。', 'geo-engine' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="geo-auth-scheme"><?php esc_html_e( '鉴权方式', 'geo-engine' ); ?></label></th>
					<td>
						<select id="geo-auth-scheme" name="auth_scheme">
							<?php foreach ( self::auth_schemes() as $id => $label ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['auth_scheme'], $id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<div class="geo-custom-auth" data-visible-for="custom">
							<label class="geo-field-row">
								<span><?php esc_html_e( '请求头名称', 'geo-engine' ); ?></span>
								<input type="text" class="regular-text code" name="auth_header_name" value="<?php echo esc_attr( $s['auth_header_name'] ); ?>" />
							</label>
							<label class="geo-field-row">
								<span><?php esc_html_e( '请求头值模板', 'geo-engine' ); ?></span>
								<input type="text" class="regular-text code" name="auth_header_value" value="<?php echo esc_attr( $s['auth_header_value'] ); ?>" />
							</label>
							<p class="description"><?php esc_html_e( '{key} 会被替换为实际密钥，例如 Token {key}。', 'geo-engine' ); ?></p>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="geo-extra-headers"><?php esc_html_e( '额外请求头', 'geo-engine' ); ?></label></th>
					<td>
						<textarea id="geo-extra-headers" class="large-text code" rows="4" name="extra_headers" placeholder="anthropic-version: 2023-06-01"><?php echo esc_textarea( $s['extra_headers'] ); ?></textarea>
						<p class="description"><?php esc_html_e( '每行一条 Name: Value。Host / Content-Length 等危险头会被自动丢弃。', 'geo-engine' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( '生成参数', 'geo-engine' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="geo-temperature"><?php esc_html_e( 'temperature', 'geo-engine' ); ?></label></th>
					<td>
						<input type="number" id="geo-temperature" name="temperature" value="<?php echo esc_attr( $s['temperature'] ); ?>" min="0" max="2" step="0.1" class="small-text" />
						<p class="description"><?php esc_html_e( '结构化输出建议 0.1–0.3，过高会让问答跑题。', 'geo-engine' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="geo-max-tokens"><?php esc_html_e( 'max_tokens', 'geo-engine' ); ?></label></th>
					<td>
						<input type="number" id="geo-max-tokens" name="max_tokens" value="<?php echo esc_attr( $s['max_tokens'] ); ?>" min="256" max="8192" step="64" class="small-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="geo-response-format"><?php esc_html_e( '响应格式策略', 'geo-engine' ); ?></label></th>
					<td>
						<select id="geo-response-format" name="response_format">
							<option value="auto" <?php selected( $s['response_format'], 'auto' ); ?>><?php esc_html_e( 'auto — 服务商支持则发送 json_object', 'geo-engine' ); ?></option>
							<option value="force" <?php selected( $s['response_format'], 'force' ); ?>><?php esc_html_e( 'force — 总是发送 json_object', 'geo-engine' ); ?></option>
							<option value="off" <?php selected( $s['response_format'], 'off' ); ?>><?php esc_html_e( 'off — 不发送，靠提示词与解析器', 'geo-engine' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( '若服务商报 400 且提示 response_format 不支持，请改为 off。', 'geo-engine' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '思考模式', 'geo-engine' ); ?></th>
					<td>
						<label class="geo-inline">
							<input type="checkbox" name="enable_thinking" value="1" <?php checked( $s['enable_thinking'], 1 ); ?> />
							<?php esc_html_e( '向服务商发送 enable_thinking: true', 'geo-engine' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '仅对支持该参数的服务商生效。FAQ 生成属结构化任务，一般建议关闭以节省 token。', 'geo-engine' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '超时与重试', 'geo-engine' ); ?></th>
					<td>
						<label class="geo-inline">
							<?php esc_html_e( '超时', 'geo-engine' ); ?>
							<input type="number" name="timeout" value="<?php echo esc_attr( $s['timeout'] ); ?>" min="10" max="180" step="5" class="small-text" />
							<?php esc_html_e( '秒', 'geo-engine' ); ?>
						</label>
						<label class="geo-inline">
							<?php esc_html_e( '重试次数', 'geo-engine' ); ?>
							<input type="number" name="max_retries" value="<?php echo esc_attr( $s['max_retries'] ); ?>" min="0" max="5" step="1" class="small-text" />
						</label>
						<p class="description"><?php esc_html_e( '429 / 5xx / 超时会按 2s → 4s → 8s 指数退避。', 'geo-engine' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '端点安全', 'geo-engine' ); ?></th>
					<td>
						<label class="geo-inline">
							<input type="checkbox" name="allow_local_endpoint" value="1" <?php checked( $s['allow_local_endpoint'], 1 ); ?> />
							<?php esc_html_e( '允许本地 / 内网端点（localhost、127.0.0.1、私有网段）', 'geo-engine' ); ?>
						</label>
						<br />
						<label class="geo-inline">
							<input type="checkbox" name="allow_custom_endpoint" value="1" <?php checked( $s['allow_custom_endpoint'], 1 ); ?> />
							<?php esc_html_e( '允许预设白名单之外的域名', 'geo-engine' ); ?>
						</label>
						<p class="description"><?php esc_html_e( '默认拒绝，用于防止服务端请求伪造（SSRF）。启用前请确认端点由可信方控制。', 'geo-engine' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( '生成提示词', 'geo-engine' ); ?></h2>
			<p class="description"><?php esc_html_e( '可用占位符：{{title}} {{content}} {{headings}} {{existing}} {{count}} {{site}}。留空则使用内置模板。', 'geo-engine' ); ?></p>
			<textarea class="large-text code" rows="12" name="prompt_template" spellcheck="false"><?php echo esc_textarea( '' !== $s['prompt_template'] ? $s['prompt_template'] : GEO_FAQ_Generator::default_prompt_template() ); ?></textarea>
			<p>
				<label class="geo-inline">
					<input type="checkbox" name="restore_prompt" value="1" />
					<?php esc_html_e( '恢复默认提示词', 'geo-engine' ); ?>
				</label>
			</p>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( '保存配置', 'geo-engine' ); ?></button>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=geo_engine_export' ), 'geo_engine_export' ) ); ?>"><?php esc_html_e( '导出配置', 'geo-engine' ); ?></a>
			</p>
		</form>

		<h2><?php esc_html_e( '导入配置', 'geo-engine' ); ?></h2>
		<p class="description"><?php esc_html_e( '用于多站点部署：导入服务商与参数，密钥需在目标站重新填写。', 'geo-engine' ); ?></p>
		<form method="post" enctype="multipart/form-data" class="geo-form geo-import-form">
			<?php wp_nonce_field( 'geo_engine_import_llm' ); ?>
			<input type="hidden" name="geo_engine_action" value="import_llm" />
			<input type="file" name="geo_engine_import" accept="application/json,.json" />
			<button type="submit" class="button"><?php esc_html_e( '导入', 'geo-engine' ); ?></button>
		</form>
		<?php
	}
}
