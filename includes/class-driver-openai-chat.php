<?php
/**
 * OpenAI Chat Completions protocol driver.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Covers every OpenAI-compatible gateway (SiliconFlow, DeepSeek, Moonshot,
 * Zhipu, DashScope compatible mode, Ollama, self-hosted proxies).
 */
class GEO_Driver_OpenAI_Chat extends GEO_LLM_Driver_Base {

	/**
	 * Build the request.
	 *
	 * @param array<int,array<string,string>> $messages Neutral messages.
	 * @param array<string,mixed>             $config   Connection config.
	 * @return array{url:string,headers:array<string,string>,body:array<string,mixed>}
	 */
	public function build_request( array $messages, array $config ) {
		$url = rtrim( (string) $config['api_base'], '/' ) . '/chat/completions';

		$body = array(
			'model'       => (string) $config['model'],
			'messages'    => $this->normalize_messages( $messages ),
			'temperature' => isset( $config['temperature'] ) ? (float) $config['temperature'] : 0.2,
			'max_tokens'  => isset( $config['max_tokens'] ) ? (int) $config['max_tokens'] : 1500,
			'stream'      => false,
		);

		if ( ! empty( $config['enable_thinking'] ) ) {
			$body['enable_thinking'] = true;
		}

		$mode = isset( $config['response_format'] ) ? (string) $config['response_format'] : 'auto';

		if ( 'off' !== $mode && ( 'force' === $mode || $this->supports_response_format() ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		return array(
			'url'     => $url,
			'headers' => $this->merged_headers(
				$config,
				array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				)
			),
			'body'    => $body,
		);
	}

	/**
	 * Parse the response.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Raw body.
	 * @return string|WP_Error
	 */
	public function parse_response( $status, $body ) {
		$decoded = json_decode( (string) $body, true );

		$status = (int) $status;

		if ( $status < 200 || $status >= 300 ) {
			return $this->http_error( $status, $decoded );
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'invalid_json', __( '服务商返回的内容不是合法 JSON，请检查 API Base 是否指向了正确接口。', 'geo-engine' ) );
		}

		if ( ! isset( $decoded['choices'][0]['message'] ) || ! is_array( $decoded['choices'][0]['message'] ) ) {
			return new WP_Error( 'unexpected_shape', __( '响应结构异常：缺少 choices[0].message。该端点可能不是 OpenAI 兼容接口。', 'geo-engine' ) );
		}

		$content = $this->flatten_content( $decoded['choices'][0]['message']['content'] );

		if ( '' === $content ) {
			$finish = isset( $decoded['choices'][0]['finish_reason'] ) ? (string) $decoded['choices'][0]['finish_reason'] : '';

			if ( 'length' === $finish ) {
				return new WP_Error( 'empty_response', __( '模型在输出正文前已用尽 max_tokens。请调高 max_tokens，或关闭思考模式。', 'geo-engine' ) );
			}

			return new WP_Error( 'empty_response', __( '服务商返回了空内容。推理类模型请关闭「思考模式」，或改用非推理模型。', 'geo-engine' ) );
		}

		return $content;
	}
}
