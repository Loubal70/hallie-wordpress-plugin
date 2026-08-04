<?php
/**
 * Emitter behaviour.
 *
 * @package Hallie
 */

declare(strict_types=1);

use Hallie\Reviews\Frontend\Emitter\SchemaEmitter;
use Hallie\Reviews\Frontend\Emitter\StandaloneEmitter;
use Hallie\Reviews\Frontend\ReviewSchema;

require_once __DIR__ . '/includes/class-hallie-fake-linked-emitter.php';

/**
 * What the emitters state about the business the reviews belong to.
 *
 * Two nodes sharing an `@id` are one node in JSON-LD: their properties merge, `@type`
 * included. Publishing the aggregate under a type of this plugin's choosing therefore
 * requalifies whatever the host declared — and `LocalBusiness` commits the site to an
 * address it may never have published.
 */
final class Test_Hallie_Emitters extends WP_UnitTestCase {

	private const string HOST_ID = 'https://example.com/#/schema/Organization';

	private const string BRANCH_ID = 'https://example.com/lyon/#/schema/LocalBusiness';

	/**
	 * The defect this whole chain exists to prevent.
	 */
	public function test_it_publishes_under_the_type_the_host_declares(): void {
		$emitter = $this->linked_to( $this->organization() );

		$this->assertSame( 'Organization', $this->business_node_of( $emitter )['@type'] );
	}

	public function test_a_local_business_host_keeps_its_own_type(): void {
		$emitter = $this->linked_to( $this->local_business() );

		$this->assertSame( 'LocalBusiness', $this->business_node_of( $emitter )['@type'] );
	}

	/**
	 * A branch page declares the establishment beside the company that owns the site. The
	 * reviews are the establishment's, whichever order the host wrote the two nodes in.
	 */
	public function test_the_most_specific_entity_wins_over_graph_order(): void {
		$emitter = $this->linked_to( $this->organization(), $this->local_business() );

		$business = $this->business_node_of( $emitter );

		$this->assertSame( 'LocalBusiness', $business['@type'] );
		$this->assertSame( self::BRANCH_ID, $business['@id'] );
	}

	public function test_a_node_declaring_several_types_is_read_as_the_most_specific(): void {
		$emitter = $this->linked_to(
			array(
				'@type' => array( 'Organization', 'LocalBusiness' ),
				'@id'   => self::HOST_ID,
			)
		);

		$this->assertSame( 'LocalBusiness', $this->business_node_of( $emitter )['@type'] );
	}

	/**
	 * A node with no `@id` cannot be linked to, so the search carries on rather than
	 * settling for it and leaving the reviews pointing at nothing.
	 */
	public function test_an_anchorless_node_does_not_end_the_search(): void {
		$emitter = $this->linked_to(
			array( '@type' => 'LocalBusiness' ),
			$this->organization()
		);

		$business = $this->business_node_of( $emitter );

		$this->assertSame( 'Organization', $business['@type'] );
		$this->assertSame( self::HOST_ID, $business['@id'] );
	}

	/**
	 * Reviews floating free of any subject say less than nothing.
	 */
	public function test_nothing_is_published_without_an_entity_to_link_to(): void {
		$emitter = $this->linked_to( array( '@type' => 'WebSite', '@id' => self::HOST_ID ) );

		$this->assertSame( array(), $this->published_graph( $emitter ) );
	}

	public function test_the_reviews_point_at_the_host_entity(): void {
		$emitter = $this->linked_to( $this->organization() );

		$graph = $this->published_graph( $emitter )['@graph'];

		$this->assertSame( array( '@id' => self::HOST_ID ), $graph[0]['itemReviewed'] );
	}

	/**
	 * The site knows things the graph does not; a stated type overrides what was read.
	 */
	public function test_a_type_stated_by_the_site_overrides_the_host(): void {
		$emitter = $this->linked_to( $this->organization() );
		$emitter->emit( $this->a_schema( 'Dentist' ) );

		$this->assertSame( 'Dentist', $this->business_node_of( $emitter )['@type'] );
	}

	/**
	 * With no host graph to read, the type must carry no obligation the plugin cannot meet.
	 */
	public function test_a_standalone_graph_falls_back_to_a_neutral_type(): void {
		$emitter = new StandaloneEmitter();
		$emitter->emit( $this->a_schema() );

		$this->assertSame( 'Organization', $this->published_graph( $emitter )['@type'] );
	}

	/**
	 * Build an emitter that has already read the given host graph and been handed a payload.
	 *
	 * @param array<string, mixed> ...$nodes Nodes the host plugin would have produced.
	 */
	private function linked_to( array ...$nodes ): Hallie_Fake_Linked_Emitter {
		$emitter = new Hallie_Fake_Linked_Emitter();

		$emitter->capture( $nodes );
		$emitter->emit( $this->a_schema() );

		return $emitter;
	}

	/**
	 * The node carrying the aggregate — the one whose type is in question.
	 *
	 * @return array<string, mixed>
	 */
	private function business_node_of( SchemaEmitter $emitter ): array {
		$graph = $this->published_graph( $emitter )['@graph'];

		return end( $graph );
	}

	/**
	 * The JSON-LD the emitter actually prints.
	 *
	 * Asserting on the output rather than on an internal method: the failure being guarded
	 * against is what search engines read, not what a private method returns.
	 *
	 * @return array<string, mixed>
	 */
	private function published_graph( SchemaEmitter $emitter ): array {
		ob_start();
		$emitter->render();
		$html = (string) ob_get_clean();

		if ( 1 !== preg_match( '#<script[^>]*>(.*)</script>#s', $html, $matches ) ) {
			return array();
		}

		return (array) json_decode( $matches[1], true );
	}

	/**
	 * A payload with an aggregate, since only the aggregate node carries a business type.
	 */
	private function a_schema( ?string $business_type = null ): ReviewSchema {
		return new ReviewSchema(
			reviews: array(
				array(
					'@type'      => 'Review',
					'reviewBody' => 'Great bakery.',
				),
			),
			aggregate: array(
				'@type'       => 'AggregateRating',
				'ratingValue' => '4.9',
				'ratingCount' => 42,
			),
			business_type: $business_type,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function organization(): array {
		return array(
			'@type' => 'Organization',
			'@id'   => self::HOST_ID,
			'name'  => 'Example',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function local_business(): array {
		return array(
			'@type' => 'LocalBusiness',
			'@id'   => self::BRANCH_ID,
			'name'  => 'Example Lyon',
		);
	}
}
