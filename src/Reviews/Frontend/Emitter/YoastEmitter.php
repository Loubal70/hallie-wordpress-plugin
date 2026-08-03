<?php
/**
 * Yoast SEO integration.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Frontend\Emitter;

defined( 'ABSPATH' ) || exit;

/**
 * Links reviews to the business entity declared by Yoast SEO.
 *
 * `wpseo_schema_graph` hands over the finished graph, which is all this needs: the `@id`
 * of the Organization node. Yoast's own output is read and passed straight back.
 *
 * @see https://developer.yoast.com/features/schema/api/
 */
final class YoastEmitter extends LinkedEmitter {

	public const string ID = 'yoast';

	public function is_supported(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	protected function observe_host_graph(): void {
		add_filter( 'wpseo_schema_graph', $this->capture( ... ) );
	}
}
