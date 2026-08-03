<?php
/**
 * Translation of upstream payloads into plugin objects.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Provider;

use DateTimeImmutable;
use Exception;
use Hallie\Reviews\Data\BusinessProfile;
use Hallie\Reviews\Data\FetchedReview;

defined( 'ABSPATH' ) || exit;

/**
 * Turns raw API payloads into the objects the rest of the plugin understands.
 *
 * Kept apart from the provider because this is the part a new source actually has to
 * rewrite: field names, date formats and the quirks of one API belong here, whereas
 * speaking HTTP and declaring a settings form barely change from one source to the next.
 * It also makes the awkward cases — a machine-translated review, a missing date — testable
 * without a single request.
 */
final readonly class PayloadMapper {

	public function __construct( private string $provider_id ) {}

	/**
	 * @param array<string, mixed> $item Raw profile payload.
	 */
	public function profile( array $item ): BusinessProfile {
		$rating = isset( $item['rating'] ) && is_array( $item['rating'] ) ? $item['rating'] : array();

		return new BusinessProfile(
			id: (string) ( $item['id'] ?? '' ),
			name: (string) ( $item['name'] ?? '' ),
			address: $this->text( $item['address'] ?? null ),
			rating_average: (float) ( $rating['average'] ?? 0 ),
			rating_count: (int) ( $rating['count'] ?? 0 ),
			place_id: $this->text( $item['place_id'] ?? null ),
			review_url: $this->text( $item['review_url'] ?? null ),
			profile_url: $this->text( $item['profile_url'] ?? null ),
			provider: $this->provider_id,
		);
	}

	/**
	 * A review, or null when the payload lacks what identifies and dates it.
	 *
	 * @param array<string, mixed> $item Raw review payload.
	 */
	public function review( array $item ): ?FetchedReview {
		$external_id = (string) ( $item['id'] ?? '' );
		$reviewed_at = $this->date( $item['reviewed_at'] ?? null );

		if ( '' === $external_id || null === $reviewed_at ) {
			return null;
		}

		$author = isset( $item['author'] ) && is_array( $item['author'] ) ? $item['author'] : array();
		$reply  = isset( $item['reply'] ) && is_array( $item['reply'] ) ? $item['reply'] : array();

		return new FetchedReview(
			external_id: $external_id,
			rating: (int) ( $item['rating'] ?? 0 ),
			comment: $this->authored_text( (string) ( $item['comment'] ?? '' ) ),
			author_name: isset( $author['name'] ) ? (string) $author['name'] : null,
			author_photo_url: isset( $author['photo_url'] ) ? (string) $author['photo_url'] : null,
			reviewed_at: $reviewed_at,
			updated_at: $this->date( $item['updated_at'] ?? null ),
			reply_comment: isset( $reply['comment'] ) ? (string) $reply['comment'] : null,
			reply_published_at: $this->date( $reply['published_at'] ?? null ),
			provider: $this->provider_id,
		);
	}

	/**
	 * The text its author actually wrote, without Google's machine translation.
	 *
	 * When a review is not in the listing's language, Google returns both versions in one
	 * field, separated by a marker. Publishing that verbatim shows every review twice, in
	 * two languages, and puts both into the structured data.
	 *
	 * Two shapes exist depending on which side Google considers primary:
	 *
	 *     original\n\n(Translated by Google) translation
	 *     (Translated by Google) translation\n\n(Original) original
	 */
	private function authored_text( string $comment ): string {
		$original = $this->segment_after( $comment, '(Original)' );

		if ( null !== $original ) {
			return $original;
		}

		$before_translation = $this->segment_before( $comment, '(Translated by Google)' );

		/**
		 * Filters the review text kept when the source supplies several languages.
		 *
		 * @param string $text    Text about to be stored.
		 * @param string $comment Raw value as published by the source.
		 */
		return (string) apply_filters(
			'hallie_review_text',
			$before_translation ?? $comment,
			$comment
		);
	}

	private function segment_after( string $comment, string $marker ): ?string {
		$position = strpos( $comment, $marker );

		if ( false === $position ) {
			return null;
		}

		$text = trim( substr( $comment, $position + strlen( $marker ) ) );

		return '' !== $text ? $text : null;
	}

	private function segment_before( string $comment, string $marker ): ?string {
		$position = strpos( $comment, $marker );

		if ( false === $position ) {
			return null;
		}

		$text = trim( substr( $comment, 0, $position ) );

		return '' !== $text ? $text : null;
	}

	private function text( mixed $value ): ?string {
		return is_string( $value ) && '' !== $value ? $value : null;
	}


	private function date( mixed $value ): ?DateTimeImmutable {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $value );
		} catch ( Exception ) {
			return null;
		}
	}
}
