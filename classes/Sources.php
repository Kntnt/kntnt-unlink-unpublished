<?php
/**
 * The sources that link to a target.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_Post;

/**
 * What Source_Finder found: the posts that link to a target, and whether a
 * classic menu or a widget does.
 *
 * @since 0.1.0
 */
final readonly class Sources {

	/**
	 * Creates the result.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post[] $posts      Posts of any status but trash that link.
	 * @param bool      $in_menus   Whether a classic menu item links.
	 * @param bool      $in_widgets Whether a widget links.
	 */
	public function __construct(
		public array $posts,
		public bool $in_menus,
		public bool $in_widgets,
	) {}

	/**
	 * Tells whether anything links to the target.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_linked(): bool {
		return $this->posts !== [] || $this->in_menus || $this->in_widgets;
	}

	/**
	 * Tells whether a source renders on every page (spec §7).
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $site_wide_types The post types that render site-wide.
	 * @return bool
	 */
	public function is_site_wide( array $site_wide_types ): bool {
		return $this->in_menus || $this->in_widgets || array_any( $this->posts, static fn( WP_Post $post ): bool => in_array( $post->post_type, $site_wide_types, true ) );
	}

	/**
	 * Returns the permalinks of the published sources that render on their own.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $site_wide_types The post types that render site-wide.
	 * @return string[]
	 */
	public function published_permalinks( array $site_wide_types ): array {

		// Only published sources are cached; site-wide ones go with purge-all.
		$permalinks = [];
		foreach ( $this->posts as $post ) {
			if ( Targets::is_published( $post->post_status ) && ! in_array( $post->post_type, $site_wide_types, true ) ) {
				$permalink = get_permalink( $post );
				if ( is_string( $permalink ) && $permalink !== '' ) {
					$permalinks[] = $permalink;
				}
			}
		}

		return $permalinks;

	}

}
