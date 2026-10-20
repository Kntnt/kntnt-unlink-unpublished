<?php
/**
 * Seeds the rendering end-to-end test, in disposable Playground only.
 *
 * Creates targets in every state of the status matrix through the WordPress
 * API, so the plugin records last public URLs and tombstones as it would on a
 * real site, plus sources that link to them in every form the spec names.
 *
 * @package Tests\Integration
 * @since   0.1.0
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Install the fixture controls and pretty permalinks.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
file_put_contents( WP_CONTENT_DIR . '/mu-plugins/kntnt-rendering-helper.php', "<?php\nrequire '" . __DIR__ . "/rendering-helper.php';\n" );
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%/' );
$wp_rewrite->flush_rules( true );
wp_set_current_user( 1 );

/**
 * Creates a page.
 *
 * @param string               $slug   The slug.
 * @param string               $status The status.
 * @param array<string, mixed> $args   More post fields.
 * @return int The page ID.
 */
function kntnt_test_page( string $slug, string $status = 'publish', array $args = [] ): int {
	return (int) wp_insert_post( array_merge( [
		'post_type' => 'page',
		'post_status' => $status,
		'post_name' => $slug,
		'post_title' => ucfirst( str_replace( '-', ' ', $slug ) ),
		'post_content' => '<!-- wp:paragraph --><p>' . $slug . '</p><!-- /wp:paragraph -->',
	], $args ) );
}

// Targets published first, then moved to their state the way editors do.
$ids = [ 'live' => kntnt_test_page( 'live-page' ) ];
foreach ( [ 'draft', 'pending', 'trash', 'private', 'moved', 'gone', 'restored', 'custom', 'purge-a', 'purge-b', 'purge-c', 'purge-d' ] as $name ) {
	$ids[ $name ] = kntnt_test_page( $name . '-page' );
}
wp_update_post( [ 'ID' => $ids['draft'], 'post_status' => 'draft' ] );
wp_update_post( [ 'ID' => $ids['pending'], 'post_status' => 'pending' ] );
wp_update_post( [ 'ID' => $ids['custom'], 'post_status' => 'draft' ] );
wp_trash_post( $ids['trash'] );
wp_update_post( [ 'ID' => $ids['private'], 'post_status' => 'private' ] );
wp_update_post( [ 'ID' => $ids['moved'], 'post_status' => 'draft' ] );
wp_update_post( [ 'ID' => $ids['moved'], 'post_name' => 'moved-new' ] );
wp_trash_post( $ids['restored'] );
wp_untrash_post( $ids['restored'] );
wp_trash_post( $ids['purge-d'] );
wp_delete_post( $ids['gone'], true );

// Targets that were never public, a password-protected page, a published page
// whose slug would attract the guess redirect, and a trashed parent.
$ids['future'] = kntnt_test_page( 'future-page', 'future', [ 'post_date' => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) ] );
$ids['purge-e'] = kntnt_test_page( 'purge-e-page', 'future', [ 'post_date' => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) ] );
$ids['never'] = kntnt_test_page( 'never-page', 'draft' );
$ids['password'] = kntnt_test_page( 'password-page', 'publish', [ 'post_password' => 'secret' ] );
$ids['similar'] = kntnt_test_page( 'draft-page-2' );
$ids['old-parent'] = kntnt_test_page( 'old-parent' );
$ids['kid'] = kntnt_test_page( 'kid', 'publish', [ 'post_parent' => $ids['old-parent'] ] );
wp_trash_post( $ids['old-parent'] );

