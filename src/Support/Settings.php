<?php
/**
 * Plugin settings store.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Thin accessor over the single option row holding every plugin setting.
 *
 * Values flagged as secret are transparently encrypted on write and decrypted on read,
 * so callers never deal with ciphertext.
 */
final class Settings {

	public const string OPTION = 'hallie_settings';

	/** Setting keys whose value is encrypted at rest. */
	private const array SECRET_KEYS = array( 'provider_token' );

	private const array DEFAULTS = array(
		'provider'       => 'hallie',
		'provider_token' => '',
		'profile_id'     => '',
		'sync_limit'     => 200,
		'sync_interval'  => 'hallie_six_hours',
		'avatars'        => 'none',
		'schema_emitter' => 'auto',
		'last_sync_at'   => '',
		// Opt-in: uninstalling removes configuration either way, but never content unless
		// the site owner asked for it.
		'delete_data'    => false,
	);

	/**
	 * Every setting this version knows about, filled in from what is stored.
	 *
	 * DEFAULTS is the allowlist on the way out as much as on the way in. A key dropped from
	 * a later release stays in the row it was written to, and without this would keep being
	 * handed to the settings screen and read on every front-end request.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return array_intersect_key(
			array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() ),
			self::DEFAULTS
		);
	}

	public static function get( string $key, mixed $fallback = null ): mixed {
		$settings = self::all();

		if ( ! array_key_exists( $key, $settings ) ) {
			return $fallback;
		}

		$value = $settings[ $key ];

		if ( in_array( $key, self::SECRET_KEYS, true ) && is_string( $value ) ) {
			return SecretCipher::decrypt( $value );
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $values Raw values, secrets in clear text.
	 */
	public static function update( array $values ): void {
		$settings = get_option( self::OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();

		foreach ( $values as $key => $value ) {
			if ( in_array( $key, self::SECRET_KEYS, true ) && is_string( $value ) ) {
				$value = SecretCipher::encrypt( $value );
			}

			$settings[ $key ] = $value;
		}

		// Autoloaded: read on every front-end request by the schema emitter resolution.
		update_option( self::OPTION, $settings, true );
	}

	/**
	 * Keep only keys this plugin actually declares.
	 *
	 * @param array<string, mixed> $values Untrusted input.
	 *
	 * @return array<string, mixed>
	 */
	public static function only_known( array $values ): array {
		return array_intersect_key( $values, self::DEFAULTS );
	}
}
