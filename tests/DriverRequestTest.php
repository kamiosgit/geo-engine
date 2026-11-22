<?php
/**
 * Protocol drivers: request construction and response parsing.
 *
 * @package GEO_Engine
 */

use PHPUnit\Framework\TestCase;

/**
 * Class DriverRequestTest
 */
class DriverRequestTest extends TestCase {

	/**
	 * Base openai-chat config.
	 *
	 * @param array $overrides Overrides.
	 * @return array
	 */
	private function openai_config( array $overrides = array() ) {
		return array_merge(
			array(
				'provider'              => 'siliconflow',
				'api_base'              => 'https://api.siliconflow.cn/v1',
				'model'                 => 'deepseek-ai/DeepSeek-V3.2',
				'api_key'               => 'sk-test',
				'auth_scheme'           => 'bearer',
				'auth_header_name'      => 'Authorization',
				'auth_header_value'     => 'Bearer {key}',
				'extra_headers'         => '',
				'protocol'              => 'openai-chat',
				'response_format'       => 'auto',
				'temperature'           => 0.2,
				'max_tokens'            => 1500,
				'enable_thinking'       => 0,
			),
			$overrides
		);
	}

	/**
	 * Base anthropic config.
	 *
	 * @param array $overrides Overrides.
	 * @return array
	 */
	private function anthropic_config( array $overrides = array() ) {
		return array_merge(
			array(
				'provider'          => 'anthropic',
				'api_base'          => 'https://api.anthropic.com/v1',
				'model'             => 'claude-3-5-haiku-latest',
				'api_key'           => 'sk-ant-test',
				'auth_scheme'       => 'x-api-key',
				'auth_header_name'  => 'x-api-key',
				'auth_header_value' => '{key}',
				'extra_headers'     => 'anthropic-version: 2023-06-01',
				'protocol'          => 'anthropic-messages',
				'response_format'   => 'auto',
				'temperature'       => 0.2,
				'max_tokens'        => 1500,
			),
			$overrides
		);
	}

	/**
	 * URL, auth header and body for an OpenAI compatible provider.
	 *
	 * @return void
	 */
	public function test_openai_request_shape() {
		$driver = new GEO_Driver_OpenAI_Chat();

		$request = $driver->build_request(
			array(
				array(
					'role'    => 'system',
					'content' => 'sys',
				),
				array(
					'role'    => 'user',
					'content' => 'usr',
				),
			),
			$this->openai_config()
		);

		$this->assertSame( 'https://api.siliconflow.cn/v1/chat/completions', $request['url'] );
		$this->assertSame( 'Bearer sk-test', $request['headers']['Authorization'] );
		$this->assertSame( 'deepseek-ai/DeepSeek-V3.2', $request['body']['model'] );
		$this->assertFalse( $request['body']['stream'] );
		$this->assertSame( array( 'type' => 'json_object' ), $request['body']['response_format'] );
		$this->assertArrayNotHasKey( 'enable_thinking', $request['body'] );
	}

	/**
	 * Trailing slash in the base must not double up.
	 *
	 * @return void
	 */
	public function test_openai_trailing_slash_is_handled() {
		$driver = new GEO_Driver_OpenAI_Chat();

		$request = $driver->build_request( array(), $this->openai_config( array( 'api_base' => 'https://api.openai.com/v1/' ) ) );

		$this->assertSame( 'https://api.openai.com/v1/chat/completions', $request['url'] );
	}

	/**
	 * response_format=off must be omitted entirely.
	 *
	 * @return void
	 */
	public function test_openai_response_format_off() {
		$driver = new GEO_Driver_OpenAI_Chat();

		$request = $driver->build_request( array(), $this->openai_config( array( 'response_format' => 'off' ) ) );

		$this->assertArrayNotHasKey( 'response_format', $request['body'] );
	}

	/**
	 * Thinking mode is opt-in.
	 *
	 * @return void
	 */
	public function test_openai_enable_thinking() {
		$driver = new GEO_Driver_OpenAI_Chat();

		$request = $driver->build_request( array(), $this->openai_config( array( 'enable_thinking' => 1 ) ) );

		$this->assertTrue( $request['body']['enable_thinking'] );
	}

	/**
	 * Custom auth scheme substitutes the {key} placeholder.
	 *
	 * @return void
	 */
	public function test_custom_auth_header() {
		$driver = new GEO_Driver_OpenAI_Chat();

		$request = $driver->build_request(
			array(),
			$this->openai_config(
				array(
					'auth_scheme'       => 'custom',
					'auth_header_name'  => 'X-Token',
					'auth_header_value' => 'Token {key}',
				)
			)
		);

		$this->assertSame( 'Token sk-test', $request['headers']['X-Token'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
	}

	/**
	 * Anthropic moves the system prompt out of the message list.
	 *
	 * @return void
	 */
	public function test_anthropic_request_shape() {
		$driver = new GEO_Driver_Anthropic();

		$request = $driver->build_request(
			array(
				array(
					'role'    => 'system',
					'content' => '系统提示',
				),
				array(
					'role'    => 'user',
					'content' => '用户内容',
				),
			),
			$this->anthropic_config()
		);

		$this->assertSame( 'https://api.anthropic.com/v1/messages', $request['url'] );
		$this->assertSame( 'sk-ant-test', $request['headers']['x-api-key'] );
		$this->assertSame( '2023-06-01', $request['headers']['anthropic-version'] );
		$this->assertSame( '系统提示', $request['body']['system'] );
		$this->assertCount( 1, $request['body']['messages'] );
		$this->assertSame( 'user', $request['body']['messages'][0]['role'] );
		$this->assertArrayNotHasKey( 'response_format', $request['body'] );
		$this->assertFalse( $driver->supports_response_format() );
	}

	/**
	 * Anthropic requires at least one user turn.
	 *
	 * @return void
	 */
	public function test_anthropic_adds_user_turn_when_missing() {
		$driver = new GEO_Driver_Anthropic();

		$request = $driver->build_request(
			array(
				array(
					'role'    => 'system',
					'content' => 'only system',
				),
			),
			$this->anthropic_config()
		);

		$this->assertSame( 'user', $request['body']['messages'][0]['role'] );
	}

	/**
	 * Unknown protocols yield no driver.
	 *
	 * @return void
	 */
	public function test_unknown_protocol_returns_null() {
		$this->assertNull( GEO_LLM_Client::driver_for( 'grpc-magic' ) );
		$this->assertInstanceOf( GEO_Driver_OpenAI_Chat::class, GEO_LLM_Client::driver_for( 'openai-chat' ) );
		$this->assertInstanceOf( GEO_Driver_Anthropic::class, GEO_LLM_Client::driver_for( 'anthropic-messages' ) );
	}
}
