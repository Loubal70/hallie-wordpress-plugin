<?php
/**
 * Scriptable provider for tests.
 *
 * @package Hallie
 */

declare(strict_types=1);

use Hallie\Reviews\Contract\ReviewProvider;
use Hallie\Reviews\Data\BusinessProfile;
use Hallie\Reviews\Data\ReviewPage;
use Hallie\Reviews\Data\FetchedReview;

/**
 * A provider whose answers are set by the test.
 *
 * Lets the synchroniser be exercised over its real edge cases — an unchanged source, a
 * review edited upstream, one that disappeared — without any HTTP.
 */
final class Hallie_Fake_Provider implements ReviewProvider {

	/** @var array<int, FetchedReview[]|null> Answers handed out, one per fetch call. */
	private array $answers = array();

	private int $call = 0;

	/**
	 * @param FetchedReview[]|null $reviews Reviews for the first page; null means unchanged.
	 */
	public function will_return( ?array $reviews ): void {
		// A page of results is always followed by an empty one, which ends the walk.
		$this->answers = null === $reviews ? array( null ) : array( $reviews, array() );
		$this->call    = 0;
	}

	public function id(): string {
		return 'fake';
	}

	public function label(): string {
		return 'Fake';
	}

	public function platform_name(): string {
		return 'Fakebook';
	}

	public function supports_sync(): bool {
		return true;
	}

	public function is_configured(): bool {
		return true;
	}

	public function fetch_profile( string $profile_id ): ?BusinessProfile {
		return new BusinessProfile(
			id: 'fake-profile',
			name: 'Fake Business',
			rating_average: 4.7,
			rating_count: 213,
			provider: $this->id(),
		);
	}

	public function fetch_reviews( string $profile_id, int $page = 1, int $per_page = 100 ): ?ReviewPage {
		$answer = $this->answers[ $this->call ] ?? array();

		++$this->call;

		// Null keeps meaning "unchanged". A bare array is the common case — one page with
		// nothing after it — while will_return_page() scripts the rest.
		if ( null === $answer ) {
			return null;
		}

		return $answer instanceof ReviewPage ? $answer : new ReviewPage( $answer, false );
	}

	/**
	 * Append one page, along with whether the source reports more after it.
	 *
	 * Unlike will_return(), which scripts a whole run at once, this stacks: call it once
	 * per page to describe a walk that spans several.
	 *
	 * @param FetchedReview[] $reviews  Reviews this page yields.
	 * @param bool            $has_more Whether further pages remain.
	 */
	public function will_return_page( array $reviews, bool $has_more ): void {
		$this->answers[] = new ReviewPage( $reviews, $has_more );
	}

	public function documentation_url(): ?string {
		return null;
	}

	public function field_options( string $key ): array {
		return array();
	}

	public function settings_fields(): array {
		return array();
	}
}
