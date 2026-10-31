<?php
/**
 * Encryption of the API keys stored in the database.
 *
 * Keys are encrypted with XSalsa20-Poly1305 (sodium secretbox; WordPress ships
 * sodium_compat so it is always available). The encryption key is derived from
 * GAIML_ENCRYPTION_KEY when defined, otherwise from the site salts, so a
 * database dump alone does not reveal the API keys.
 *
 * @package GustinAI\Multilingual
 */

namespace GustinAI\Multilingual\Support;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	private const PREFIX = 'gv1:';

	private static function key(): string {
		$material = defined( 'GAIML_ENCRYPTION_KEY' ) ? (string) GAIML_ENCRYPTION_KEY : wp_salt( 'secure_auth' );
		return sodium_crypto_generichash( $material . '|gustinai-multilingual', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Returns '' when the value cannot be decrypted (e.g. salts were rotated).
	 */
	public static function decrypt( string $encoded ): string {
		if ( '' === $encoded || 0 !== strpos( $encoded, self::PREFIX ) ) {
			return '';
		}
		$raw = base64_decode( substr( $encoded, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		try {
			$plain = sodium_crypto_secretbox_open( $box, $nonce, self::key() );
		} catch ( \Throwable $e ) {
			return '';
		}
		return false === $plain ? '' : $plain;
	}
}
