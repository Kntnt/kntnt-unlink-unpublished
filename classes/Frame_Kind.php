<?php
/**
 * The kinds of element link surgery can cut.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

/**
 * What a surgery frame stands for.
 *
 * @since 0.1.0
 */
enum Frame_Kind {

	/**
	 * A link that isn't live: unlinked or dropped.
	 */
	case Link;

	/**
	 * An element carrying the drop marker: dropped, unwrapped or kept.
	 */
	case Marker;

	/**
	 * A paragraph, list item or list: removed when a drop empties it.
	 */
	case Container;

}
