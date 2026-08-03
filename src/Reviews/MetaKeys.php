<?php
/**
 * Meta key registry.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * Every meta key used by a review, in one place.
 *
 * The prefixes are the contract that makes overrides survive syncing. `_hallie_src_*`
 * holds the review as its source published it, `_hallie_ovr_*` holds what an editor
 * chose to display instead, `_hallie_sys_*` is bookkeeping.
 *
 * The source is usually the synchroniser. For a review written in the admin it is the
 * editor — which is why the metabox may write `_hallie_src_*` on those, and only those.
 * What no one may do is edit the source values of a review that came from elsewhere.
 *
 * The body and publication date live in `post_content` and `post_date_gmt` rather than in
 * meta, which buys native search, ordering and pagination.
 */
final class MetaKeys {

	/* Upstream values — overwritten on every sync. */

	public const string SRC_EXTERNAL_ID = '_hallie_src_external_id';

	public const string SRC_PROVIDER = '_hallie_src_provider';

	public const string SRC_PROFILE_ID = '_hallie_src_profile_id';

	public const string SRC_RATING = '_hallie_src_rating';

	public const string SRC_AUTHOR_NAME = '_hallie_src_author_name';

	public const string SRC_AUTHOR_PHOTO_URL = '_hallie_src_author_photo_url';

	public const string SRC_UPDATED_AT = '_hallie_src_updated_at';

	public const string SRC_REPLY_COMMENT = '_hallie_src_reply_comment';

	public const string SRC_REPLY_PUBLISHED_AT = '_hallie_src_reply_published_at';

	/* Editor overrides — never touched by the synchroniser. */

	public const string OVR_HIDDEN = '_hallie_ovr_hidden';

	public const string OVR_FEATURED = '_hallie_ovr_featured';

	/* Bookkeeping. */

	public const string SYS_AVATAR_ID = '_hallie_sys_avatar_id';

	public const string SYS_AVATAR_HASH = '_hallie_sys_avatar_hash';

	/** The picture owed, held as the URL to fetch. Absence means nothing to do. */
	public const string SYS_AVATAR_PENDING = '_hallie_sys_avatar_pending';

	public const string SYS_CONTENT_CHANGED_AT = '_hallie_sys_content_changed_at';

	public const string SYS_SYNC_RUN = '_hallie_sys_sync_run';


	/**
	 * Keys holding the review as published by its source.
	 *
	 * @return string[]
	 */
	public static function source_keys(): array {
		return array(
			self::SRC_EXTERNAL_ID,
			self::SRC_PROVIDER,
			self::SRC_PROFILE_ID,
			self::SRC_RATING,
			self::SRC_AUTHOR_NAME,
			self::SRC_AUTHOR_PHOTO_URL,
			self::SRC_UPDATED_AT,
			self::SRC_REPLY_COMMENT,
			self::SRC_REPLY_PUBLISHED_AT,
		);
	}
}
