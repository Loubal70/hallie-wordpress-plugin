<?php
/**
 * Review repository.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Hallie\Reviews\AvatarMode;
use Hallie\Reviews\Data\DisplayableReview;
use Hallie\Reviews\PostType\ReviewPostType;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Reads stored reviews and turns them into render-ready objects.
 *
 * Every override resolution happens here, once, so no template ever has to know that an
 * editor's featured image wins over the picture imported from the source.
 */
final class Repository {

	private const string BODY_REQUIRED = 'hallie_body_required';

	/**
	 * Fetch reviews for display.
	 *
	 * @param int    $limit             How many to return at most.
	 * @param int    $min_rating        Lowest rating to include. Deliberately not 5: a wall of
	 *                                  five-star reviews under an advertised average of 4.7 is
	 *                                  an inconsistency anyone can spot, human or machine.
	 * @param bool   $featured_first    Show the ones an editor picked before the rest.
	 * @param bool   $with_comment_only Leave out reviews that are a rating and nothing else.
	 * @param string $provider          Restrict to one source, or all of them when empty.
	 *
	 * @return DisplayableReview[]
	 */
	public function find_for_display(
		int $limit = 12,
		int $min_rating = 4,
		bool $featured_first = true,
		bool $with_comment_only = true,
		string $provider = '',
	): array {
		$wanted = max( 1, $limit );

		$reviews = $featured_first
			? $this->featured_then_recent( $wanted, $min_rating, $with_comment_only, $provider )
			: $this->fetch( $wanted, $min_rating, $with_comment_only, $provider );

		$this->prime_avatars( $reviews );

		return $reviews;
	}

	/**
	 * Featured reviews first, the most recent ones after.
	 *
	 * Two queries rather than a sort: ordering on the meta key in SQL drops every review
	 * that was never featured, and sorting in PHP can only reorder rows the query already
	 * returned — a featured review older than the newest N would never surface.
	 *
	 * @return DisplayableReview[]
	 */
	private function featured_then_recent( int $limit, int $min_rating, bool $with_comment_only, string $provider ): array {
		$featured = $this->fetch( $limit, $min_rating, $with_comment_only, $provider, true );
		$missing  = $limit - count( $featured );

		if ( $missing < 1 ) {
			return $featured;
		}

		return array_merge( $featured, $this->fetch( $missing, $min_rating, $with_comment_only, $provider, false ) );
	}

	/**
	 * One query's worth of reviews.
	 *
	 * `$featured` has three meanings rather than two: true keeps only the ones an editor
	 * picked, false keeps only the rest, and null keeps both.
	 *
	 * @return DisplayableReview[]
	 */
	private function fetch( int $limit, int $min_rating, bool $with_comment_only, string $provider, ?bool $featured = null ): array {
		$query = $this->query_for( $limit, $min_rating, $provider );

		$query[ self::BODY_REQUIRED ] = $with_comment_only;

		if ( null !== $featured ) {
			$query['meta_query'][] = array(
				'key'     => MetaKeys::OVR_FEATURED,
				'compare' => $featured ? 'EXISTS' : 'NOT EXISTS',
			);
		}

		return array_map( $this->to_displayable_review( ... ), new WP_Query( $query )->posts );
	}

	/**
	 * Hook the body condition once, for good.
	 *
	 * Permanent rather than wrapped around each call: the flag it looks for is what tells
	 * our queries from every other one `posts_where` sees — including those another plugin
	 * runs from inside ours.
	 */
	public static function register(): void {
		add_filter( 'posts_where', self::require_body( ... ), 10, 2 );
	}

	/**
	 * Require a non-empty body, on the queries that asked for one.
	 *
	 * WP_Query has no vocabulary for this, and filtering in PHP would mean over-fetching to
	 * compensate — the caller could no longer trust the row count it asked for.
	 */
	public static function require_body( string $where, WP_Query $query ): string {
		global $wpdb;

		if ( ! $query->get( self::BODY_REQUIRED ) ) {
			return $where;
		}

		return $where . " AND {$wpdb->posts}.post_content <> ''";
	}


	/**
	 * Load every avatar in one pass.
	 *
	 * `wp_get_attachment_image()` costs two queries per call, so rendering twelve reviews
	 * would otherwise issue twenty-four on its own — more than the review query itself.
	 *
	 * @param DisplayableReview[] $reviews Reviews about to be rendered.
	 */
	private function prime_avatars( array $reviews ): void {
		$avatar_ids = array_filter(
			array_map( static fn ( DisplayableReview $review ): ?int => $review->avatar_id, $reviews )
		);

		if ( array() === $avatar_ids ) {
			return;
		}

		_prime_post_caches( array_values( $avatar_ids ), false, true );
	}

