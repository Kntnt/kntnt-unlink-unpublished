<?php
/**
 * Link surgery on a fragment parsed as a tree.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_HTML_Processor;
use WP_HTML_Tag_Processor;

/**
 * The surgeon built on WordPress's HTML Processor (docs/adr/0006).
 *
 * The HTML Processor parses a fragment the way a browser does, so depths
 * follow implied end tags and the tree builder's rules. Tokens it adds that do
 * not exist in the source text – implied closers, reconstructed formatting
 * elements – have no span and are never cut. Create it with
 * `Tree_Surgeon::create_fragment()`, which returns null for input the
 * processor refuses outright.
 *
 * @since 0.1.0
 */
final class Tree_Surgeon extends WP_HTML_Processor {

	use Link_Surgery;

	/**
	 * Name of the short-lived bookmark that reads a token's span.
	 *
	 * @since 0.1.0
	 */
	private const string BOOKMARK = 'kntnt_unlink_unpublished';

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected function surgery_depth(): int {
		return $this->get_current_depth();
	}

	/**
	 * {@inheritDoc}
	 *
	 * A token is virtual when the lexical token underneath differs from it;
	 * comparing them avoids the notice set_bookmark() raises on a virtual token.
	 *
	 * @since 0.1.0
	 */
	protected function surgery_span(): ?array {

		// Refuse tokens that do not exist in the source text.
		$is_real = WP_HTML_Tag_Processor::get_token_type() === $this->get_token_type()
			&& WP_HTML_Tag_Processor::get_tag() === $this->get_tag()
			&& WP_HTML_Tag_Processor::is_tag_closer() === $this->is_tag_closer();
		if ( ! $is_real || ! $this->set_bookmark( self::BOOKMARK ) ) {
			return null;
		}

		// The HTML Processor stores its bookmarks with a leading underscore.
		$bookmark = $this->bookmarks[ '_' . self::BOOKMARK ];
		$span = [ $bookmark->start, $bookmark->length ];
		$this->release_bookmark( self::BOOKMARK );

		return $span;

	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected function surgery_in_svg(): bool {
		return in_array( 'SVG', $this->get_breadcrumbs(), true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected function surgery_failed(): bool {
		return $this->get_last_error() !== null || $this->paused_at_incomplete_token();
	}

}
