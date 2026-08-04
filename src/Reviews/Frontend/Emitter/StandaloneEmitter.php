<?php
/**
 * Self-contained JSON-LD output.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Frontend\Emitter;

use Hallie\Reviews\Frontend\ReviewSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the reviews in a graph of their own.
 *
 * The fallback, for sites with no SEO plugin owning a graph. It declares the business
 * itself, which is only correct because nothing else on the page does.
 */
final class StandaloneEmitter extends BufferedEmitter {

	public const string ID = 'standalone';

	public function is_supported(): bool {
		return true;
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function build( ReviewSchema $schema ): array {
		$node = array(
			'@context' => 'https://schema.org',
			'@type'    => $schema->business_type ?? ReviewSchema::DEFAULT_BUSINESS_TYPE,
			'name'     => get_bloginfo( 'name' ),
			'url'      => home_url( '/' ),
		);

		if ( null !== $schema->listing_url ) {
			$node['sameAs'] = array( $schema->listing_url );
		}

		if ( $schema->has_aggregate() ) {
			$node['aggregateRating'] = $schema->aggregate;
		}

		$node['review'] = $schema->reviews;

		return $node;
	}
}
