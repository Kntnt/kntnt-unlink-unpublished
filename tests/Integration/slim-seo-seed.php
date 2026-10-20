<?php
/**
 * Seeds the Slim SEO end-to-end test, in disposable Playground only.
 *
 * Creates one published page for the driver to walk through a trash cycle,
 * with Slim SEO's automatic redirection on, as it is by default.
 *
 * @package Tests\Integration
 * @since   0.2.0
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Install the fixture controls and pretty permalinks.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
file_put_contents( WP_CONTENT_DIR . '/mu-plugins/kntnt-slim-seo-helper.php', "<?php\nrequire '" . __DIR__ . "/slim-seo-helper.php';\n" );
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%/' );
$wp_rewrite->flush_rules( true );
wp_set_current_user( 1 );

// The page the driver trashes, restores, publishes and unpublishes.
$id = (int) wp_insert_post( [
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_name' => 'slim-test',
	'post_title' => 'Slim test',
	'post_content' => '<!-- wp:paragraph --><p>SLIM-TEST</p><!-- /wp:paragraph -->',
] );
update_option( 'kntnt_test_ids', [ 'slim' => $id ], false );
