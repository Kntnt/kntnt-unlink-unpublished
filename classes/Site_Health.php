<?php
/**
 * Reports what the plugin hides and what needs an editor's attention.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_Post;
use WP_REST_Request;

/**
 * Registers three asynchronous Site Health tests (spec §10).
 *
 * Each test runs through its own REST endpoint, as asynchronous tests do, and
 * directly through `async_direct_test` when Site Health runs from cron.
 *
 * @since 0.1.0
 */
final class Site_Health {

	/**
	 * The REST namespace of the tests.
	 *
	 * @since 0.1.0
	 */
	public const string REST_NAMESPACE = 'kntnt-unlink-unpublished/v1';

	/**
	 * The tests: REST slug => method.
	 *
	 * @since 0.1.0
	 */
	private const array TESTS = [
		'deleted-links' => 'deleted_links',
		'redirects' => 'redirects',
		'hidden-links' => 'hidden_links',
	];

	/**
	 * Creates the Site Health integration.
	 *
	 * @since 0.1.0
	 *
	 * @param Index_Store     $store      Serves the index.
	 * @param Source_Finder   $finder     Finds the sources of a tombstone.
	 * @param Redirect_Check  $redirects  Re-checks the recorded redirects.
	 * @param Href_Normaliser $normaliser Normalises the hrefs found in sources.
	 */
	public function __construct(
		private readonly Index_Store $store,
		private readonly Source_Finder $finder,
		private readonly Redirect_Check $redirects,
		private readonly Href_Normaliser $normaliser,
	) {}

	/**
	 * Registers the hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'site_status_tests', [ $this, 'add_tests' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Adds the asynchronous tests.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $tests The Site Health tests.
	 * @return mixed
	 */
	public function add_tests( mixed $tests ): mixed {

		// Leave a malformed list alone.
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		// Each test runs through its REST route, or directly from cron.
		$async = is_array( $tests['async'] ?? null ) ? $tests['async'] : [];
		$labels = [
			'deleted-links' => __( 'Links to deleted pages', 'kntnt-unlink-unpublished' ),
			'redirects' => __( 'Published pages whose address redirects', 'kntnt-unlink-unpublished' ),
			'hidden-links' => __( 'Links currently hidden', 'kntnt-unlink-unpublished' ),
		];
		foreach ( self::TESTS as $slug => $method ) {
			$async[ self::test_id( $slug ) ] = [
				'label' => $labels[ $slug ],
				'test' => rest_url( self::REST_NAMESPACE . '/tests/' . $slug ),
				'has_rest' => true,
				'async_direct_test' => [ $this, $method ],
			];
		}
		$tests['async'] = $async;

		return $tests;

	}

	/**
	 * Registers the REST routes of the tests.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/tests/(?P<test>' . implode( '|', array_keys( self::TESTS ) ) . ')',
			[
				'methods' => 'GET',
				'callback' => fn( WP_REST_Request $request ): mixed => rest_ensure_response( $this->run( $request->get_param( 'test' ) ) ),
				'permission_callback' => static fn(): bool => current_user_can( 'view_site_health_checks' ),
			],
		);
	}

	/**
	 * Runs a test by its REST slug.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $slug The slug from the route.
	 * @return array<string, mixed> A Site Health result, or nothing for an
	 *                              unknown slug, which the route rules out.
	 */
	private function run( mixed $slug ): array {
		return match ( $slug ) {
			'deleted-links' => $this->deleted_links(),
			'redirects' => $this->redirects(),
			'hidden-links' => $this->hidden_links(),
			default => [],
		};
	}

	/**
	 * Lists the tombstones that sources still link to.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> A Site Health result.
	 */
	public function deleted_links(): array {

		// Collect each tombstone that something still links to.
		$items = [];
		foreach ( Tombstones::all() as $tombstone ) {
			$sources = $this->finder->find( [ $tombstone['url'] ], $tombstone['id'] );
			if ( $sources->is_linked() ) {
				$items[] = sprintf(
					/* translators: 1: title of the deleted page, 2: its address, 3: the pages that link to it. */
					__( '%1$s (%2$s), linked from %3$s', 'kntnt-unlink-unpublished' ),
					'<strong>' . esc_html( $tombstone['title'] ) . '</strong>',
					'<code>' . esc_html( $tombstone['url'] ) . '</code>',
					$this->source_list( $sources ),
				);
			}
		}

		// Recommend cleaning up when anything links to a deleted page.
		if ( $items === [] ) {
			return $this->result( 'deleted-links', 'good', __( 'No content links to deleted pages', 'kntnt-unlink-unpublished' ), __( 'Links to deleted pages are hidden from visitors, and none are left in your content.', 'kntnt-unlink-unpublished' ) );
		}
		return $this->result( 'deleted-links', 'recommended', __( 'Content links to deleted pages', 'kntnt-unlink-unpublished' ), __( 'These links are hidden from visitors, but the pages are gone for good. Remove the links or point them elsewhere.', 'kntnt-unlink-unpublished' ), $items );

	}

