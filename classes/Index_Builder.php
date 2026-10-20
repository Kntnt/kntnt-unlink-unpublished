<?php
/**
 * Builds the index from the database.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_Post;

/**
 * Builds the array form of the index (spec §5.1).
 *
 * It holds every unpublished and private target under its would-be permalink,
 * its last public URL and its post ID, the pre-trash paths of published pages
 * below a trashed ancestor, and every tombstone. URLs WordPress serves as
 * something else are left out (spec §4.1): the home URL, the posts page,
 * post-type archives and any URL a published post owns.
 *
 * @since 0.1.0
 */
final class Index_Builder {

	/**
	 * Creates the builder.
	 *
	 * @since 0.1.0
	 *
	 * @param Permalinks      $permalinks Computes would-be permalinks.
	 * @param Href_Normaliser $normaliser Turns URLs into path keys.
	 */
	public function __construct(
		private readonly Permalinks $permalinks,
		private readonly Href_Normaliser $normaliser,
	) {}

	/**
	 * Builds the index in its array form.
	 *
	 * @since 0.1.0
	 *
	 * @return array{paths: array<string, array{int, string, bool}>, ids: array<int, array{string, bool}>}
	 */
	public function build(): array {

		$paths = [];
		$ids = [];

		// Every target that isn't published, under each URL it answers to.
		$posts = Targets::find( Targets::hidden_statuses() );
		$trashed_parents = [];
		foreach ( $posts as $post ) {
			$state = $post->post_status === 'private' ? Target_State::Private : Target_State::Unpublished;
			$last_public = Last_Public_Url::of( $post->ID );
			$entry = [ $post->ID, $state->value, $last_public !== '' ];
			$ids[ $post->ID ] = [ $state->value, $last_public !== '' ];
			foreach ( [ $this->permalinks->would_be( $post ), $last_public ] as $url ) {
				$key = $this->key_of( $url );
				if ( $key !== null ) {
					$paths[ $key ] = $entry;
				}
			}
			if ( $post->post_status === 'trash' && is_post_type_hierarchical( $post->post_type ) ) {
				$trashed_parents[] = $post;
			}
		}

		// A trashed ancestor moves its published descendants' URLs, so their
		// pre-trash paths no longer resolve.
		foreach ( $this->published_descendants( $trashed_parents ) as $descendant ) {
			$key = $this->key_of( $this->permalinks->would_be( $descendant ) );
			if ( $key !== null ) {
				$paths[ $key ] = [ $descendant->ID, Target_State::Unpublished->value, true ];
			}
		}

		// Tombstones, unless their post ID exists again.
		foreach ( Tombstones::all() as $id => $tombstone ) {
			if ( get_post( $id ) === null ) {
				$ids[ $id ] = [ Target_State::Deleted->value, true ];
			}
			$paths[ $tombstone['path'] ] ??= [ $id, Target_State::Deleted->value, true ];
		}

		// Leave out every URL WordPress serves as something else.
		foreach ( $this->served_elsewhere( array_keys( $paths ) ) as $key ) {
			unset( $paths[ $key ] );
		}
		foreach ( [ 'page_on_front', 'page_for_posts' ] as $option ) {
			unset( $ids[ self::page_option( $option ) ] );
		}

		return [
			'paths' => $paths,
			'ids' => $ids,
		];

	}

	/**
	 * Returns the inputs that change which URLs are served as something else.
	 *
	 * Index_Store makes them part of the cache key, so a change rebuilds the
	 * index without a hook for each of them.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public static function environment(): array {

		// Post types with an archive, keyed by their archive slug.
		$archives = [];
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $type ) {
			if ( $type->has_archive ) {
				$archives[] = is_string( $type->has_archive ) ? $type->has_archive : $type->name;
			}
		}
		sort( $archives );

		return [
			'home' => self::home(),
			'permalinks' => get_option( 'permalink_structure' ),
			'front' => [ get_option( 'show_on_front' ), get_option( 'page_on_front' ), get_option( 'page_for_posts' ) ],
			'archives' => $archives,
			'types' => Targets::post_types(),
		];

	}

	/**
	 * Returns the path key of a URL, or null for no URL.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null|false $url The URL.
	 * @return string|null
	 */
	private function key_of( string|null|false $url ): ?string {
		$href = is_string( $url ) && $url !== '' ? $this->normaliser->normalise( $url ) : null;
		return $href?->path;
	}

