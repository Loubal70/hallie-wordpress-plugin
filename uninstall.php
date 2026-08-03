<?php
/**
 * Uninstall routine.
 *
 * Configuration always goes; reviews only if the site owner asked for it. Deleting
 * content on uninstall by default would destroy work that a reinstall cannot recover —
 * an override photo, a hidden review, a hand-written testimonial.
 *
 * @package Hallie
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Autoloader.php';

\Hallie\Autoloader::register();

use Hallie\Reviews\MetaKeys;
use Hallie\Reviews\PostType\ReviewPostType;
use Hallie\Reviews\Sync\Scheduler;
use Hallie\Reviews\Sync\Synchronizer;
use Hallie\Support\Settings;

/**
 * Remove everything this plugin created on the current site.
 */
function hallie_uninstall_site(): void {
	$delete_content = (bool) Settings::get( 'delete_data', false );

	wp_clear_scheduled_hook( Scheduler::HOOK );
	wp_clear_scheduled_hook( Scheduler::AVATARS_HOOK );

	if ( $delete_content ) {
		hallie_uninstall_reviews();
	}

	delete_option( Settings::OPTION );
	delete_option( Synchronizer::PROFILE_OPTION );

	hallie_uninstall_transients();
}

/**
 * Forget every transient this plugin ever wrote.
 *
 * Swept by the plugin-wide prefix rather than asked of each owner: uninstalling is the one
 * place that must not miss a cache, and a class added later would otherwise leave its own
 * behind with nothing to notice.
 */
function hallie_uninstall_transients(): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transients have no delete-by-prefix API.
	$names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_hallie_' ) . '%'
		)
	);

	foreach ( $names as $name ) {
		delete_transient( str_replace( '_transient_', '', (string) $name ) );
	}
}

/**
 * Delete stored reviews, along with the avatars imported for them.
 */
function hallie_uninstall_reviews(): void {
	$review_ids = get_posts(
		array(
			'post_type'              => ReviewPostType::SLUG,
			// Not 'any', which drops the statuses flagged exclude_from_search: a binned
			// review would survive a cleanup the site owner explicitly asked for.
			'post_status'            => array_keys( get_post_stati() ),
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		)
	);

	foreach ( $review_ids as $review_id ) {
		// Only the avatar this plugin imported. Attachment parentage would also match an
		// image the editor uploaded from this screen, which is theirs, not ours.
		$imported = (int) get_post_meta( $review_id, MetaKeys::SYS_AVATAR_ID, true );

		if ( $imported > 0 ) {
			wp_delete_attachment( $imported, true );
		}

		wp_delete_post( $review_id, true );
	}
}

/**
 * Run the cleanup on every site of the install.
 */
function hallie_uninstall(): void {
	if ( ! is_multisite() ) {
		hallie_uninstall_site();

		return;
	}

	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $blog_id ) {
		switch_to_blog( (int) $blog_id );
		hallie_uninstall_site();
		restore_current_blog();
	}
}

hallie_uninstall();
