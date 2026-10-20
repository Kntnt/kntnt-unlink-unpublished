<?php
/**
 * The URLs a target has, has had or will have.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_Post;

/**
 * Computes would-be permalinks (spec §4.1).
 *
 * A would-be permalink is the permalink of a clone of the post with the status
 * set to `publish`, so multilingual permalink filters stay in play. A
 * published post's would-be permalink is its permalink; computing it from the
 * post object rather than the stored post gives the right URL also while the
 * stored post is being changed, as in `post_updated`. A
 * trashed post and its trashed ancestors contribute their slug from before the
 * trash (`_wp_desired_post_slug`), which also gives the pre-trash path of a
 * published page below a trashed ancestor.
 *
 * @since 0.1.0
 */
final class Permalinks {

	/**
	 * The meta key WordPress stores a trashed post's original slug under.
	 *
	 * @since 0.1.0
	 */
	private const string DESIRED_SLUG = '_wp_desired_post_slug';

	/**
	 * Returns the would-be permalink of a post, or null when it has no slug.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post The post, in any status.
	 * @return string|null
	 */
	public function would_be( WP_Post $post ): ?string {

		// A post without a slug can only be matched through its ID forms.
		$slug = $this->slug_of( $post );
		if ( $slug === '' ) {
			return null;
		}

		// Clone the post as published under its original slug. The clone keeps
		// the raw filter, or get_post() would fetch the stored post instead.
		$clone = clone $post;
		$clone->post_status = 'publish';
		$clone->post_name = $slug;
		$clone->filter = 'raw';

		// Give hierarchical posts their path with the ancestors' original slugs.
		// A page is linked as a sample – the flag core sets for a draft's URL in
		// the editor – because `page_link` callbacks get only the post ID and
		// look up the stored post, which Bogo, for one, links with `?lang=`
		// instead of its prefix while the page is unpublished.
		$uri = function ( string $uri, WP_Post $page ) use ( $clone ): string {
			return $page->ID === $clone->ID ? $this->untrashed_uri( $clone ) : $uri;
		};
		add_filter( 'get_page_uri', $uri, 10, 2 );
		$link = $clone->post_type === 'page' ? get_page_link( $clone, false, true ) : get_permalink( $clone );
		remove_filter( 'get_page_uri', $uri, 10 );

		return is_string( $link ) && $link !== '' ? $link : null;

	}

	/**
	 * Returns a post's slug as it was before any trash.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post The post.
	 * @return string
	 */
	public function slug_of( WP_Post $post ): string {
		$desired = $post->post_status === 'trash' ? get_post_meta( $post->ID, self::DESIRED_SLUG, true ) : '';
		return is_string( $desired ) && $desired !== '' ? $desired : $post->post_name;
	}

	/**
	 * Builds the page URI of a hierarchical post from untrashed slugs.
	 *
	 * Mirrors get_page_uri(), which prepends every ancestor's slug, except that
	 * a trashed ancestor contributes its original slug.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post The post, its own slug already untrashed.
	 * @return string
	 */
	private function untrashed_uri( WP_Post $post ): string {

		// Walk up the ancestors, guarding against a corrupt parent cycle. A post
		// without a parent must not ask get_post() for ID 0, which answers with
		// the global post of the loop.
		$segments = [ $post->post_name ];
		$seen = [ $post->ID => true ];
		$parent = $post->post_parent > 0 ? get_post( $post->post_parent ) : null;
		while ( $parent instanceof WP_Post && ! isset( $seen[ $parent->ID ] ) ) {
			$seen[ $parent->ID ] = true;
			$slug = $this->slug_of( $parent );
			if ( $slug !== '' ) {
				array_unshift( $segments, $slug );
			}
			$parent = $parent->post_parent > 0 ? get_post( $parent->post_parent ) : null;
		}

		return implode( '/', $segments );

	}

}
