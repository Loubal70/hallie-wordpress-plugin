<?php
/**
 * Synchronisation outcome.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Tally of what a sync run did, surfaced in the admin and in WP-CLI output.
 */
final class SyncResult {

	public int $created = 0;

	public int $updated = 0;

	public int $unchanged = 0;


	/**
	 * Reviews deleted because they belong to another business profile.
	 *
	 * @var int
	 */
	public int $removed = 0;

	/** @var string[] */
	public array $errors = array();

	public bool $not_modified = false;

	/**
	 * True when a batch was handled and more remain.
	 *
	 * @var bool
	 */
	public bool $in_progress = false;

	public function add_error( string $message ): void {
		$this->errors[] = $message;
	}

	public function has_errors(): bool {
		return ! empty( $this->errors );
	}

	public function summary(): string {
		if ( $this->not_modified ) {
			return __( 'Nothing changed upstream.', 'hallie' );
		}

		if ( $this->in_progress ) {
			return sprintf(
				/* translators: %d: number of reviews imported so far. */
				__( '%d imported so far, still working…', 'hallie' ),
				$this->created + $this->updated + $this->unchanged
			);
		}

		$summary = sprintf(
			/* translators: 1: created count, 2: updated count, 3: unchanged count. */
			__( '%1$d added, %2$d updated, %3$d unchanged.', 'hallie' ),
			$this->created,
			$this->updated,
			$this->unchanged
		);

		if ( 0 === $this->removed ) {
			return $summary;
		}

		return $summary . ' ' . sprintf(
			/* translators: %d: number of reviews deleted. */
			__( '%d deleted, no longer among the reviews kept.', 'hallie' ),
			$this->removed
		);
	}
}
