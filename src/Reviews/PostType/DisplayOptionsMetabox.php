<?php
/**
 * Editor overrides metabox.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\PostType;

use Hallie\Reviews\MetaKeys;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The editing surface for a review.
 *
 * Narrow on purpose: a synced rating, body and date record what someone published
 * elsewhere, so only presentation is editable — displayed name, visibility, ordering.
 */
final class DisplayOptionsMetabox {

	private const string NONCE_ACTION = 'hallie_save_overrides';

	private const string NONCE_NAME = 'hallie_overrides_nonce';

	private const string FIELDS_PRESENT = 'hallie_overrides_rendered';

	public function register(): void {
		add_action( 'add_meta_boxes', $this->add_boxes( ... ) );
		add_action( 'save_post_' . ReviewPostType::SLUG, $this->save( ... ) );
	}

	public function add_boxes(): void {
		add_meta_box(
			'hallie-source',
			__( 'Review as published', 'hallie' ),
			$this->render_source( ... ),
			ReviewPostType::SLUG,
			'normal',
			'high'
		);

		add_meta_box(
			'hallie-overrides',
			__( 'Display options', 'hallie' ),
			$this->render_overrides( ... ),
			ReviewPostType::SLUG,
			'side',
			'default'
		);
	}

	public function render_source( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$rating   = (int) get_post_meta( $post->ID, MetaKeys::SRC_RATING, true );
		$provider = (string) get_post_meta( $post->ID, MetaKeys::SRC_PROVIDER, true );
		$changed  = (string) get_post_meta( $post->ID, MetaKeys::SYS_CONTENT_CHANGED_AT, true );

		if ( '' !== $changed ) {
			wp_admin_notice(
				__( 'Its author edited this review after you last customised it. Check that your display options still make sense.', 'hallie' ),
				array(
					'type'               => 'info',
					'additional_classes' => array( 'inline' ),
				)
			);
		}

		echo '<table class="widefat striped"><tbody>';
		$this->render_summary_row( __( 'Rating', 'hallie' ), str_repeat( '★', $rating ) . str_repeat( '☆', max( 0, 5 - $rating ) ) );
		$this->render_summary_row( __( 'Author', 'hallie' ), (string) get_post_meta( $post->ID, MetaKeys::SRC_AUTHOR_NAME, true ) );
		$this->render_summary_row( __( 'Published', 'hallie' ), get_the_date( '', $post ) );
		$this->render_summary_row( __( 'Source', 'hallie' ), $provider );
		echo '</tbody></table>';

		printf(
			'<p style="margin-top:1em"><strong>%s</strong></p><blockquote style="white-space:pre-wrap">%s</blockquote>',
			esc_html__( 'Review', 'hallie' ),
			esc_html( $post->post_content )
		);

		$reply = (string) get_post_meta( $post->ID, MetaKeys::SRC_REPLY_COMMENT, true );

		if ( '' !== $reply ) {
			printf(
				'<p><strong>%s</strong></p><blockquote style="white-space:pre-wrap">%s</blockquote>',
				esc_html__( 'Your reply', 'hallie' ),
				esc_html( $reply )
			);
		}
	}

	public function render_overrides( WP_Post $post ): void {
		// Marks the fields as actually rendered. The block editor skips this box but still
		// posts the metabox form, and without this an absent checkbox would read as
		// "unchecked" and wipe what the sidebar panel just saved.
		printf( '<input type="hidden" name="%s" value="1" />', esc_attr( self::FIELDS_PRESENT ) );

		$hidden   = (bool) get_post_meta( $post->ID, MetaKeys::OVR_HIDDEN, true );
		$featured = (bool) get_post_meta( $post->ID, MetaKeys::OVR_FEATURED, true );

		printf(
			'<p><label><input type="checkbox" name="hallie_featured" value="1" %s /> %s</label></p>',
			checked( $featured, true, false ),
			esc_html__( 'Show first', 'hallie' )
		);

		printf(
			'<p><label><input type="checkbox" name="hallie_hidden" value="1" %s /> %s</label></p>',
			checked( $hidden, true, false ),
			esc_html__( 'Hide from the site', 'hallie' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Set a featured image to replace the author picture — useful when someone reviewed you from an account with no photo.', 'hallie' )
		);
	}

	public function save( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		// Everything read from $_POST stays in this method, below the nonce check, so the
		// guard is verifiable by reading a single function.
		if ( isset( $_POST[ self::FIELDS_PRESENT ] ) ) {
			$this->save_or_delete( $post_id, MetaKeys::OVR_HIDDEN, isset( $_POST['hallie_hidden'] ) ? '1' : null );
			$this->save_or_delete( $post_id, MetaKeys::OVR_FEATURED, isset( $_POST['hallie_featured'] ) ? '1' : null );
		}
	}

	/**
	 * Absent overrides are deleted rather than stored empty, so `NOT EXISTS` queries stay
	 * meaningful when filtering hidden reviews.
	 */
	private function save_or_delete( int $post_id, string $key, ?string $value ): void {
		if ( null === $value ) {
			delete_post_meta( $post_id, $key );

			return;
		}

		update_post_meta( $post_id, $key, $value );
	}

	private function render_summary_row( string $label, string $value ): void {
		printf(
			'<tr><th scope="row" style="width:8em">%s</th><td>%s</td></tr>',
			esc_html( $label ),
			esc_html( '' !== $value ? $value : '—' )
		);
	}
}
