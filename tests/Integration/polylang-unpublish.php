<?php
/**
 * Unpublishes the targets of the Polylang end-to-end test, in disposable
 * Playground only.
 *
 * Runs as its own blueprint step after polylang-seed.php, so the targets are
 * unpublished in a later request than the one that set their language – as on
 * a real site. Polylang caches links per request, and a language set and a
 * page unpublished in the same request would leave the cached link behind.
 *
 * @package Tests\Integration
 * @since   0.1.0
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

wp_set_current_user( 1 );
$ids = get_option( 'kntnt_test_ids', [] );
foreach ( [ 'nodkontakt', 'byt-sprak', 'emergency-contact' ] as $target ) {
	wp_update_post( [ 'ID' => $ids[ $target ], 'post_status' => 'draft' ] );
}
