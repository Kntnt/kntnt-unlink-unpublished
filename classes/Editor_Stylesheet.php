<?php
/**
 * The stylesheet that marks links and drop markers in the editor.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

/**
 * Generates the editor markers from the index (spec §9).
 *
 * CSS only: attribute selectors per URL variant mark links to unpublished,
 * private and deleted targets, each state with its own underline and badge,
 * and the drop markers get an outline. Nothing touches the editor's DOM or
 * rich-text values. Absolute URLs are matched by their ending after the
 * scheme, so `https:`, `http:` and protocol-relative hrefs share selectors;
 * the `i` flag makes every match case-insensitive.
 *
 * @since 0.1.0
 */
final readonly class Editor_Stylesheet {

	/**
	 * The scope of every rule: the editor canvas.
	 *
	 * @since 0.1.0
	 */
	private const string SCOPE = '.editor-styles-wrapper';

	/**
	 * Creates the stylesheet generator.
	 *
	 * @since 0.1.0
	 *
	 * @param Href_Normaliser $normaliser Knows the hosts that are same-site.
	 */
	public function __construct( private Href_Normaliser $normaliser ) {}

	/**
	 * Returns the stylesheet.
	 *
	 * @since 0.1.0
	 *
	 * @param Index $index The index.
	 * @return string
	 */
	public function css( Index $index ): string {

		// One underline and badge per state, for every link to a target in it.
		$rules = [];
		$hidden = [];
		foreach ( $this->states() as [ $state, $label, $underline, $badge ] ) {
			$selectors = $this->selectors( $index->paths( $state ), $index->ids( $state ) );
			if ( $selectors === [] ) {
				continue;
			}
			$hidden = [ ...$hidden, ...$selectors ];
			$list = implode( ',', $selectors );
			$rules[] = self::SCOPE . " :is({$list}){text-decoration:underline {$underline} !important;text-decoration-thickness:2px !important;text-underline-offset:3px}";
			$rules[] = self::SCOPE . " :is({$list})::after{content:\"{$this->string( $label )}\";{$badge};display:inline-block;margin-inline-start:.35em;padding:0 .4em;border-radius:3px;font:600 10px/1.7 system-ui,sans-serif;letter-spacing:.02em;text-decoration:none;vertical-align:.4em;pointer-events:none}";
		}

		// The drop markers: a subtle outline on a marked span, an outline and a
		// corner badge on a marked block, which says so when it is hidden now.
		$marked_block = self::SCOPE . ' .' . Tree_Surgeon::DROP_MARKER . ':not(span)';
		$rules[] = self::SCOPE . ' span.' . Tree_Surgeon::DROP_MARKER . '{outline:1px dashed rgba(128,128,128,.7);outline-offset:2px;border-radius:2px}';
		$rules[] = "{$marked_block}{outline:1px dashed rgba(128,128,128,.8);outline-offset:4px}";
		$rules[] = "{$marked_block}::before{content:\"{$this->string( __( 'Hidden if target unpublished', 'kntnt-unlink-unpublished' ) )}\";position:absolute;top:0;right:0;z-index:1;transform:translateY(-100%);padding:0 .5em;border-radius:3px 3px 0 0;background:#e5e7eb;color:#1f2937;font:600 10px/1.8 system-ui,sans-serif;pointer-events:none}";
		if ( $hidden !== [] ) {
			$rules[] = "{$marked_block}:has(" . implode( ',', $hidden ) . '){outline-color:#b45309}';
			$rules[] = "{$marked_block}:has(" . implode( ',', $hidden ) . ")::before{content:\"{$this->string( __( 'Hidden now', 'kntnt-unlink-unpublished' ) )}\";background:#fde68a;color:#78350f}";
		}

		return implode( "\n", $rules );

	}

	/**
	 * Returns each state with its badge label, underline and badge colours.
	 *
	 * The badges carry their own background and text colour, so they stay
	 * legible in light and dark editor themes.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array{Target_State, string, string, string}>
	 */
	private function states(): array {
		return [
			[ Target_State::Unpublished, __( 'Unpublished', 'kntnt-unlink-unpublished' ), 'dashed #d97706', 'background:#fef3c7;color:#78350f' ],
			[ Target_State::Private, __( 'Private', 'kntnt-unlink-unpublished' ), 'dotted #7c3aed', 'background:#ede9fe;color:#4c1d95' ],
			[ Target_State::Deleted, __( 'Deleted', 'kntnt-unlink-unpublished' ), 'wavy #dc2626', 'background:#fee2e2;color:#7f1d1d' ],
		];
	}

	/**
	 * Returns the selectors that match links to the paths and IDs.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $paths Path keys.
	 * @param int[]    $ids   Post IDs.
	 * @return string[]
	 */
	private function selectors( array $paths, array $ids ): array {

		$selectors = [];

		// Each path, as typed and percent-encoded, with and without a trailing
		// slash, query or fragment – absolute on either host, or root-relative.
		foreach ( $paths as $key ) {
			$encoded = strtolower( implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) ) );
			foreach ( array_unique( [ $encoded, $key ] ) as $path ) {
				foreach ( $this->normaliser->authorities() as $host ) {
					$base = $this->attribute( "//{$host}{$path}" );
					array_push( $selectors, "a[href\$=\"{$base}\" i]", "a[href\$=\"{$base}/\" i]", "a[href*=\"{$base}?\" i]", "a[href*=\"{$base}/?\" i]", "a[href*=\"{$base}#\" i]", "a[href*=\"{$base}/#\" i]" );
				}
				$base = $this->attribute( $path );
				array_push( $selectors, "a[href=\"{$base}\" i]", "a[href=\"{$base}/\" i]", "a[href^=\"{$base}?\" i]", "a[href^=\"{$base}/?\" i]", "a[href^=\"{$base}#\" i]", "a[href^=\"{$base}/#\" i]" );
			}
		}

		// Each ID form, anchored so that 12 never matches 123.
		foreach ( $ids as $id ) {
			foreach ( [ 'page_id', 'p' ] as $parameter ) {
				foreach ( [ '?', '&' ] as $separator ) {
					$query = "{$separator}{$parameter}={$id}";
					array_push( $selectors, "a[href\$=\"{$query}\" i]", "a[href*=\"{$query}&\" i]", "a[href*=\"{$query}#\" i]" );
				}
			}
		}

		return $selectors;

	}

	/**
	 * Escapes a value for a double-quoted CSS attribute selector.
	 *
	 * Angle brackets are escaped too, so no value can close the inline style
	 * element the stylesheet is printed in.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The value.
	 * @return string
	 */
	private function attribute( string $value ): string {
		return addcslashes( $value, "\"\\\n\r<>" );
	}

	/**
	 * Escapes text for a double-quoted CSS string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The text.
	 * @return string
	 */
	private function string( string $text ): string {
		return addcslashes( $text, "\"\\\n\r<>" );
	}

}
