<?php
/**
 * A same-site href reduced to what the index can match.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

/**
 * The normalised form of a same-site href.
 *
 * The path key is what a would-be permalink, a last public URL or a tombstone
 * URL is matched against; the post ID is set only for an ID form such as
 * `?page_id=12`, which matches its target directly.
 *
 * @since 0.1.0
 */
final readonly class Href {

	/**
	 * Creates the normalised href.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $path The path key (see Href_Normaliser::path_key()).
	 * @param int|null $id   The post ID of an ID form, or null.
	 */
	public function __construct(
		public string $path,
		public ?int $id = null,
	) {}

}
