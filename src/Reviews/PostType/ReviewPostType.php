<?php
/**
 * Review post type.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\PostType;

use Hallie\Reviews\MetaKeys;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the storage for reviews.
 *
 * Reviews are content, so they get a real post type and their own menu — but no public
 * URL: republishing each review on its own permalink would create thin pages duplicating
 * the source listing, with nothing to gain.
 *
 * No review can be added, and none of its substance can be changed: the rating, the body,
 * the author and the date record what someone published elsewhere. Only presentation is
 * editable — the displayed name, the picture, the ordering.
 *
 * The block editor is deliberately not used: this screen is a short read-only record plus
 * three display options, which the classic metaboxes render natively and compactly.
 * Writes to the body are refused server-side all the same.
 */
final class ReviewPostType {

	public const string SLUG = 'hallie_review';

	/**
	 * Every status a stored review may hold.
	 *
	 * Not `'any'`, which drops the statuses flagged `exclude_from_search` — the bin among
	 * them, leaving a binned review invisible to reconciliation and to picture cleanup.
	 *
	 * @return string[]
	 */
	public static function every_status(): array {
		return array_keys( get_post_stati() );
	}

	public function register(): void {
		add_action( 'after_setup_theme', $this->claim_thumbnail_support( ... ), 20 );
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_filter( 'manage_' . self::SLUG . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::SLUG . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-' . self::SLUG . '_sortable_columns', array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts', $this->apply_meta_ordering( ... ) );
		add_filter( 'update_post_metadata', $this->delete_falsy_toggle( ... ), 10, 4 );
		add_filter( 'rest_pre_insert_' . self::SLUG, $this->reject_body_edits( ... ), 10, 2 );
		add_action( 'before_delete_post', $this->discard_imported_avatar( ... ) );
	}

	/**
	 * Make sure featured images are available for reviews.
	 *
	 * Declaring `thumbnail` in `supports` is not enough: the featured image box only
	 * appears when the active theme opted into post thumbnails, and a theme that opted in
	 * for its own post types only would silently hide it here.
	 */
	public function claim_thumbnail_support(): void {
		$support = get_theme_support( 'post-thumbnails' );

		// The theme enabled it for everything; nothing to add.
		if ( true === $support ) {
			return;
		}

		$post_types = is_array( $support ) && isset( $support[0] ) && is_array( $support[0] )
			? $support[0]
			: array();

		add_theme_support( 'post-thumbnails', array_merge( $post_types, array( self::SLUG ) ) );
	}

	public function register_post_type(): void {
		register_post_type(
			self::SLUG,
			array(
				'labels'                => array(
					'name'               => _x( 'Reviews', 'post type general name', 'hallie' ),
					'singular_name'      => _x( 'Review', 'post type singular name', 'hallie' ),
					'menu_name'          => _x( 'Reviews', 'admin menu', 'hallie' ),
					'all_items'          => __( 'All reviews', 'hallie' ),
					'add_new'            => __( 'Add review', 'hallie' ),
					'add_new_item'       => __( 'Add review', 'hallie' ),
					'edit_item'          => __( 'Edit review', 'hallie' ),
					'search_items'       => __( 'Search reviews', 'hallie' ),
					'not_found'          => __( 'No reviews yet.', 'hallie' ),
					'not_found_in_trash' => __( 'No reviews in the bin.', 'hallie' ),
				),
				'public'                => false,
				'publicly_queryable'    => false,
				'exclude_from_search'   => true,
				'show_ui'               => true,
				'show_in_menu'          => true,
				'show_in_rest'          => true,
				'rest_base'             => 'hallie-reviews',
				'rest_controller_class' => ReviewRestController::class,
				'menu_position'         => 26,
				'menu_icon'             => 'dashicons-star-filled',
				'hierarchical'          => false,
				'has_archive'           => false,
				'rewrite'               => false,
				'query_var'             => false,
				'supports'              => array( 'title', 'thumbnail' ),
				'map_meta_cap'          => true,
				// Reviews are never created here — they mirror testimonials published elsewhere, so authoring one by hand would mean fabricating it.
				'capabilities'          => array( 'create_posts' => 'do_not_allow' ),
			)
		);
	}

	/**
	 * Exposes meta over REST so the editor UI and future blocks can read it.
	 *
	 * Source values are registered read-only: nothing but the synchroniser may write them.
	 */
	public function register_meta(): void {
		foreach ( MetaKeys::source_keys() as $key ) {
			$this->register_meta_field( $key, 'string', false );
		}

		$this->register_meta_field( MetaKeys::OVR_HIDDEN, 'boolean', true );
		$this->register_meta_field( MetaKeys::OVR_FEATURED, 'boolean', true );
		$this->register_meta_field( MetaKeys::SYS_AVATAR_ID, 'integer', false );
		$this->register_meta_field( MetaKeys::SYS_CONTENT_CHANGED_AT, 'string', false );
	}

