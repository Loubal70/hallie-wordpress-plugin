<?php
/**
 * Progress of a sync spread over several runs.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Where a sync got to, so the next run picks up rather than starts over.
 *
 * A listing with thousands of reviews cannot be imported in one request: PHP would hit
 * its time or memory limit somewhere in the middle, and nothing would tell the next
 * attempt what had already been done. Each run therefore handles one batch and records
 * its position.
 */
final class SyncState {

	private const string OPTION = 'hallie_sync_state';

	private function __construct(
		public int $page,
		public int $imported,
		public string $run_id,
		public int $created = 0,
		public int $updated = 0,
		public int $unchanged = 0,
	) {}

	/**
	 * Begin a fresh walk.
	 *
	 * The run id stamps every review this walk touches, which is what lets the final pass
	 * tell "still published upstream" from "gone" without holding thousands of ids in an
	 * option.
	 */
	public static function start(): self {
		return new self( 1, 0, uniqid( 'run_', true ) );
	}

	/**
	 * The walk in progress, if any.
	 */
	public static function resume(): ?self {
		$stored = get_option( self::OPTION );

		if ( ! is_array( $stored ) || ! isset( $stored['page'], $stored['run_id'] ) ) {
			return null;
		}

		return new self(
			(int) $stored['page'],
			(int) ( $stored['imported'] ?? 0 ),
			(string) $stored['run_id'],
			(int) ( $stored['created'] ?? 0 ),
			(int) ( $stored['updated'] ?? 0 ),
			(int) ( $stored['unchanged'] ?? 0 ),
		);
	}

	/**
	 * Move to the next page, carrying this batch's tally forward.
	 */
	public function advance( SyncResult $batch ): void {
		++$this->page;

		$this->created   += $batch->created;
		$this->updated   += $batch->updated;
		$this->unchanged += $batch->unchanged;
	}

	/**
	 * Fold everything counted so far into the result handed back to the caller.
	 */
	public function apply_totals_to( SyncResult $result ): void {
		$result->created   = $this->created;
		$result->updated   = $this->updated;
		$result->unchanged = $this->unchanged;
	}

	public function save(): void {
		update_option(
			self::OPTION,
			array(
				'page'      => $this->page,
				'imported'  => $this->imported,
				'run_id'    => $this->run_id,
				'created'   => $this->created,
				'updated'   => $this->updated,
				'unchanged' => $this->unchanged,
			),
			false
		);
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
