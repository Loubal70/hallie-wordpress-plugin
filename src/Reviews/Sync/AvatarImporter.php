<?php
/**
 * Avatar side-loader.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Sync;

use Hallie\Reviews\AvatarMode;
use Hallie\Reviews\MetaKeys;
use Hallie\Reviews\PostType\ReviewPostType;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Copies remote author pictures into the media library.
 *
 * Serving them from the provider's CDN would leak every visitor's IP to a third party on
 * page load, and those URLs expire.
 */
final class AvatarImporter {

	/** Core waits 300s per file; a hundred of those would hold a batch for hours. */
	private const int DOWNLOAD_TIMEOUT = 15;

	/** Keeps borrowed pictures out of the month folders, and removable in one sweep. */
	private const string UPLOAD_SUBDIR = 'hallie';

	/**
	 * One batch of reviews carrying a given marker.
	 *
	 * Statuses are enumerated rather than asked for as 'any', which drops the ones flagged
	 * exclude_from_search: a binned review would keep its picture with nothing pointing at it.
	 *
	 * @return int[]
	 */
	private function reviews_marked_with( string $meta_key, int $batch_size ): array {
		$ids = get_posts(
			array(
				'post_type'              => ReviewPostType::SLUG,
				'post_status'            => array_keys( get_post_stati() ),
				'posts_per_page'         => $batch_size,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded batch.
				'meta_query'             => array(
					array(
						'key'     => $meta_key,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return array_map( intval( ... ), $ids );
	}

	/**
	 * Remove one batch of the avatars this plugin imported.
	 *
	 * @return bool Whether nothing is left to remove.
	 */
	public function discard_all( int $batch_size = 200 ): bool {
		$imported = $this->reviews_marked_with( MetaKeys::SYS_AVATAR_ID, $batch_size );

		foreach ( $imported as $review_id ) {
			$this->discard( (int) get_post_meta( $review_id, MetaKeys::SYS_AVATAR_ID, true ) );

			delete_post_meta( $review_id, MetaKeys::SYS_AVATAR_ID );
			delete_post_meta( $review_id, MetaKeys::SYS_AVATAR_HASH );
			delete_post_meta( $review_id, MetaKeys::SYS_AVATAR_PENDING );
		}

		return count( $imported ) < $batch_size;
	}

	/** Import the picture for a review, skipping work already done. */
	private function import( int $post_id, ?string $url ): void {
		if ( null === $url || '' === $url || ! wp_http_validate_url( $url ) ) {
			return;
		}

		if ( $this->already_imported( $post_id, $url ) ) {
			return;
		}

		$attachment_id = $this->sideload( $url, $post_id );

		if ( null === $attachment_id ) {
			return;
		}

		$superseded = (int) get_post_meta( $post_id, MetaKeys::SYS_AVATAR_ID, true );

		update_post_meta( $post_id, MetaKeys::SYS_AVATAR_ID, $attachment_id );
		update_post_meta( $post_id, MetaKeys::SYS_AVATAR_HASH, md5( $url ) );

		$this->discard( $superseded );
	}

	/**
	 * Remove the picture a rotated URL just replaced: those URLs rotate, and every rotation
	 * would otherwise strand one image per review in the media library.
	 */
	private function discard( int $attachment_id ): void {
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return;
		}

		wp_delete_attachment( $attachment_id, true );
	}

	/** Whether this exact picture is already stored, and the attachment still exists. */
	public function already_imported( int $post_id, string $url ): bool {
		$stored_hash = (string) get_post_meta( $post_id, MetaKeys::SYS_AVATAR_HASH, true );
		$stored_id   = (int) get_post_meta( $post_id, MetaKeys::SYS_AVATAR_ID, true );

		return md5( $url ) === $stored_hash && $stored_id > 0 && null !== get_post( $stored_id );
	}

	/**
	 * Fetch one batch of the pictures reviews are owed.
	 *
	 * @return bool Whether the queue is now empty.
	 */
	public function drain_queue( int $batch_size = 20 ): bool {
		$owed = $this->reviews_marked_with( MetaKeys::SYS_AVATAR_PENDING, $batch_size );

		// Checked again, not redundantly: the setting can change between queueing and draining.
		$wanted = AvatarMode::current()->stores_files();

		foreach ( $owed as $review_id ) {
			if ( $wanted ) {
				$this->import( $review_id, (string) get_post_meta( $review_id, MetaKeys::SYS_AVATAR_PENDING, true ) );
			}

			// Cleared whatever happened, a failed fetch included: the next walk queues it
			// again from the hash, so nothing is lost and nothing loops here.
			delete_post_meta( $review_id, MetaKeys::SYS_AVATAR_PENDING );
		}

		return count( $owed ) < $batch_size;
	}

	private function sideload( string $url, int $post_id ): ?int {
		$this->load_media_functions();

		$upload = $this->fetch_file( $url );

		return null === $upload ? null : $this->attach( $upload, $post_id );
	}

	/**
	 * Load the media helpers.
	 *
	 * They live in wp-admin, which neither cron nor REST loads — and both are how a sync
	 * runs. Without this the first import fatals.
	 */
	private function load_media_functions(): void {
		if ( function_exists( 'media_handle_sideload' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * Download the picture and hand back a file ready for the library.
	 *
	 * @return array{name: string, tmp_name: string}|null
	 */
	private function fetch_file( string $url ): ?array {
		$temp_file = download_url( $url, self::DOWNLOAD_TIMEOUT );

		if ( is_wp_error( $temp_file ) ) {
			return null;
		}

		return $this->as_webp( $temp_file, $url );
	}

	/**
	 * Register the file as an attachment on the review.
	 *
	 * A refusal leaves the converted file behind — as_webp() removed the download already,
	 * and media_handle_sideload only cleans up after itself on success. The alt text is
	 * stored yet never rendered: avatar_html() keeps alt="" because the name sits beside the
	 * picture, so this fills the field only the media library shows.
	 *
	 * @param array{name: string, tmp_name: string} $upload File ready to move into the library.
	 */
	private function attach( array $upload, int $post_id ): ?int {
		$author = get_the_title( $post_id );

		$attachment_id = $this->create_attachment(
			$upload,
			$post_id,
			/* translators: %s: review author name. */
			sprintf( __( 'Profile picture for %s', 'hallie' ), $author )
		);

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $upload['tmp_name'] ) ) {
				wp_delete_file( $upload['tmp_name'] );
			}

			return null;
		}

		update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $author ) );

		return (int) $attachment_id;
	}

	/**
	 * Create the attachment, with the plugin's own folder in force for the call.
	 *
	 * The filter is dropped in a finally, or every upload for the rest of the request would
	 * land there too. It is held in a variable because remove_filter matches closures by
	 * identity, and naming it twice would build two of them.
	 *
	 * @param array{name: string, tmp_name: string} $upload      File to move into the library.
	 * @param int                                   $post_id     Review the picture belongs to.
	 * @param string                                $description Attachment title.
	 */
	private function create_attachment( array $upload, int $post_id, string $description ): int|WP_Error {
		$own_directory = $this->own_directory( ... );

		add_filter( 'upload_dir', $own_directory );

		try {
			return media_handle_sideload( $upload, $post_id, $description );
		} finally {
			remove_filter( 'upload_dir', $own_directory );
		}
	}

	/**
	 * Send the picture, and the sizes generated from it, to the plugin's own folder.
	 *
	 * @param array<string, string> $dirs Upload paths WordPress resolved.
	 *
	 * @return array<string, string>
	 */
	private function own_directory( array $dirs ): array {
		$dirs['subdir'] = '/' . self::UPLOAD_SUBDIR;
		$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
		$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];

		return $dirs;
	}

