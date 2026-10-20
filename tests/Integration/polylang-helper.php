<?php
/**
 * Fixture controls for the Polylang end-to-end test, in disposable Playground only.
 *
 * @package Tests\Integration
 * @since   0.1.0
 */

declare( strict_types = 1 );

add_action(
	'wp_loaded',
	static function (): void {
		if ( ( $_GET['kntnt_fixture'] ?? '' ) !== 'fixture-only' ) {
			return;
		}
		$ids = get_option( 'kntnt_test_ids', [] );

		// Switching a draft to English is what an editor does in Polylang.
		if ( ( $_GET['action'] ?? '' ) === 'switch' ) {
			pll_set_post_language( $ids['byt-sprak'], 'en' );
		}

		// What the plugin computes, for diagnosing a failed run.
		if ( ( $_GET['action'] ?? '' ) === 'diagnose' ) {
			header( 'Content-Type: application/json' );
			echo wp_json_encode( [
				'php' => PHP_VERSION,
				'would_be' => array_map( static fn( int $id ): ?string => ( new \Kntnt\Unlink_Unpublished\Permalinks() )->would_be( get_post( $id ) ), $ids ),
				'languages' => array_map( static fn( int $id ): string => (string) pll_get_post_language( $id ), $ids ),
				'index' => get_transient( 'kntnt_unlink_unpublished_index' ),
			] );
			exit;
		}

		header( 'Content-Type: application/json' );
		echo wp_json_encode( [
			'php' => PHP_VERSION,
			'polylang' => defined( 'POLYLANG_VERSION' ) ? POLYLANG_VERSION : null,
			'default' => pll_default_language(),
			'languages' => pll_languages_list(),
			'ids' => $ids,
			'last_public' => array_map( static fn( int $id ): string => (string) get_post_meta( $id, '_kntnt_unlink_unpublished_last_public_url', true ), $ids ),
			'permalinks' => array_map( static fn( int $id ): string => (string) get_permalink( $id ), $ids ),
		] );
		exit;
	},
);
