<?php
/**
 * Plugin bootstrap.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie;

use Hallie\Reviews\Sync\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Start-up wiring: lifecycle hooks, then hand-off to Plugin once WordPress is loaded.
 */
final readonly class Bootstrap {

	public function __construct( private string $plugin_file ) {}

	public function register(): void {
		// Before the activation hook, which runs in a request where plugins_loaded is past.
		Scheduler::register_intervals();

		register_activation_hook( $this->plugin_file, Scheduler::on_activation( ... ) );
		register_deactivation_hook( $this->plugin_file, Scheduler::on_deactivation( ... ) );

		add_action( 'plugins_loaded', $this->boot( ... ) );
	}

	public function boot(): void {
		Plugin::instance( $this->plugin_file );
	}
}
