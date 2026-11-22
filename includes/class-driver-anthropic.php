<?php
/**
 * Anthropic Messages protocol driver.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Anthropic keeps the system prompt outside the message list, requires
 * max_tokens and has no response_format equivalent.
 */
class GEO_Driver_Anthropic extends GEO_LLM_Driver_Base {

	/**
	 * Anthropic has no response_format; JSON comes from the prompt.
	 *
	 * @var bool
	 */
	protected $response_format_support = false;

	/**
	 * Build the request.
	 *
	 * @param array<int,array<string,string>> $messages Neutral messages.
	 * @param array<string,mixed>             $config   Connection config.
	 * @return array{url:string,headers:array<string,string>,body:array<string,mixed>}
	 */
	public function build_request( array $messages, array $config ) {
		$url = rtrim( (string) $config['api_base'], '/' ) . '/messages';

		$system_parts = array();
		$conversation = array();

		foreach ( $this->normalize_messages( $messages ) as $message ) {
			if ( 'system' === $message['role'] ) {
				$system_parts[] = $message['content'];
				continue;
			}

			$conversation[] = array(
				'role'    => $message['role'],
				'content' => $message['content'],
			);
		}

		// The Messages API requires at least one user turn.
		if ( empty( $conversation ) ) {
			$conversation[] = array(
				'role'    => 'user',
				'content' => 'Reply with OK.',
			);
		}

		$body = array(
			'model'       => (string) $config['model'],
			'messages'    => $conversation,
			'max_tokens'  => isset( $config['max_tokens'] ) ? (int) $config['max_tokens'] : 1500,
			'temperature' => isset( $config['temperature'] ) ? (float) $config['temperature'] : 0.2,
		);

		if ( ! empty( $system_parts ) ) {
			$body['system'] = implode( "\n\n", $system_parts );
		}

		return array(
			'url'     => $url,
			'headers' => $this->merged_headers(
				$config,
				array(
					'Content-Type'      => 'application/json',
					'Accept'            => 'application/json',
					'anthropic-version' => '2023-06-01',
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

		if ( ! is_array( $decoded ) || ! isset( $decoded['content'] ) || ! is_array( $decoded['content'] ) ) {
			return new WP_Error( 'unexpected_shape', __( '响应结构异常：缺少 content 数组。请确认协议驱动选择了 Anthropic Messages。', 'geo-engine' ) );
		}

		$text = '';

		foreach ( $decoded['content'] as $block ) {
			if ( is_array( $block ) && isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
				$text .= (string) $block['text'];
			}
		}

		$text = trim( $text );

		if ( '' === $text ) {
			return new WP_Error( 'empty_response', __( 'Anthropic 返回了空文本。请调高 max_tokens 后重试。', 'geo-engine' ) );
		}

		return $text;
	}
}
