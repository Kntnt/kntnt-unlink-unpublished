<?php
/**
 * Seeds the Bogo end-to-end test, in disposable Playground only.
 *
 * Mirrors the first real site: Swedish is the default locale without a URL
 * prefix and English answers under /en/. Each language has a source page and
 * a target that was published and is unpublished now.
 *
 * @package Tests\Integration
 * @since   0.1.0
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Swedish as the site language. An empty language file is enough for
// WordPress and Bogo to count the locale as installed; the option is written
// straight to the database, past the check against downloaded language packs.
global $wpdb;
wp_mkdir_p( WP_LANG_DIR );
file_put_contents( WP_LANG_DIR . '/sv_SE.mo', '' );
$wpdb->replace( $wpdb->options, [ 'option_name' => 'WPLANG', 'option_value' => 'sv_SE', 'autoload' => 'on' ] );
wp_cache_delete( 'alloptions', 'options' );
wp_cache_delete( 'WPLANG', 'options' );

// The fixture control, read straight from the mounted plugin.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
file_put_contents( WP_CONTENT_DIR . '/mu-plugins/kntnt-bogo-helper.php', "<?php\nrequire '" . __DIR__ . "/bogo-helper.php';\n" );
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%/' );
wp_set_current_user( 1 );

// Targets in each language, published first and unpublished the way an
// editor does it – one Swedish target is switched to English by the driver –
// and a source per language.
$ids = [];
foreach ( [ 'sv_SE' => [ 'nodkontakt', 'byt-sprak' ], 'en_US' => [ 'emergency-contact' ] ] as $locale => $targets ) {
	foreach ( $targets as $target ) {
		$ids[ $target ] = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $target, 'post_title' => $target, 'post_content' => '', 'meta_input' => [ '_locale' => $locale ] ] );
		wp_update_post( [ 'ID' => $ids[ $target ], 'post_status' => 'draft' ] );
	}
}
$ids['hundar'] = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'hundar', 'post_title' => 'Hundar', 'meta_input' => [ '_locale' => 'sv_SE' ], 'post_content' => '<!-- wp:paragraph --><p>Läs mer under <a href="' . home_url( '/nodkontakt/' ) . '">SV-TEXT</a>. <a href="' . home_url( '/en/emergency-contact/' ) . '">SV-TO-EN-TEXT</a></p><!-- /wp:paragraph -->' ] );
$ids['dogs'] = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'dogs', 'post_title' => 'Dogs', 'meta_input' => [ '_locale' => 'en_US' ], 'post_content' => '<!-- wp:paragraph --><p>Read more under <a href="' . home_url( '/en/emergency-contact/' ) . '">EN-TEXT</a>. <a href="/nodkontakt/">EN-TO-SV-TEXT</a> <a href="/en/byt-sprak/">SWITCH-TEXT</a></p><!-- /wp:paragraph -->' ] );
$wp_rewrite->flush_rules( true );

update_option( 'kntnt_test_ids', $ids, false );
