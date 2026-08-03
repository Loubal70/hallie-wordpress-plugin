<?php
/**
 * Provider registry.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Provider;

use Hallie\Reviews\Contract\ReviewProvider;
use Hallie\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Holds every available review source and resolves the active one.
 *
 * Third parties add a source without touching this plugin:
 *
 *     add_filter( 'hallie_register_providers', function ( array $providers ) {
 *         $providers[] = new My_Provider();
 *         return $providers;
 *     } );
 */
final class ProviderRegistry {

	/** @var array<string, ReviewProvider> */
	private array $providers = array();

	public function register(): void {
		$providers = array( new HallieProvider() );

		/**
		 * Filters the list of available review providers.
		 *
		 * @param ReviewProvider[] $providers Registered providers.
		 */
		$providers = apply_filters( 'hallie_register_providers', $providers );

		foreach ( $providers as $provider ) {
			if ( $provider instanceof ReviewProvider ) {
				$this->providers[ $provider->id() ] = $provider;
			}
		}
	}

	/**
	 * @return array<string, ReviewProvider>
	 */
	public function all(): array {
		return $this->providers;
	}

	public function get( string $id ): ?ReviewProvider {
		return $this->providers[ $id ] ?? null;
	}

	/**
	 * The provider selected in the settings.
	 *
	 * Falls back to the first registered one rather than returning null: a stored provider
	 * id can outlive the plugin that registered it, and callers should never have to guard
	 * against a missing source.
	 */
	public function active(): ReviewProvider {
		$selected = (string) Settings::get( 'provider', HallieProvider::ID );

		$provider = $this->get( $selected ) ?? reset( $this->providers );

		return $provider instanceof ReviewProvider ? $provider : new HallieProvider();
	}
}
