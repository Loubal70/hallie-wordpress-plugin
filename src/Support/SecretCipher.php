<?php
/**
 * Secret encryption helper.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts short secrets so a provider token is never stored in clear text.
 *
 * Authenticated XChaCha20-Poly1305, keyed from HALLIE_ENCRYPTION_KEY when defined — which
 * keeps the key out of the database — and from the auth salt otherwise.
 */
final class SecretCipher {

	private const string CONTEXT = 'hallie:provider-token';

	/** Stands in for the hidden part of a secret, and marks a value as being a mask. */
	private const string HIDDEN = '•';

	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$key        = self::key();
		$nonce      = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plaintext, self::CONTEXT, $nonce, $key );

		sodium_memzero( $key );

		return base64_encode( $nonce . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding ciphertext, not obfuscating code.
	}

	/**
	 * Whether a stored value exists but can no longer be read.
	 *
	 * Without HALLIE_ENCRYPTION_KEY the key derives from the auth salt, so rotating the
	 * salts — a routine security measure — makes every stored secret undecryptable. Left
	 * undetected, that looks exactly like "no token configured" and syncing stops in
	 * silence.
	 */
	public static function is_unreadable( string $stored ): bool {
		return '' !== $stored && '' === self::decrypt( $stored );
	}

	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		$decoded = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding ciphertext, not obfuscating code.

		if ( false === $decoded || strlen( $decoded ) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES ) {
			return '';
		}

		$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$ciphertext = substr( $decoded, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$key        = self::key();
		$plaintext  = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $ciphertext, self::CONTEXT, $nonce, $key );

		sodium_memzero( $key );

		return is_string( $plaintext ) ? $plaintext : '';
	}

	/**
	 * Masked form for display, so a saved token can be shown without leaking it.
	 */
	public static function mask( string $plaintext ): string {
		$length = strlen( $plaintext );

		if ( $length <= 4 ) {
			return str_repeat( self::HIDDEN, max( 0, $length ) );
		}

		return str_repeat( self::HIDDEN, 8 ) . substr( $plaintext, -4 );
	}

	/**
	 * Whether a value is one of the masks above rather than a secret.
	 *
	 * Asked here because this is where the mask is shaped; a caller testing for the
	 * character itself would silently stop matching the day that character changes.
	 */
	public static function is_mask( string $value ): bool {
		return str_contains( $value, self::HIDDEN );
	}

	private static function key(): string {
		$material = defined( 'HALLIE_ENCRYPTION_KEY' ) && '' !== (string) HALLIE_ENCRYPTION_KEY
			? (string) HALLIE_ENCRYPTION_KEY
			: wp_salt( 'auth' );

		return substr( hash( 'sha256', $material, true ), 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES );
	}
}
