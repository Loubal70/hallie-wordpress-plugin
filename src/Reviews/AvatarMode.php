<?php
/**
 * How author pictures reach the page.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews;

use Hallie\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The three ways to show — or not show — an author's picture.
 *
 * They trade three different things against each other, and no single answer is right for
 * every site: bandwidth and storage, visitor privacy, and how the wall looks.
 */
enum AvatarMode: string {

	/** Downloaded once into the media library: no third-party call, images stay available. */
	case Local = 'local';

	/** Served from the provider: nothing stored, but every visitor's IP reaches them. */
	case Remote = 'remote';

	/** Initials only: nothing stored, nothing requested, nothing leaked. */
	case None = 'none';

	public static function current(): self {
		return self::tryFrom( (string) Settings::get( 'avatars', self::None->value ) ) ?? self::None;
	}

	public function stores_files(): bool {
		return self::Local === $this;
	}

	public function shows_pictures(): bool {
		return self::None !== $this;
	}
}
