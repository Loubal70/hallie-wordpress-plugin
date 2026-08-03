<?php
/**
 * Settings REST endpoints.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Admin;

use Hallie\Reviews\AvatarMode;
use Hallie\Reviews\Contract\ReviewProvider;
use Hallie\Reviews\Exception\ProviderException;
use Hallie\Reviews\Provider\ProviderRegistry;
use Hallie\Reviews\Sync\Scheduler;
use Hallie\Reviews\Sync\Synchronizer;
use Hallie\Support\SecretCipher;
use Hallie\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Backs the settings screen.
 *
 * Secrets travel one way only: a saved token is returned masked, never in clear, so
 * opening the settings screen cannot leak it to anyone who can read the page source.
 */
final class SettingsRestController {

	private const string NAMESPACE = 'hallie/v1';

	public function __construct( private readonly ProviderRegistry $providers ) {}

	public function register(): void {
		add_action( 'rest_api_init', $this->register_routes( ... ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => $this->read_settings( ... ),
					'permission_callback' => $this->can_manage( ... ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => $this->save_settings( ... ),
					'permission_callback' => $this->can_manage( ... ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/options/(?P<field>[a-z0-9_-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => $this->read_field_options( ... ),
				'permission_callback' => $this->can_manage( ... ),
				'args'                => array(
					'field' => array(
						'description' => __( 'Key of the field whose choices are wanted.', 'hallie' ),
						'type'        => 'string',
						'required'    => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/sync',
			array(
				'methods'             => 'POST',
				'callback'            => $this->run_sync( ... ),
				'permission_callback' => $this->can_manage( ... ),
			)
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function read_settings(): WP_REST_Response {
		$settings = Settings::all();
		$token    = (string) Settings::get( 'provider_token', '' );
		$stored   = (string) ( $settings['provider_token'] ?? '' );

		unset( $settings['provider_token'] );

		return new WP_REST_Response(
			array(
				'settings'        => $settings,
				'token'           => SecretCipher::mask( $token ),
				'tokenUnreadable' => SecretCipher::is_unreadable( $stored ),
				'providers'       => $this->describe_providers(),
			)
		);
	}

	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$values = $this->submitted_settings( $request );

		// Called before the write so it can compare the incoming value against the stored one.
		$cadence_moved = $this->changes_the_schedule( $values );

		Settings::update( $values );

		$this->reconcile_avatar_storage();

		if ( $cadence_moved ) {
			Scheduler::reschedule();
		}

		return $this->read_settings();
	}


	/**
	 * What the form sent, reduced to values worth storing.
	 *
	 * Absent keys are left as they are: Settings::update() writes only what it receives, so
	 * a secret the admin never retyped is simply not part of the request.
	 *
	 * @return array<string, mixed>
	 */
	private function submitted_settings( WP_REST_Request $request ): array {
		$values = array();

		foreach ( (array) $request->get_param( 'settings' ) as $key => $value ) {
			$values[ sanitize_key( (string) $key ) ] = $this->clean( $value );
		}

		// Belt and braces: the screen shows the mask as a placeholder and never submits it,
		// but this endpoint answers anything holding the capability — a browser still running
		// a cached bundle included. Storing a mask locks the site out of its own source.
		if ( isset( $values['provider_token'] ) && SecretCipher::is_mask( (string) $values['provider_token'] ) ) {
			unset( $values['provider_token'] );
		}

		return Settings::only_known( $values );
	}

	/**
	 * Whether the cron entry has to be reinstalled; storing a new cadence moves nothing.
	 *
	 * @param array<string, mixed> $values Settings about to be stored.
	 */
	private function changes_the_schedule( array $values ): bool {
		return array_any( array( 'sync_interval', 'provider' ), fn( $key ) => isset( $values[ $key ] ) && Settings::get( $key ) !== $values[ $key ] );
	}

	/**
	 * Reconciled on every save rather than on a transition, so a state that already
	 * disagrees with the setting — an interrupted change, a value written elsewhere —
	 * repairs itself. Asking for nothing costs nothing.
	 */
	private function reconcile_avatar_storage(): void {
		if ( AvatarMode::current()->stores_files() ) {
			return;
		}

		// Handed to the background job rather than done here: a large library takes many
		// batches, and the screen should not wait for any of them.
		Scheduler::drain_soon();
	}

	/**
	 * Choices the active provider resolves from its own source.
	 */
	public function read_field_options( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$provider = $this->providers->active();
		$field    = (string) $request['field'];
		$declared = $this->declared_field( $provider, $field );

		// An empty list is a legitimate answer — no token yet, so no profile to offer. Both
		// refusals below would otherwise look exactly like it, and a typo in a provider's
		// declaration would surface as a silently empty dropdown.
		if ( null === $declared ) {
			return new WP_Error(
				'hallie_unknown_field',
				__( 'This provider declares no field by that name.', 'hallie' ),
				array( 'status' => 404 )
			);
		}

		if ( ReviewProvider::SELECT_REMOTE !== ( $declared['type'] ?? '' ) ) {
			return new WP_Error(
				'hallie_field_is_not_remote',
				__( 'This field does not take its choices from the source.', 'hallie' ),
				array( 'status' => 400 )
			);
		}

		try {
			$options = $provider->field_options( $field );
		} catch ( ProviderException $exception ) {
			return new WP_Error(
				'hallie_options_unavailable',
				$exception->getMessage(),
				array( 'status' => 502 )
			);
		}

		return new WP_REST_Response( array( 'options' => $options ) );
	}

	/**
	 * The provider's own declaration for a field, or null if it declares none.
	 *
	 * @return array<string, mixed>|null
	 */
	private function declared_field( ReviewProvider $provider, string $field ): ?array {
		return array_find(
			$provider->settings_fields(),
			static fn ( array $declared ): bool => ( $declared['key'] ?? '' ) === $field
		);
	}

	public function run_sync(): WP_REST_Response|WP_Error {
		$provider = $this->providers->active();

		if ( ! $provider->supports_sync() || ! $provider->is_configured() ) {
			return new WP_Error(
				'hallie_provider_not_configured',
				__( 'Finish configuring the provider before syncing.', 'hallie' ),
				array( 'status' => 400 )
			);
		}

		$result = new Synchronizer( $provider )->run();

		// The synchroniser reports failures in the result rather than by throwing, so a
		// 200 with an errors array would read as a success on the client.
		if ( $result->has_errors() ) {
			return new WP_Error(
				'hallie_sync_failed',
				implode( ' ', $result->errors ),
				array( 'status' => 502 )
			);
		}

		return new WP_REST_Response(
			array(
				'summary' => $result->summary(),
				'created' => $result->created,
				'updated' => $result->updated,
			)
		);
	}

	/**
	 * Reduce an arbitrary posted value to a scalar this plugin can store.
	 *
	 * Every declared setting is a string or a boolean, so anything else — an array posted
	 * as `settings[provider_token][]`, for one — is rejected rather than passed through.
	 */
	private function clean( mixed $value ): string|bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_scalar( $value ) ) {
			return sanitize_text_field( (string) $value );
		}

		return '';
	}

	/**
	 * Provider list with the fields each one needs, so the screen builds itself.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function describe_providers(): array {
		$described = array();

		foreach ( $this->providers->all() as $provider ) {
			$described[] = array(
				'id'            => $provider->id(),
				'label'         => $provider->label(),
				'configured'    => $provider->is_configured(),
				'supportsSync'  => $provider->supports_sync(),
				'documentation' => $provider->documentation_url(),
				'platform'      => $provider->platform_name(),
				'fields'        => $provider->settings_fields(),
			);
		}

		return $described;
	}
}
