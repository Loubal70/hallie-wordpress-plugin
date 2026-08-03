<?php
/**
 * Structured data for rendered reviews.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Frontend;

use Hallie\Reviews\Data\BusinessProfile;
use Hallie\Reviews\Data\DisplayableReview;
use Hallie\Reviews\Frontend\Emitter\EmitterResolver;
use Hallie\Reviews\Frontend\Emitter\SchemaEmitter;
use Hallie\Reviews\Provider\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the reviews a page rendered, and nothing else.
 *
 * Never queries the database: search engines treat markup describing content absent from
 * the page as spam, so this can only report what the rendering path handed it.
 *
 * `publisher` names the platform the review appeared on, `sdPublisher` names this site,
 * which built the markup from it.
 */
final class StructuredData {

	/** @var array<int, DisplayableReview> */
	private array $rendered = array();

	private ?BusinessProfile $profile = null;

	private bool $aggregate_is_visible = false;

	private ?SchemaEmitter $emitter = null;

	private static ?self $instance = null;

	public function __construct( private readonly ProviderRegistry $providers ) {}

	public function register(): void {
		// wp_footer and the host graph filters only ever fire on the front end, so admin,
		// cron, AJAX and REST requests should not pay for any of this.
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return;
		}

		self::$instance = $this;

		$this->emitter = new EmitterResolver()->resolve();
		$this->emitter->register();

		// Late enough that every template has run, early enough to precede the emitters.
		add_action( 'wp_footer', $this->publish( ... ), 10 );
	}

	public static function instance(): ?self {
		return self::$instance;
	}

	/**
	 * Declare reviews as rendered on the current page.
	 */
	public function remember_rendered( DisplayableReview ...$reviews ): void {
		foreach ( $reviews as $review ) {
			// Keyed on the post, not the upstream id: manually authored reviews have no
			// upstream id, and would all collapse onto the same empty key.
			$this->rendered[ $review->post_id ] = $review;
		}
	}

	/**
	 * Declare the profile behind the reviews.
	 *
	 * @param BusinessProfile $profile              The reviewed business.
	 * @param bool            $aggregate_is_visible Whether the average and total are printed
	 *                                              on the page. The aggregate is only marked
	 *                                              up when they are: claiming a total the
	 *                                              visitor cannot see is what the policies
	 *                                              forbid.
	 */
	public function set_profile( BusinessProfile $profile, bool $aggregate_is_visible = false ): void {
		$this->profile              = $profile;
		$this->aggregate_is_visible = $aggregate_is_visible;
	}

	public function publish(): void {
		if ( array() === $this->rendered || null === $this->emitter ) {
			return;
		}

		$this->emitter->emit( $this->build() );
	}

	private function build(): ReviewSchema {
		$schema = new ReviewSchema(
			reviews: array_values( array_map( $this->review_node( ... ), $this->rendered ) ),
			aggregate: $this->aggregate_node(),
			listing_url: $this->profile?->profile_url,
			business_type: (string) apply_filters( 'hallie_schema_business_type', 'LocalBusiness' ),
		);

		/**
		 * Filters the structured data about to be published.
		 *
		 * @param ReviewSchema      $schema   Payload handed to the emitter.
		 * @param DisplayableReview[] $rendered Reviews rendered on this page.
		 */
		return apply_filters( 'hallie_schema', $schema, $this->rendered );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function review_node( DisplayableReview $review ): array {
		$node = array(
			'@type'         => 'Review',
			'author'        => array(
				'@type' => 'Person',
				'name'  => $review->author_name,
			),
			'datePublished' => $review->iso_date(),
			'reviewRating'  => array(
				'@type'       => 'Rating',
				'ratingValue' => (string) $review->rating,
				'bestRating'  => '5',
				'worstRating' => '1',
			),
			'reviewBody'    => $review->comment,
			// Who turned the source into this markup — here.
			'sdPublisher'   => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			),
		);

		$platform = $this->platform_of( $review->provider );

		// Only credited when a platform actually published the review. A locally written
		// one gets no publisher rather than a borrowed one.
		if ( '' !== $platform ) {
			$node['publisher'] = array(
				'@type' => 'Organization',
				'name'  => $platform,
			);
		}

		/**
		 * Filters a single review node.
		 *
		 * @param array<string, mixed> $node   Node about to be published.
		 * @param DisplayableReview    $review Source review.
		 */
		return (array) apply_filters( 'hallie_schema_review', $node, $review );
	}

	/**
	 * The aggregate is only claimed when the provider supplied one and the page shows it.
	 *
	 * @return array<string, mixed>|null
	 */
	private function aggregate_node(): ?array {
		if ( ! $this->should_publish_aggregate() ) {
			return null;
		}

		return array(
			'@type'       => 'AggregateRating',
			'ratingValue' => (string) $this->profile->rating_average,
			'ratingCount' => $this->profile->rating_count,
			'bestRating'  => '5',
			'worstRating' => '1',
		);
	}

	private function should_publish_aggregate(): bool {
		if ( ! $this->aggregate_is_visible ) {
			return false;
		}

		return $this->profile instanceof BusinessProfile && $this->profile->has_ratings();
	}

	/**
	 * Platform that published a review, as declared by the provider that produced it.
	 */
	private function platform_of( string $provider ): string {
		$platform = $this->providers->get( $provider )?->platform_name() ?? '';

		/**
		 * Filters the platform credited as publisher of a review.
		 *
		 * @param string $platform Platform name, empty for a locally written review.
		 * @param string $provider Provider identifier that produced the review.
		 */
		return (string) apply_filters( 'hallie_schema_publisher_name', $platform, $provider );
	}
}
