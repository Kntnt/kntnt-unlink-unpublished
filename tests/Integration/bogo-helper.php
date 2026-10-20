<?php
/**
 * Fixture controls for the Bogo end-to-end test, in disposable Playground only.
 *
 * @package Tests\Integration
 * @since   0.1.0
 */

declare( strict_types = 1 );

add_action(
	'init',
	static function (): void {
		if ( ( $_GET['kntnt_fixture'] ?? '' ) !== 'fixture-only' ) {
			return;
		}
		$ids = get_option( 'kntnt_test_ids', [] );

		// Switching a draft to English is what an editor does in Bogo.
		if ( ( $_GET['action'] ?? '' ) === 'switch' ) {
			update_post_meta( $ids['byt-sprak'], '_locale', 'en_US' );
		}

		header( 'Content-Type: application/json' );
		echo wp_json_encode( [
			'php' => PHP_VERSION,
			'bogo' => defined( 'BOGO_VERSION' ) ? BOGO_VERSION : null,
			'locale' => get_locale(),
			'ids' => $ids,
			'last_public' => array_map( static fn( int $id ): string => (string) get_post_meta( $id, '_kntnt_unlink_unpublished_last_public_url', true ), $ids ),
			'permalinks' => array_map( static fn( int $id ): string => (string) get_permalink( $id ), $ids ),
		] );
		exit;
	},
	-100,
);
