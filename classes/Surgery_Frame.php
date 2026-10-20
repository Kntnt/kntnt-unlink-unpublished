<?php
/**
 * An element link surgery may cut, while the walk is inside it.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

/**
 * One open element on the surgery's stack.
 *
 * The flags record what the element contains, learnt token by token, so the
 * element can be settled when its closer is reached.
 *
 * @since 0.1.0
 */
final class Surgery_Frame {

	/**
	 * Whether the element holds visible content: text or a visible element.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $visible = false;

	/**
	 * Whether the element holds text outside `svg` elements.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $text = false;

	/**
	 * Whether the element holds embedded media worth unlinking.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $media = false;

	/**
	 * Whether something inside a container was dropped.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $had_drop = false;

	/**
	 * Whether a marked element holds a link that isn't live.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $tainted = false;

	/**
	 * Creates the frame.
	 *
	 * @since 0.1.0
	 *
	 * @param Frame_Kind $kind       What the element stands for.
	 * @param string     $tag        The element's tag name, upper case.
	 * @param int        $depth      The element's depth, itself included.
	 * @param int        $start      Byte offset of its opener.
	 * @param int        $length     Byte length of its opener.
	 * @param bool       $force_drop Whether a link is dropped whatever it holds
	 *                               – a button.
	 */
	public function __construct(
		public readonly Frame_Kind $kind,
		public readonly string $tag,
		public readonly int $depth,
		public readonly int $start,
		public readonly int $length,
		public readonly bool $force_drop = false,
	) {}

	/**
	 * Takes on the content flags of a kept child element.
	 *
	 * @since 0.1.0
	 *
	 * @param Surgery_Frame $child The child.
	 * @return void
	 */
	public function absorb( Surgery_Frame $child ): void {
		$this->visible = $this->visible || $child->visible;
		$this->text = $this->text || $child->text;
		$this->media = $this->media || $child->media;
	}

}
