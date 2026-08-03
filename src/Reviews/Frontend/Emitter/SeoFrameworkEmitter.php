<?php
/**
 * The SEO Framework integration.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Frontend\Emitter;

defined( 'ABSPATH' ) || exit;

/**
 * Links reviews to the business entity declared by The SEO Framework.
 *
 * TSF has emitted a single linked graph since 5.0, and documents
 * `the_seo_framework_schema_graph_data` as the hook fired on the final graph. It is used
 * here only to read the Organization `@id`.
 *
 * @see https://kb.theseoframework.com/kb/structured-data-supported-by-the-seo-framework/
 */
final class SeoFrameworkEmitter extends LinkedEmitter {

	public const string ID = 'seo-framework';

	/** The single-graph output this emitter relies on landed in TSF 5.0. */
	private const string MINIMUM_VERSION = '5.0';

	public function is_supported(): bool {
		if ( ! defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			return false;
		}

		return version_compare( (string) THE_SEO_FRAMEWORK_VERSION, self::MINIMUM_VERSION, '>=' );
	}

	protected function observe_host_graph(): void {
		add_filter( 'the_seo_framework_schema_graph_data', $this->capture( ... ) );
	}
}
