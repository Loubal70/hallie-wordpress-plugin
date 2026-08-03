<?php
/**
 * Structured data emitter contract.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Frontend\Emitter;

use Hallie\Reviews\Frontend\ReviewSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Publishes a review payload into the page.
 *
 * Split in two because of ordering: SEO plugins print their graph during `wp_head`, before
 * any review has been rendered. `register()` runs early enough to observe that graph,
 * `emit()` supplies the payload once the page knows what it displayed.
 */
interface SchemaEmitter {

	/**
	 * Machine identifier, stored in the settings when the mode is forced.
	 */
	public function id(): string;


	/**
	 * Whether this emitter can run on the current site.
	 */
	public function is_supported(): bool;

	/**
	 * Hook early: observe the host graph, reserve the output slot.
	 */
	public function register(): void;

	/**
	 * Hand over what the page rendered.
	 */
	public function emit( ReviewSchema $schema ): void;
}
