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

	/** LocalBusiness is a subtype of Organization; hosts emit one or the other. */
	private const array BUSINESS_TYPES = array( 'LocalBusiness', 'Organization' );

	private ?string $business_id = null;

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
		$this->business_id ??= $this->business_id_in( $graph );

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
				'@type'           => $schema->business_type,
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
	 * @param array<int, array<string, mixed>> $graph Nodes as built by the host plugin.
	 */
	private function business_id_in( array $graph ): ?string {
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) || ! $this->describes_the_business( $node ) ) {
				continue;
			}

			$id = $node['@id'] ?? null;

			if ( is_string( $id ) && '' !== $id ) {
				return $id;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $node Candidate graph node.
	 */
	private function describes_the_business( array $node ): bool {
		$types = (array) ( $node['@type'] ?? array() );

		return array() !== array_intersect( $types, self::BUSINESS_TYPES );
	}
}
