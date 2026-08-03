<?php
/**
 * Hallie API provider.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Provider;

use Hallie\Http\Client;
use Hallie\Http\Response;
use Hallie\Reviews\Contract\ReviewProvider;
use Hallie\Reviews\Data\BusinessProfile;
use Hallie\Reviews\Data\ReviewPage;
use Hallie\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Google Business Profile reviews through the Hallie API.
 *
 * Everything specific to that API — endpoints, payload shape, field names — is confined
 * to this class. The rest of the plugin only ever sees FetchedReview and BusinessProfile.
 *
 * @see https://my.hallie.app/docs/api
 */
final class HallieProvider implements ReviewProvider {

	public const string ID = 'hallie';

	private const string BASE_URL = 'https://api.hallie.app/v1';

	private const string TOKENS_URL = 'https://my.hallie.app/organization/api-tokens';

	private const string DOCS_URL = 'https://my.hallie.app/docs/api/reviews';


	/** Upstream caps `per_page` at 100. */
	private const int MAX_PER_PAGE = 100;

	/** Stops the walk if upstream ever reports a page count it does not honour. */
	private const int MAX_PAGES = 100;

	private const string PROFILES_CACHE = 'hallie_profiles';

	/** Long enough that the fallback copy outlives the validator it backs. */
	private const int PROFILES_CACHE_LIFETIME = DAY_IN_SECONDS;

	private ?Client $client = null;

	public function __construct(
		private readonly PayloadMapper $payloads = new PayloadMapper( self::ID ),
	) {}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Hallie', 'hallie' );
	}

	public function platform_name(): string {
		return 'Google';
	}

	public function supports_sync(): bool {
		return true;
	}

	public function is_configured(): bool {
		return '' !== (string) Settings::get( 'provider_token', '' )
			&& '' !== (string) Settings::get( 'profile_id', '' );
	}

	public function field_options( string $key ): array {
		if ( 'profile_id' !== $key || '' === (string) Settings::get( 'provider_token', '' ) ) {
			return array();
		}

		return array_map(
			static fn ( array $profile ): array => array(
				'value' => (string) ( $profile['id'] ?? '' ),
				'label' => trim(
					sprintf(
						'%s — %s',
						(string) ( $profile['name'] ?? '' ),
						(string) ( $profile['address'] ?? '' )
					),
					' —'
				),
			),
			$this->all_profiles()
		);
	}

	/**
	 * Every profile the token grants access to.
	 *
	 * Both callers walk this endpoint with the same parameters, so they share one ETag. The
	 * copy is what the second one falls back on: without it a 304 reads as "no profiles",
	 * emptying the settings dropdown and freezing the advertised rating.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function all_profiles(): array {
		$profiles = array();
		$page     = 1;

		do {
			$response = $this->client()->get(
				'/profiles',
				array(
					'page'     => $page,
					'per_page' => self::MAX_PER_PAGE,
				)
			);

			if ( $response->not_modified ) {
				$cached = get_transient( self::PROFILES_CACHE );

				return is_array( $cached ) ? $cached : array();
			}

			$profiles = array_merge( $profiles, $response->items() );

			++$page;
		} while ( ! $response->is_last_page() && $page <= self::MAX_PAGES );

		set_transient( self::PROFILES_CACHE, $profiles, self::PROFILES_CACHE_LIFETIME );

		return $profiles;
	}

	public function fetch_profile( string $profile_id ): ?BusinessProfile {
		// The API exposes a collection rather than a single-profile endpoint, so the one
		// being looked for is picked out of the full list.
		foreach ( $this->all_profiles() as $item ) {
			if ( isset( $item['id'] ) && (string) $item['id'] === $profile_id ) {
				return $this->payloads->profile( $item );
			}
		}

		return null;
	}

	public function fetch_reviews( string $profile_id, int $page = 1, int $per_page = self::MAX_PER_PAGE ): ?ReviewPage {
		$current = max( 1, $page );

		// An empty answer must mean "no more reviews", never "this page happened to hold
		// nothing usable" — the caller stops walking on the first empty array, and would
		// unpublish everything that follows.
		do {
			$response = $this->request_reviews( $profile_id, $current, $per_page );

			if ( $response->not_modified ) {
				return null;
			}

			$reviews = array_values(
				array_filter(
					array_map( $this->payloads->review( ... ), $response->items() )
				)
			);

			if ( array() !== $reviews ) {
				return new ReviewPage( $reviews, ! $response->is_last_page() );
			}

			++$current;
		} while ( ! $response->is_last_page() && $current - $page < self::MAX_PAGES );

		return new ReviewPage( array(), false );
	}

	private function request_reviews( string $profile_id, int $page, int $per_page ): Response {
		return $this->client()->get(
			sprintf( '/profiles/%s/reviews', rawurlencode( $profile_id ) ),
			array(
				'page'      => $page,
				'per_page'  => min( self::MAX_PER_PAGE, max( 1, $per_page ) ),
				'sort'      => 'reviewed_at',
				'direction' => 'desc',
			)
		);
	}

	public function documentation_url(): ?string {
		return self::DOCS_URL;
	}

	public function settings_fields(): array {
		return array(
			array(
				'key'    => 'provider_token',
				'label'  => __( 'API token', 'hallie' ),
				'type'   => 'password',
				'secret' => true,
				'help'   => __( 'Created by the account owner in your Hallie organisation settings.', 'hallie' ),
				'link'   => array(
					'url'   => self::TOKENS_URL,
					'label' => __( 'Create a token', 'hallie' ),
				),
			),
			array(
				'key'   => 'profile_id',
				'label' => __( 'Business profile', 'hallie' ),
				'type'  => self::SELECT_REMOTE,
				'help'  => __( 'Choose among the profiles your token grants access to.', 'hallie' ),
			),
		);
	}

	private function client(): Client {
		if ( null === $this->client ) {
			$this->client = new Client(
				(string) apply_filters( 'hallie_api_base_url', self::BASE_URL ),
				(string) Settings::get( 'provider_token', '' )
			);
		}

		return $this->client;
	}
}
