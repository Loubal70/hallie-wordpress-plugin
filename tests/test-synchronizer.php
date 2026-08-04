<?php
/**
 * Synchroniser behaviour.
 *
 * @package Hallie
 */

declare(strict_types=1);

use Hallie\Reviews\Data\FetchedReview;
use Hallie\Reviews\MetaKeys;
use Hallie\Reviews\PostType\ReviewPostType;
use Hallie\Reviews\Repository;
use Hallie\Reviews\Sync\Synchronizer;

require_once __DIR__ . '/includes/class-hallie-fake-provider.php';

/**
 * The synchroniser's contract with stored data.
 *
 * Each of these covers a way the sync could silently destroy work: overwriting an
 * editor's customisation, unpublishing the whole corpus, or duplicating everything.
 */
final class Test_Hallie_Synchronizer extends WP_UnitTestCase {

	private Hallie_Fake_Provider $provider;

	public function set_up(): void {
		parent::set_up();

		$this->provider = new Hallie_Fake_Provider();
	}

	public function test_it_creates_a_review_from_the_source(): void {
		$this->provider->will_return( array( $this->a_review( 'ext-1', 'Great bakery.' ) ) );

		$result = new Synchronizer( $this->provider )->run();

		$this->assertSame( 1, $result->created );
		$this->assertCount( 1, $this->stored_reviews() );
	}

	public function test_syncing_twice_does_not_duplicate(): void {
		$this->provider->will_return( array( $this->a_review( 'ext-1', 'Great bakery.' ) ) );
		new Synchronizer( $this->provider )->run();

		$this->provider->will_return( array( $this->a_review( 'ext-1', 'Great bakery.' ) ) );
		$result = new Synchronizer( $this->provider )->run();

		$this->assertSame( 0, $result->created );
		$this->assertSame( 1, $result->unchanged );
		$this->assertCount( 1, $this->stored_reviews() );
	}

