<?php
defined( 'ABSPATH' ) || exit;

/**
 * Encrypts API keys before they are stored in wp_options (libsodium secretbox).
 * The key is derived from WordPress' AUTH salt, so a database dump alone is not enough to read secrets.
 */
final class Matin_AI_Secrets {
	private const PREFIX = 'enc:v1:';

	public static function encrypt( string $plain, string $material ) : string {
		if ( '' === $plain ) {
			return '';
		}
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return $plain; // libsodium is missing: keep the value readable rather than losing it.
		}
		try {
			$key    = self::derive( $material );
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, $key );
			self::wipe( $key );
			return self::PREFIX . base64_encode( $nonce . $cipher );
		} catch ( \Throwable $e ) {
			// Never let a crypto failure take the whole site down; keep the value and let the admin see the readiness notice.
			return $plain;
		}
	}

	/**
	 * Wipes a buffer only when the real libsodium extension is loaded.
	 * sodium_compat (the pure-PHP fallback) throws on sodium_memzero, which would fatal the site.
	 */
	private static function wipe( string &$buffer ) : void {
		if ( extension_loaded( 'sodium' ) && function_exists( 'sodium_memzero' ) ) {
			try {
				sodium_memzero( $buffer );
			} catch ( \Throwable $e ) {
				// Best effort only.
			}
		}
		$buffer = '';
	}

	/** Returns the plain value, or '' when a stored value cannot be decrypted with the current salt. */
	public static function decrypt( string $stored, string $material ) : string {
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return $stored; // Legacy plaintext value; re-saved encrypted on the next admin save.
		}
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		try {
			$key   = self::derive( $material );
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			self::wipe( $key );
		} catch ( \Throwable $e ) {
			return '';
		}
		return false === $plain ? '' : $plain;
	}

	public static function is_encrypted( string $stored ) : bool {
		return 0 === strpos( $stored, self::PREFIX );
	}

	private static function derive( string $material ) : string {
		return hash( 'sha256', 'matin-ai-secrets|' . $material, true );
	}
}
