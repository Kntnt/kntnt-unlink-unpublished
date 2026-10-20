<?php
/**
 * Rewrites the links of a fragment that aren't live.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

/**
 * Runs link surgery on a fragment, with the tree surgeon first and the lexical
 * surgeon as fallback (docs/adr/0006).
 *
 * @since 0.1.0
 */
final class Link_Rewriter {

	/**
	 * Unlinks and drops what isn't live in a fragment.
	 *
	 * @since 0.1.0
	 *
	 * @param string                  $html          The fragment.
	 * @param callable(string): ?bool $verdict       Tells whether an href is live
	 *                                              (true), not live (false) or
	 *                                              no target (null).
	 * @param bool                    $submenu_label Whether the fragment's first
	 *                                              navigation label is a submenu
	 *                                              label.
	 * @return Surgery_Result
	 */
	public function rewrite( string $html, callable $verdict, bool $submenu_label = false ): Surgery_Result {
		return Tree_Surgeon::create_fragment( $html )?->operate( $verdict, $submenu_label )
			?? ( new Lexical_Surgeon( $html ) )->operate( $verdict, $submenu_label )
			?? new Surgery_Result( $html, 0, false );
	}

}