// The source: every element and URL form the spec names.
$home = home_url();
$authority = (string) wp_parse_url( $home, PHP_URL_HOST ) . ( wp_parse_url( $home, PHP_URL_PORT ) ? ':' . wp_parse_url( $home, PHP_URL_PORT ) : '' );
$source = <<<HTML
<!-- wp:paragraph -->
<p>INLINE <a href="{$home}/draft-page/">DRAFT-TEXT</a> <a href="/pending-page/">PENDING-TEXT</a> <a href="//{$authority}/future-page/">FUTURE-TEXT</a> <a href="{$home}/trash-page">TRASH-TEXT</a> <a href="{$home}/private-page/">PRIVATE-TEXT</a> <a href="/password-page/">PASSWORD-TEXT</a> <a href="/live-page/?x=1#y">LIVE-TEXT</a> <a href="/?page_id={$ids['draft']}&amp;preview=true">IDFORM-TEXT</a> <a href="/Moved-Page/">MOVED-OLD-TEXT</a> <a href="/moved-new/">MOVED-NEW-TEXT</a> <a href="/restored-page/">RESTORED-TEXT</a> <a href="/never-page/">NEVER-TEXT</a> <a href="/old-parent/kid/">KID-OLD-TEXT</a> <a href="/gone-page/">GONE-TEXT</a> <a href="/?page_id={$ids['gone']}">GONE-ID-TEXT</a> <a href="https://{$authority}/draft-page/">HTTPS-TEXT</a> <a href="https://alias.example.test/pending-page/">ALIAS-TEXT</a> <a href="/custom/custom-page/">CUSTOM-TEXT</a></p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"className":"kntnt-test-buttons"} -->
<div class="wp-block-buttons kntnt-test-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/draft-page/">BUTTON-TEXT</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:image -->
<figure class="wp-block-image"><a href="/draft-page/"><img src="/wp-content/missing.png" alt="IMAGE-ALT"/></a></figure>
<!-- /wp:image -->

<!-- wp:html -->
<p class="icon-holder">ICON-BEFORE <a class="icon-link" href="/draft-page/"><svg viewBox="0 0 1 1"><path d="M0 0"/></svg></a></p>
<!-- /wp:html -->

<!-- wp:paragraph -->
<p>SPAN-KEEP. <span class="kntnt-unlink-unpublished-drop">SPAN-DEAD <a href="/draft-page/">x</a>.</span> <span class="kntnt-unlink-unpublished-drop">SPAN-LIVE <a href="/live-page/">y</a>.</span></p>
<!-- /wp:paragraph -->

<!-- wp:group {"className":"kntnt-test-grid","layout":{"type":"grid"}} -->
<div class="wp-block-group kntnt-test-grid"><!-- wp:group {"className":"kntnt-unlink-unpublished-drop"} -->
<div class="wp-block-group kntnt-unlink-unpublished-drop"><!-- wp:heading -->
<h2 class="wp-block-heading">CARD-DEAD</h2>
<!-- /wp:heading -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/draft-page/">Read</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"kntnt-unlink-unpublished-drop"} -->
<div class="wp-block-group kntnt-unlink-unpublished-drop"><!-- wp:heading -->
<h2 class="wp-block-heading">CARD-LIVE</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><a href="/live-page/">More</a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"className":"kntnt-unlink-unpublished-drop"} -->
<div class="wp-block-group kntnt-unlink-unpublished-drop"><!-- wp:paragraph -->
<p>OUTER-NESTED</p>
<!-- /wp:paragraph -->

<!-- wp:group {"className":"kntnt-unlink-unpublished-drop"} -->
<div class="wp-block-group kntnt-unlink-unpublished-drop"><!-- wp:paragraph -->
<p>INNER-NESTED <a href="/pending-page/">z</a></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->

<!-- wp:list {"className":"kntnt-test-list"} -->
<ul class="wp-block-list kntnt-test-list"><!-- wp:list-item -->
<li><span class="kntnt-unlink-unpublished-drop">LI-DEAD <a href="/draft-page/">x</a></span></li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:navigation {"overlayMenu":"never","className":"kntnt-test-nav"} -->
<!-- wp:navigation-link {"label":"NAV-DRAFT","url":"/draft-page/","kind":"custom"} /-->
<!-- wp:navigation-link {"label":"NAV-LIVE","url":"/live-page/","kind":"custom"} /-->
<!-- wp:navigation-link {"label":"NAV-PRIVATE","type":"page","id":{$ids['private']},"url":"/private-page/","kind":"post-type"} /-->
<!-- wp:navigation-submenu {"label":"SUB-PARENT","type":"page","id":{$ids['draft']},"url":"/draft-page/","kind":"post-type"} -->
<!-- wp:navigation-link {"label":"SUB-CHILD","url":"/live-page/","kind":"custom"} /-->
<!-- /wp:navigation-submenu -->
<!-- /wp:navigation -->
HTML;
$ids['source'] = kntnt_test_page( 'source', 'publish', [ 'post_content' => $source ] );

