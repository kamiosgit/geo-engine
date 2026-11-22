<?php
/**
 * Shared driver behaviour: auth headers, header merging, HTTP error mapping.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base class for protocol drivers.
 */
abstract class GEO_LLM_Driver_Base implements GEO_LLM_Driver {

	/**
	 * Response format support flag.
	 *
	 * @var bool
	 */
	protected $response_format_support = true;

	/**
	 * Whether this protocol understands response_format=json_object.
	 *
	 * @return bool
	 */
	public function supports_response_format() {
		return (bool) $this->response_format_support;
	}

	/**
	 * Build authentication headers from the resolved config.
	 *
	 * @param array<string,mixed> $config Connection config.
	 * @return array<string,string>
	 */
	protected function auth_headers( array $config ) {
		$key     = isset( $config['api_key'] ) ? (string) $config['api_key'] : '';
		$scheme  = isset( $config['auth_scheme'] ) ? (string) $config['auth_scheme'] : 'bearer';
		$headers = array();

		if ( 'bearer' === $scheme ) {
			if ( '' !== $key ) {
				$headers['Authorization'] = 'Bearer ' . $key;
			}
		} elseif ( 'x-api-key' === $scheme ) {
			if ( '' !== $key ) {
				$headers['x-api-key'] = $key;
			}
		} elseif ( 'custom' === $scheme ) {
			$name = isset( $config['auth_header_name'] ) ? trim( (string) $config['auth_header_name'] ) : '';
			$tpl  = isset( $config['auth_header_value'] ) ? (string) $config['auth_header_value'] : '{key}';

			if ( '' !== $name && preg_match( '/^[A-Za-z0-9\-_]+$/', $name ) ) {
				$headers[ $name ] = str_replace( '{key}', $key, $tpl );
			}
		}

		return $headers;
	}

	/**
	 * Merge default, extra and auth headers (auth wins, so it can never be
	 * silently overridden by a leftover custom header).
	 *
	 * @param array<string,mixed> $config   Connection config.
	 * @param array<string,string> $defaults Protocol defaults.
	 * @return array<string,string>
	 */
	protected function merged_headers( array $config, array $defaults ) {
		$headers = $defaults;

		$extra_raw = isset( $config['extra_headers'] ) ? (string) $config['extra_headers'] : '';

		if ( '' !== trim( $extra_raw ) ) {
			$extra = GEO_Settings_LLM::parse_extra_headers( $extra_raw );

			foreach ( $extra as $name => $value ) {
				$headers[ $name ] = $value;
			}
		}

		foreach ( $this->auth_headers( $config ) as $name => $value ) {
			$headers[ $name ] = $value;
		}

		return $headers;
	}

	/**
	 * Turn an HTTP failure into a WP_Error with an actionable message.
	 *
	 * @param int   $status  HTTP status.
	 * @param mixed $decoded Decoded body, or null.
	 * @return WP_Error
	 */
	protected function http_error( $status, $decoded ) {
		$detail = '';

		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
				$detail = $decoded['error']['message'];
			} elseif ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
				$detail = $decoded['error'];
			} elseif ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
				$detail = $decoded['message'];
			}
		}

		$detail = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $detail ) ) );

		if ( function_exists( 'mb_substr' ) && mb_strlen( $detail ) > 200 ) {
			$detail = mb_substr( $detail, 0, 200 ) . '…';
		}

		$status = (int) $status;

		switch ( true ) {
			case 401 === $status:
				$code    = 'invalid_key';
				$message = __( '密钥无效（401）。请在设置页重新填写 API Key。', 'geo-engine' );
				break;

			case 403 === $status:
				$code    = 'forbidden';
				$message = __( '权限不足（403）。该密钥可能没有此模型的访问权限，或账户余额不足。', 'geo-engine' );
				break;

			case 404 === $status:
				$code    = 'not_found';
				$message = __( '接口不存在（404）。请检查 API Base 是否多写了路径，以及协议驱动是否与服务商匹配。', 'geo-engine' );
				break;

			case 429 === $status:
				$code    = 'rate_limited';
				$message = __( '触发限流（429）。已自动退避重试，仍失败请降低批量并发。', 'geo-engine' );
				break;

			case 400 === $status || 422 === $status:
				$code    = 'bad_request';
				$message = __( '请求被拒绝。请检查模型名与参数；若提示 response_format 不支持，请把「响应格式策略」改为 off。', 'geo-engine' );
				break;

			case $status >= 500:
				$code    = 'server_error';
				$message = __( '服务商端错误（5xx）。稍后重试即可，本文不会写入任何内容。', 'geo-engine' );
				break;

			default:
				$code    = 'http_error';
				/* translators: %d: HTTP status code. */
				$message = sprintf( __( '请求失败（HTTP %d）。', 'geo-engine' ), $status );
				break;
		}

		if ( '' !== $detail ) {
			$message .= ' ' . $detail;
		}

		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Normalise a neutral message list into role/content pairs.
	 *
	 * @param array<int,array<string,string>> $messages Messages.
	 * @return array<int,array<string,string>>
	 */
	protected function normalize_messages( array $messages ) {
		$out = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['content'] ) ) {
				continue;
			}

			$role = isset( $message['role'] ) ? (string) $message['role'] : 'user';

			if ( ! in_array( $role, array( 'system', 'user', 'assistant' ), true ) ) {
				$role = 'user';
			}

			$out[] = array(
				'role'    => $role,
				'content' => (string) $message['content'],
			);
		}

		return $out;
	}

	/**
	 * Extract assistant text from a content value that may be a string or an
	 * array of typed parts (several compatible gateways do the latter).
	 *
	 * @param mixed $content Raw content value.
	 * @return string
	 */
	protected function flatten_content( $content ) {
		if ( is_string( $content ) ) {
			return trim( $content );
		}

		if ( ! is_array( $content ) ) {
			return '';
		}

		$parts = array();

		foreach ( $content as $part ) {
			if ( is_string( $part ) ) {
				$parts[] = $part;
				continue;
			}

			if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
				$parts[] = $part['text'];
			}
		}

		return trim( implode( "\n", $parts ) );
	}
}
