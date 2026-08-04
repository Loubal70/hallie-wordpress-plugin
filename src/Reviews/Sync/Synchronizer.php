<?php
/**
 * Review synchroniser.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Sync;

use Hallie\Http\EtagStore;
use Hallie\Reviews\Contract\ReviewProvider;
use Hallie\Reviews\Data\BusinessProfile;
use Hallie\Reviews\Data\FetchedReview;
use Hallie\Reviews\Data\ReviewPage;
use Hallie\Reviews\Exception\ProviderException;
use Hallie\Reviews\AvatarMode;
use Hallie\Reviews\MetaKeys;
use Hallie\Reviews\PostType\ReviewPostType;
use Hallie\Reviews\Repository;
use Hallie\Support\Settings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Pulls reviews from the active provider into the post type.
 *
 * The stored set is a mirror of the newest reviews upstream, never an archive: anything a
 * run does not bring back is deleted. Editor overrides are never written here, so a review
 * can be rewritten from upstream without losing its custom photo.
 */
final class Synchronizer {

	public const string PROFILE_OPTION = 'hallie_profile';

	private const int PER_PAGE = 100;

	/**
	 * Ceiling on every bulk operation, deletions included.
	 *
	 * Deleting a review costs several queries plus its avatar, so a listing of a hundred
	 * thousand would never finish in one request. Each pass handles a slice and asks for
	 * another, exactly like the import.
	 */
	private const int BATCH_SIZE = 100;

	/** Guard against an endless walk if a provider never reports an empty page. */
	private const int MAX_PAGES = 100;

	public function __construct(
		private readonly ReviewProvider $provider,
		private readonly Repository $repository = new Repository(),
		private readonly AvatarImporter $avatars = new AvatarImporter(),
	) {}

	/**
	 * Carry the walk forward by one batch.
	 *
	 * A single call is never the whole job: it resumes where the last one stopped, handles
	 * one page, and either asks for another pass or closes the walk. Spreading it this way
	 * is what lets a listing of any size sync without a request running long.
	 *
	 * The order matters. Reviews belonging to another listing go first, or the page would
	 * show two businesses at once until the deletion caught up. Reconciliation comes last
	 * and only on the final pass, because deciding what is gone requires having read
	 * everything — which is also why an unchanged source stops here rather than reconciling
	 * from pages it never saw.
	 */
	public function run(): SyncResult {
		$result     = new SyncResult();
		$state      = SyncState::resume() ?? SyncState::start();
		$profile_id = (string) Settings::get( 'profile_id', '' );

		// Reviews from another listing go first: importing on top of them would mix two
		// businesses on the page until the deletion caught up.
		if ( 1 === $state->page && ! $this->forget_other_profiles( $profile_id, $result ) ) {
			return $this->pause( $state, $result );
		}

		// Pictures the current mode no longer wants. Asked for rather than done here: the
		// background job clears them a batch at a time without holding up this run.
		if ( ! AvatarMode::current()->stores_files() ) {
			Scheduler::drain_soon();
		}

		try {
			if ( 1 === $state->page ) {
				$this->sync_profile( $profile_id );
			}

			$page = $this->provider->fetch_reviews( $profile_id, $state->page, self::PER_PAGE );
		} catch ( ProviderException $exception ) {
			return $this->abort( $exception, $result );
		}

		// A 304 on any page leaves the pages after it unread, so the upstream set is unknown
		// and nothing may be reconciled from it.
		if ( null === $page ) {
			SyncState::clear();
			$result->not_modified = true;
			$this->record_run();

			return $result;
		}

		foreach ( $page->reviews as $review ) {
			if ( $this->reached_limit( $state ) ) {
				break;
			}

			$this->upsert( $review, $result, $state->run_id );
			++$state->imported;
		}

		$state->advance( $result );

		if ( $this->has_more_to_do( $page, $state ) ) {
			return $this->pause( $state, $result );
		}

		$state->apply_totals_to( $result );

		if ( ! $this->delete_reviews_this_walk_missed( $state->run_id, $result ) ) {
			return $this->pause( $state, $result );
		}

		SyncState::clear();
		$this->record_run();

		return $result;
	}

