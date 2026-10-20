<?php
/**
 * Fixture controls for the Slim SEO end-to-end test, in disposable Playground only.
 *
 * Changes the page's status the way an editor does and switches the plugin's
 * redirect guard off on request. The controls run on `wp_loaded`, after Slim
 * SEO has set itself up on `init`, so that it records the slug changes as it
 * does on a real site. Every control answers only with the fixed token below,
 * which exists only on the test site.
 *
 * @package Tests\Integration
 * @since   0.2.0
 */

declare( strict_types = 1 );

// Lets the driver show what Slim SEO does without the plugin's guard. A query
// argument would change the address Slim SEO matches, so an option switches it.
add_action(
	'init',
	static function (): void {
		if ( get_option( 'kntnt_test_guard_off' ) === '1' ) {
			remove_all_filters( 'wp_redirect' );
		}
	},
	PHP_INT_MAX,
);

// The fixture controls.
add_action(
	'wp_loaded',
	static function (): void {

		if ( ( $_GET['kntnt_fixture'] ?? '' ) !== 'fixture-only' ) {
			return;
		}
		$id = (int) ( get_option( 'kntnt_test_ids', [] )['slim'] ?? 0 );
		$action = sanitize_key( (string) ( $_GET['action'] ?? 'ready' ) );
		$result = [ 'ok' => true ];

		// Changes are made as the administrator.
		wp_set_current_user( 1 );
		switch ( $action ) {
			case 'ready':
				$result = [
					'php' => PHP_VERSION,
					'slim_seo' => class_exists( 'SlimSEO\Redirection\Loader' ) ? get_file_data( WP_PLUGIN_DIR . '/slim-seo/slim-seo.php', [ 'Version' => 'Version' ] )['Version'] : null,
					'ids' => [ 'slim' => $id ],
				];
				break;
			case 'trash':
				wp_trash_post( $id );
				break;
			case 'restore':
				wp_untrash_post( $id );
				break;
			case 'publish':
				wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
				break;
			case 'unpublish':
				wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
				break;
			case 'records':
				$result = [ 'status' => get_post_status( $id ), 'records' => get_post_meta( $id, '_ss_old_permalink' ) ];
				break;
			case 'guard-off':
				update_option( 'kntnt_test_guard_off', '1', false );
				break;
			case 'guard-on':
				update_option( 'kntnt_test_guard_off', '0', false );
				break;
		}

		header( 'Content-Type: application/json' );
		echo wp_json_encode( $result );
		exit;

	},
);
