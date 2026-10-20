<?php
/**
 * Seeds the Polylang end-to-end test, in disposable Playground only.
 *
 * Mirrors the Bogo test with Polylang's default URL mode: the language is set
 * from the directory name, Swedish is the default language without a prefix
 * and English answers under /en/. The languages are added by
 * polylang-languages.php in the step before.
 *
 * @package Tests\Integration
 * @since   0.1.0
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// The fixture controls, read straight from the mounted plugin, and pretty
// permalinks.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
file_put_contents( WP_CONTENT_DIR . '/mu-plugins/kntnt-polylang-helper.php', "<?php\nrequire '" . __DIR__ . "/polylang-helper.php';\n" );
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%/' );
wp_set_current_user( 1 );

/**
 * Creates a page in a language.
 *
 * @param string $slug    The slug.
 * @param string $lang    The language slug.
 * @param string $content The content.
 * @return int The page ID.
 */
function kntnt_test_polylang_page( string $slug, string $lang, string $content = '' ): int {
	$id = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $slug, 'post_content' => $content ] );
	pll_set_post_language( $id, $lang );
	return $id;
}

// Targets in each language, published here and unpublished in the next step
// – a later request, as when an editor unpublishes a page – with one Swedish
// target that the driver switches to English, and a source per language.
$ids = [];
foreach ( [ 'sv' => [ 'nodkontakt', 'byt-sprak' ], 'en' => [ 'emergency-contact' ] ] as $lang => $targets ) {
	foreach ( $targets as $target ) {
		$ids[ $target ] = kntnt_test_polylang_page( $target, $lang );
	}
}
$ids['hundar'] = kntnt_test_polylang_page( 'hundar', 'sv', '<!-- wp:paragraph --><p>Läs mer under <a href="' . home_url( '/nodkontakt/' ) . '">SV-TEXT</a>. <a href="' . home_url( '/en/emergency-contact/' ) . '">SV-TO-EN-TEXT</a></p><!-- /wp:paragraph -->' );
$ids['dogs'] = kntnt_test_polylang_page( 'dogs', 'en', '<!-- wp:paragraph --><p>Read more under <a href="' . home_url( '/en/emergency-contact/' ) . '">EN-TEXT</a>. <a href="/nodkontakt/">EN-TO-SV-TEXT</a> <a href="/en/byt-sprak/">SWITCH-TEXT</a></p><!-- /wp:paragraph -->' );
$wp_rewrite->flush_rules( true );

update_option( 'kntnt_test_ids', $ids, false );