	/**
	 * Convert to WebP when the server can, keeping the original otherwise.
	 *
	 * A third of the weight for the same rendering, on a page showing a dozen at once.
	 *
	 * @return array{name: string, tmp_name: string}
	 */
	private function as_webp( string $temp_file, string $url ): array {
		$original = array(
			'name'     => $this->filename_for( $url ),
			'tmp_name' => $temp_file,
		);

		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return $original;
		}

		$editor = wp_get_image_editor( $temp_file );

		if ( is_wp_error( $editor ) ) {
			return $original;
		}

		$saved = $editor->save( $temp_file . '.webp', 'image/webp' );

		if ( is_wp_error( $saved ) ) {
			return $original;
		}

		wp_delete_file( $temp_file );

		return array(
			'name'     => $this->filename_for( $url, 'webp' ),
			'tmp_name' => $saved['path'],
		);
	}

	/** CDNs often serve extension-less URLs; jpg keeps WordPress from rejecting the mime. */
	private function filename_for( string $url, ?string $force = null ): string {
		$extension = $force ?? $this->extension_of( $url );

		return 'hallie-avatar-' . substr( md5( $url ), 0, 12 ) . '.' . $extension;
	}

	private function extension_of( string $url ): string {
		$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ), true )
			? $extension
			: 'jpg';
	}
}