	public function test_an_upstream_edit_keeps_the_editor_customisations(): void {
		$this->provider->will_return( array( $this->a_review( 'ext-1', 'Great bakery.' ) ) );
		new Synchronizer( $this->provider )->run();

		$post_id = $this->stored_reviews()[0];
		// The displayed name is the post title; there is no second field for the same thing.
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Camille Dupont',
			)
		);
		update_post_meta( $post_id, MetaKeys::OVR_FEATURED, '1' );

		$this->provider->will_return( array( $this->a_review( 'ext-1', 'Actually, the best in town.' ) ) );
		$result = new Synchronizer( $this->provider )->run();

		$this->assertSame( 1, $result->updated );
		$this->assertSame( 'Actually, the best in town.', get_post_field( 'post_content', $post_id ) );
		$this->assertSame( 'Camille Dupont', get_post_field( 'post_title', $post_id ) );
		$this->assertSame( '1', get_post_meta( $post_id, MetaKeys::OVR_FEATURED, true ) );
		$this->assertNotSame( '', get_post_meta( $post_id, MetaKeys::SYS_CONTENT_CHANGED_AT, true ) );
	}

	public function test_a_review_no_longer_returned_is_deleted(): void {
		$this->provider->will_return(
			array(
				$this->a_review( 'ext-1', 'Still here.' ),
				$this->a_review( 'ext-2', 'About to vanish.' ),
			)
		);
		new Synchronizer( $this->provider )->run();

		$vanishing = $this->find_by_external_id( 'ext-2' );

		$this->provider->will_return( array( $this->a_review( 'ext-1', 'Still here.' ) ) );
		$result = new Synchronizer( $this->provider )->run();

		// The stored set mirrors the newest reviews upstream rather than archiving them, so
		// falling out of it is routine and leaves nothing behind.
		$this->assertSame( 1, $result->removed );
		$this->assertNull( get_post( $vanishing ) );
		$this->assertCount( 1, $this->stored_reviews() );
	}

	public function test_a_review_that_comes_back_is_imported_again(): void {
		$this->provider->will_return( array( $this->a_review( 'ext-1', 'Here.' ), $this->a_review( 'ext-2', 'Gone soon.' ) ) );
		new Synchronizer( $this->provider )->run();

		$this->provider->will_return( array( $this->a_review( 'ext-1', 'Here.' ) ) );
		new Synchronizer( $this->provider )->run();

		$this->provider->will_return( array( $this->a_review( 'ext-1', 'Here.' ), $this->a_review( 'ext-2', 'Gone soon.' ) ) );
		new Synchronizer( $this->provider )->run();

		$this->assertSame( 'publish', get_post_status( $this->find_by_external_id( 'ext-2' ) ) );
		$this->assertCount( 2, $this->stored_reviews() );
	}

	/**
	 * The failure that would delete every review past the first page.
	 *
	 * A provider drops payloads it cannot map, so a page can come back shorter than asked
	 * for while more remain upstream. Reading that as "the walk is over" ends it early and
	 * hands everything unread to the reconciliation, which deletes it.
	 */
	public function test_a_short_page_does_not_end_the_walk(): void {
		$this->provider->will_return_page( array( $this->a_review( 'ext-1', 'Page one.' ) ), true );
		$this->provider->will_return_page( array( $this->a_review( 'ext-2', 'Page two.' ) ), false );

		new Synchronizer( $this->provider )->run();
		new Synchronizer( $this->provider )->run();

		$this->assertCount( 2, $this->stored_reviews() );
	}

	/**
	 * The regression that would silently wipe every published review.
	 */
	public function test_an_unchanged_source_leaves_everything_published(): void {
		$this->provider->will_return( array( $this->a_review( 'ext-1', 'Great bakery.' ) ) );
		new Synchronizer( $this->provider )->run();

		$this->provider->will_return( null );
		$result = new Synchronizer( $this->provider )->run();

		$this->assertTrue( $result->not_modified );
		$this->assertSame( 'publish', get_post_status( $this->find_by_external_id( 'ext-1' ) ) );
	}

	public function test_it_stores_the_upstream_aggregate(): void {
		$this->provider->will_return( array( $this->a_review( 'ext-1', 'Great bakery.' ) ) );
		new Synchronizer( $this->provider )->run();

		$profile = get_option( Synchronizer::PROFILE_OPTION );

		$this->assertSame( 4.7, $profile['rating_average'] );
		$this->assertSame( 213, $profile['rating_count'] );
	}

	public function test_hidden_reviews_are_kept_out_of_the_display_query(): void {
		$this->provider->will_return( array( $this->a_review( 'ext-1', 'Shown.' ), $this->a_review( 'ext-2', 'Hidden.' ) ) );
		new Synchronizer( $this->provider )->run();

		update_post_meta( $this->find_by_external_id( 'ext-2' ), MetaKeys::OVR_HIDDEN, '1' );

		$this->assertCount( 1, new Repository()->find_for_display() );
	}

	private function a_review( string $external_id, string $comment ): FetchedReview {
		return new FetchedReview(
			external_id: $external_id,
			rating: 5,
			comment: $comment,
			author_name: 'Camille D.',
			author_photo_url: null,
			reviewed_at: new DateTimeImmutable( '2026-07-19 18:41:00' ),
			updated_at: new DateTimeImmutable( '2026-07-19 18:41:00' ),
			provider: $this->provider->id(),
		);
	}

	/**
	 * @return int[]
	 */
	private function stored_reviews(): array {
		return get_posts(
			array(
				'post_type'      => ReviewPostType::SLUG,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
	}

	private function find_by_external_id( string $external_id ): int {
		$post = new Repository()->find_by_external_id( $external_id, $this->provider->id() );

		$this->assertNotNull( $post, "No review stored for {$external_id}." );

		return $post->ID;
	}
}