	/**
	 * Returns the published descendants of trashed posts.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post[] $parents Trashed hierarchical posts.
	 * @return WP_Post[]
	 */
	private function published_descendants( array $parents ): array {

		// Walk down from each trashed post; trash is small.
		$found = [];
		$queue = array_map( static fn( WP_Post $post ): int => $post->ID, $parents );
		$seen = array_fill_keys( $queue, true );
		while ( $queue !== [] ) {
			$parent = array_shift( $queue );
			foreach ( Targets::find( Targets::all_statuses(), $parent ) as $child ) {
				if ( isset( $seen[ $child->ID ] ) ) {
					continue;
				}
				$seen[ $child->ID ] = true;
				$queue[] = $child->ID;
				if ( Targets::is_published( $child->post_status ) ) {
					$found[] = $child;
				}
			}
		}

		return $found;

	}

	/**
	 * Returns the path keys among the candidates that WordPress serves as
	 * something else.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $candidates Path keys about to be indexed.
	 * @return string[]
	 */
	private function served_elsewhere( array $candidates ): array {

		// The home URL, the posts page and the post-type archives.
		$served = [ $this->key_of( self::home() ) ];
		$posts_page = self::page_option( 'page_for_posts' );
		$posts_page_post = $posts_page > 0 ? get_post( $posts_page ) : null;
		if ( $posts_page_post instanceof WP_Post ) {
			$served[] = $this->key_of( get_permalink( $posts_page_post ) );
			$served[] = $this->key_of( $this->permalinks->would_be( $posts_page_post ) );
		}
		foreach ( get_post_types( [ 'public' => true ] ) as $type ) {
			$served[] = $this->key_of( get_post_type_archive_link( $type ) );
		}

		// Published posts that own a candidate URL, found by its last segment.
		$slugs = [];
		foreach ( $candidates as $candidate ) {
			$segment = basename( $candidate );
			if ( $segment !== '' ) {
				$slugs[ $segment ] = true;
				$slugs[ strtolower( rawurlencode( $segment ) ) ] = true;
			}
		}
		$types = Targets::post_types();
		if ( $slugs !== [] && $types !== [] ) {
			$wpdb = Database::get();
			$statuses = Database::list_of( array_values( get_post_stati( [ 'public' => true ] ) ) );
			$types = Database::list_of( $types );
			$slugs = Database::list_of( array_map( 'strval', array_keys( $slugs ) ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every list is prepared above.
			$owners = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status IN ({$statuses}) AND post_type IN ({$types}) AND post_name IN ({$slugs})" );
			foreach ( $owners as $owner ) {
				if ( is_numeric( $owner ) ) {
					$served[] = $this->key_of( get_permalink( (int) $owner ) );
				}
			}
		}

		return array_values( array_filter( $served, 'is_string' ) );

	}

	/**
	 * Returns the site's home URL, unfiltered.
	 *
	 * Multilingual plugins filter home_url() with the visitor's language –
	 * Bogo adds its prefix once `template_redirect` has run – but links and
	 * paths are matched against the site's root.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function home(): string {
		$home = get_option( 'home' );
		return set_url_scheme( is_string( $home ) ? $home : '' );
	}

	/**
	 * Returns the page ID stored in a reading-settings option.
	 *
	 * @since 0.1.0
	 *
	 * @param string $option `page_on_front` or `page_for_posts`.
	 * @return int The page ID, or 0.
	 */
	private static function page_option( string $option ): int {
		$value = get_option( $option );
		return is_numeric( $value ) ? (int) $value : 0;
	}

}
