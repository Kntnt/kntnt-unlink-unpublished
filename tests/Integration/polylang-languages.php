<?php
/**
 * Adds the languages of the Polylang end-to-end test, in disposable Playground only.
 *
 * Runs as its own blueprint step before polylang-seed.php: Polylang hooks its
 * link filters at start-up, and only when languages exist, so content must be
 * created in a later request – as on any real site, where the languages come
 * before the content.
 *
 * @package Tests\Integration
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Polylang loads its API outside wp-admin only once a language exists.
define( 'WP_ADMIN', true );
require '/wordpress/wp-load.php';

// Swedish first, which makes it the default language, then English.
foreach ( [ [ 'Svenska', 'sv', 'sv_SE' ], [ 'English', 'en', 'en_US' ] ] as $order => [ $name, $slug, $locale ] ) {
	$language = PLL()->model->languages->add( [ 'name' => $name, 'slug' => $slug, 'locale' => $locale, 'rtl' => false, 'term_group' => $order ] );
	if ( is_wp_error( $language ) ) {
		throw new RuntimeException( 'Could not add the language ' . $slug . ': ' . $language->get_error_message() );
	}
}
