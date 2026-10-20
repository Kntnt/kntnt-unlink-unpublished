<?php
/**
 * The outcome of link surgery on one fragment.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

/**
 * What link surgery did to a fragment.
 *
 * @since 0.1.0
 */
final readonly class Surgery_Result {

	/**
	 * Creates the result.
	 *
	 * @since 0.1.0
	 *
	 * @param string $html     The fragment after surgery; byte-identical to the
	 *                         input when nothing changed.
	 * @param int    $not_live How many links that aren't live the fragment held.
	 * @param bool   $changed  Whether any byte changed.
	 */
	public function __construct(
		public string $html,
		public int $not_live,
		public bool $changed,
	) {}

}