	/**
	 * Locate a stored review by its upstream identifier — the upsert key.
	 */
	public function find_by_external_id( string $external_id, string $provider ): ?WP_Post {
		if ( '' === $external_id ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'              => ReviewPostType::SLUG,
				'post_status'            => ReviewPostType::every_status(),
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Lookup by unique upstream id.
				'meta_query'             => array(
					array(
						'key'   => MetaKeys::SRC_EXTERNAL_ID,
						'value' => $external_id,
					),
					array(
						'key'   => MetaKeys::SRC_PROVIDER,
						'value' => $provider,
					),
				),
			)
		);

		return $posts[0] ?? null;
	}

	public function to_displayable_review( WP_Post $post ): DisplayableReview {
		$custom_avatar_id = (int) get_post_thumbnail_id( $post );

		return new DisplayableReview(
			post_id: $post->ID,
			rating: (int) get_post_meta( $post->ID, MetaKeys::SRC_RATING, true ),
			comment: (string) $post->post_content,
			author_name: $this->author_name_of( $post ),
			avatar_id: $this->avatar_id_of( $post, $custom_avatar_id ),
			avatar_url: $this->avatar_url_of( $post, $custom_avatar_id ),
			reviewed_at: $this->to_date( $post->post_date_gmt ),
			reply_comment: $this->nullable_meta( $post->ID, MetaKeys::SRC_REPLY_COMMENT ),
			reply_published_at: $this->nullable_date_meta( $post->ID, MetaKeys::SRC_REPLY_PUBLISHED_AT ),
			provider: (string) get_post_meta( $post->ID, MetaKeys::SRC_PROVIDER, true ),
			is_featured: (bool) get_post_meta( $post->ID, MetaKeys::OVR_FEATURED, true ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function query_for( int $limit, int $min_rating, string $provider ): array {
		return array(
			'post_type'              => ReviewPostType::SLUG,
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Filtering a small, private post type.
			'meta_query'             => $this->meta_filters_for( $min_rating, $provider ),
		);
	}

	/**
	 * Meta conditions a listing query carries, all of them combined with AND.
	 *
	 * Hiding is tested with a bare NOT EXISTS: a falsy toggle is deleted rather than stored
	 * empty (see ReviewPostType), so nothing is missed, and an OR group covering both would
	 * multiply rows through the joins it adds.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function meta_filters_for( int $min_rating, string $provider ): array {
		$filters = array(
			array(
				'key'     => MetaKeys::OVR_HIDDEN,
				'compare' => 'NOT EXISTS',
			),
		);

		if ( $min_rating > 0 ) {
			$filters[] = array(
				'key'     => MetaKeys::SRC_RATING,
				'value'   => $min_rating,
				'type'    => 'NUMERIC',
				'compare' => '>=',
			);
		}

		if ( '' !== $provider ) {
			$filters[] = array(
				'key'   => MetaKeys::SRC_PROVIDER,
				'value' => $provider,
			);
		}

		return $filters;
	}




	/**
	 * The name to print, which is simply the post title.
	 *
	 * The synchroniser sets it once at creation and never rewrites it, so editing the
	 * title is how an author's displayed name gets customised — no second field for the
	 * same thing.
	 */
	private function author_name_of( WP_Post $post ): string {
		$title = trim( $post->post_title );

		return '' !== $title ? $title : __( 'Anonymous', 'hallie' );
	}

	/**
	 * A picture the editor chose always wins; an imported one only when it exists.
	 */
	private function avatar_id_of( WP_Post $post, int $custom_avatar_id ): ?int {
		if ( $custom_avatar_id > 0 ) {
			return $custom_avatar_id;
		}

		// Only the local mode reads what was downloaded; the remote mode must reach for the
		// provider's URL even when an old import is still lying around.
		if ( ! AvatarMode::current()->stores_files() ) {
			return null;
		}

		$imported_id = (int) get_post_meta( $post->ID, MetaKeys::SYS_AVATAR_ID, true );

		return $imported_id > 0 ? $imported_id : null;
	}

	/**
	 * The provider's own picture, used only when nothing is stored locally.
	 */
	private function avatar_url_of( WP_Post $post, int $custom_avatar_id ): ?string {
		if ( $custom_avatar_id > 0 || AvatarMode::Remote !== AvatarMode::current() ) {
			return null;
		}

		return $this->nullable_meta( $post->ID, MetaKeys::SRC_AUTHOR_PHOTO_URL );
	}

	private function nullable_meta( int $post_id, string $key ): ?string {
		$value = (string) get_post_meta( $post_id, $key, true );

		return '' !== $value ? $value : null;
	}

	private function nullable_date_meta( int $post_id, string $key ): ?DateTimeImmutable {
		$value = $this->nullable_meta( $post_id, $key );

		return null === $value ? null : $this->to_date( $value );
	}

	private function to_date( string $value ): DateTimeImmutable {
		try {
			return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
		} catch ( Exception ) {
			return new DateTimeImmutable( '@0' );
		}
	}
}
