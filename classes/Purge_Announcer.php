<?php
/**
 * Tells caches which pages render differently after a change.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_Post;

/**
 * Detects changes that alter rendered output and announces them (spec §7).
 *
 * A change is judged for anonymous visitors, after it is committed: the
 * sources are purged when a link to the target turns live or stops being
 * live, or when the set of URLs that match the target changes; the target's
 * own URLs are included whenever their response code changes. Changes are
 * queued during the request and announced on `shutdown` – `purge_urls` once
 * per target, or a single `purge_all` that replaces them when a site-wide
 * source is involved.
 *
 * @since 0.1.0
 */
final class Purge_Announcer {

	/**
	 * The action announcing URLs to purge.
	 *
	 * @since 0.1.0
	 */
	public const string PURGE_URLS = 'kntnt_unlink_unpublished_purge_urls';

	/**
	 * The action announcing that the whole page cache should be purged.
	 *
	 * @since 0.1.0
	 */
	public const string PURGE_ALL = 'kntnt_unlink_unpublished_purge_all';

	/**
	 * Last public URLs as they were before the change of this request.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, string>
	 */
	private array $prior_last_public = [];

	/**
	 * Targets being deleted: whether they were live and the URLs they had.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, array{live: bool, urls: string[]}>
	 */
	private array $deleting = [];

	/**
	 * The queued changes, by target ID.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, array{target: WP_Post, change: string, search: string[], own: string[]}>
	 */
	private array $queue = [];

	/**
	 * Creates the announcer.
	 *
	 * @since 0.1.0
	 *
	 * @param Permalinks    $permalinks Computes would-be permalinks.
	 * @param Source_Finder $finder     Finds the sources of a target.
	 */
	public function __construct(
		private readonly Permalinks $permalinks,
		private readonly Source_Finder $finder,
	) {}

	/**
	 * Registers the hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_post_status', [ $this, 'remember_last_public' ], 1, 3 );
		add_action( 'wp_after_insert_post', [ $this, 'saved' ], 10, 4 );
		add_action( 'before_delete_post', [ $this, 'deleting' ], 20, 2 );
		add_action( 'deleted_post', [ $this, 'deleted' ], 20, 2 );
		add_action( 'shutdown', [ $this, 'announce' ] );
	}

	/**
	 * Remembers a target's last public URL before the change can rewrite it.
	 *
	 * `transition_post_status` fires before `post_updated`, where the record is
	 * written, on every path – cron's wp_publish_post() included.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $new_status The new status.
	 * @param string  $old_status The old status.
	 * @param WP_Post $post       The post.
	 * @return void
	 */
	public function remember_last_public( string $new_status, string $old_status, WP_Post $post ): void {
		if ( ! isset( $this->prior_last_public[ $post->ID ] ) ) {
			$this->prior_last_public[ $post->ID ] = Last_Public_Url::of( $post->ID );
		}
	}

	/**
	 * Queues a saved target whose change alters what anonymous visitors get.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $post_id     The post ID.
	 * @param WP_Post      $post        The post after the save.
	 * @param bool         $update      Whether an existing post was updated.
	 * @param WP_Post|null $post_before The post before the save.
	 * @return void
	 */
	public function saved( int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before ): void {

		// A new post had no links to it, and only targets matter.
		if ( ! $post_before instanceof WP_Post || ! Targets::is_target( $post ) ) {
			return;
		}

		// Compare liveness and the URLs that match the target.
		$was_live = Targets::is_published( $post_before->post_status );
		$is_live = Targets::is_published( $post->post_status );
		$before = $this->urls_of( $post_before, $this->prior_last_public[ $post_id ] ?? Last_Public_Url::of( $post_id ) );
		$after = $this->urls_of( $post, Last_Public_Url::of( $post_id ) );
		$moved = ! $was_live && ! $is_live && $this->differ( $before, $after );

		// A change of liveness purges the sources and the target's own URLs; a
		// move of a target that isn't live purges the sources of both URLs.
		if ( $was_live !== $is_live ) {
			$urls = array_values( array_unique( [ ...$before, ...$after ] ) );
			$this->enqueue( $post, Targets::state_of( $post->post_status ), $urls, $urls );
		} elseif ( $moved ) {
			$this->enqueue( $post, 'moved', array_values( array_unique( [ ...$before, ...$after ] ) ), [] );
		}

		// Trashing or restoring a parent moves its published descendants' URLs.
		$trash_changed = ( $post_before->post_status === 'trash' ) !== ( $post->post_status === 'trash' );
		if ( $trash_changed && is_post_type_hierarchical( $post->post_type ) ) {
			foreach ( $this->published_descendants( $post ) as $descendant ) {
				$urls = array_filter( [ $this->permalinks->would_be( $descendant ), get_permalink( $descendant ) ], 'is_string' );
				$this->enqueue( $descendant, 'moved', array_values( array_unique( $urls ) ), array_values( array_unique( $urls ) ) );
			}
		}

	}

	/**
	 * Remembers what a target being deleted was.
	 *
	 * @since 0.1.0
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post.
	 * @return void
	 */
	public function deleting( int $post_id, WP_Post $post ): void {
		if ( Targets::is_target( $post ) ) {
			$this->deleting[ $post_id ] = [
				'live' => Targets::is_published( $post->post_status ),
				'urls' => $this->urls_of( $post, Last_Public_Url::of( $post_id ) ),
			];
		}
	}

