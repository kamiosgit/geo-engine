<?php
/**
 * SSRF policy for configurable endpoints.
 *
 * @package GEO_Engine
 */

use PHPUnit\Framework\TestCase;

/**
 * Class EndpointPolicyTest
 */
class EndpointPolicyTest extends TestCase {

	/**
	 * Empty base is rejected.
	 *
	 * @return void
	 */
	public function test_empty_endpoint_rejected() {
		$result = GEO_LLM_Client::validate_endpoint( '', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'endpoint_empty', $result->get_error_code() );
	}

	/**
	 * Relative or malformed values are rejected.
	 *
	 * @return void
	 */
	public function test_invalid_endpoint_rejected() {
		$result = GEO_LLM_Client::validate_endpoint( 'api.example.com/v1', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'endpoint_invalid', $result->get_error_code() );
	}

	/**
	 * Credentials inside the URL are refused.
	 *
	 * @return void
	 */
	public function test_url_credentials_rejected() {
		$result = GEO_LLM_Client::validate_endpoint( 'https://user:pass@api.siliconflow.cn/v1', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'endpoint_credentials', $result->get_error_code() );
	}

	/**
	 * A preset host over https is allowed without any switch.
	 *
	 * @return void
	 */
	public function test_whitelisted_https_allowed() {
		$this->assertTrue( GEO_LLM_Client::validate_endpoint( 'https://api.siliconflow.cn/v1', array() ) );
		$this->assertTrue( GEO_LLM_Client::validate_endpoint( 'https://api.anthropic.com/v1', array() ) );
	}

	/**
	 * Plain http is refused for public hosts.
	 *
	 * @return void
	 */
	public function test_http_scheme_rejected() {
		$result = GEO_LLM_Client::validate_endpoint( 'http://api.siliconflow.cn/v1', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'endpoint_scheme', $result->get_error_code() );
	}

	/**
	 * Localhost is blocked unless explicitly enabled (AC-18).
	 *
	 * @return void
	 */
	public function test_localhost_blocked_by_default() {
		$result = GEO_LLM_Client::validate_endpoint( 'http://localhost:11434/v1', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'endpoint_local_blocked', $result->get_error_code() );

		$allowed = GEO_LLM_Client::validate_endpoint(
			'http://localhost:11434/v1',
			array( 'allow_local_endpoint' => 1 )
		);

		$this->assertTrue( $allowed );
	}

	/**
	 * Private IPs are treated as local.
	 *
	 * @return void
	 */
	public function test_private_ip_is_local() {
		$this->assertTrue( GEO_LLM_Client::is_local_host( '192.168.1.10' ) );
		$this->assertTrue( GEO_LLM_Client::is_local_host( '10.0.0.5' ) );
		$this->assertTrue( GEO_LLM_Client::is_local_host( '127.0.0.1' ) );
		$this->assertFalse( GEO_LLM_Client::is_local_host( 'api.openai.com' ) );
	}

	/**
	 * An unknown public host needs the custom-endpoint switch.
	 *
	 * @return void
	 */
	public function test_custom_host_requires_switch() {
		$blocked = GEO_LLM_Client::validate_endpoint( 'https://llm.internal-proxy.example/v1', array() );

		$this->assertInstanceOf( WP_Error::class, $blocked );
		$this->assertSame( 'endpoint_host', $blocked->get_error_code() );

		$allowed = GEO_LLM_Client::validate_endpoint(
			'https://llm.internal-proxy.example/v1',
			array( 'allow_custom_endpoint' => 1 )
		);

		$this->assertTrue( $allowed );
	}

	/**
	 * Dangerous headers are dropped when parsing the textarea.
	 *
	 * @return void
	 */
	public function test_extra_header_blocklist() {
		$headers = GEO_Settings_LLM::parse_extra_headers(
			"Host: evil.test\nContent-Length: 999\nX-Custom: ok\nanthropic-version: 2023-06-01\nbroken line"
		);

		$this->assertArrayNotHasKey( 'Host', $headers );
		$this->assertArrayNotHasKey( 'Content-Length', $headers );
		$this->assertSame( 'ok', $headers['X-Custom'] );
		$this->assertSame( '2023-06-01', $headers['anthropic-version'] );
		$this->assertCount( 2, $headers );
	}

	/**
	 * The API base is normalised: trailing slash and pasted path removed.
	 *
	 * @return void
	 */
	public function test_api_base_normalisation() {
		$this->assertSame(
			'https://api.openai.com/v1',
			GEO_Settings_LLM::normalize_api_base( 'https://api.openai.com/v1/chat/completions/' )
		);

		$this->assertSame(
			'https://api.anthropic.com/v1',
			GEO_Settings_LLM::normalize_api_base( ' https://api.anthropic.com/v1/messages ' )
		);
	}

	/**
	 * Export never contains the secret (AC-17).
	 *
	 * @return void
	 */
	public function test_export_excludes_key() {
		GEO_Key_Vault::set_key( 'sk-very-secret' );

		$export = GEO_Settings_LLM::export();

		$this->assertArrayHasKey( 'config', $export );
		$this->assertSame( '', $export['config']['api_key'] );
		$this->assertStringNotContainsString( 'sk-very-secret', wp_json_encode( $export ) );

		GEO_Key_Vault::delete_key();
	}
}
