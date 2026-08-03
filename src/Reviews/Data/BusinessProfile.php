<?php
/**
 * Normalised business profile DTO.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-agnostic representation of the reviewed business.
 *
 * The aggregate rating carried here must always come from the provider, never from a
 * hand-typed field: a page claiming a rating that does not match the public listing is
 * the fastest way to lose credibility with both search engines and generative engines.
 */
final readonly class BusinessProfile {

	/**
	 * @param string      $id                Provider-side profile identifier.
	 * @param string      $name              Business display name.
	 * @param string|null $address           Formatted postal address.
	 * @param float       $rating_average    Average rating as published upstream.
	 * @param int         $rating_count      Total number of reviews upstream, including those not displayed.
	 * @param string|null $place_id          Google Place ID, only used to build a review link when none is supplied.
	 * @param string|null $review_url        Ready-made link to the review form, as published by the source.
	 * @param string|null $profile_url       Public listing URL, used for attribution and schema `sameAs`.
	 * @param string      $provider          Identifier of the provider that produced this DTO.
	 */
	public function __construct(
		public string $id,
		public string $name,
		public ?string $address = null,
		public float $rating_average = 0.0,
		public int $rating_count = 0,
		public ?string $place_id = null,
		public ?string $review_url = null,
		public ?string $profile_url = null,
		public string $provider = '',
	) {}

	public function has_ratings(): bool {
		return $this->rating_count > 0 && $this->rating_average > 0.0;
	}

	/**
	 * Direct link to the review form.
	 *
	 * The source's own link wins: it is authoritative and survives any change to the URL
	 * format. Building one from the Place ID is the fallback for sources that do not
	 * publish it. Null when neither is known, so callers hide the button rather than
	 * render a broken link.
	 */
	public function write_review_url(): ?string {
		if ( null !== $this->review_url ) {
			return $this->review_url;
		}

		if ( null === $this->place_id ) {
			return null;
		}

		return add_query_arg(
			'placeid',
			rawurlencode( $this->place_id ),
			'https://search.google.com/local/writereview'
		);
	}
}
