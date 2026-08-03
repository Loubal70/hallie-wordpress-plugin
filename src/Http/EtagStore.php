<?php
/**
 * Conditional request validators.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers the ETag an endpoint last handed out, per URL.
 *
 * Replaying it lets the server answer "nothing changed" with a 304, which turns a sync
 * that has no work to do into a single cheap request instead of a full page walk.
 */
final class EtagStore {

	private const string PREFIX = 'hallie_etag_';

	private const int LIFETIME = WEEK_IN_SECONDS;

	public function find_for( string $url ): ?string {
		$validator = get_transient( $this->key_for( $url ) );

		if ( ! is_string( $validator ) || '' === $validator ) {
			return null;
		}

		return $validator;
	}

	public function remember_for( string $url, ?string $validator ): void {
		if ( null === $validator || '' === $validator ) {
			$this->forget_for( $url );

			return;
		}

		set_transient( $this->key_for( $url ), $validator, self::LIFETIME );
	}

	private function forget_for( string $url ): void {
		delete_transient( $this->key_for( $url ) );
	}

	/**
	 * Drop every stored validator.
	 *
	 * Called when a run fails partway: the pages already read would otherwise answer 304
	 * on the next attempt, which would look like "nothing changed" and stall the sync
	 * until the transients expire.
	 */
	public function forget_everything(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transients have no delete-by-prefix API.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::PREFIX ) . '%'
			)
		);

		foreach ( $names as $name ) {
			delete_transient( str_replace( '_transient_', '', (string) $name ) );
		}
	}

	private function key_for( string $url ): string {
		return self::PREFIX . md5( $url );
	}
}
