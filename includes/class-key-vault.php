<?php
/**
 * API key storage: AES-256-CBC with a key derived from wp-config.php salts.
 *
 * @package GEO_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts / decrypts the provider API key at rest.
 *
 * The encryption key never touches the database: it is derived at runtime from
 * constants that already live in wp-config.php. Copying the database to another
 * host therefore does not expose the credential.
 */
class GEO_Key_Vault {

	/**
	 * Option holding the ciphertext.
	 */
	const OPTION = 'geo_engine_api_key_cipher';

	/**
	 * Cipher used when openssl is available.
	 */
	const CIPHER = 'aes-256-cbc';

	/**
	 * Prefix marking an openssl ciphertext.
	 */
	const PREFIX_OPENSSL = 'geov1:';

	/**
	 * Prefix marking the degraded reversible encoding.
	 */
	const PREFIX_FALLBACK = 'geob64:';

	/**
	 * Is real encryption available in this environment?
	 *
	 * @return bool
	 */
	public static function encryption_available() {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}

		$methods = openssl_get_cipher_methods();

		return is_array( $methods ) && in_array( self::CIPHER, $methods, true );
	}

	/**
	 * Derive the binary encryption key from wp-config.php salts.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function derived_key() {
		$constants = array( 'LOGGED_IN_KEY', 'LOGGED_IN_SALT', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'NONCE_SALT', 'AUTH_SALT' );
		$material  = '';

		foreach ( $constants as $constant ) {
			if ( defined( $constant ) ) {
				$material .= (string) constant( $constant );
			}
		}

		// Extremely unlikely: a site with none of the salts defined. Fall back to
		// database identity so the key is still site-specific rather than global.
		if ( '' === $material ) {
			$material = 'geo-engine-fallback|' . ( defined( 'DB_NAME' ) ? DB_NAME : 'unknown' ) . '|' . ( defined( 'DB_HOST' ) ? DB_HOST : '' );
		}

		return hash( 'sha256', $material . '|geo-engine-key-v1', true );
	}

	/**
	 * Encrypt a secret for storage.
	 *
	 * @param string $plain Plaintext secret.
	 * @return string Stored representation, or empty string on failure.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;

		if ( '' === $plain ) {
			return '';
		}

		if ( self::encryption_available() ) {
			$iv_length = openssl_cipher_iv_length( self::CIPHER );
			$iv        = random_bytes( $iv_length );

			$cipher = openssl_encrypt( $plain, self::CIPHER, self::derived_key(), OPENSSL_RAW_DATA, $iv );

			if ( false === $cipher ) {
				return '';
			}

			return self::PREFIX_OPENSSL . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		// Documented, non-silent downgrade (see README + settings warning).
		return self::PREFIX_FALLBACK . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $stored Stored representation.
	 * @return string Plaintext, or empty string when unreadable.
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;

		if ( '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, self::PREFIX_OPENSSL ) ) {
			// openssl may have been disabled after the secret was stored.
			if ( ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'openssl_cipher_iv_length' ) ) {
				return '';
			}

			$payload = base64_decode( substr( $stored, strlen( self::PREFIX_OPENSSL ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

			if ( false === $payload || '' === $payload ) {
				return '';
			}

			$iv_length = openssl_cipher_iv_length( self::CIPHER );

			if ( strlen( $payload ) <= $iv_length ) {
				return '';
			}

			$iv     = substr( $payload, 0, $iv_length );
			$cipher = substr( $payload, $iv_length );

			$plain = openssl_decrypt( $cipher, self::CIPHER, self::derived_key(), OPENSSL_RAW_DATA, $iv );

			return false === $plain ? '' : $plain;
		}

		if ( 0 === strpos( $stored, self::PREFIX_FALLBACK ) ) {
			$plain = base64_decode( substr( $stored, strlen( self::PREFIX_FALLBACK ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

			return false === $plain ? '' : $plain;
		}

		return '';
	}

	/**
	 * Read the plaintext API key.
	 *
	 * @return string
	 */
	public static function get_key() {
		return self::decrypt( (string) get_option( self::OPTION, '' ) );
	}

	/**
	 * Store the plaintext API key (encrypted). Empty input deletes it.
	 *
	 * @param string $plain Plaintext key.
	 * @return bool
	 */
	public static function set_key( $plain ) {
		$plain = is_string( $plain ) ? trim( $plain ) : '';

		if ( '' === $plain ) {
			return delete_option( self::OPTION );
		}

		$stored = self::encrypt( $plain );

		if ( '' === $stored ) {
			return false;
		}

		return update_option( self::OPTION, $stored, false );
	}

	/**
	 * Whether a key is stored.
	 *
	 * @return bool
	 */
	public static function has_key() {
		return '' !== self::get_key();
	}

	/**
	 * Build the masked display value shown to the browser (never the secret).
	 *
	 * @param string $plain Plaintext key.
	 * @return string
	 */
	public static function mask( $plain ) {
		$plain = (string) $plain;
		$len   = strlen( $plain );

		if ( 0 === $len ) {
			return '';
		}

		if ( $len <= 8 ) {
			return str_repeat( '•', 8 );
		}

		return substr( $plain, 0, 4 ) . str_repeat( '•', 8 ) . substr( $plain, -4 );
	}

	/**
	 * Delete the stored secret.
	 *
	 * @return bool
	 */
	public static function delete_key() {
		return delete_option( self::OPTION );
	}
}
