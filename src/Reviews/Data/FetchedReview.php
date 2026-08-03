<?php
/**
 * Normalised review DTO.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Data;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-agnostic representation of a single review.
 *
 * This is the abstraction boundary: nothing outside of Reviews\Provider knows where a
 * review came from. Swapping the upstream API means writing a new mapper to this shape.
 */
final readonly class FetchedReview {

	/**
	 * @param string                 $external_id        Stable upstream identifier, used as the upsert key.
	 * @param int                    $rating             Star rating, 1 to 5.
	 * @param string                 $comment            Review body as published upstream. Never rewritten locally.
	 * @param string|null            $author_name        Null when the review was posted anonymously.
	 * @param string|null            $author_photo_url   Remote avatar, side-loaded at sync time.
	 * @param DateTimeImmutable      $reviewed_at        First publication date.
	 * @param DateTimeImmutable|null $updated_at         Last upstream edit, when known.
	 * @param string|null            $reply_comment      Owner reply, when the business answered.
	 * @param DateTimeImmutable|null $reply_published_at Owner reply date.
	 * @param string                 $provider           Identifier of the provider that produced this DTO.
	 */
	public function __construct(
		public string $external_id,
		public int $rating,
		public string $comment,
		public ?string $author_name,
		public ?string $author_photo_url,
		public DateTimeImmutable $reviewed_at,
		public ?DateTimeImmutable $updated_at = null,
		public ?string $reply_comment = null,
		public ?DateTimeImmutable $reply_published_at = null,
		public string $provider = '',
	) {}



	/**
	 * Anonymous reviews carry no author name upstream; callers decide how to label them.
	 */
	public function is_anonymous(): bool {
		return null === $this->author_name || '' === trim( $this->author_name );
	}

	/**
	 * Last moment the upstream content changed, falling back to publication date.
	 */
	public function changed_at(): DateTimeImmutable {
		return $this->updated_at ?? $this->reviewed_at;
	}
}
