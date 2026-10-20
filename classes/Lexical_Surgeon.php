<?php
/**
 * Link surgery on a fragment read token by token.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_HTML_Processor;
use WP_HTML_Tag_Processor;

/**
 * The fallback surgeon built on WordPress's Tag Processor (docs/adr/0006).
 *
 * Used when the HTML Processor cannot parse a fragment. It keeps its own stack
 * of open elements and matches a closer to the nearest open element of the
 * same name, as the Interactivity API's directives processor does, so it
 * relies on explicit end tags – which block markup and filtered classic
 * content have.
 *
 * @since 0.1.0
 */
final class Lexical_Surgeon extends WP_HTML_Tag_Processor {

	use Link_Surgery;

	/**
	 * Name of the short-lived bookmark that reads a token's span.
	 *
	 * @since 0.1.0
	 */
	private const string BOOKMARK = 'kntnt_unlink_unpublished';

	/**
	 * Elements whose content the Tag Processor reads as part of the opener, so
	 * their closer is never visited.
	 *
	 * @since 0.1.0
	 */
	private const array ATOMIC = [ 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE', 'IFRAME', 'NOEMBED', 'NOFRAMES', 'XMP' ];

	/**
	 * The names of the open elements, outermost first.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private array $open = [];

	/**
	 * The token the open-element stack was last updated for.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $tracked = -1;

	/**
	 * Counts the tokens visited, so the stack is updated once per token.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $visited = 0;

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function next_token(): bool {
		++$this->visited;
		return parent::next_token();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Updates the open-element stack for the current tag on first call.
	 *
	 * @since 0.1.0
	 */
	protected function surgery_depth(): int {

		// Each token updates the stack once, however often its depth is read.
		if ( $this->tracked === $this->visited || $this->get_token_type() !== '#tag' ) {
			return count( $this->open );
		}
		$this->tracked = $this->visited;
		$tag = (string) $this->get_tag();

		// A closer closes the nearest open element of its name, and every
		// element opened after it.
		if ( $this->is_tag_closer() ) {
			$index = array_search( $tag, array_reverse( $this->open, true ), true );
			if ( is_int( $index ) ) {
				$this->open = array_slice( $this->open, 0, $index );
			}
			return count( $this->open );
		}

		// An opener opens an element unless it is void, atomic or self-closing
		// foreign content.
		$self_closing = $this->has_self_closing_flag() && ( $tag === 'SVG' || in_array( 'SVG', $this->open, true ) );
		if ( ! WP_HTML_Processor::is_void( $tag ) && ! in_array( $tag, self::ATOMIC, true ) && ! $self_closing ) {
			$this->open[] = $tag;
		}

		return count( $this->open );

	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected function surgery_span(): ?array {

		// Read the span through a bookmark released at once.
		if ( ! $this->set_bookmark( self::BOOKMARK ) ) {
			return null;
		}
		$bookmark = $this->bookmarks[ self::BOOKMARK ];
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
		return in_array( 'SVG', $this->open, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected function surgery_failed(): bool {
		return false;
	}

}
