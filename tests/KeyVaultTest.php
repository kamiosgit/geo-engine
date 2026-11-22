<?php
/**
 * Key vault: encryption round trip and masking.
 *
 * @package GEO_Engine
 */

use PHPUnit\Framework\TestCase;

/**
 * Class KeyVaultTest
 */
class KeyVaultTest extends TestCase {

	/**
	 * Reset stored state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		delete_option( GEO_Key_Vault::OPTION );
	}

	/**
	 * A secret must survive an encrypt/decrypt cycle unchanged.
	 *
	 * @return void
	 */
	public function test_roundtrip_preserves_secret() {
		$secret = 'sk-test-0123456789abcdef';

		$stored = GEO_Key_Vault::encrypt( $secret );

		$this->assertNotSame( '', $stored );
		$this->assertNotSame( $secret, $stored );
		$this->assertSame( $secret, GEO_Key_Vault::decrypt( $stored ) );
	}

	/**
	 * Empty input must never produce a ciphertext.
	 *
	 * @return void
	 */
	public function test_empty_secret_stays_empty() {
		$this->assertSame( '', GEO_Key_Vault::encrypt( '' ) );
		$this->assertSame( '', GEO_Key_Vault::decrypt( '' ) );
	}

	/**
	 * The stored value must not contain the plaintext or plain base64 of it.
	 *
	 * @return void
	 */
	public function test_stored_value_is_not_reversible_encoding() {
		$secret = 'sk-live-abcdefghijklmnop';

		$stored = GEO_Key_Vault::encrypt( $secret );

		$this->assertStringNotContainsString( $secret, $stored );
		$this->assertStringNotContainsString( base64_encode( $secret ), $stored ); // phpcs:ignore

		if ( GEO_Key_Vault::encryption_available() ) {
			$this->assertStringStartsWith( GEO_Key_Vault::PREFIX_OPENSSL, $stored );
		}
	}

	/**
	 * Masking must keep a recognisable head and tail only.
	 *
	 * @return void
	 */
	public function test_mask_hides_the_middle() {
		$masked = GEO_Key_Vault::mask( 'sk-1234567890abcdef' );

		$this->assertStringStartsWith( 'sk-1', $masked );
		$this->assertStringEndsWith( 'cdef', $masked );
		$this->assertStringNotContainsString( '234567890ab', $masked );
		$this->assertSame( str_repeat( '•', 8 ), GEO_Key_Vault::mask( 'short' ) );
	}

	/**
	 * Garbage must decrypt to an empty string, never to code execution or a warning.
	 *
	 * @return void
	 */
	public function test_garbage_payload_returns_empty_string() {
		$this->assertSame( '', GEO_Key_Vault::decrypt( 'not-a-payload' ) );
		$this->assertSame( '', GEO_Key_Vault::decrypt( GEO_Key_Vault::PREFIX_OPENSSL . 'short' ) );
	}

	/**
	 * The degraded encoding must still round trip, and be clearly prefixed.
	 *
	 * @return void
	 */
	public function test_fallback_encoding_is_prefixed_and_readable() {
		$stored = GEO_Key_Vault::PREFIX_FALLBACK . base64_encode( 'sk-fallback' ); // phpcs:ignore

		$this->assertSame( 'sk-fallback', GEO_Key_Vault::decrypt( $stored ) );
	}

	/**
	 * Option storage helpers.
	 *
	 * @return void
	 */
	public function test_option_storage_helpers() {
		$this->assertFalse( GEO_Key_Vault::has_key() );

		GEO_Key_Vault::set_key( 'sk-stored-key' );

		$this->assertTrue( GEO_Key_Vault::has_key() );
		$this->assertSame( 'sk-stored-key', GEO_Key_Vault::get_key() );
		$this->assertStringNotContainsString( 'sk-stored-key', (string) get_option( GEO_Key_Vault::OPTION ) );

		GEO_Key_Vault::delete_key();

		$this->assertFalse( GEO_Key_Vault::has_key() );
	}
}
