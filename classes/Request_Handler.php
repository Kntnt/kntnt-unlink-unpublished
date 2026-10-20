<?php
/**
 * Makes the URL of an unpublished or deleted target say the right thing.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_Post;

/**
 * Handles requests for URLs in the index (spec §6).
 *
 * WordPress already answers 404 at the URL of an unpublished target, except to
 * a user who may preview it. The handler stops the guess redirect for every
 * URL in the index, answers 410 at a tombstone URL and tells the theme the
 * request state. The state is `unpublished` only for a URL that has been
 * public, so a draft or private page that never was public gives a plain 404
 * that reveals nothing WordPress itself would not.
 *
 * @since 0.1.0
 */
final class Request_Handler {

	/**
	 * Whether the request URI has been looked up in the index.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $looked_up = false;

	/**
	 * The request's index entry, once looked up.
	 *
	 * @since 0.1.0
	 *
	 * @var Index_Entry|null
	 */
	private ?Index_Entry $entry = null;

	/**
	 * Creates the handler.
	 *
	 * @since 0.1.0
	 *
	 * @param Index_Store     $store      Serves the index.
	 * @param Href_Normaliser $normaliser Normalises the request URI.
	 */
	public function __construct(
		private readonly Index_Store $store,
		private readonly Href_Normaliser $normaliser,
	) {}

	/**
	 * Registers the hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'do_redirect_guess_404_permalink', [ $this, 'allow_guess_redirect' ] );
		add_action( 'template_redirect', [ $this, 'send_status' ], 0 );
		add_filter( 'body_class', [ $this, 'body_class' ] );
	}

	/**
	 * Stops WordPress from guessing a similarly named page for a URL in the index.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $do_redirect Whether to guess.
	 * @return mixed
	 */
	public function allow_guess_redirect( mixed $do_redirect ): mixed {
		return $this->entry() === null ? $do_redirect : false;
	}

	/**
	 * Sends the status code of the request state, when it differs from 404.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function send_status(): void {

		// Only a request that has a state gets a status code of its own.
		$state = $this->request_state();
		$entry = $this->entry();
		if ( $state === null || $entry === null ) {
			return;
		}

		// 404 for an unpublished page and 410 for a deleted one, unless a site
		// filters it, for example to 503 during a short planned absence.
		$default = $state === Target_State::Deleted->value ? 410 : 404;
		$post = $entry->state === Target_State::Deleted ? null : get_post( $entry->target_id );
		$code = apply_filters( 'kntnt_unlink_unpublished_status_code', $default, $state, $post instanceof WP_Post ? $post : null );
		if ( is_int( $code ) && $code !== 404 ) {
			status_header( $code );
		}

	}

	/**
	 * Adds the request state as a body class.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $classes The body classes.
	 * @return mixed
	 */
	public function body_class( mixed $classes ): mixed {
		$state = $this->request_state();
		if ( is_array( $classes ) && $state !== null ) {
			$classes[] = 'kntnt-unlink-unpublished-' . $state;
		}
		return $classes;
	}

	/**
	 * Returns the state of the current request.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null 'unpublished', 'deleted' or null.
	 */
	public function request_state(): ?string {

		// A request that WordPress answers, such as an editor's preview, has no
		// state, and only a 404 is worth looking up.
		$entry = is_404() ? $this->entry() : null;
		if ( $entry === null ) {
			return null;
		}

		return match ( true ) {
			$entry->state === Target_State::Deleted => Target_State::Deleted->value,
			$entry->state === Target_State::Unpublished && $entry->was_public => Target_State::Unpublished->value,
			default => null,
		};

	}

	/**
	 * Looks up the request URI in the index, once per request.
	 *
	 * @since 0.1.0
	 *
	 * @return Index_Entry|null
	 */
	private function entry(): ?Index_Entry {

		// Reuse the lookup.
		if ( $this->looked_up ) {
			return $this->entry;
		}
		$this->looked_up = true;

		// The request URI is root-relative, so it normalises like a link.
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$href = $uri === '' ? null : $this->normaliser->normalise( $uri );
		$this->entry = $href === null ? null : $this->store->index()->lookup( $href );

		return $this->entry;

	}

}
