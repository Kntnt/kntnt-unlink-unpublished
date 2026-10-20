<?php
/**
 * The surgery on links that aren't live, shared by both surgeons.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_HTML_Processor;
use WP_HTML_Text_Replacement;

/**
 * Unlinks and drops elements of a fragment in a single pass (spec §4.2, §4.3).
 *
 * The walk keeps a stack of frames for the elements that may be cut: links
 * that aren't live, elements carrying the drop marker, and the paragraphs,
 * list items and lists a drop may leave empty. A frame learns what it
 * contains while the walk is inside it and is settled when its closer is
 * reached. Every cut is spliced into the processor's lexical updates, so only
 * the bytes of the elements unlinked or dropped change; everything else keeps
 * its bytes. A range dropped whole replaces the cuts inside it.
 *
 * The using class supplies the depth of the current token and the byte span of
 * a token that exists in the source text, which is where the tree surgeon and
 * the lexical surgeon differ.
 *
 * @since 0.1.0
 */
trait Link_Surgery {

	/**
	 * The class of an element carrying the drop marker.
	 *
	 * @since 0.1.0
	 */
	public const string DROP_MARKER = 'kntnt-unlink-unpublished-drop';

	/**
	 * The class of a navigation label in the block navigation.
	 *
	 * @since 0.1.0
	 */
	private const string NAVIGATION_LABEL = 'wp-block-navigation-item__content';

	/**
	 * Classes that make a link a button.
	 *
	 * @since 0.1.0
	 */
	private const array BUTTON_CLASSES = [ 'wp-block-button__link', 'wp-element-button' ];

	/**
	 * Embedded media that keeps a link's content worth unlinking.
	 *
	 * @since 0.1.0
	 */
	private const array LINK_MEDIA = [ 'IMG', 'PICTURE', 'VIDEO', 'FIGURE' ];

	/**
	 * Elements that are visible content of a paragraph or list item.
	 *
	 * @since 0.1.0
	 */
	private const array VISIBLE = [ 'IMG', 'PICTURE', 'VIDEO', 'AUDIO', 'FIGURE', 'SVG', 'IFRAME', 'CANVAS', 'EMBED', 'OBJECT', 'INPUT', 'SELECT', 'TEXTAREA', 'BUTTON' ];

	/**
	 * Containers removed when a drop leaves them without visible content.
	 *
	 * @since 0.1.0
	 */
	private const array CONTAINERS = [ 'P', 'LI', 'UL', 'OL' ];

	/**
	 * Returns the depth of the current token, after it has been entered.
	 *
	 * On an opener the depth includes the opened element; on a closer it no
	 * longer does.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	abstract protected function surgery_depth(): int;

	/**
	 * Returns the byte span of the current token, or null for a token that does
	 * not exist in the source text.
	 *
	 * @since 0.1.0
	 *
	 * @return array{int, int}|null Start and length.
	 */
	abstract protected function surgery_span(): ?array;