	/**
	 * Queues a deleted target.
	 *
	 * A target that was live changes its sources and its URL; one that wasn't
	 * only changes its URL, from 404 to 410, and only when it leaves a
	 * tombstone.
	 *
	 * @since 0.1.0
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post as it was.
	 * @return void
	 */
	public function deleted( int $post_id, WP_Post $post ): void {

		// Only targets prepared on before_delete_post.
		$deleting = $this->deleting[ $post_id ] ?? null;
		unset( $this->deleting[ $post_id ] );
		if ( $deleting === null ) {
			return;
		}

		// Tombstones are recorded on deleted_post at an earlier priority.
		if ( $deleting['live'] ) {
			$this->enqueue( $post, Target_State::Deleted->value, $deleting['urls'], $deleting['urls'] );
		} elseif ( isset( Tombstones::all()[ $post_id ] ) ) {
			$this->enqueue( $post, Target_State::Deleted->value, [], $deleting['urls'] );
		}

	}

	/**
	 * Announces the queued changes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function announce(): void {

		// Nothing changed in this request.
		if ( $this->queue === [] ) {
			return;
		}
		$queue = $this->queue;
		$this->queue = [];

		// Find each target's sources; a site-wide source calls for purge-all.
		$site_wide_types = apply_filters( 'kntnt_unlink_unpublished_site_wide_post_types', [ 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_block' ] );
		$site_wide_types = is_array( $site_wide_types ) ? array_values( array_filter( $site_wide_types, 'is_string' ) ) : [];
		$purge_all = [];
		$purge_urls = [];
		foreach ( $queue as $id => $item ) {
			$sources = $item['search'] === [] ? new Sources( [], false, false ) : $this->finder->find( $item['search'], $id );
			if ( $sources->is_site_wide( $site_wide_types ) ) {
				$purge_all[] = $item;
			}
			$urls = array_values( array_unique( [ ...$sources->published_permalinks( $site_wide_types ), ...$item['own'] ] ) );
			if ( $urls !== [] ) {
				$purge_urls[] = [ $urls, $item ];
			}
		}

		// One purge-all replaces every purge of URLs in the request.
		if ( $purge_all !== [] ) {
			if ( count( $purge_all ) === 1 ) {
				do_action( self::PURGE_ALL, $purge_all[0]['target'], $purge_all[0]['change'] );
			} else {
				do_action( self::PURGE_ALL, null, 'multiple' );
			}
			return;
		}

		foreach ( $purge_urls as [ $urls, $item ] ) {
			do_action( self::PURGE_URLS, $urls, $item['target'], $item['change'] );
		}

	}

	/**
	 * Queues a change, merging it with an earlier change of the same target.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post  $target The target.
	 * @param string   $change The change.
	 * @param string[] $search The URLs to find sources for.
	 * @param string[] $own    The target's own URLs to purge.
	 * @return void
	 */
	private function enqueue( WP_Post $target, string $change, array $search, array $own ): void {
		$earlier = $this->queue[ $target->ID ] ?? [
			'search' => [],
			'own' => [],
		];
		$this->queue[ $target->ID ] = [
			'target' => $target,
			'change' => $change,
			'search' => array_values( array_unique( [ ...$earlier['search'], ...$search ] ) ),
			'own' => array_values( array_unique( [ ...$earlier['own'], ...$own ] ) ),
		];
	}

	/**
	 * Returns the URLs that match a target in a given state.
	 *
	 * A published target answers at its permalink – its would-be permalink,
	 * computed from the post object because the stored post may already have
	 * changed; any other target matches its would-be permalink and its last
	 * public URL.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post        The post in that state.
	 * @param string  $last_public Its last public URL in that state.
	 * @return string[]
	 */
	private function urls_of( WP_Post $post, string $last_public ): array {
		$urls = Targets::is_published( $post->post_status ) ? [ $this->permalinks->would_be( $post ) ] : [ $this->permalinks->would_be( $post ), $last_public ];
		return array_values( array_unique( array_filter( $urls, static fn( ?string $url ): bool => $url !== null && $url !== '' ) ) );
	}

	/**
	 * Tells whether two sets of URLs differ.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $before The URLs before.
	 * @param string[] $after  The URLs after.
	 * @return bool
	 */
	private function differ( array $before, array $after ): bool {
		sort( $before );
		sort( $after );
		return $before !== $after;
	}

	/**
	 * Returns the published descendants of a post.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post The post.
	 * @return WP_Post[]
	 */
	private function published_descendants( WP_Post $post ): array {

		// Walk down the tree, breadth first.
		$found = [];
		$queue = [ $post->ID ];
		$seen = [ $post->ID => true ];
		while ( $queue !== [] ) {
			foreach ( Targets::find( Targets::all_statuses(), array_shift( $queue ) ) as $child ) {
				if ( ! isset( $seen[ $child->ID ] ) ) {
					$seen[ $child->ID ] = true;
					$queue[] = $child->ID;
					if ( Targets::is_published( $child->post_status ) ) {
						$found[] = $child;
					}
				}
			}
		}

		return $found;

	}

}
