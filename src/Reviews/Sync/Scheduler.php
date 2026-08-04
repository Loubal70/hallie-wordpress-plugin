<?php
/**
 * Sync scheduling.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Sync;

use Hallie\Reviews\AvatarMode;
use Hallie\Reviews\Provider\ProviderRegistry;
use Hallie\Support\Settings;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Owns when a sync happens.
 *
 * WP-Cron only fires when someone visits the site, which makes it a poor fit for a job
 * that should run on a schedule. The cron event is therefore a floor, not a guarantee,
 * and `wp hallie sync` is provided so a real system cron can drive it.
 */
final class Scheduler {

	public const string HOOK = 'hallie_sync_reviews';

	/** Pictures are fetched here, apart, so a slow source never delays a sync. */
	public const string AVATARS_HOOK = 'hallie_import_avatars';

	/** Stops the command-line drain if the queue somehow never empties. */
	private const int MAX_DRAIN_PASSES = 1000;

	private const string DEFAULT_INTERVAL = 'hallie_six_hours';

	public function __construct( private readonly ProviderRegistry $providers ) {}

	public function register(): void {
		add_action( self::HOOK, $this->run( ... ) );
		add_action( self::AVATARS_HOOK, self::drain_avatars( ... ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->register_cli();
		}
	}

	/**
	 * Declare the custom intervals.
	 *
	 * Called at plugin-file include time rather than from register(): during an activation
	 * request `plugins_loaded` has already fired, so anything wired there is absent exactly
	 * when the activation hook tries to schedule.
	 */
	public static function register_intervals(): void {
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Six hours, well above the recommended floor.
		add_filter( 'cron_schedules', self::add_schedules( ... ) );
	}

	public static function on_activation(): void {
		self::reschedule();
	}

	public static function on_deactivation(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::AVATARS_HOOK );
	}

	/**
	 * One pass of the picture work, whichever way the setting points.
	 *
	 * Hosting on, the queue is fetched. Hosting off, it is discarded and what was already
	 * stored goes with it. Both are batched, and the pass asks for another until nothing is
	 * left — one event per batch, so each gets a fresh time limit and no request runs long.
	 */
	public static function drain_avatars(): void {
		if ( ! self::settle_pictures() ) {
			self::drain_soon();
		}
	}

	/**
	 * One pass of the picture work, whichever way the setting points.
	 *
	 * Hosting on, the queue is fetched. Hosting off, it is discarded and whatever was
	 * already stored goes with it. Both drivers — cron and the command line — call this, so
	 * a sync run either way settles the same amount of work.
	 *
	 * @return bool Whether nothing is left to do.
	 */
	private static function settle_pictures(): bool {
		$importer = new AvatarImporter();
		$done     = $importer->drain_queue();

		if ( ! AvatarMode::current()->stores_files() ) {
			$done = $importer->discard_all() && $done;
		}

		return $done;
	}

	/** Ask for a drain pass. Idempotent, so callers may say it as often as they like. */
	public static function drain_soon(): void {
		if ( wp_next_scheduled( self::AVATARS_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time(), self::AVATARS_HOOK );
	}

	/**
	 * (Re)install the recurring event at the configured interval.
	 */
	public static function reschedule( int $starts_in = MINUTE_IN_SECONDS ): void {
		wp_clear_scheduled_hook( self::HOOK );

		// Nothing to poll before the source is configured: a scheduled run could only no-op.
		if ( ! self::has_a_source_to_poll() ) {
			return;
		}

		wp_schedule_event( time() + max( 0, $starts_in ), self::interval(), self::HOOK );
	}

	/**
	 * Delay the next run after the provider asked us to slow down.
	 *
	 * The recurring event is re-installed further out rather than replaced by a one-off:
	 * a single 429 must not be able to switch automatic syncing off for good.
	 */
	public static function back_off( int $seconds ): void {
		self::reschedule( max( MINUTE_IN_SECONDS, $seconds ) );
	}

	/**
	 * @param array<string, array{interval: int, display: string}> $schedules Registered intervals.
	 *
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function add_schedules( array $schedules ): array {
		$schedules['hallie_six_hours'] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every six hours (Hallie)', 'hallie' ),
		);

		$schedules['hallie_daily'] = array(
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'Once a day (Hallie)', 'hallie' ),
		);

		return $schedules;
	}

	/**
	 * Whether the configured source can actually be polled right now.
	 */
	private static function has_a_source_to_poll(): bool {
		$registry = new ProviderRegistry();
		$registry->register();

		$provider = $registry->active();

		return $provider->supports_sync() && $provider->is_configured();
	}

	/**
	 * Configured interval, falling back when the stored one is unknown.
	 */
	private static function interval(): string {
		$configured = (string) Settings::get( 'sync_interval', self::DEFAULT_INTERVAL );

		return array_key_exists( $configured, self::add_schedules( array() ) )
			? $configured
			: self::DEFAULT_INTERVAL;
	}

	public function run(): SyncResult {
		$provider = $this->providers->active();

		if ( ! $provider->supports_sync() || ! $provider->is_configured() ) {
			return new SyncResult();
		}

		return new Synchronizer( $provider )->run();
	}

	private function register_cli(): void {
		WP_CLI::add_command(
			'hallie sync',
			$this->sync_from_command_line( ... ),
			array(
				'shortdesc' => 'Pull reviews and their author pictures from the configured provider.',
			)
		);
	}

	/**
	 * Run everything to completion, which the web paths deliberately do not.
	 *
	 * A request handles one batch and reschedules; on the command line there is nothing to
	 * protect and no reason to wait for cron — which the site may not even run.
	 */
	private function sync_from_command_line(): void {
		do {
			$result = $this->run();

			foreach ( $result->errors as $error ) {
				WP_CLI::warning( $error );
			}

			if ( $result->has_errors() ) {
				WP_CLI::error( __( 'Sync finished with errors.', 'hallie' ) );
			}

			if ( $result->in_progress ) {
				WP_CLI::log( $result->summary() );
			}
		} while ( $result->in_progress );

		$this->settle_all_pictures();

		WP_CLI::success( $result->summary() );
	}

	/**
	 * Settle the pictures here and now, batch after batch.
	 *
	 * A system cron driving this command is often the only thing that runs on the site, so
	 * leaving work scheduled would read as a silent failure rather than as deferred work.
	 */
	private function settle_all_pictures(): void {
		for ( $pass = 0; $pass < self::MAX_DRAIN_PASSES; $pass++ ) {
			if ( self::settle_pictures() ) {
				return;
			}
		}

		WP_CLI::warning( __( 'Some author pictures are still pending. Run the command again to continue.', 'hallie' ) );
	}
}