	/**
	 * Lists the published pages whose address redirects.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> A Site Health result.
	 */
	public function redirects(): array {

		// Check every recorded page again before reporting.
		$this->redirects->recheck();
		$items = [];
		foreach ( Redirect_Check::records() as $post_id => $record ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$items[] = $record['status'] === 'redirect'
				? sprintf(
					/* translators: 1: page title with edit link, 2: HTTP status code, 3: redirect target. */
					__( '%1$s answers %2$d and redirects to %3$s', 'kntnt-unlink-unpublished' ),
					$this->edit_link( $post ),
					$record['code'],
					'<code>' . esc_html( $record['location'] ) . '</code>',
				)
				: sprintf(
					/* translators: %s: page title with edit link. */
					__( '%s could not be checked', 'kntnt-unlink-unpublished' ),
					$this->edit_link( $post ),
				);
		}

		// Recommend removing leftover redirect rules.
		if ( $items === [] ) {
			return $this->result( 'redirects', 'good', __( 'Published pages answer at their address', 'kntnt-unlink-unpublished' ), __( 'No published page redirects elsewhere.', 'kntnt-unlink-unpublished' ) );
		}
		return $this->result( 'redirects', 'recommended', __( 'Published pages redirect elsewhere', 'kntnt-unlink-unpublished' ), __( 'These pages are published, but their address redirects – typically because a redirect rule was left in an SEO or redirect plugin while the page was unpublished.', 'kntnt-unlink-unpublished' ), $items );

	}

	/**
	 * Counts and lists the targets that aren't live and that sources link to.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> A Site Health result.
	 */
	public function hidden_links(): array {

		// Read every href and block URL in published content against the index.
		$index = $this->store->index();
		$targets = [];
		if ( ! $index->is_empty() ) {
			$wpdb = Database::get();
			$contents = $wpdb->get_col( "SELECT post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND ( post_content LIKE '%href%' OR post_content LIKE '%\"url\"%' )" );
			foreach ( array_filter( $contents, 'is_string' ) as $content ) {
				preg_match_all( '/(?:\bhref\s*=\s*|"url"\s*:\s*)(["\'])(.*?)\1/i', $content, $matches );
				foreach ( $matches[2] as $raw ) {
					$href = $this->normaliser->normalise( html_entity_decode( str_replace( '&', '&', $raw ), ENT_QUOTES | ENT_HTML5 ) );
					$entry = $href === null ? null : $index->lookup( $href );
					if ( $entry !== null ) {
						$targets[ $entry->target_id ] = $entry;
					}
				}
			}
		}

		// Describe each target by title and state.
		$items = [];
		$tombstones = Tombstones::all();
		foreach ( $targets as $id => $entry ) {
			$post = get_post( $id );
			$title = $post instanceof WP_Post ? $this->edit_link( $post ) : esc_html( $tombstones[ $id ]['title'] ?? (string) $id );
			$items[] = $title . ' – ' . esc_html( $this->state_label( $entry->state ) );
		}

		$label = sprintf(
			/* translators: %d: number of pages. */
			_n( 'Links to %d page are hidden from visitors', 'Links to %d pages are hidden from visitors', count( $items ), 'kntnt-unlink-unpublished' ),
			count( $items ),
		);
		return $this->result( 'hidden-links', 'good', $label, __( 'Links to pages that are not published render as plain text. They come back by themselves when the pages are published.', 'kntnt-unlink-unpublished' ), $items );

	}

	/**
	 * Returns the Site Health ID of a test.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug The test's REST slug.
	 * @return string
	 */
	private static function test_id( string $slug ): string {
		return 'kntnt_unlink_unpublished_' . str_replace( '-', '_', $slug );
	}

	/**
	 * Builds a Site Health result.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $slug        The test's REST slug.
	 * @param string   $status      'good' or 'recommended'.
	 * @param string   $label       The result's headline.
	 * @param string   $explanation A sentence about the result.
	 * @param string[] $items       Escaped HTML list items.
	 * @return array<string, mixed>
	 */
	private function result( string $slug, string $status, string $label, string $explanation, array $items = [] ): array {
		$list = $items === [] ? '' : '<ul><li>' . implode( '</li><li>', $items ) . '</li></ul>';
		return [
			'label' => $label,
			'status' => $status,
			'badge' => [
				'label' => __( 'Content', 'kntnt-unlink-unpublished' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html( $explanation ) . '</p>' . $list,
			'actions' => '',
			'test' => self::test_id( $slug ),
		];
	}

	/**
	 * Returns the sources as a list of edit links.
	 *
	 * @since 0.1.0
	 *
	 * @param Sources $sources The sources.
	 * @return string Escaped HTML.
	 */
	private function source_list( Sources $sources ): string {
		$links = array_map( $this->edit_link( ... ), $sources->posts );
		if ( $sources->in_menus ) {
			$links[] = esc_html__( 'a menu', 'kntnt-unlink-unpublished' );
		}
		if ( $sources->in_widgets ) {
			$links[] = esc_html__( 'a widget', 'kntnt-unlink-unpublished' );
		}
		return implode( ', ', $links );
	}

	/**
	 * Returns a post's title linked to its editor.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post The post.
	 * @return string Escaped HTML.
	 */
	private function edit_link( WP_Post $post ): string {
		$title = get_the_title( $post );
		$title = esc_html( $title !== '' ? $title : __( '(no title)', 'kntnt-unlink-unpublished' ) );
		$url = get_edit_post_link( $post, 'raw' );
		return is_string( $url ) ? '<a href="' . esc_url( $url ) . '">' . $title . '</a>' : $title;
	}

	/**
	 * Returns the label of a target state.
	 *
	 * @since 0.1.0
	 *
	 * @param Target_State $state The state.
	 * @return string
	 */
	private function state_label( Target_State $state ): string {
		return match ( $state ) {
			Target_State::Unpublished => __( 'unpublished', 'kntnt-unlink-unpublished' ),
			Target_State::Private => __( 'private', 'kntnt-unlink-unpublished' ),
			Target_State::Deleted => __( 'deleted', 'kntnt-unlink-unpublished' ),
		};
	}

}