	/**
	 * @param array<string, string> $columns Registered columns.
	 *
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		unset( $columns['date'] );

		return array_merge(
			array(
				'cb'    => $columns['cb'] ?? '',
				'title' => __( 'Author', 'hallie' ),
			),
			array(
				'hallie_rating'   => __( 'Rating', 'hallie' ),
				'hallie_excerpt'  => __( 'Review', 'hallie' ),
				'hallie_provider' => __( 'Source', 'hallie' ),
				'hallie_state'    => __( 'State', 'hallie' ),
				'hallie_date'     => __( 'Published', 'hallie' ),
			)
		);
	}

	public function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'hallie_rating':
				$rating = (int) get_post_meta( $post_id, MetaKeys::SRC_RATING, true );
				echo esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', max( 0, 5 - $rating ) ) );
				break;

			case 'hallie_excerpt':
				echo esc_html( wp_trim_words( (string) get_post_field( 'post_content', $post_id ), 18 ) );
				break;

			case 'hallie_provider':
				echo esc_html( (string) get_post_meta( $post_id, MetaKeys::SRC_PROVIDER, true ) );
				break;

			case 'hallie_state':
				$this->render_state( $post_id );
				break;

			case 'hallie_date':
				echo esc_html( get_the_date( '', $post_id ) );
				break;
		}
	}

	/**
	 * @param array<string, string> $columns Sortable columns.
	 *
	 * @return array<string, string>
	 */
	public function sortable_columns( array $columns ): array {
		$columns['hallie_date']   = 'date';
		$columns['hallie_rating'] = MetaKeys::SRC_RATING;

		return $columns;
	}

	/**
	 * Delete the avatar imported for a review being deleted.
	 *
	 * WordPress leaves attachments behind when their parent goes, so without this every
	 * deleted review would strand an image in the media library and a file on disk. Only
	 * what the plugin imported is removed; a picture the editor chose is theirs.
	 */
	public function discard_imported_avatar( int $post_id ): void {
		if ( self::SLUG !== get_post_type( $post_id ) ) {
			return;
		}

		$imported = (int) get_post_meta( $post_id, MetaKeys::SYS_AVATAR_ID, true );

		if ( $imported > 0 ) {
			wp_delete_attachment( $imported, true );
		}
	}

	/**
	 * Refuse any attempt to rewrite the body of a review.
	 *
	 * The editor is supported so the block editor loads — that is what gives the review
	 * its native sidebar panel — but the text itself records what someone published
	 * elsewhere. Enforcing that here rather than by hiding the field means the rule holds
	 * for the REST API too, not only for whoever is looking at the screen.
	 *
	 * The synchroniser is unaffected: it writes through wp_insert_post(), not REST.
	 *
	 * @param \stdClass        $prepared Post about to be inserted or updated.
	 * @param \WP_REST_Request $request  Incoming request.
	 *
	 * @return \stdClass|\WP_Error
	 */
	public function reject_body_edits( $prepared, $request ) {
		$submitted = $request['content'];

		if ( ! isset( $submitted ) ) {
			return $prepared;
		}

		$body   = is_array( $submitted ) ? ( $submitted['raw'] ?? '' ) : $submitted;
		$stored = (string) get_post_field( 'post_content', (int) ( $prepared->ID ?? 0 ) );

		if ( $body === $stored ) {
			return $prepared;
		}

		return new \WP_Error(
			'hallie_review_body_is_read_only',
			__( 'The text of a review cannot be changed: it records what its author published elsewhere.', 'hallie' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Store a switched-off toggle as an absent row, not an empty one.
	 *
	 * The REST path writes `false` as an empty string, which leaves a row behind. Queries
	 * would then need an OR group to tell "off" from "never set", and such a group
	 * multiplies result rows through its joins. Deleting keeps NOT EXISTS meaningful.
	 *
	 * @param mixed  $check    Short-circuit value; null lets WordPress proceed.
	 * @param int    $post_id  Post being updated.
	 * @param string $meta_key Meta key being written.
	 * @param mixed  $value    Value being written.
	 *
	 * @return mixed True when handled here, untouched otherwise.
	 */
	public function delete_falsy_toggle( mixed $check, int $post_id, string $meta_key, mixed $value ): mixed {
		$toggles = array( MetaKeys::OVR_HIDDEN, MetaKeys::OVR_FEATURED );

		if ( ! in_array( $meta_key, $toggles, true ) || ! empty( $value ) ) {
			return $check;
		}

		delete_post_meta( $post_id, $meta_key );

		return true;
	}

	/**
	 * Make the sortable rating column actually sort.
	 *
	 * Declaring a column sortable only tells WordPress which `orderby` value to pass back;
	 * turning a meta key into an ORDER BY is left to the plugin.
	 */
	public function apply_meta_ordering( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( MetaKeys::SRC_RATING !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set( 'meta_key', MetaKeys::SRC_RATING );
		$query->set( 'orderby', 'meta_value_num' );
	}

	/**
	 * Surfaces the states an editor needs to act on, starting with an upstream edit that
	 * may have invalidated an override.
	 */
	private function render_state( int $post_id ): void {
		$states = array();

		if ( '' !== (string) get_post_meta( $post_id, MetaKeys::SYS_CONTENT_CHANGED_AT, true ) ) {
			$states[] = __( 'Edited by its author', 'hallie' );
		}

		if ( get_post_meta( $post_id, MetaKeys::OVR_HIDDEN, true ) ) {
			$states[] = __( 'Hidden', 'hallie' );
		}

		if ( has_post_thumbnail( $post_id ) ) {
			$states[] = __( 'Custom photo', 'hallie' );
		}

		echo esc_html( empty( $states ) ? '—' : implode( ', ', $states ) );
	}

	private function register_meta_field( string $key, string $type, bool $editable ): void {
		register_post_meta(
			self::SLUG,
			$key,
			array(
				'single'        => true,
				'type'          => $type,
				'show_in_rest'  => true,
				'auth_callback' => static fn (): bool => $editable && current_user_can( 'edit_posts' ),
			)
		);
	}
}
