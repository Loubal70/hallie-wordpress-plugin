<?php
/**
 * Review provider contract.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Contract;

use Hallie\Reviews\Data\BusinessProfile;
use Hallie\Reviews\Data\ReviewPage;

defined( 'ABSPATH' ) || exit;

/**
 * Contract every review source must satisfy.
 *
 * Nothing outside of the Provider namespace may reference a concrete implementation.
 * Adding a source — or replacing the upstream API entirely — means writing one class
 * against this interface and registering it via the `hallie_register_providers` filter.
 */
interface ReviewProvider {

	/**
	 * Field type whose choices only the provider can resolve, from its own source.
	 *
	 * Everything else in a field declaration is static enough to ship in the code; these
	 * depend on the credentials just entered, so the admin fetches them separately.
	 */
	public const string SELECT_REMOTE = 'select-remote';

	/**
	 * Machine identifier, stored on every synced review. Must be stable across releases.
	 */
	public function id(): string;

	/**
	 * Human-readable name shown in the settings screen.
	 */
	public function label(): string;

	/**
	 * Platform credited as having published the reviews, for attribution markup.
	 *
	 * Empty when reviews originate here rather than on an external platform — claiming a
	 * publisher that did not publish the review is a false attribution.
	 */
	public function platform_name(): string;

	/**
	 * Whether this provider has a source to pull from at all.
	 *
	 * False for reviews authored in the admin: there is nothing to schedule, nothing to
	 * report as synced, and "absent from the source" never means "deleted upstream".
	 */
	public function supports_sync(): bool;

	/**
	 * Whether the provider holds everything it needs to talk to its source.
	 *
	 * Providers that need no configuration (such as manual entry) return true.
	 */
	public function is_configured(): bool;

	/**
	 * Fetch the reviewed business, including its aggregate rating.
	 *
	 * @throws \Hallie\Reviews\Exception\ProviderException When the source cannot be reached.
	 */
	public function fetch_profile( string $profile_id ): ?BusinessProfile;

	/**
	 * Fetch one page of reviews, newest first.
	 *
	 * Null means the source reports no change since the last call — never confuse it with a
	 * page that came back empty, or a caller concludes every review was deleted upstream.
	 * The page carries its own `has_more`, because an implementation that drops unusable
	 * payloads is the only one able to say whether a short page was really the last.
	 *
	 * @throws \Hallie\Reviews\Exception\ProviderException When the source cannot be reached.
	 */
	public function fetch_reviews( string $profile_id, int $page = 1, int $per_page = 100 ): ?ReviewPage;

	/**
	 * Where a site owner can read how this source works, if anywhere.
	 */
	public function documentation_url(): ?string;

	/**
	 * Choices for a field the provider resolves from its own source.
	 *
	 * Lets a `select-remote` field offer real options instead of asking the site owner to
	 * paste an opaque identifier. Returns an empty array when the field has no remote
	 * options, or when the provider is not configured enough to fetch them.
	 *
	 * @return array<int, array{value: string, label: string}>
	 *
	 * @throws \Hallie\Reviews\Exception\ProviderException When the source cannot be reached.
	 */
	public function field_options( string $key ): array;

	/**
	 * Declarative description of the provider's settings, used to build the admin UI.
	 *
	 * Each entry: array{key: string, label: string, type: string, secret?: bool,
	 * help?: string, link?: array{url: string, label: string}}
	 *
	 * The optional `link` is rendered under the field — a setting the user has to go and
	 * fetch elsewhere should say where.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function settings_fields(): array;
}
