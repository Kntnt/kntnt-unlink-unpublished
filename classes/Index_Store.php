<?php
/**
 * Caches the index and keeps it fresh.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_Post;

/**
 * Serves the index from the object cache, with a transient fallback, under a
 * version key (spec §5.1).
 *
 * The key combines a version number, bumped whenever an input changes, with a
 * hash of the site settings that decide which URLs WordPress serves as
 * something else. A cached request therefore costs one cache read; the index
 * is rebuilt only after a change.
 *
 * @since 0.1.0
 */
final class Index_Store {

	/**
	 * The option holding the version number.
	 *
	 * @since 0.1.0
	 */
	public const string VERSION_OPTION = 'kntnt_unlink_unpublished_index_version';

	/**
	 * The transient holding the latest index when there is no object cache.
	 *
	 * @since 0.1.0
	 */
	public const string TRANSIENT = 'kntnt_unlink_unpublished_index';

	/**
	 * The object-cache group.
	 *
	 * @since 0.1.0
	 */
	private const string GROUP = 'kntnt_unlink_unpublished';

	/**
	 * The index of this request, once loaded.
	 *
	 * @since 0.1.0
	 *
	 * @var Index|null
	 */
	private ?Index $index = null;

	/**
	 * Creates the store.
	 *
	 * @since 0.1.0
	 *
	 * @param Index_Builder $builder Builds the index on a cache miss.
	 */
	public function __construct( private readonly Index_Builder $builder ) {}

	/**
	 * Registers the hooks that invalidate the index.
	 *
	 * Last public URLs and tombstones bump the version when they are written;
	 * multilingual plugins announce language changes through the action
	 * `kntnt_unlink_unpublished_permalinks_changed`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {

		// Status changes, slug and parent changes, and deletions of targets. An
		// ordinary save fires transition_post_status too, without a change.
		add_action(
			'transition_post_status',
			function ( string $new_status, string $old_status, WP_Post $post ): void {
				if ( $new_status !== $old_status && Targets::is_target( $post ) ) {
					$this->bump();
				}
			},
			1,
			3,
		);
		add_action(
			'post_updated',
			function ( int $id, WP_Post $after, WP_Post $before ): void {
				if ( Targets::is_target( $after ) && ( $after->post_name !== $before->post_name || $after->post_parent !== $before->post_parent ) ) {
					$this->bump();
				}
			},
			1,
			3,
		);
		add_action(
			'deleted_post',
			function ( int $id, WP_Post $post ): void {
				if ( Targets::is_target( $post ) ) {
					$this->bump();
				}
			},
			1,
			2,
		);

		// Anything that moves permalinks in a way the plugin cannot see – a
		// post's language, above all – announces it (docs/adr/0010).
		add_action( Language_Plugins::PERMALINKS_CHANGED, [ $this, 'bump' ], 10, 0 );

	}

	/**
	 * Returns the index, building it on a cache miss.
	 *
	 * @since 0.1.0
	 *
	 * @return Index
	 */
	public function index(): Index {

		// The index is loaded once per request.
		if ( $this->index !== null ) {
			return $this->index;
		}

		// Read the object cache, then the transient when there is no object
		// cache, and build on a miss.
		$key = $this->key();
		$data = wp_cache_get( $key, self::GROUP );
		if ( ! is_array( $data ) && ! wp_using_ext_object_cache() ) {
			$stored = get_transient( self::TRANSIENT );
			$data = is_array( $stored ) && ( $stored['key'] ?? null ) === $key ? $stored['data'] : null;
		}
		if ( ! is_array( $data ) ) {
			$data = $this->builder->build();
			wp_cache_set( $key, $data, self::GROUP, DAY_IN_SECONDS );
			if ( ! wp_using_ext_object_cache() ) {
				set_transient(
					self::TRANSIENT,
					[
						'key' => $key,
						'data' => $data,
					]
				);
			}
		}

		$this->index = Index::from_array( $data );
		return $this->index;

	}

	/**
	 * Invalidates the index.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function bump(): void {
		update_option( self::VERSION_OPTION, self::version() + 1 );
		$this->index = null;
	}

	/**
	 * Returns the cache key of the current index.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function key(): string {
		return 'index-' . self::version() . '-' . md5( (string) wp_json_encode( Index_Builder::environment() ) );
	}

	/**
	 * Returns the version number.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	private static function version(): int {
		$version = get_option( self::VERSION_OPTION, 0 );
		return is_numeric( $version ) ? (int) $version : 0;
	}

}
