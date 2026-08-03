<?php
/**
 * PSR-4 autoloader for the plugin's classes.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie;

defined( 'ABSPATH' ) || exit;

/**
 * PSR-4 autoloader for the plugin's own classes (no runtime vendor/ is shipped).
 */
final class Autoloader {

	private const string PREFIX = 'Hallie\\';

	public static function register(): void {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	public static function autoload( string $class_name ): void {
		if ( ! self::owns( $class_name ) ) {
			return;
		}

		$file = self::path_for( $class_name );

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}

	private static function owns( string $class_name ): bool {
		return str_starts_with( $class_name, self::PREFIX );
	}

	private static function path_for( string $class_name ): string {
		$relative_class = substr( $class_name, strlen( self::PREFIX ) );

		return __DIR__ . '/' . str_replace( '\\', '/', $relative_class ) . '.php';
	}
}
