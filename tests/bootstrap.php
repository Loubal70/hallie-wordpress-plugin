<?php
/**
 * PHPUnit bootstrap.
 *
 * @package Hallie
 */

declare(strict_types=1);

$hallie_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $hallie_tests_dir ) {
	$hallie_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $hallie_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find {$hallie_tests_dir}/includes/functions.php — run bin/install-wp-tests.sh first." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once $hallie_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/hallie.php';
	}
);

require $hallie_tests_dir . '/includes/bootstrap.php';
