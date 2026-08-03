<?php
/**
 * HTTP response value object.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Decoded JSON response from a provider endpoint.
 */
final readonly class Response {

	/**
	 * @param array<string, mixed> $data         Decoded body, empty when unchanged.
	 * @param bool                 $not_modified True when upstream reported no change since the cached ETag.
	 */
	public function __construct(
		public array $data,
		public bool $not_modified = false,
	) {}

	/**
	 * The answer to a conditional request the server had nothing new for.
	 */
	public static function unchanged(): self {
		return new self( array(), true );
	}

	/**
	 * The `data` envelope of a paginated payload.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function items(): array {
		$items = $this->data['data'] ?? array();

		return is_array( $items ) ? $items : array();
	}

	/**
	 * The `meta` envelope of a paginated payload.
	 *
	 * @return array<string, mixed>
	 */
	private function meta(): array {
		$meta = $this->data['meta'] ?? array();

		return is_array( $meta ) ? $meta : array();
	}

	public function is_last_page(): bool {
		$meta = $this->meta();

		if ( ! isset( $meta['current_page'], $meta['last_page'] ) ) {
			return true;
		}

		return (int) $meta['current_page'] >= (int) $meta['last_page'];
	}
}
