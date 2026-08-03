<?php
/**
 * REST controller for the review post type.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\PostType;

use WP_Error;
use WP_REST_Posts_Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Holds reading the collection to the same capability as opening the screen.
 *
 * The post type needs `show_in_rest` for its editing screen; without it WordPress falls
 * back to the classic editor. That same flag opens /wp/v2/hallie-reviews, where core lets
 * any published post through to anonymous callers. The reviews are public on the source
 * listing, but serving them back as a machine-readable roster — the ones an editor chose
 * to hide included — is not what `public => false` leads anyone to expect.
 */
final class ReviewRestController extends WP_REST_Posts_Controller {

	/**
	 * @param WP_REST_Request $request Full details about the request.
	 */
	public function get_items_permissions_check( $request ): true|WP_Error {
		$refusal = $this->refuse_anonymous_readers();

		if ( $refusal instanceof WP_Error ) {
			return $refusal;
		}

		return parent::get_items_permissions_check( $request );
	}

	/**
	 * @param WP_REST_Request $request Full details about the request.
	 */
	public function get_item_permissions_check( $request ): true|WP_Error {
		$refusal = $this->refuse_anonymous_readers();

		if ( $refusal instanceof WP_Error ) {
			return $refusal;
		}

		return parent::get_item_permissions_check( $request );
	}

	private function refuse_anonymous_readers(): ?WP_Error {
		if ( current_user_can( 'edit_posts' ) ) {
			return null;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to browse reviews.', 'hallie' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
