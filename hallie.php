<?php
/**
 * Plugin Name:       Hallie
 * Plugin URI:        https://hallie.app
 * Description:       Local search optimisation. Syncs verified customer reviews from your Google Business Profile and publishes them with honest structured data attribution. Not affiliated with Google.
 * Version:           0.1.0
 * Requires at least: 7.0.2
 * Requires PHP:      8.4
 * Author:            Hallie
 * Author URI:        https://hallie.app
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hallie
 * Domain Path:       /languages
 *
 * @package Hallie
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'HALLIE_VERSION', '0.1.0' );

require_once __DIR__ . '/src/Autoloader.php';

\Hallie\Autoloader::register();

new \Hallie\Bootstrap( __FILE__ )->register();
