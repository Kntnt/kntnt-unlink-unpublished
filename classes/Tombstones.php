<?php
/**
 * Tombstones of deleted targets.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_Post;

/**
 * Records, lists and prunes tombstones (spec §5.3).
 *
 * A tombstone is recorded when a target that has a last public URL, or is
 * published, is deleted permanently. All tombstones live in one option that
 * is not autoloaded; the index reads it once per rebuild.
 *
 * @since 0.1.0
 */
final class Tombstones {

	/**
	 * The option that holds the tombstones.
	 *
	 * @since 0.1.0
	 */
	public const string OPTION = 'kntnt_unlink_unpublished_tombstones';

	/**
	 * How long a tombstone is kept at least, whatever links to it.
	 *
	 * @since 0.1.0
	 */
	public const int MINIMUM_AGE = 90 * DAY_IN_SECONDS;

	/**
	 * Last public URLs of targets being deleted, read before their meta goes.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, string>
	 */
	private array $pending = [];

	/**
	 * Creates the tombstone store.
	 *
	 * @since 0.1.0
	 *
	 * @param Index_Store $store The index to invalidate on a write.
	 */
	public function __construct( private readonly Index_Store $store ) {}

	/**
	 * Registers the hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'before_delete_post', [ $this, 'prepare' ], 10, 2 );
		add_action( 'deleted_post', [ $this, 'record' ], 10, 2 );
	}

	/**
	 * Reads the URL a target being deleted had last as public.
	 *
	 * Runs on `before_delete_post`, while the post meta still exists.
	 *
	 * @since 0.1.0
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post.
	 * @return void
	 */
	public function prepare( int $post_id, WP_Post $post ): void {

		// Only targets that have been public leave a tombstone.
		if ( ! Targets::is_target( $post ) ) {
			return;
		}
		$url = Targets::is_published( $post->post_status ) ? get_permalink( $post ) : Last_Public_Url::of( $post_id );
		if ( is_string( $url ) && $url !== '' ) {
			$this->pending[ $post_id ] = $url;
		}

	}

	/**
	 * Records the tombstone of a deleted target.
	 *
	 * @since 0.1.0
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post as it was.
	 * @return void
	 */
	public function record( int $post_id, WP_Post $post ): void {

		// Nothing was prepared for posts that leave no tombstone.
		$url = $this->pending[ $post_id ] ?? null;
		unset( $this->pending[ $post_id ] );
		if ( $url === null ) {
			return;
		}

		// Store the URL, the ID and what an editor needs to recognise the page.
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$tombstones = self::all();
		$tombstones[ $post_id ] = [
			'id' => $post_id,
			'url' => $url,
			'path' => Href_Normaliser::path_key( is_string( $path ) ? $path : '' ),
			'title' => $post->post_title,
			'type' => $post->post_type,
			'deleted' => time(),
		];
		update_option( self::OPTION, $tombstones, false );
		$this->store->bump();

	}

	/**
	 * Returns every tombstone, keyed by post ID.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array{id: int, url: string, path: string, title: string, type: string, deleted: int}>
	 */
	public static function all(): array {

		// Keep only well-formed records; the option is ours but may be edited.
		$stored = get_option( self::OPTION, [] );
		$tombstones = [];
		foreach ( is_array( $stored ) ? $stored : [] as $id => $tombstone ) {
			if ( is_int( $id ) && is_array( $tombstone ) && is_string( $tombstone['url'] ?? null ) && is_string( $tombstone['path'] ?? null ) ) {
				$tombstones[ $id ] = [
					'id' => $id,
					'url' => $tombstone['url'],
					'path' => $tombstone['path'],
					'title' => is_string( $tombstone['title'] ?? null ) ? $tombstone['title'] : '',
					'type' => is_string( $tombstone['type'] ?? null ) ? $tombstone['type'] : '',
					'deleted' => is_int( $tombstone['deleted'] ?? null ) ? $tombstone['deleted'] : 0,
				];
			}
		}

		return $tombstones;

	}

	/**
	 * Prunes the tombstones old enough that no source links to any more.
	 *
	 * @since 0.1.0
	 *
	 * @param Source_Finder $finder Finds the sources of a tombstone.
	 * @return int How many tombstones were pruned.
	 */
	public function prune( Source_Finder $finder ): int {

		// Keep every tombstone that is young or still linked to.
		$tombstones = self::all();
		$kept = array_filter(
			$tombstones,
			static fn( array $tombstone ): bool => time() - $tombstone['deleted'] < self::MINIMUM_AGE
				|| $finder->find( [ $tombstone['url'] ], $tombstone['id'] )->is_linked(),
		);

		// Save and rebuild the index only when something went.
		$pruned = count( $tombstones ) - count( $kept );
		if ( $pruned > 0 ) {
			update_option( self::OPTION, $kept, false );
			$this->store->bump();
		}

		return $pruned;

	}

}
