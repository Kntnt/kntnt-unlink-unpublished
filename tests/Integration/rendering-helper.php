<?php
/**
 * Fixture controls for the rendering end-to-end test, in disposable Playground only.
 *
 * Records the plugin's purge actions, logs a visitor in, changes targets on
 * request and reports what the driver needs to assert. Every control answers
 * only with the fixed token below, which exists only on the test site.
 *
 * @package Tests\Integration
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Record every purge announcement for the driver to read.
add_action(
	'kntnt_unlink_unpublished_purge_urls',
	static function ( array $urls, WP_Post $target, string $change ): void {
		$log = get_option( 'kntnt_test_purges', [] );
		$log[] = [ 'type' => 'urls', 'urls' => $urls, 'target' => $target->ID, 'change' => $change ];
		update_option( 'kntnt_test_purges', $log, false );
	},
	10,
	3,
);
add_action(
	'kntnt_unlink_unpublished_purge_all',
	static function ( ?WP_Post $target, string $change ): void {
		$log = get_option( 'kntnt_test_purges', [] );
		$log[] = [ 'type' => 'all', 'target' => $target?->ID, 'change' => $change ];
		update_option( 'kntnt_test_purges', $log, false );
	},
	10,
	2,
);

// The loopback of the redirect check cannot reach a single-worker Playground;
// answer it as a leftover redirect rule would.
add_filter(
	'pre_http_request',
	static function ( mixed $response, array $args, string $url ): mixed {
		$redirecting = get_option( 'kntnt_test_redirecting' );
		if ( is_string( $redirecting ) && $redirecting !== '' && str_starts_with( $url, $redirecting ) ) {
			return [ 'headers' => [ 'location' => home_url( '/' ) ], 'body' => '', 'response' => [ 'code' => 302, 'message' => 'Found' ], 'cookies' => [], 'filename' => null ];
		}
		return $response;
	},
	10,
	3,
);

// Lets the driver show what WordPress does without the plugin's guard.
if ( ( $_GET['kntnt_without_guard'] ?? '' ) === 'fixture-only' ) {
	add_action( 'init', static fn() => remove_all_filters( 'do_redirect_guess_404_permalink' ), PHP_INT_MAX );
}

// A second host that serves the same site, added through the hosts filter.
add_filter( 'kntnt_unlink_unpublished_hosts', static fn( array $hosts ): array => [ ...$hosts, 'alias.example.test' ] );

// A third-party permalink filter, standing in for a language plugin that
// moves one page under /custom/ once it is switched on.
add_filter(
	'page_link',
	static function ( string $link, int $post_id ): string {
		$ids = get_option( 'kntnt_test_ids', [] );
		return get_option( 'kntnt_test_custom_prefix' ) === '1' && $post_id === ( $ids['custom'] ?? 0 ) ? str_replace( home_url( '/' ), home_url( '/custom/' ), $link ) : $link;
	},
	30,
	2,
);

// The fixture controls.
add_action(
	'init',
	static function (): void {

		if ( ( $_GET['kntnt_fixture'] ?? '' ) !== 'fixture-only' ) {
			return;
		}
		$ids = get_option( 'kntnt_test_ids', [] );
		$action = sanitize_key( (string) ( $_GET['action'] ?? 'ready' ) );
		$page = static fn( string $name ): WP_Post => get_post( $ids[ $name ] );
		$result = [ 'ok' => true ];

		// Changes are made as the administrator.
		wp_set_current_user( 1 );
		switch ( $action ) {
			case 'ready':
				$result = [
					'php' => PHP_VERSION,
					'wp' => get_bloginfo( 'version' ),
					'ids' => $ids,
					'seeded' => [
						'old' => get_post_meta( $ids['seed-old'], '_kntnt_unlink_unpublished_last_public_url', true ),
						'new' => get_post_meta( $ids['seed-new'], '_kntnt_unlink_unpublished_last_public_url', true ),
						'future' => get_post_meta( $ids['seed-future'], '_kntnt_unlink_unpublished_last_public_url', true ),
					],
					'tombstones' => get_option( 'kntnt_unlink_unpublished_tombstones', [] ),
				];
				break;
			case 'login':
				wp_set_auth_cookie( 1 );
				break;
			case 'content':
				$result = [ 'content' => $page( (string) ( $_GET['page'] ?? '' ) )->post_content ];
				break;
			case 'purges':
				$result = [ 'purges' => get_option( 'kntnt_test_purges', [] ) ];
				update_option( 'kntnt_test_purges', [], false );
				break;
			case 'publish':
				wp_update_post( [ 'ID' => $ids[ (string) ( $_GET['page'] ?? '' ) ], 'post_status' => 'publish' ] );
				break;
			case 'unpublish':
				wp_update_post( [ 'ID' => $ids[ (string) ( $_GET['page'] ?? '' ) ], 'post_status' => 'draft' ] );
				break;
			case 'unpublish-bulk':
				foreach ( explode( ',', (string) ( $_GET['pages'] ?? '' ) ) as $name ) {
					wp_update_post( [ 'ID' => $ids[ $name ], 'post_status' => 'draft' ] );
				}
				break;
			case 'save':
				$post = $page( (string) ( $_GET['page'] ?? '' ) );
				wp_update_post( [ 'ID' => $post->ID, 'post_title' => $post->post_title . ' (edited)' ] );
				break;
			case 'delete':
				wp_delete_post( $ids[ (string) ( $_GET['page'] ?? '' ) ], true );
				break;
			case 'cron-publish':
				wp_publish_post( $ids[ (string) ( $_GET['page'] ?? '' ) ] );
				break;
			case 'check-redirect':
				$post = $page( (string) ( $_GET['page'] ?? '' ) );
				update_option( 'kntnt_test_redirecting', get_permalink( $post ), false );
				do_action( 'kntnt_unlink_unpublished_check_redirect', $post->ID );
				$result = [ 'records' => get_option( 'kntnt_unlink_unpublished_redirect_checks', [] ), 'scheduled' => wp_next_scheduled( 'kntnt_unlink_unpublished_check_redirect', [ $post->ID ] ) !== false ];
				break;
			case 'site-health':
				$tests = apply_filters( 'site_status_tests', [ 'direct' => [], 'async' => [] ] );
				foreach ( $tests['async'] as $id => $test ) {
					if ( str_starts_with( $id, 'kntnt_unlink_unpublished_' ) ) {
						$route = (string) preg_replace( '~^.*/wp-json~', '', (string) wp_parse_url( $test['test'], PHP_URL_PATH ) );
						$response = rest_do_request( new WP_REST_Request( 'GET', $route ) );
						$result[ $id ] = $response->get_data();
					}
				}
				break;
			case 'rest-save':
				$post = $page( (string) ( $_GET['page'] ?? '' ) );
				$before = $post->post_content;
				$request = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $post->ID );
				$request->set_param( 'content', $before );
				$response = rest_do_request( $request );
				$result = [ 'status' => $response->get_status(), 'unchanged' => get_post( $post->ID )->post_content === $before ];
				break;
			case 'custom-prefix':
				update_option( 'kntnt_test_custom_prefix', '1', false );
				do_action( 'kntnt_unlink_unpublished_permalinks_changed' );
				break;
			case 'reset-index':
				update_option( 'kntnt_unlink_unpublished_index_version', (int) get_option( 'kntnt_unlink_unpublished_index_version', 0 ) + 1 );
				break;
			case 'index':
				$result = [ 'index' => get_transient( 'kntnt_unlink_unpublished_index' ), 'version' => get_option( 'kntnt_unlink_unpublished_index_version' ) ];
				break;
			case 'housekeeping':
				$result = [ 'scheduled' => wp_next_scheduled( 'kntnt_unlink_unpublished_housekeeping' ) !== false ];
				break;
		}

		header( 'Content-Type: application/json' );
		echo wp_json_encode( $result );
		exit;

	},
	-100,
);