// Classic content, a post for the feed, and the sources of the purge targets.
$ids['classic'] = kntnt_test_page( 'classic', 'publish', [ 'post_content' => '<p>CLASSIC <a href="/draft-page/">CLASSIC-DEAD</a></p><div class="kntnt-unlink-unpublished-drop">CLASSIC-MARKED <a href="/pending-page/">z</a></div><p>CLASSIC-END</p>' ] );
$ids['feed-source'] = (int) wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'feed-source', 'post_title' => 'Feed source', 'post_content' => '<!-- wp:paragraph --><p>FEED <a href="/draft-page/">FEED-DEAD</a></p><!-- /wp:paragraph -->' ] );
$ids['purge-source'] = kntnt_test_page( 'purge-source', 'publish', [ 'post_content' => '<!-- wp:paragraph --><p><a href="/purge-a-page/">A</a> <a href="/purge-d-page/">D</a> <a href="/purge-e-page/">E</a></p><!-- /wp:paragraph -->' ] );
$ids['purge-part'] = (int) wp_insert_post( [ 'post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_name' => 'purge-part', 'post_title' => 'Purge part', 'post_content' => '<!-- wp:paragraph --><p><a href="/purge-b-page/">B</a> <a href="/purge-c-page/">C</a></p><!-- /wp:paragraph -->' ] );
wp_set_object_terms( $ids['purge-part'], get_stylesheet(), 'wp_theme' );

// A 404 template that tells visitors what happened.
$notice = static fn( string $when, string $text ): string => '<!-- wp:kntnt-unlink-unpublished/notice {"when":"' . $when . '"} --><div class="wp-block-kntnt-unlink-unpublished-notice"><!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph --></div><!-- /wp:kntnt-unlink-unpublished/notice -->';
$template = (int) wp_insert_post( [ 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_name' => '404', 'post_title' => '404', 'post_content' => $notice( 'unpublished', 'NOTICE-UNPUBLISHED' ) . $notice( 'deleted', 'NOTICE-DELETED' ) . '<!-- wp:paragraph --><p>PLAIN-404</p><!-- /wp:paragraph -->' ] );
wp_set_object_terms( $template, get_stylesheet(), 'wp_theme' );

// Targets the activation seeding should and should not pick up, written
// straight to the database as on a site that unpublished them by hand.
global $wpdb;
foreach ( [ 'seed-old' => [ 'draft', '2026-01-01 10:00:00' ], 'seed-new' => [ 'draft', '0000-00-00 00:00:00' ], 'seed-future' => [ 'future', '2030-01-01 10:00:00' ] ] as $slug => [ $status, $gmt ] ) {
	$wpdb->insert( $wpdb->posts, [
		'post_type' => 'page',
		'post_status' => $status,
		'post_name' => $slug,
		'post_title' => $slug,
		'post_content' => '',
		'post_excerpt' => '',
		'to_ping' => '',
		'pinged' => '',
		'post_content_filtered' => '',
		'post_date' => '2026-01-01 10:00:00',
		'post_date_gmt' => $gmt,
		'post_modified' => '2026-01-01 10:00:00',
		'post_modified_gmt' => '2026-01-01 10:00:00',
		'post_author' => 1,
	] );
	$ids[ $slug ] = (int) $wpdb->insert_id;
}
clean_post_cache( $ids['seed-old'] );
deactivate_plugins( 'kntnt-unlink-unpublished/kntnt-unlink-unpublished.php' );
activate_plugin( 'kntnt-unlink-unpublished/kntnt-unlink-unpublished.php' );

update_option( 'kntnt_test_ids', $ids, false );
update_option( 'kntnt_test_purges', [], false );
