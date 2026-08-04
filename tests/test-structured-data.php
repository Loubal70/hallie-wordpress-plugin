<?php
/**
 * Structured data payload.
 *
 * @package Hallie
 */

declare(strict_types=1);

use Hallie\Reviews\Data\DisplayableReview;
use Hallie\Reviews\Frontend\ReviewSchema;
use Hallie\Reviews\Frontend\StructuredData;
use Hallie\Reviews\Provider\ProviderRegistry;

/**
 * The business type a site may state for itself.
 *
 * Answering on the site's behalf is what this must not do: reviews say nothing about
 * whether a company is a local business, and `LocalBusiness` commits it to an address.
 */
final class Test_Hallie_Structured_Data extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'hallie_schema_business_type' );

		parent::tear_down();
	}

	/**
	 * Nothing stated means nothing decided — the emitters read the host graph instead.
	 */
	public function test_no_type_is_stated_by_default(): void {
		$this->assertNull( $this->built_schema()->business_type );
	}

	public function test_a_filtered_type_is_carried_through(): void {
		add_filter( 'hallie_schema_business_type', static fn (): string => 'Dentist' );

		$this->assertSame( 'Dentist', $this->built_schema()->business_type );
	}

	/**
	 * A filter returning an empty string states nothing. Carried through as-is it would
	 * become the `@type` of the business entity.
	 */
	public function test_an_empty_answer_states_nothing(): void {
		add_filter( 'hallie_schema_business_type', static fn (): string => '' );

		$this->assertNull( $this->built_schema()->business_type );
	}

	/**
	 * The payload StructuredData hands to its emitter, caught on its way through.
	 */
	private function built_schema(): ReviewSchema {
		$captured = null;

		add_filter(
			'hallie_schema',
			static function ( ReviewSchema $schema ) use ( &$captured ): ReviewSchema {
				$captured = $schema;

				return $schema;
			}
		);

		$structured_data = new StructuredData( new ProviderRegistry() );
		$structured_data->register();
		$structured_data->remember_rendered( $this->a_review() );
		$structured_data->publish();

		remove_all_filters( 'hallie_schema' );

		return $captured;
	}

	private function a_review(): DisplayableReview {
		return new DisplayableReview(
			post_id: 1,
			rating: 5,
			comment: 'Great bakery.',
			author_name: 'Camille Dupont',
			avatar_id: null,
			avatar_url: null,
			reviewed_at: new DateTimeImmutable( '2026-01-15' ),
		);
	}
}
