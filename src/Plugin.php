<?php
/**
 * Main plugin class.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie;

use Hallie\Admin\SettingsPage;
use Hallie\Admin\SettingsRestController;
use Hallie\Reviews\Frontend\StructuredData;
use Hallie\Reviews\PostType\DisplayOptionsMetabox;
use Hallie\Reviews\PostType\ReviewPostType;
use Hallie\Reviews\Repository;
use Hallie\Reviews\Provider\ProviderRegistry;
use Hallie\Reviews\Sync\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin singleton: wires up feature modules.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private ProviderRegistry $providers;

	public static function instance( string $plugin_file ): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self( $plugin_file );
			self::$instance->init();
		}

		return self::$instance;
	}

	private function __construct( private readonly string $plugin_file ) {}

	public function dir(): string {
		return plugin_dir_path( $this->plugin_file );
	}

	public function url(): string {
		return plugin_dir_url( $this->plugin_file );
	}

	private function init(): void {
		$this->providers = new ProviderRegistry();
		$this->providers->register();

		Repository::register();
		new ReviewPostType()->register();
		new DisplayOptionsMetabox()->register();
		new Scheduler( $this->providers )->register();
		new StructuredData( $this->providers )->register();

		// REST requests are not admin requests: gating the controller behind is_admin()
		// would register the routes only where they are never called.
		new SettingsRestController( $this->providers )->register();

		if ( is_admin() ) {
			new SettingsPage( $this )->register();
		}
	}
}
