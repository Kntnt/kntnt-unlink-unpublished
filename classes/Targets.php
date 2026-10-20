<?php
/**
 * Which posts are targets, and which states they are in.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_Post;

/**
 * The targeted post types and the mapping of post statuses to target states.
 *
 * @since 0.1.0
 */
final class Targets {

	/**
	 * Returns the targeted post types (filter `kntnt_unlink_unpublished_post_types`).
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Public post types except attachments, by default.
	 */
	public static function post_types(): array {
		$types = array_values( array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] ) );
		$filtered = apply_filters( 'kntnt_unlink_unpublished_post_types', $types );
		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $types;
	}

	/**
	 * Tells whether a post is of a targeted post type.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post The post.
	 * @return bool
	 */
	public static function is_target( WP_Post $post ): bool {
		return in_array( $post->post_type, self::post_types(), true );
	}

	/**
	 * Returns the statuses of targets that aren't published.
	 *
	 * Every registered status that isn't public, except the internal
	 * auto-draft and inherit statuses: private, scheduled, draft, pending,
	 * trash and any custom non-public status.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public static function hidden_statuses(): array {
		return array_values( array_diff( get_post_stati(), get_post_stati( [ 'public' => true ] ), [ 'auto-draft', 'inherit' ] ) );
	}

	/**
	 * Returns every status a post can have, except the internal auto-draft and
	 * inherit statuses.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public static function all_statuses(): array {
		return array_values( array_diff( get_post_stati(), [ 'auto-draft', 'inherit' ] ) );
	}

	/**
	 * Returns the targets in some statuses, straight from the database.
	 *
	 * WP_Query is avoided on purpose: multilingual plugins such as Polylang and
	 * WPML restrict every query to the current language, and `suppress_filters`
	 * does not stop them, so an index built during a request in one language
	 * would miss the targets in all the others.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $statuses The statuses.
	 * @param int|null $parent   Only the children of this post, or null for all.
	 * @return WP_Post[] In ID order.
	 */
	public static function find( array $statuses, ?int $parent = null ): array {

		// Nothing can match an empty list.
		$types = self::post_types();
		if ( $types === [] || $statuses === [] ) {
			return [];
		}

		// Read the IDs, then load the posts with their meta and terms at once.
		$wpdb = Database::get();
		$types = Database::list_of( $types );
		$statuses = Database::list_of( $statuses );
		$children = $parent === null ? '' : (string) $wpdb->prepare( ' AND post_parent = %d', $parent );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every list and clause is prepared above.
		$ids = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$types}) AND post_status IN ({$statuses}){$children} ORDER BY ID" ) );
		_prime_post_caches( $ids );

		return array_values( array_filter( array_map( 'get_post', $ids ), static fn( mixed $post ): bool => $post instanceof WP_Post ) );

	}

	/**
	 * Returns the name of the state a post status puts a target in.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status A post status.
	 * @return string 'published', 'private' or 'unpublished'.
	 */
	public static function state_of( string $status ): string {
		return match ( true ) {
			in_array( $status, get_post_stati( [ 'public' => true ] ), true ) => 'published',
			$status === 'private' => Target_State::Private->value,
			default => Target_State::Unpublished->value,
		};
	}

	/**
	 * Tells whether a post status makes a target published.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status A post status.
	 * @return bool
	 */
	public static function is_published( string $status ): bool {
		return self::state_of( $status ) === 'published';
	}

}
