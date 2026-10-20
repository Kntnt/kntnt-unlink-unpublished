<?php
/**
 * Answers the current request with the theme's 404 template.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use Stringable;

/**
 * Renders the 404 template in place of a vetoed redirect (spec §6).
 *
 * Does what WordPress 6.9's template loader does for a 404 once
 * `template_redirect` has run, so that a vetoed 404 is the 404 WordPress would
 * have rendered: no body for HEAD, the embed, 404 or index template through
 * `template_include`, and `wp_before_include_template`, where core starts its
 * template enhancement output buffer. The loader itself is not included,
 * because it would fire `template_redirect` again and rerun the callback that
 * redirected. The status code is left as it is.
 *
 * @since 0.2.0
 */
final class Not_Found_Page {

	/**
	 * Renders the 404 template and ends the request.
	 *
	 * @since 0.2.0
	 *
	 * @return never
	 */
	public function render(): never {

		// A HEAD request gets the headers only, as the template loader decides.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'HEAD' && apply_filters( 'exit_on_http_head', true ) ) {
			exit;
		}

		// Choose the template as the template loader does for a 404: the embed
		// template for an embed, then the 404 template, then the index.
		$template = is_embed() ? get_embed_template() : '';
		if ( $template === '' ) {
			$template = get_404_template();
		}
		if ( $template === '' ) {
			$template = get_index_template();
		}
		$template = apply_filters( 'template_include', $template );
		$template = is_string( $template ) || $template instanceof Stringable ? realpath( (string) $template ) : false;

		// Include it the way the loader does. load_template() gives it the
		// globals the loader's global scope would have given it.
		if ( is_string( $template ) && ( str_ends_with( $template, '.php' ) || str_ends_with( $template, '.html' ) ) && is_file( $template ) && is_readable( $template ) ) {
			do_action( 'wp_before_include_template', $template );
			load_template( $template, false );
		}

		exit;

	}

}
