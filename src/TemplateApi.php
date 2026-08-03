<?php
/**
 * Public API.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie;

use Hallie\Reviews\Data\DisplayableReview;
use Hallie\Reviews\Data\BusinessProfile;
use Hallie\Reviews\Frontend\StructuredData;
use Hallie\Reviews\Repository;
use Hallie\Reviews\Sync\Synchronizer;

defined( 'ABSPATH' ) || exit;

/**
 * Everything a theme needs, and nothing it does not.
 *
 * Themes call this rather than querying the post type: overrides are resolved, the
 * aggregate comes from the provider, and reviews handed out here are registered for
 * structured data — so the markup can only ever describe what was actually rendered.
 */
final class TemplateApi {

	/**
	 * Resolved once per request: three template helpers ask for the same profile.
	 *
	 * @var BusinessProfile|null
	 */
	private static ?BusinessProfile $profile = null;

	private static bool $profile_resolved = false;

	/**
	 * Reviews ready to render.
	 *
	 * @param array{limit?: int, min_rating?: int, provider?: string, featured_first?: bool, with_comment_only?: bool, schema?: bool} $args Query options. Pass `schema: false` when fetching reviews you will not display, so they stay out of the structured data.
	 *
	 * @return DisplayableReview[]
	 */
	public static function get_reviews( array $args = array() ): array {
		$collect = $args['schema'] ?? true;
		unset( $args['schema'] );

		$reviews = new Repository()->find_for_display( ...$args );

		if ( $collect ) {
			StructuredData::instance()?->remember_rendered( ...$reviews );
		}

		return $reviews;
	}

	/**
	 * The reviewed business, with its aggregate rating.
	 *
	 * @param bool $aggregate_is_visible True when the template prints the average and the
	 *                                   total, which is what makes marking them up
	 *                                   legitimate.
	 */
	public static function get_profile( bool $aggregate_is_visible = true ): ?BusinessProfile {
		$profile = self::read_profile();

		if ( null === $profile ) {
			return null;
		}

		StructuredData::instance()?->set_profile( $profile, $aggregate_is_visible );

		return $profile;
	}

	/**
	 * Read the stored profile without declaring anything about the page.
	 *
	 * The CTA helpers below need the profile but display nothing, so they must not be able
	 * to retract an aggregate a template already declared as visible.
	 */
	private static function read_profile(): ?BusinessProfile {
		if ( self::$profile_resolved ) {
			return self::$profile;
		}

		self::$profile_resolved = true;
		self::$profile          = self::load_profile();

		return self::$profile;
	}

	private static function load_profile(): ?BusinessProfile {
		$stored = get_option( Synchronizer::PROFILE_OPTION );

		if ( ! is_array( $stored ) || empty( $stored['id'] ) ) {
			return null;
		}

		return new BusinessProfile(
			id: (string) $stored['id'],
			name: (string) ( $stored['name'] ?? '' ),
			address: isset( $stored['address'] ) ? (string) $stored['address'] : null,
			rating_average: (float) ( $stored['rating_average'] ?? 0 ),
			rating_count: (int) ( $stored['rating_count'] ?? 0 ),
			place_id: isset( $stored['place_id'] ) ? (string) $stored['place_id'] : null,
			review_url: isset( $stored['review_url'] ) ? (string) $stored['review_url'] : null,
			profile_url: isset( $stored['profile_url'] ) ? (string) $stored['profile_url'] : null,
			provider: (string) ( $stored['provider'] ?? '' ),
		);
	}


	/**
	 * Link to the review form, or null when no Place ID is configured.
	 */
	public static function write_review_url(): ?string {
		return self::read_profile()?->write_review_url();
	}

	/**
	 * Link to the public listing, or null when it is not configured.
	 */
	public static function profile_url(): ?string {
		return self::read_profile()?->profile_url;
	}
}
