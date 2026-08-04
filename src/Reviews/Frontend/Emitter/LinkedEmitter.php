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
	 * A branch page declares both the owning company and the establishment. The reviews are
	 * the establishment's, so the `LocalBusiness` subtype is looked for before `Organization`.
	 */
	private const array BUSINESS_TYPES = array( 'LocalBusiness', 'Organization' );

	/** @var array{id: string, type: string}|null */
	private ?array $host_business = null;

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
		$this->host_business ??= $this->business_in( $graph );

		return $graph;
	}

	/** Reviews with no subject to point at say less than nothing; better to publish none. */
	protected function has_something_to_publish(): bool {
		return parent::has_something_to_publish() && null !== $this->host_business;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function build( ReviewSchema $schema ): array {
		$subject = array( '@id' => $this->host_business['id'] );

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
				'@id'             => $this->host_business['id'],
				'aggregateRating' => $schema->aggregate,
			);
		}

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);
	}

	/**
	 * The type to publish the aggregate under: what the site states, else what the host
	 * already declared — repeating its answer keeps one entity carrying one type.
	 */
	private function business_type_for( ReviewSchema $schema ): string {
		return $schema->business_type
			?? $this->host_business['type']
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
	 * A node declaring the type without an `@id` cannot be anchored to, so the search goes on.
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