	/**
	 * Hand back after one batch, with the next already asked for.
	 */
	private function pause( SyncState $state, SyncResult $result ): SyncResult {
		$state->save();
		$state->apply_totals_to( $result );

		$this->continue_soon();

		$result->in_progress = true;

		$this->record_run();

		return $result;
	}

	/**
	 * Give up on this run, leaving no stale progress behind.
	 */
	private function abort( ProviderException $exception, SyncResult $result ): SyncResult {
		$result->add_error( $exception->getMessage() );

		// Pages read before the failure already banked their validator; keeping them would
		// make the next run see 304s and conclude there is nothing to do.
		new EtagStore()->forget_everything();
		SyncState::clear();

		if ( $exception->is_rate_limited() ) {
			Scheduler::back_off( $exception->retry_after() );
		}

		$this->record_run();

		return $result;
	}

	private function reached_limit( SyncState $state ): bool {
		$limit = $this->keep_at_most();

		return 0 !== $limit && $state->imported >= $limit;
	}

	private function has_more_to_do( ReviewPage $page, SyncState $state ): bool {
		if ( $page->is_empty() || ! $page->has_more || $this->reached_limit( $state ) ) {
			return false;
		}

		return $state->page <= self::MAX_PAGES;
	}

	/**
	 * Ask WordPress to run the next batch as soon as it can.
	 */
	private function continue_soon(): void {
		if ( ! wp_next_scheduled( Scheduler::HOOK ) || wp_next_scheduled( Scheduler::HOOK ) > time() + MINUTE_IN_SECONDS ) {
			wp_schedule_single_event( time(), Scheduler::HOOK );
		}
	}

	/**
	 * Store the upstream aggregate rating.
	 *
	 * This is what the front end displays, and it must never be a hand-typed number: a
	 * rating that does not match the public listing destroys the credibility of every
	 * review shown next to it.
	 */
	private function sync_profile( string $profile_id ): void {
		$profile = $this->provider->fetch_profile( $profile_id );

		if ( ! $profile instanceof BusinessProfile ) {
			return;
		}

		update_option(
			self::PROFILE_OPTION,
			array(
				'id'             => $profile->id,
				'name'           => $profile->name,
				'address'        => $profile->address,
				'rating_average' => $profile->rating_average,
				'rating_count'   => $profile->rating_count,
				'place_id'       => $profile->place_id,
				'review_url'     => $profile->review_url,
				'profile_url'    => $profile->profile_url,
				'provider'       => $profile->provider,
			),
			true
		);
	}


	/**
	 * How many reviews to keep, newest first. Zero means all of them.
	 *
	 * A listing with thousands of reviews would otherwise mean thousands of posts and as
	 * many downloaded pictures, to display a dozen. The advertised total stays right
	 * regardless: it comes from the source, never from what is stored here.
	 */
	private function keep_at_most(): int {
		return max( 0, (int) Settings::get( 'sync_limit', 200 ) );
	}

	/**
	 * Create or refresh a single review, keyed on its upstream identifier.
	 */
	private function upsert( FetchedReview $review, SyncResult $result, string $run_id ): void {
		$existing = $this->repository->find_by_external_id( $review->external_id, $review->provider );

		$post_id = $existing instanceof WP_Post
			? $this->update( $existing, $review, $result )
			: $this->create( $review, $result );

		if ( $post_id > 0 ) {
			update_post_meta( $post_id, MetaKeys::SYS_SYNC_RUN, $run_id );
		}
	}

