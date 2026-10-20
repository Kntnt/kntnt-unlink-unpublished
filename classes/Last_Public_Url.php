<?php
/**
 * Remembers the URL a target had while it was published.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_Post;

/**
 * Records and clears last public URLs (spec §5.2).
 *
 * When a target leaves the published state, its permalink from before the
 * change is stored as post meta. While the target is unpublished or private,
 * links to that URL are matched alongside its would-be permalink, and when it
 * is deleted the URL becomes its tombstone. Publishing the target again
 * clears the record.
 *
 * @since 0.1.0
 */
final class Last_Public_Url {

	/**
	 * The post meta key of the record.
	 *
	 * @since 0.1.0
	 */
	public const string META = '_kntnt_unlink_unpublished_last_public_url';

	/**
	 * Creates the recorder.
	 *
	 * @since 0.1.0
	 *
	 * @param Index_Store $store      The index to invalidate on a write.
	 * @param Permalinks  $permalinks Computes would-be permalinks for seeding.
	 */
	public function __construct(
		private readonly Index_Store $store,
		private readonly Permalinks $permalinks,
	) {}

	/**
	 * Registers the hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'post_updated', [ $this, 'remember' ], 10, 3 );
		add_action( 'transition_post_status', [ $this, 'forget' ], 10, 3 );
	}

	/**
	 * Records the permalink of a target that leaves the published state.
	 *
	 * Runs on `post_updated`, where the post as it was before the change is at
	 * hand – its permalink is the URL that was public.
	 *
	 * @since 0.1.0
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $after   The post after the update.
	 * @param WP_Post $before  The post before the update.
	 * @return void
	 */
	public function remember( int $post_id, WP_Post $after, WP_Post $before ): void {

		// Only a target leaving the published state has a URL to remember.
		if ( ! Targets::is_published( $before->post_status ) || Targets::is_published( $after->post_status ) || ! Targets::is_target( $before ) ) {
			return;
		}

		// Store the URL the post had as published. The stored post has already
		// changed, so the URL is computed from the post as it was.
		$url = $this->permalinks->would_be( $before );
		if ( $url !== null ) {
			update_post_meta( $post_id, self::META, $url );
			$this->store->bump();
		}

	}

	/**
	 * Clears the record of a target that is published again.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $new_status The new status.
	 * @param string  $old_status The old status.
	 * @param WP_Post $post       The post.
	 * @return void
	 */
	public function forget( string $new_status, string $old_status, WP_Post $post ): void {
		if ( Targets::is_published( $new_status ) && ! Targets::is_published( $old_status ) && self::of( $post->ID ) !== '' ) {
			delete_post_meta( $post->ID, self::META );
			$this->store->bump();
		}
	}

	/**
	 * Returns a post's last public URL, or an empty string.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id The post ID.
	 * @return string
	 */
	public static function of( int $post_id ): string {
		$url = get_post_meta( $post_id, self::META, true );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Seeds the record for targets unpublished before the plugin was installed.
	 *
	 * A target looks once-published when it has a slug and a set
	 * `post_date_gmt`: WordPress leaves that date at zero for drafts and
	 * pending posts that were never published or dated by hand, and keeps it
	 * when a published post goes back to draft. Scheduled and private targets
	 * are left out; a target that already has a record keeps it.
	 *
	 * @since 0.1.0
	 *
	 * @return int How many records were written.
	 */
	public function seed(): int {

		// Fetch every unpublished target except scheduled ones.
		$statuses = array_values( array_diff( Targets::hidden_statuses(), [ 'future', 'private' ] ) );
		$posts = Targets::find( $statuses );

		// Record the would-be permalink of each once-published target.
		$written = 0;
		foreach ( $posts as $post ) {
			if ( $post->post_date_gmt === '0000-00-00 00:00:00' || self::of( $post->ID ) !== '' ) {
				continue;
			}
			$url = $this->permalinks->would_be( $post );
			if ( $url !== null ) {
				update_post_meta( $post->ID, self::META, $url );
				++$written;
			}
		}

		// The index includes last public URLs, so it must be rebuilt.
		if ( $written > 0 ) {
			$this->store->bump();
		}

		return $written;

	}

}
