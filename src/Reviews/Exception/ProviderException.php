<?php
/**
 * Provider failure.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Exception;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Raised when a provider cannot reach or understand its upstream source.
 *
 * Carries the delay the server asked for, so the scheduler can back off instead of
 * hammering a rate-limited API.
 */
final class ProviderException extends RuntimeException {

	public function __construct( string $message, private readonly int $retry_after = 0 ) {
		parent::__construct( $message );
	}

	public function retry_after(): int {
		return max( 0, $this->retry_after );
	}

	public function is_rate_limited(): bool {
		return $this->retry_after > 0;
	}
}
