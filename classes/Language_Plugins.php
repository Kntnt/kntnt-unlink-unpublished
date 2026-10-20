<?php
/**
 * Connects the supported multilingual plugins to the plugin's hooks.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

/**
 * Adapters for Bogo and Polylang, built on the public hooks (docs/adr/0010).
 *
 * A multilingual plugin's permalink filters already put a language into
 * would-be permalinks. What the plugin cannot see by itself is when a post's
 * language changes, which moves its URL, and which hosts serve the site's
 * languages. These adapters tell it through the same two hooks any other
 * multilingual plugin, or a site adapter, would use: the action
 * `kntnt_unlink_unpublished_permalinks_changed` and the filter
 * `kntnt_unlink_unpublished_hosts`. Each adapter does nothing when its plugin
 * is not active.
 *
 * @since 0.1.0
 */
final class Language_Plugins {

	/**
	 * The action that tells the plugin permalinks changed.
	 *
	 * @since 0.1.0
	 */
	public const string PERMALINKS_CHANGED = 'kntnt_unlink_unpublished_permalinks_changed';

	/**
	 * Bogo's post meta: a post's locale, and the post it translates.
	 *
	 * @since 0.1.0
	 */
	private const array BOGO_META = [ '_locale', '_original_post' ];

	/**
	 * Polylang's taxonomy holding a post's language.
	 *
	 * @since 0.1.0
	 */
	private const string POLYLANG_LANGUAGE = 'language';

	/**
	 * Polylang's actions and option updates that change language prefixes or
	 * domains.
	 *
	 * @since 0.1.0
	 */
	private const array POLYLANG_SETTINGS = [ 'pll_add_language', 'pll_update_language', 'pll_update_default_lang', 'update_option_polylang' ];

	/**
	 * Registers the hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {

		// Bogo keeps a post's locale and translation in post meta.
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ $this, 'meta_changed' ], 10, 3 );
		}

		// Polylang keeps a post's language as a term, and the languages and
		// their URL settings in its own terms and option.
		add_action( 'set_object_terms', [ $this, 'terms_changed' ], 10, 4 );
		add_action( 'delete_term', [ $this, 'term_deleted' ], 10, 3 );
		foreach ( self::POLYLANG_SETTINGS as $hook ) {
			add_action( $hook, [ $this, 'announce' ] );
		}

		// Polylang can give each language a domain or subdomain of its own.
		add_filter( 'kntnt_unlink_unpublished_hosts', [ $this, 'polylang_hosts' ] );

	}

	/**
	 * Announces changed permalinks when Bogo changes a post's locale or
	 * translation.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $meta_id  The meta ID, or IDs on deletion.
	 * @param mixed $post_id  The post ID.
	 * @param mixed $meta_key The meta key.
	 * @return void
	 */
	public function meta_changed( mixed $meta_id, mixed $post_id, mixed $meta_key ): void {
		if ( in_array( $meta_key, self::BOGO_META, true ) ) {
			$this->announce();
		}
	}

	/**
	 * Announces changed permalinks when Polylang changes a post's language.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $object_id The object ID.
	 * @param mixed $terms     The terms.
	 * @param mixed $tt_ids    The term taxonomy IDs.
	 * @param mixed $taxonomy  The taxonomy.
	 * @return void
	 */
	public function terms_changed( mixed $object_id, mixed $terms, mixed $tt_ids, mixed $taxonomy ): void {
		if ( $taxonomy === self::POLYLANG_LANGUAGE ) {
			$this->announce();
		}
	}

	/**
	 * Announces changed permalinks when Polylang deletes a language.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $term     The term ID.
	 * @param mixed $tt_id    The term taxonomy ID.
	 * @param mixed $taxonomy The taxonomy.
	 * @return void
	 */
	public function term_deleted( mixed $term, mixed $tt_id, mixed $taxonomy ): void {
		$this->terms_changed( $term, [], [], $taxonomy );
	}

	/**
	 * Tells the plugin that permalinks changed.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function announce(): void {
		do_action( self::PERMALINKS_CHANGED );
	}

	/**
	 * Adds the hosts of Polylang's languages, for languages on domains or
	 * subdomains of their own.
	 *
	 * In Polylang's directory mode every language shares the site's host, so
	 * nothing is added. Polylang is asked only once it has set up its
	 * languages.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $hosts The hosts so far.
	 * @return mixed
	 */
	public function polylang_hosts( mixed $hosts ): mixed {

		// Leave the hosts alone without Polylang or before it is ready.
		if ( ! is_array( $hosts ) || ! function_exists( 'pll_languages_list' ) || ! function_exists( 'pll_home_url' ) || did_action( 'pll_init' ) === 0 ) {
			return $hosts;
		}

		// Add the host of each language's home URL.
		$hosts = array_values( array_filter( $hosts, 'is_string' ) );
		$languages = pll_languages_list();
		foreach ( is_array( $languages ) ? $languages : [] as $slug ) {
			$home = is_string( $slug ) ? pll_home_url( $slug ) : null;
			$parts = is_string( $home ) ? wp_parse_url( $home ) : null;
			if ( is_array( $parts ) && isset( $parts['host'] ) ) {
				$hosts[] = strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
			}
		}

		return array_values( array_unique( $hosts ) );

	}

}
