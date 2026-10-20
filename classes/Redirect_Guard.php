<?php
/**
 * Stops redirects to targets that aren't live.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

/**
 * Applies the visibility rule to redirects (spec §6).
 *
 * A redirect issued while answering a front-end 404 is followed only if its
 * target is live. SEO and redirect plugins send such redirects to
 * `get_permalink()`, which for a post that isn't published is its ID form, so
 * without the guard the visitor gets a 301 to a 404, or a loop. The guard
 * judges where a redirect goes, not who issued it, and resolves the location
 * through the index alone, as links are. On a veto the 404 template is
 * rendered in place of the redirect, so the request keeps its own 404 or 410
 * and its request state. Redirects on any other request are editorial
 * decisions or part of a flow, and pass (docs/adr/0011).
 *
 * @since 0.2.0
 */
final class Redirect_Guard {

	/**
	 * Whether the guard is rendering a 404 in place of a redirect, so that a
	 * redirect issued while it renders is left alone.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $rendering = false;

	/**
	 * Creates the guard.
	 *
	 * @since 0.2.0
	 *
	 * @param Index_Store     $store       Serves the index.
	 * @param Liveness        $liveness    Decides whether a target is live.
	 * @param Href_Normaliser $normaliser  Normalises redirect locations.
	 * @param Not_Found_Page  $page        Answers a vetoed redirect.
	 * @param bool            $logs_vetoes Whether each veto is written to the
	 *                                     debug log.
	 */
	public function __construct(
		private readonly Index_Store $store,
		private readonly Liveness $liveness,
		private readonly Href_Normaliser $normaliser,
		private readonly Not_Found_Page $page,
		private readonly bool $logs_vetoes,
	) {}

	/**
	 * Registers the hooks.
	 *
	 * The location filter runs last, so it judges the location every other
	 * filter has settled on.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'old_slug_redirect_post_id', [ $this, 'old_slug_post_id' ] );
		add_filter( 'wp_redirect', [ $this, 'veto_redirect' ], PHP_INT_MAX, 2 );
	}

	/**
	 * Stops core's old-slug redirect to a target that isn't live.
	 *
	 * Core then answers with its ordinary 404, so nothing has to be rendered.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $post_id The ID of the post that once had the slug, or 0.
	 * @return mixed 0 for a target that isn't live, else the ID unchanged.
	 */
	public function old_slug_post_id( mixed $post_id ): mixed {
		$entry = is_int( $post_id ) ? $this->store->index()->lookup( new Href( '/', $post_id ) ) : null;
		return $entry !== null && ! $this->liveness->is_live( $entry ) ? 0 : $post_id;
	}

	/**
	 * Vetoes a redirect to a target that isn't live.
	 *
	 * Runs before `wp_redirect()` sets its status, so a veto keeps the status
	 * code already set. A vetoed redirect never returns: callers exit right
	 * after `wp_redirect()`, so the 404 is rendered here.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $location The redirect location.
	 * @param mixed $status   The redirect status code.
	 * @return mixed The location unchanged, when the redirect is followed.
	 */
	public function veto_redirect( mixed $location, mixed $status ): mixed {

		// Only a redirect on a front-end 404 is judged; anywhere else a
		// redirect is an editorial decision or part of a flow. While the guard
		// renders a 404, a redirect from the template is left alone, so that
		// it never renders twice.
		if ( $this->rendering || ! is_string( $location ) || is_admin() || did_action( 'wp' ) === 0 || ! is_404() ) {
			return $location;
		}

		// Let redirects to live targets, to URLs the index doesn't hold and to
		// other sites through.
		$href = $this->normaliser->normalise( $location );
		$entry = $href === null ? null : $this->store->index()->lookup( $href );
		if ( $entry === null || $this->liveness->is_live( $entry ) ) {
			return $location;
		}

		// Tell a developer who is debugging which redirect was vetoed.
		if ( $this->logs_vetoes ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			error_log( sprintf( 'Kntnt Unlink Unpublished: vetoed a %s redirect from %s to %s, because its target is not live.', is_scalar( $status ) ? (string) $status : '?', $uri, $location ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only when debug logging is on.
		}

		// Answer the request as the 404 or 410 it is.
		$this->rendering = true;
		$this->page->render();

	}

}
