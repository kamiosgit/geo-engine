<?php
/**
 * Response parsing and error mapping.
 *
 * @package GEO_Engine
 */

use PHPUnit\Framework\TestCase;

/**
 * Class DriverParseTest
 */
class DriverParseTest extends TestCase {

	/**
	 * Assistant content is extracted from the first choice.
	 *
	 * @return void
	 */
	public function test_openai_extracts_content() {
		$driver = new GEO_Driver_OpenAI_Chat();

		$body = wp_json_encode(
			array(
				'choices' => array(
					array(
						'message' => array(
							'role'    => 'assistant',
							'content' => '  {"faqs":[]}  ',
						),
					),
				),
			)
		);

		$this->assertSame( '{"faqs":[]}', $driver->parse_response( 200, $body ) );
	}

	/**
	 * reasoning_content must be ignored, not concatenated.
	 *
	 * @return void
	 */
	public function test_openai_ignores_reasoning_content() {
		$driver = new GEO_Driver_OpenAI_Chat();

		$body = wp_json_encode(
			array(
				'choices' => array(
					array(
						'message' => array(
							'content'           => 'final answer',
							'reasoning_content' => 'long internal monologue',
						),
					),
				),
			)
		);

		$this->assertSame( 'final answer', $driver->parse_response( 200, $body ) );
	}

	/**
	 * Array-shaped content parts are flattened.
	 *
	 * @return void
	 */
	public function test_openai_flattens_content_parts() {
		$driver = new GEO_Driver_OpenAI_Chat();

		$body = wp_json_encode(
			array(
				'choices' => array(
					array(
						'message' => array(
							'content' => array(
								array( 'type' => 'text', 'text' => 'part one' ),
								array( 'type' => 'text', 'text' => 'part two' ),
							),
						),
					),
				),
			)
		);

		$this->assertSame( "part one\npart two", $driver->parse_response( 200, $body ) );
	}

	/**
	 * Empty content maps to a helpful error.
	 *
	 * @return void
	 */
	public function test_openai_empty_content_is_error() {
		$driver = new GEO_Driver_OpenAI_Chat();

		$body = wp_json_encode(
			array(
				'choices' => array(
					array(
						'message'       => array( 'content' => '' ),
						'finish_reason' => 'length',
					),
				),
			)
		);

		$result = $driver->parse_response( 200, $body );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'empty_response', $result->get_error_code() );
	}

	/**
	 * 401 maps to invalid_key.
	 *
	 * @return void
	 */
	public function test_openai_maps_auth_error() {
		$driver = new GEO_Driver_OpenAI_Chat();

		$body = wp_json_encode( array( 'error' => array( 'message' => 'Invalid token' ) ) );

		$result = $driver->parse_response( 401, $body );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_key', $result->get_error_code() );
		$this->assertStringContainsString( 'Invalid token', $result->get_error_message() );
	}

	/**
	 * 429 and 5xx must be retryable.
	 *
	 * @return void
	 */
	public function test_retryable_codes() {
		$driver = new GEO_Driver_OpenAI_Chat();

		$rate = $driver->parse_response( 429, '{}' );
		$down = $driver->parse_response( 503, '<html>oops</html>' );

		$this->assertSame( 'rate_limited', $rate->get_error_code() );
		$this->assertSame( 'server_error', $down->get_error_code() );

		$this->assertTrue( GEO_LLM_Client::is_retryable( 'rate_limited' ) );
		$this->assertTrue( GEO_LLM_Client::is_retryable( 'timeout' ) );
		$this->assertFalse( GEO_LLM_Client::is_retryable( 'invalid_key' ) );
		$this->assertFalse( GEO_LLM_Client::is_retryable( 'bad_request' ) );
	}

	/**
	 * 404 points at a base URL / protocol mismatch.
	 *
	 * @return void
	 */
	public function test_openai_maps_not_found() {
		$driver = new GEO_Driver_OpenAI_Chat();

		$result = $driver->parse_response( 404, '' );

		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	/**
	 * Anthropic text blocks are concatenated, thinking blocks are skipped.
	 *
	 * @return void
	 */
	public function test_anthropic_extracts_text_blocks_only() {
		$driver = new GEO_Driver_Anthropic();

		$body = wp_json_encode(
			array(
				'content' => array(
					array(
						'type'     => 'thinking',
						'thinking' => 'internal',
					),
					array(
						'type' => 'text',
						'text' => 'OK',
					),
				),
			)
		);

		$this->assertSame( 'OK', $driver->parse_response( 200, $body ) );
	}

	/**
	 * Wrong protocol shape is reported clearly.
	 *
	 * @return void
	 */
	public function test_anthropic_wrong_shape() {
		$driver = new GEO_Driver_Anthropic();

		$body = wp_json_encode( array( 'choices' => array() ) );

		$result = $driver->parse_response( 200, $body );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unexpected_shape', $result->get_error_code() );
	}

	/**
	 * Usage counters are normalised across protocols.
	 *
	 * @return void
	 */
	public function test_usage_normalisation() {
		$openai = GEO_LLM_Client::usage_from(
			'openai-chat',
			array(
				'usage' => array(
					'prompt_tokens'     => 100,
					'completion_tokens' => 50,
					'total_tokens'      => 150,
				),
			)
		);

		$this->assertSame( 150, $openai['total'] );

		$anthropic = GEO_LLM_Client::usage_from(
			'anthropic-messages',
			array(
				'usage' => array(
					'input_tokens'  => 80,
					'output_tokens' => 20,
				),
			)
		);

		$this->assertSame( 100, $anthropic['total'] );
	}
}
