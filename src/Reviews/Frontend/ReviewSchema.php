<?php
/**
 * Structured data payload.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * The structured data a page has to publish, independent of how it gets published.
 *
 * Building the nodes and placing them in the page are two different jobs: the same
 * payload is either grafted onto an existing SEO plugin's graph or printed on its own.
 */
final readonly class ReviewSchema {

	/**
	 * @param array<int, array<string, mixed>> $reviews      One node per review actually rendered.
	 * @param array<string, mixed>|null        $aggregate    AggregateRating node, only when the page shows it.
	 * @param string|null                      $listing_url  Public listing, for `sameAs`.
	 * @param string                           $business_type Schema type describing the business.
	 */
	public function __construct(
		public array $reviews,
		public ?array $aggregate = null,
		public ?string $listing_url = null,
		public string $business_type = 'LocalBusiness',
	) {}

	public function is_empty(): bool {
		return array() === $this->reviews;
	}

	public function has_aggregate(): bool {
		return null !== $this->aggregate;
	}
}
