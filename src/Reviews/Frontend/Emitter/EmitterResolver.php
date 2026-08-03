<?php
/**
 * Emitter selection.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Frontend\Emitter;

use Hallie\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Picks how structured data reaches the page.
 *
 * Automatic by default, because a duplicated business entity fails silently. The setting
 * is there for sites where detection guesses wrong.
 */
final class EmitterResolver {

	public const string AUTOMATIC = 'auto';

	/**
	 * Ordered by precedence: an SEO plugin owning the graph always wins over standing alone.
	 *
	 * @return SchemaEmitter[]
	 */
	public function all(): array {
		/**
		 * Filters the available structured data emitters.
		 *
		 * @param SchemaEmitter[] $emitters Registered emitters, most specific first.
		 */
		return (array) apply_filters(
			'hallie_schema_emitters',
			array(
				new YoastEmitter(),
				new SeoFrameworkEmitter(),
				new StandaloneEmitter(),
			)
		);
	}

	/**
	 * The emitter to use on this site.
	 */
	public function resolve(): SchemaEmitter {
		$configured = (string) Settings::get( 'schema_emitter', self::AUTOMATIC );

		$emitter = self::AUTOMATIC === $configured
			? $this->first_supported()
			: $this->find( $configured );

		/**
		 * Filters the selected structured data emitter.
		 *
		 * @param SchemaEmitter $emitter    Emitter about to be used.
		 * @param string        $configured Configured mode, or `auto`.
		 */
		return apply_filters( 'hallie_schema_emitter', $emitter, $configured );
	}

	private function first_supported(): SchemaEmitter {
		foreach ( $this->all() as $emitter ) {
			if ( $emitter->is_supported() ) {
				return $emitter;
			}
		}

		return new StandaloneEmitter();
	}

	/**
	 * A forced emitter that is no longer installed falls back rather than going silent.
	 */
	private function find( string $id ): SchemaEmitter {
		foreach ( $this->all() as $emitter ) {
			if ( $emitter->id() === $id && $emitter->is_supported() ) {
				return $emitter;
			}
		}

		return $this->first_supported();
	}
}
