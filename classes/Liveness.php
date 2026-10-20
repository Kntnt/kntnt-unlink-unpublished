<?php
/**
 * Decides whether a link is live for the current visitor.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_Post;

/**
 * Applies the status matrix (spec §3) to an index entry.
 *
 * A link to a private target is live for a visitor who can read it; a link to
 * an unpublished or deleted target is live for no one. The filter
 * `kntnt_unlink_unpublished_is_live` can change the verdict for targets in the
 * index – it is never asked about a published target, which is not in the
 * index. Verdicts are cached per visitor and target for the request, because
 * the current user can change mid-request (kntnt-ai-visibility renders its
 * Markdown alternates as an anonymous visitor).
 *
 * @since 0.1.0
 */
final class Liveness {

	/**
	 * Verdicts of this request, by user ID and target ID.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, array<int, bool>>
	 */
	private array $verdicts = [];

	/**
	 * Tells whether a link to an indexed target is live for the current visitor.
	 *
	 * @since 0.1.0
	 *
	 * @param Index_Entry $entry The index entry the link matched.
	 * @return bool
	 */
	public function is_live( Index_Entry $entry ): bool {

		// A deleted target is gone for everyone.
		if ( $entry->state === Target_State::Deleted ) {
			return false;
		}

		// Reuse the verdict for this visitor and target.
		$user_id = get_current_user_id();
		if ( isset( $this->verdicts[ $user_id ][ $entry->target_id ] ) ) {
			return $this->verdicts[ $user_id ][ $entry->target_id ];
		}

		// Apply the matrix, then the filter.
		$post = get_post( $entry->target_id );
		$live = $entry->state === Target_State::Private && current_user_can( 'read_post', $entry->target_id );
		if ( $post instanceof WP_Post ) {
			$live = (bool) apply_filters( 'kntnt_unlink_unpublished_is_live', $live, $post, $user_id );
		}

		$this->verdicts[ $user_id ][ $entry->target_id ] = $live;
		return $live;

	}

	/**
	 * Tells whether a post that isn't published is live for the current visitor.
	 *
	 * Used where core decides first, as in block navigation (spec §4.6).
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post The post.
	 * @return bool
	 */
	public function is_live_post( WP_Post $post ): bool {
		$state = $post->post_status === 'private' ? Target_State::Private : Target_State::Unpublished;
		return $this->is_live( new Index_Entry( $post->ID, $state, false ) );
	}

}
