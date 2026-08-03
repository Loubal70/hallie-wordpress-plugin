<?php
/**
 * Settings screen.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Admin;

use Hallie\Plugin;
use Hallie\Support\BuildAssets;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the settings screen and mounts the admin app on it.
 */
final class SettingsPage {

	public const string SLUG = 'hallie';

	private const string SCRIPT_HANDLE = 'hallie-settings';

	public function __construct( private readonly Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', $this->add_page( ... ) );
		add_action( 'admin_enqueue_scripts', $this->enqueue_assets( ... ) );
	}

	public function add_page(): void {
		add_options_page(
			__( 'Hallie', 'hallie' ),
			__( 'Hallie', 'hallie' ),
			'manage_options',
			self::SLUG,
			$this->render( ... )
		);
	}

	public function render(): void {
		printf(
			'<div class="wrap"><h1>%s</h1><div id="hallie-settings-root"></div></div>',
			esc_html__( 'Hallie', 'hallie' )
		);
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		$asset = BuildAssets::manifest( $this->plugin->dir(), 'settings' );

		if ( array() === $asset ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$this->plugin->url() . 'build/settings.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style( 'wp-components' );

		wp_enqueue_style(
			self::SCRIPT_HANDLE,
			$this->plugin->url() . 'build/style-settings.css',
			array( 'wp-components' ),
			$asset['version']
		);

		// Path is required: without it WordPress only checks WP_LANG_DIR/plugins, not the plugin's bundled languages folder.
		wp_set_script_translations( self::SCRIPT_HANDLE, 'hallie', $this->plugin->dir() . 'languages' );
	}
}
