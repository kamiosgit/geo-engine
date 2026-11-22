<?php
/**
 * LLM client facade: driver selection, retries, timeouts, endpoint policy.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything outside this class talks to providers through here.
 */
class GEO_LLM_Client {

	/**
	 * Resolved connection config.
	 *
	 * @var array<string,mixed>
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>|null $config Optional config override.
	 */
	public function __construct( $config = null ) {
		$this->config = is_array( $config ) ? array_merge( GEO_Settings::get_llm_config(), $config ) : GEO_Settings::get_llm_config();
	}

	/**
	 * Instantiate the driver for a protocol id.
	 *
	 * @param string $protocol Protocol id.
	 * @return GEO_LLM_Driver|null
	 */
	public static function driver_for( $protocol ) {
		switch ( $protocol ) {
			case 'anthropic-messages':
				return new GEO_Driver_Anthropic();

			case 'openai-chat':
				return new GEO_Driver_OpenAI_Chat();
		}

		return null;
	}

	/**
	 * Read-only view of the resolved config (key included for transport only).
	 *
	 * @return array<string,mixed>
	 */
	public function config() {
		return $this->config;
	}

	/**
	 * Is the host a loopback / link-local / private address?
	 *
	 * @param string $host Hostname or IP.
	 * @return bool
	 */
	public static function is_local_host( $host ) {
		$host = strtolower( trim( (string) $host, '[]' ) );

		if ( '' === $host ) {
			return false;
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', 'host.docker.internal' ), true ) ) {
			return true;
		}

		foreach ( array( '.localhost', '.local', '.internal', '.test', '.lan' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return self::is_private_ip( $host );
	}

	/**
	 * Is the string a private / reserved IP?
	 *
	 * @param string $ip IP address candidate.
	 * @return bool
	 */
	public static function is_private_ip( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$public = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );

		return false === $public;
	}

	/**
	 * Enforce the SSRF policy for an API base.
	 *
	 * @param string              $url    Candidate endpoint.
	 * @param array<string,mixed> $config Config carrying the two allow switches.
	 * @return true|WP_Error
	 */
	public static function validate_endpoint( $url, array $config ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return new WP_Error( 'endpoint_empty', __( 'API Base 不能为空。', 'geo-engine' ) );
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'endpoint_invalid', __( 'API Base 不是合法 URL。', 'geo-engine' ) );
		}

		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return new WP_Error( 'endpoint_credentials', __( '不允许在 API Base 中携带账号密码。', 'geo-engine' ) );
		}

		$scheme      = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host        = strtolower( $parts['host'] );
		$allow_local = ! empty( $config['allow_local_endpoint'] );
		$allow_custom = ! empty( $config['allow_custom_endpoint'] );
		$is_local    = self::is_local_host( $host );

		if ( $is_local && ! $allow_local ) {
			return new WP_Error(
				'endpoint_local_blocked',
				/* translators: %s: host name. */
				sprintf( __( '%s 是本地或内网地址，默认被拦截。若确为自建服务（如 Ollama），请勾选「允许本地 / 内网端点」。', 'geo-engine' ), $host )
			);
		}

		if ( 'https' !== $scheme ) {
			if ( ! ( $is_local && $allow_local ) ) {
				return new WP_Error( 'endpoint_scheme', __( 'API Base 必须以 https:// 开头。仅本地端点可在开启开关后使用 http。', 'geo-engine' ) );
			}

			if ( 'http' !== $scheme ) {
				return new WP_Error( 'endpoint_scheme', __( 'API Base 仅支持 http(s) 协议。', 'geo-engine' ) );
			}
		}

		$whitelist = GEO_Settings_LLM::allowed_hosts();

		if ( ! $is_local && ! in_array( $host, $whitelist, true ) && ! $allow_custom ) {
			return new WP_Error(
				'endpoint_host',
				/* translators: %s: host name. */
				sprintf( __( '%s 不在预设白名单内。请勾选「允许预设白名单之外的域名」，或改用内置预设服务商。', 'geo-engine' ), $host )
			);
		}

		// Custom host: make sure DNS does not point back into the private network.
		if ( ! $is_local && ! in_array( $host, $whitelist, true ) ) {
			$resolved = gethostbyname( $host );

			if ( $resolved !== $host && self::is_private_ip( $resolved ) && ! $allow_local ) {
				return new WP_Error( 'endpoint_private_dns', __( '该域名解析到内网地址，已被安全策略拦截。', 'geo-engine' ) );
			}
		}

		return true;
	}

	/**
	 * Send a chat request.
	 *
	 * @param array<int,array<string,string>> $messages  Neutral messages.
	 * @param array<string,mixed>             $overrides Per-call overrides.
	 * @return array<string,mixed>
	 */
	public function chat( array $messages, array $overrides = array() ) {
		$config = array_merge( $this->config, $overrides );

		$check = self::validate_endpoint( isset( $config['api_base'] ) ? $config['api_base'] : '', $config );

		if ( is_wp_error( $check ) ) {
			return self::failure( $check->get_error_code(), $check->get_error_message() );
		}

		$host     = strtolower( (string) wp_parse_url( (string) $config['api_base'], PHP_URL_HOST ) );
		$is_local = self::is_local_host( $host );

		if ( '' === trim( (string) $config['api_key'] ) && ! $is_local ) {
			return self::failure( 'missing_key', __( '尚未填写 API Key。请在「LLM 配置」页填写后保存。', 'geo-engine' ) );
		}

		$driver = self::driver_for( isset( $config['protocol'] ) ? $config['protocol'] : 'openai-chat' );

		if ( ! $driver ) {
			return self::failure( 'bad_protocol', __( '未知的协议驱动。', 'geo-engine' ) );
		}

		$request = $driver->build_request( $messages, $config );
		$payload = wp_json_encode( $request['body'] );

		if ( false === $payload ) {
			return self::failure( 'encode_error', __( '请求体无法序列化为 JSON。请检查提示词中是否包含非法字符。', 'geo-engine' ) );
		}

		$timeout  = max( 10, min( 180, (int) ( isset( $config['timeout'] ) ? $config['timeout'] : 60 ) ) );
		$attempts = max( 0, min( 5, (int) ( isset( $config['max_retries'] ) ? $config['max_retries'] : 2 ) ) ) + 1;

		$last_code    = 'unknown';
		$last_message = __( '请求未能完成。', 'geo-engine' );
		$latency      = 0;

		for ( $attempt = 0; $attempt < $attempts; $attempt++ ) {
			$started = microtime( true );

			$response = wp_remote_post(
				$request['url'],
				array(
					'timeout'     => $timeout,
					'headers'     => $request['headers'],
					'body'        => $payload,
					'data_format' => 'body',
					'redirection' => 0,
					'sslverify'   => true,
					'user-agent'  => 'GEO-Engine/' . GEO_ENGINE_VERSION . '; ' . home_url(),
				)
			);

			$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( is_wp_error( $response ) ) {
				$raw = $response->get_error_message();

				$last_code = 'unreachable';

				if ( false !== stripos( $raw, 'timed out' ) || false !== stripos( $raw, 'cURL error 28' ) ) {
					$last_code = 'timeout';
					/* translators: %d: timeout seconds. */
					$last_message = sprintf( __( '请求超时（%d 秒）。可调高超时时间或改用更快的模型。', 'geo-engine' ), $timeout );
				} else {
					/* translators: %s: transport error. */
					$last_message = sprintf( __( '无法连接端点：%s', 'geo-engine' ), $raw );
				}

				if ( $attempt < $attempts - 1 ) {
					self::backoff( $attempt );
					continue;
				}

				break;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$body   = (string) wp_remote_retrieve_body( $response );
			$parsed = $driver->parse_response( $status, $body );

			if ( is_wp_error( $parsed ) ) {
				$last_code    = $parsed->get_error_code();
				$last_message = $parsed->get_error_message();

				if ( self::is_retryable( $last_code ) && $attempt < $attempts - 1 ) {
					$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
					$delay       = self::backoff_delay( $attempt );

					if ( is_numeric( $retry_after ) ) {
						$delay = min( 10, max( 1, (int) $retry_after ) );
					}

					sleep( $delay );
					continue;
				}

				break;
			}

			$decoded = json_decode( $body, true );
			$usage   = self::usage_from( isset( $config['protocol'] ) ? $config['protocol'] : 'openai-chat', is_array( $decoded ) ? $decoded : array() );

			return array(
				'ok'      => true,
				'content' => $parsed,
				'status'  => $status,
				'latency' => $latency,
				'usage'   => $usage,
				'tokens'  => $usage['total'],
				'code'    => '',
				'message' => '',
			);
		}

		return self::failure( $last_code, $last_message, $latency );
	}

	/**
	 * Canonical failure result.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $latency Latency in ms.
	 * @return array<string,mixed>
	 */
	public static function failure( $code, $message, $latency = 0 ) {
		return array(
			'ok'      => false,
			'content' => '',
			'status'  => 0,
			'latency' => (int) $latency,
			'usage'   => array(
				'prompt'     => 0,
				'completion' => 0,
				'total'      => 0,
			),
			'tokens'  => 0,
			'code'    => (string) $code,
			'message' => (string) $message,
		);
	}

	/**
	 * Should the client sleep and try again?
	 *
	 * @param string $code Error code.
	 * @return bool
	 */
	public static function is_retryable( $code ) {
		return in_array( (string) $code, array( 'rate_limited', 'server_error', 'timeout', 'unreachable' ), true );
	}

	/**
	 * Exponential backoff delay in seconds.
	 *
	 * @param int $attempt Zero-based attempt index.
	 * @return int
	 */
	public static function backoff_delay( $attempt ) {
		return (int) min( 8, pow( 2, (int) $attempt + 1 ) );
	}

	/**
	 * Sleep for the backoff window.
	 *
	 * @param int $attempt Zero-based attempt index.
	 * @return void
	 */
	private static function backoff( $attempt ) {
		sleep( self::backoff_delay( $attempt ) );
	}

	/**
	 * Normalise usage counters across protocols.
	 *
	 * @param string              $protocol Protocol id.
	 * @param array<string,mixed> $decoded  Decoded response.
	 * @return array{prompt:int,completion:int,total:int}
	 */
	public static function usage_from( $protocol, array $decoded ) {
		$usage = array(
			'prompt'     => 0,
			'completion' => 0,
			'total'      => 0,
		);

		if ( 'anthropic-messages' === $protocol ) {
			$usage['prompt']     = isset( $decoded['usage']['input_tokens'] ) ? (int) $decoded['usage']['input_tokens'] : 0;
			$usage['completion'] = isset( $decoded['usage']['output_tokens'] ) ? (int) $decoded['usage']['output_tokens'] : 0;
			$usage['total']      = $usage['prompt'] + $usage['completion'];

			return $usage;
		}

		if ( isset( $decoded['usage'] ) && is_array( $decoded['usage'] ) ) {
			$usage['prompt']     = isset( $decoded['usage']['prompt_tokens'] ) ? (int) $decoded['usage']['prompt_tokens'] : 0;
			$usage['completion'] = isset( $decoded['usage']['completion_tokens'] ) ? (int) $decoded['usage']['completion_tokens'] : 0;
			$usage['total']      = isset( $decoded['usage']['total_tokens'] ) ? (int) $decoded['usage']['total_tokens'] : ( $usage['prompt'] + $usage['completion'] );
		}

		return $usage;
	}

	/**
	 * Ping the configured provider with a tiny request.
	 *
	 * @param array<string,mixed>|null $config Optional config override.
	 * @return array<string,mixed>
	 */
	public static function test_connection( $config = null ) {
		$client = new self( $config );
		$cfg    = $client->config();

		$result = $client->chat(
			array(
				array(
					'role'    => 'system',
					'content' => 'You are a connectivity probe. Reply with the single word OK and nothing else.',
				),
				array(
					'role'    => 'user',
					'content' => 'Reply with OK.',
				),
			),
			array(
				'max_tokens'      => 16,
				'temperature'     => 0,
				'max_retries'     => 0,
				'response_format' => 'off',
			)
		);

		$presets  = GEO_Settings_LLM::presets();
		$provider = isset( $cfg['provider'] ) ? $cfg['provider'] : '';
		$label    = isset( $presets[ $provider ] ) ? $presets[ $provider ]['label'] : $provider;

		$payload = array(
			'ok'       => (bool) $result['ok'],
			'provider' => $label,
			'model'    => isset( $cfg['model'] ) ? $cfg['model'] : '',
			'latency'  => (int) $result['latency'],
			'message'  => $result['ok'] ? '' : $result['message'],
			'code'     => $result['ok'] ? '' : $result['code'],
		);

		set_transient( 'geo_engine_test_result', $payload, 5 * MINUTE_IN_SECONDS );

		return $payload;
	}
}