	/**
	 * Tells whether the current token lies inside an `svg` element.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	abstract protected function surgery_in_svg(): bool;

	/**
	 * Tells whether the walk stopped because the fragment could not be parsed.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	abstract protected function surgery_failed(): bool;

	/**
	 * Operates on the fragment.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(string): ?bool $verdict       Tells whether an href is live
	 *                                              (true), not live (false) or
	 *                                              no target (null).
	 * @param bool                    $submenu_label Whether the first navigation
	 *                                              label is a submenu label, which
	 *                                              becomes a placeholder link.
	 * @return Surgery_Result|null Null when the fragment could not be parsed.
	 */
	public function operate( callable $verdict, bool $submenu_label = false ): ?Surgery_Result {

		// The open frames, and the cuts: start, length and whether the cut
		// drops a whole range.
		$frames = [];
		$cuts = [];
		$not_live = 0;
		$placeholder = false;
		$label_pending = $submenu_label;

		while ( $this->next_token() ) {

			// Text is visible content of the innermost frame; text inside an
			// svg makes no link worth keeping.
			$type = $this->get_token_type();
			$top = $frames === [] ? null : $frames[ array_key_last( $frames ) ];
			if ( $type === '#text' ) {
				if ( $top !== null && trim( $this->get_modifiable_text() ) !== '' ) {
					$top->visible = true;
					$top->text = $top->text || ! $this->surgery_in_svg();
				}
				continue;
			}
			if ( $type !== '#tag' ) {
				continue;
			}
			$tag = (string) $this->get_tag();

			// A closer settles the innermost frames that belong to the element it
			// closes – an <a> can be both a marked element and a link.
			if ( $this->is_tag_closer() ) {
				$depth = $this->surgery_depth();
				$closer = null;
				while ( $frames !== [] && $frames[ array_key_last( $frames ) ]->tag === $tag && $frames[ array_key_last( $frames ) ]->depth - 1 === $depth ) {
					$closer ??= $this->surgery_span();
					$frame = array_pop( $frames );
					$this->settle( $frame, $closer, $frames, $cuts );
				}
				continue;
			}

			// Visible elements mark the innermost frame.
			$depth = $this->surgery_depth();
			if ( $top !== null && in_array( $tag, self::VISIBLE, true ) ) {
				$top->visible = true;
				$top->media = $top->media || in_array( $tag, self::LINK_MEDIA, true );
			}

			// Only links, marked elements and containers can be cut, and only
			// when their opener exists in the source text.
			$is_marker = $this->has_class( self::DROP_MARKER ) === true;
			if ( ( $tag !== 'A' && ! $is_marker && ! in_array( $tag, self::CONTAINERS, true ) ) || WP_HTML_Processor::is_void( $tag ) ) {
				continue;
			}
			$span = $this->surgery_span();
			if ( $span === null ) {
				continue;
			}

			// Open a frame for a marked element or a container.
			if ( $is_marker ) {
				$frames[] = new Surgery_Frame( Frame_Kind::Marker, $tag, $depth, $span[0], $span[1] );
			} elseif ( $tag !== 'A' ) {
				$frames[] = new Surgery_Frame( Frame_Kind::Container, $tag, $depth, $span[0], $span[1] );
			}

			// Look up a link and leave every link but those that aren't live.
			if ( $tag !== 'A' ) {
				continue;
			}
			$href = $this->get_attribute( 'href' );
			$is_label = $label_pending && $this->has_class( self::NAVIGATION_LABEL ) === true;
			$label_pending = $label_pending && ! $is_label;
			if ( ! is_string( $href ) || $verdict( $href ) !== false ) {
				continue;
			}

			// A link that isn't live taints every marked element around it.
			++$not_live;
			foreach ( $frames as $open ) {
				$open->tainted = $open->tainted || $open->kind === Frame_Kind::Marker;
			}

			// A submenu label stays an <a> without a target; any other link
			// gets a frame that decides between unlinking and dropping.
			if ( $is_label ) {
				$this->remove_attribute( 'href' );
				$this->remove_attribute( 'target' );
				$this->remove_attribute( 'rel' );
				$placeholder = true;
				continue;
			}
			$is_button = array_any( self::BUTTON_CLASSES, fn( string $name ): bool => $this->has_class( $name ) === true );
			$frames[] = new Surgery_Frame( Frame_Kind::Link, $tag, $depth, $span[0], $span[1], $is_button );

		}

		// Leave a fragment that cannot be parsed to the fallback.
		if ( $this->surgery_failed() ) {
			return null;
		}

		// Nothing to cut leaves the fragment exactly as it came in.
		if ( $cuts === [] && ! $placeholder ) {
			return new Surgery_Result( $this->get_updated_html(), $not_live, false );
		}

		// Attribute removals inside a dropped range go with it.
		foreach ( $this->lexical_updates as $key => $update ) {
			if ( array_any( $cuts, static fn( array $cut ): bool => $cut[2] && $update->start >= $cut[0] && $update->start < $cut[0] + $cut[1] ) ) {
				unset( $this->lexical_updates[ $key ] );
			}
		}
		foreach ( $cuts as [ $start, $length ] ) {
			$this->lexical_updates[] = new WP_HTML_Text_Replacement( $start, $length, '' );
		}

		return new Surgery_Result( $this->get_updated_html(), $not_live, true );

	}

	/**
	 * Settles a closed frame: cuts it as needed and tells its parent frames.
	 *
	 * @since 0.1.0
	 *
	 * @param Surgery_Frame               $frame  The frame that closed.
	 * @param array{int, int}|null        $closer The span of its closer, or
	 *                                            null when it has none in the
	 *                                            source text.
	 * @param Surgery_Frame[]             $frames The frames still open.
	 * @param list<array{int, int, bool}> $cuts   The cuts so far.
	 * @return void
	 */
	private function settle( Surgery_Frame $frame, ?array $closer, array $frames, array &$cuts ): void {

		// An element without a closer in the source text cannot be cut.
		$parent = $frames === [] ? null : $frames[ array_key_last( $frames ) ];
		if ( $closer === null ) {
			$parent?->absorb( $frame );
			return;
		}

		// A dropped element replaces every cut inside it and tells the nearest
		// container that something was dropped from it.
		$drop = match ( $frame->kind ) {
			Frame_Kind::Link => $frame->force_drop || ( ! $frame->text && ! $frame->media ),
			Frame_Kind::Marker => $frame->tainted,
			Frame_Kind::Container => $frame->had_drop && ! $frame->visible,
		};
		if ( $drop ) {
			$end = $closer[0] + $closer[1];
			$cuts = array_values( array_filter( $cuts, static fn( array $cut ): bool => $cut[0] < $frame->start || $cut[0] >= $end ) );
			$cuts[] = [ $frame->start, $end - $frame->start, true ];
			foreach ( array_reverse( $frames ) as $open ) {
				if ( $open->kind === Frame_Kind::Container ) {
					$open->had_drop = true;
					break;
				}
			}
			return;
		}

		// An unlinked link and an unwrapped marked span lose their opener and
		// closer and keep their content.
		if ( $frame->kind === Frame_Kind::Link || ( $frame->kind === Frame_Kind::Marker && $frame->tag === 'SPAN' ) ) {
			$cuts[] = [ $frame->start, $frame->length, false ];
			$cuts[] = [ $closer[0], $closer[1], false ];
		}

		// A kept element's content is content of its parent frame.
		$parent?->absorb( $frame );

	}

}
