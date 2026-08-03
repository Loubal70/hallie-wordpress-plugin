<?php
/**
 * One page of reviews as a provider read it.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Data;

defined( 'ABSPATH' ) || exit;

/**
 * What a provider brought back, and whether anything follows it.
 *
 * The two travel together on purpose. A provider drops payloads it cannot map, so the
 * number of reviews handed over says nothing about how much is left upstream: a caller
 * inferring "fewer than asked for, so this was the last page" would stop mid-listing and
 * treat everything it never read as gone. Only the provider holds the pagination it was
 * given, so only the provider may answer.
 */
final readonly class ReviewPage {

	/**
	 * @param FetchedReview[] $reviews  Reviews this page yielded, already mapped.
	 * @param bool            $has_more Whether the source reports further pages.
	 */
	public function __construct(
		public array $reviews,
		public bool $has_more,
	) {}

	public function is_empty(): bool {
		return array() === $this->reviews;
	}
}
