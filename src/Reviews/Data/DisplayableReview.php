<?php
/**
 * Render-ready review.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Data;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * A stored review with its editor overrides already applied.
 *
 * FetchedReview is the inbound boundary (what a provider produced); this is the outbound one
 * (what a template consumes). Themes never touch meta keys or resolve overrides
 * themselves — they read this.
 */
final readonly class DisplayableReview {

	public function __construct(
		public int $post_id,
		public int $rating,
		public string $comment,
		public string $author_name,
		public ?int $avatar_id,
		public ?string $avatar_url,
		public DateTimeImmutable $reviewed_at,
		public ?string $reply_comment = null,
		public ?DateTimeImmutable $reply_published_at = null,
		public string $provider = '',
		public bool $is_featured = false,
	) {}

	public function has_reply(): bool {
		return null !== $this->reply_comment && '' !== trim( $this->reply_comment );
	}

	public function has_comment(): bool {
		return '' !== trim( $this->comment );
	}

	public function has_avatar(): bool {
		return null !== $this->avatar_id || null !== $this->avatar_url;
	}

	/**
	 * Localised publication date.
	 *
	 * Displaying it is not cosmetic: a dated review is verifiable against the public
	 * listing, an undated one is just a claim.
	 */
	public function formatted_date( ?string $format = null ): string {
		return wp_date( $format ?? (string) get_option( 'date_format' ), $this->reviewed_at->getTimestamp() );
	}

	/**
	 * Publication date as a distance while that still means something.
	 *
	 * "3 months ago" tells a reader the review is current far quicker than a date does.
	 * Past a year the distance stops helping, so $format takes over.
	 */
	public function humanised_date( ?string $format = null ): string {
		$timestamp = $this->reviewed_at->getTimestamp();

		if ( time() - $timestamp > YEAR_IN_SECONDS ) {
			return $this->formatted_date( $format );
		}

		return sprintf(
			/* translators: %s: human readable time difference, e.g. "3 months". */
			__( '%s ago', 'hallie' ),
			human_time_diff( $timestamp )
		);
	}

	/**
	 * Machine-readable date for `datetime` attributes and JSON-LD.
	 */
	public function iso_date(): string {
		return $this->reviewed_at->format( 'c' );
	}

	/**
	 * Avatar markup, from wherever the picture lives.
	 *
	 * Hosting is a setting. Hosted, the picture is an attachment and WordPress renders it —
	 * srcset, and the loading and fetchpriority it judges right for where the image sits;
	 * otherwise there is none to hand over and the tag is built by hand.
	 *
	 * The alt is what an editor wrote on the picture, else the author's name. Pass
	 * `alt => ''` when the theme already prints that name beside it.
	 *
	 * @param string                $size Registered image size.
	 * @param array<string, string> $attr Extra image attributes.
	 */
	public function avatar_html( string $size = 'thumbnail', array $attr = array() ): string {
		$attr = array_merge(
			array( 'alt' => $this->written_alt() ?? $this->author_name ),
			$attr
		);

		if ( null !== $this->avatar_id ) {
			return (string) wp_get_attachment_image( $this->avatar_id, $size, false, $attr );
		}

		if ( null === $this->avatar_url ) {
			return '';
		}

		return $this->image_hosted_elsewhere( $attr );
	}

	/**
	 * An `<img>` for a file the site does not hold.
	 *
	 * No srcset can be offered for a file nothing is known about, and referrerpolicy keeps
	 * the visitor's current page out of the source's logs. Lazy unless the caller says
	 * otherwise, since there is no attachment for WordPress to reason about.
	 *
	 * @param array<string, string> $attr Image attributes, defaults already applied.
	 */
	private function image_hosted_elsewhere( array $attr ): string {
		$attr = array_merge( array( 'loading' => 'lazy' ), $attr );

		$attr['src']            = $this->avatar_url;
		$attr['referrerpolicy'] = 'no-referrer';

		return sprintf( '<img %s />', $this->attributes( $attr ) );
	}

	/**
	 * The alt text stored on the imported picture, if anyone wrote one.
	 */
	private function written_alt(): ?string {
		if ( null === $this->avatar_id ) {
			return null;
		}

		$alt = (string) get_post_meta( $this->avatar_id, '_wp_attachment_image_alt', true );

		return '' !== $alt ? $alt : null;
	}

	/**
	 * @param array<string, string> $attr Image attributes.
	 */
	private function attributes( array $attr ): string {
		$pairs = array();

		foreach ( $attr as $name => $value ) {
			$pairs[] = sprintf( '%s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}

		return implode( ' ', $pairs );
	}

	/**
	 * Initials fallback, for reviews left without a profile picture.
	 */
	public function initials(): string {
		$words    = preg_split( '/\s+/', trim( $this->author_name ) );
		$words    = is_array( $words ) ? $words : array();
		$initials = '';

		foreach ( array_slice( $words, 0, 2 ) as $word ) {
			$initials .= mb_strtoupper( mb_substr( $word, 0, 1 ) );
		}

		return $initials;
	}
}