	private function create( FetchedReview $review, SyncResult $result ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'     => ReviewPostType::SLUG,
				'post_status'   => 'publish',
				'post_title'    => $this->display_name_for( $review ),
				'post_content'  => $review->comment,
				'post_date_gmt' => $review->reviewed_at->format( 'Y-m-d H:i:s' ),
				'post_date'     => get_date_from_gmt( $review->reviewed_at->format( 'Y-m-d H:i:s' ) ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			$result->add_error( $post_id->get_error_message() );

			return 0;
		}

		$this->write_source_meta( (int) $post_id, $review );
		$this->queue_avatar( (int) $post_id, $review );

		++$result->created;

		return (int) $post_id;
	}

	/**
	 * Refresh a stored review from what the source now says.
	 *
	 * Three things are deliberate here. A review can be unchanged upstream yet miss meta a
	 * later version of this plugin started storing, so an incomplete record is rewritten
	 * rather than skipped — which spares a migration routine. The title is never touched: it
	 * was set at creation and may since have been edited to choose how the author appears.
	 * And an edited body is stamped, so the screen can tell an editor their customisation
	 * may no longer fit what it was written for.
	 */
	private function update( WP_Post $post, FetchedReview $review, SyncResult $result ): int {
		$stored_updated_at = (string) get_post_meta( $post->ID, MetaKeys::SRC_UPDATED_AT, true );
		$incoming          = $review->changed_at()->format( 'c' );
		$body_changed      = $post->post_content !== $review->comment;

		if ( $incoming === $stored_updated_at && ! $body_changed && $this->source_meta_is_complete( $post ) ) {
			$this->queue_avatar( $post->ID, $review );

			++$result->unchanged;

			return $post->ID;
		}

		wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $review->comment,
			)
		);

		$this->write_source_meta( $post->ID, $review );

		if ( $body_changed ) {
			update_post_meta( $post->ID, MetaKeys::SYS_CONTENT_CHANGED_AT, gmdate( 'c' ) );
		}

		$this->queue_avatar( $post->ID, $review );

		++$result->updated;

		return $post->ID;
	}

	/**
	 * Whether the stored source meta covers everything the plugin records today.
	 *
	 * Only the keys every review carries are checked. Judging an optional one missing —
	 * an author with no picture is the common case — would rewrite that review on every
	 * sync and report an edit that never happened.
	 */
	private function source_meta_is_complete( WP_Post $post ): bool {
		foreach ( MetaKeys::required_source_keys() as $key ) {
			if ( '' === (string) get_post_meta( $post->ID, $key, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete reviews belonging to a listing that is no longer the configured one.
	 *
	 * Switching business profile does not make the previous reviews stale — it makes them
	 * someone else's. Drafting would leave them in the admin and their pictures on disk,
	 * so they go entirely, avatars included via the post type's delete hook.
	 *
	 * Reconciled on every walk rather than caught when the setting changes: a state that
	 * disagrees with the configuration then repairs itself, whatever caused it.
	 */
	private function forget_other_profiles( string $profile_id, SyncResult $result ): bool {
		if ( '' === $profile_id ) {
			return true;
		}

		$strays = get_posts(
			array(
				'post_type'              => ReviewPostType::SLUG,
				'post_status'            => ReviewPostType::every_status(),
				'posts_per_page'         => self::BATCH_SIZE,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Once per walk, on a private post type.
				'meta_query'             => array(
					array(
						'key'   => MetaKeys::SRC_PROVIDER,
						'value' => $this->provider->id(),
					),
					array(
						'key'     => MetaKeys::SRC_PROFILE_ID,
						'value'   => $profile_id,
						'compare' => '!=',
					),
				),
			)
		);

		foreach ( $strays as $post_id ) {
			wp_delete_post( (int) $post_id, true );

			++$result->removed;
		}

		return count( $strays ) < self::BATCH_SIZE;
	}

	/**
	 * Drop the reviews this walk did not bring back.
	 *
	 * The stored set mirrors the newest ones upstream, so falling out of it is routine:
	 * every review published on the listing pushes the oldest kept one out. Deleting is
	 * what keeps the mirror a mirror, and pictures go with it.
	 */
	private function delete_reviews_this_walk_missed( string $run_id, SyncResult $result ): bool {
		$surplus = $this->reviews_not_stamped( $run_id );

		foreach ( $surplus as $post_id ) {
			wp_delete_post( $post_id, true );

			++$result->removed;
		}

		return count( $surplus ) < self::BATCH_SIZE;
	}

	/**
	 * One batch of reviews the current walk did not touch.
	 *
	 * Identified by the run stamp rather than by a list of ids: a listing with thousands of
	 * reviews would otherwise mean carrying thousands of integers across every batch.
	 *
	 * A missing stamp counts as untouched, hence the OR. On its own, "!=" joins on the meta
	 * row and matches nothing when there is none — a review stored before this plugin wrote
	 * stamps would sit here forever, invisible to every pass.
	 *
	 * @return int[]
	 */
	private function reviews_not_stamped( string $run_id ): array {
		$post_ids = get_posts(
			array(
				'post_type'              => ReviewPostType::SLUG,
				'post_status'            => ReviewPostType::every_status(),
				'posts_per_page'         => self::BATCH_SIZE,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Scoped to the active provider, one batch per pass.
				'meta_query'             => array(
					array(
						'key'   => MetaKeys::SRC_PROVIDER,
						'value' => $this->provider->id(),
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => MetaKeys::SYS_SYNC_RUN,
							'value'   => $run_id,
							'compare' => '!=',
						),
						array(
							'key'     => MetaKeys::SYS_SYNC_RUN,
							'compare' => 'NOT EXISTS',
						),
					),
				),
			)
		);

		return array_map( intval( ... ), $post_ids );
	}


	/**
	 * Note that a picture is owed, without fetching it.
	 *
	 * Downloading here would put one remote round trip between each review and the next,
	 * holding the request open for as long as the source takes. Scheduler::HOOK drains the
	 * queue afterwards, so a sync returns as soon as the reviews themselves are stored.
	 */
	private function queue_avatar( int $post_id, FetchedReview $review ): void {
		$url = (string) $review->author_photo_url;

		if ( ! AvatarMode::current()->stores_files() || '' === $url ) {
			return;
		}

		if ( $this->avatars->already_imported( $post_id, $url ) ) {
			return;
		}

		// The URL, not a flag: the walk may have seen a rotation the source meta does not
		// carry yet, and the queue must fetch what was queued.
		update_post_meta( $post_id, MetaKeys::SYS_AVATAR_PENDING, $url );

		Scheduler::drain_soon();
	}

	private function write_source_meta( int $post_id, FetchedReview $review ): void {
		$values = array(
			MetaKeys::SRC_EXTERNAL_ID        => $review->external_id,
			MetaKeys::SRC_PROVIDER           => $review->provider,
			MetaKeys::SRC_PROFILE_ID         => (string) Settings::get( 'profile_id', '' ),
			MetaKeys::SRC_RATING             => $review->rating,
			MetaKeys::SRC_AUTHOR_NAME        => (string) $review->author_name,
			MetaKeys::SRC_AUTHOR_PHOTO_URL   => (string) $review->author_photo_url,
			MetaKeys::SRC_UPDATED_AT         => $review->changed_at()->format( 'c' ),
			MetaKeys::SRC_REPLY_COMMENT      => (string) $review->reply_comment,
			MetaKeys::SRC_REPLY_PUBLISHED_AT => $review->reply_published_at?->format( 'c' ) ?? '',
		);

		foreach ( $values as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}

	private function display_name_for( FetchedReview $review ): string {
		return $review->is_anonymous()
			? __( 'Anonymous', 'hallie' )
			: (string) $review->author_name;
	}

	private function record_run(): void {
		Settings::update( array( 'last_sync_at' => gmdate( 'c' ) ) );
	}
}
