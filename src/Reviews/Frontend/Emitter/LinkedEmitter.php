<?php
/**
 * Emitter linking reviews to an existing business entity.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Frontend\Emitter;

use Hallie\Reviews\Frontend\ReviewSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Publishes reviews against the business entity an SEO plugin already declares.
 *
 * The host graph is read, never rewritten: all this needs is the `@id` of its business
 * node, which the reviews then point at through `itemReviewed`. Declaring a second
 * business entity instead would split the identity of the same company across two nodes.
 */
abstract class LinkedEmitter extends BufferedEmitter {

	/**
	 * Business types a host graph may declare, searched in this order.
	 *
	 * `LocalBusiness` is a subtype of `Organization`, and a page about a branch declares both:
	 * the company owning the site, and the establishment the page is about. Reviews belong to
	 * the establishment, so the more specific type is looked for first.
	 */
	private const array BUSINESS_TYPES = array( 'LocalBusiness', 'Organization' );

	private ?string $business_id = null;

	private ?string $host_business_type = null;

	public function register(): void {
		$this->observe_host_graph();

		parent::register();
	}

	/**
	 * Register the host's graph filter, pointing it at `capture()`.
	 */
	abstract protected function observe_host_graph(): void;

	/**
	 * Read the host graph without touching it.
	 *
	 * @param array<int, array<string, mixed>> $graph Nodes as built by the host plugin.
	 *
	 * @return array<int, array<string, mixed>> The very same graph.
	 */
	public function capture( array $graph ): array {
		if ( null === $this->business_id ) {
			$business = $this->business_in( $graph );

			$this->business_id        = $business['id'] ?? null;
			$this->host_business_type = $business['type'] ?? null;
		}

		return $graph;
	}

	/**
	 * Without an anchor the reviews would float free of any subject, which says less than
	 * nothing. Better to publish none than an orphan.
	 */
	protected function has_something_to_publish(): bool {
		return parent::has_something_to_publish() && null !== $this->business_id;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function build( ReviewSchema $schema ): array {
		$subject = array( '@id' => $this->business_id );

		$graph = array_map(
			static function ( array $review ) use ( $subject ): array {
				$review['itemReviewed'] = $subject;

				return $review;
			},
			$schema->reviews
		);

		if ( $schema->has_aggregate() ) {
			$graph[] = array(
				'@type'           => $this->business_type_for( $schema ),
				'@id'             => $this->business_id,
				'aggregateRating' => $schema->aggregate,
			);
		}

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);
	}

	/**
	 * The type to publish the aggregate under.
	 *
	 * What the site states wins; otherwise the host graph already answered the question and
	 * repeating its answer keeps one entity with one type. Neither is a reason to invent one.
	 */
	private function business_type_for( ReviewSchema $schema ): string {
		return $schema->business_type
			?? $this->host_business_type
			?? ReviewSchema::DEFAULT_BUSINESS_TYPE;
	}

	/**
	 * The business the host graph describes, taking its most specific node.
	 *
	 * @param array<int, array<string, mixed>> $graph Nodes as built by the host plugin.
	 *
	 * @return array{id: string, type: string}|null
	 */
	private function business_in( array $graph ): ?array {
		foreach ( self::BUSINESS_TYPES as $type ) {
			$id = $this->id_declared_as( $graph, $type );

			if ( null !== $id ) {
				return array(
					'id'   => $id,
					'type' => $type,
				);
			}
		}

		return null;
	}

	/**
	 * The `@id` of the first node carrying a given type.
	 *
	 * A node declaring the type without an `@id` cannot be linked to, so the search goes on
	 * rather than giving up on that type.
	 *
	 * @param array<int, array<string, mixed>> $graph Nodes as built by the host plugin.
	 * @param string                           $type  Schema type to look for.
	 */
	private function id_declared_as( array $graph, string $type ): ?string {
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$declared = (array) ( $node['@type'] ?? array() );

			if ( ! in_array( $type, $declared, true ) ) {
				continue;
			}

			$id = $node['@id'] ?? null;

			if ( is_string( $id ) && '' !== $id ) {
				return $id;
			}
		}

		return null;
	}
}
