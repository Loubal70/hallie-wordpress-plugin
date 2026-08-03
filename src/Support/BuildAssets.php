<?php
/**
 * Compiled asset manifests.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reads what the build wrote next to each bundle.
 *
 * `@wordpress/scripts` emits a `.asset.php` per entry point holding the WordPress script
 * handles the bundle needs and a content hash to bust caches with. Both are wrong to
 * hardcode: the dependency list changes with every import added to the source.
 */
final class BuildAssets {

	/**
	 * Dependencies and version for a built bundle.
	 *
	 * Returns an empty array when the bundle has not been built — a fresh clone then
	 * renders an empty screen instead of enqueueing a script that does not exist.
	 *
	 * @return array{dependencies: string[], version: string}|array{}
	 */
	public static function manifest( string $directory, string $name ): array {
		$path = trailingslashit( $directory ) . 'build/' . $name . '.asset.php';

		if ( ! is_readable( $path ) ) {
			return array();
		}

		$asset = require $path;

		if ( ! is_array( $asset ) ) {
			return array();
		}

		return array(
			'dependencies' => $asset['dependencies'] ?? array(),
			'version'      => (string) ( $asset['version'] ?? HALLIE_VERSION ),
		);
	}
}
