<?php
/**
 * What the index knows about a URL or post ID.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

/**
 * A target behind a URL or an ID form that isn't published.
 *
 * @since 0.1.0
 */
final readonly class Index_Entry {

	/**
	 * Creates the entry.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $target_id  The post ID of the target; for a deleted
	 *                                 target, the ID it had.
	 * @param Target_State $state      The target's state.
	 * @param bool         $was_public Whether the URL has been public – an
	 *                                 unpublished target with a last public
	 *                                 URL, or a tombstone.
	 */
	public function __construct(
		public int $target_id,
		public Target_State $state,
		public bool $was_public,
	) {}

}
