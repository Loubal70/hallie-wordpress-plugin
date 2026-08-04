<?php
/**
 * Linked emitter with no host plugin behind it.
 *
 * @package Hallie
 */

declare(strict_types=1);

use Hallie\Reviews\Frontend\Emitter\LinkedEmitter;

/**
 * A concrete LinkedEmitter, so the shared behaviour can be exercised.
 *
 * Yoast and The SEO Framework differ only in the filter they listen on. Tests hand the
 * graph over directly, which is what those filters would have done.
 */
final class Hallie_Fake_Linked_Emitter extends LinkedEmitter {

	public const string ID = 'fake-linked';

	public function is_supported(): bool {
		return true;
	}

	protected function observe_host_graph(): void {}
}
