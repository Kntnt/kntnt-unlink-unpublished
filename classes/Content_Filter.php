<?php
/**
 * Applies the visibility rule wherever WordPress renders content.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_Block;
use WP_Block_List;
use WP_Post;

/**
 * Hooks the rendering paths and rewrites the links that aren't live (spec §4).
 *
 * Blocks are handled in `render_block`; classic content, excerpts and widgets
 * in their own filters at a late priority; classic menus in
 * `wp_nav_menu_objects`. A fragment is parsed only when it holds a drop marker
 * or an href that isn't live – any other fragment costs a string search and is
 * returned as it came in.
 *
 * Blocks render from the inside out, so by the time a marked block reaches
 * `render_block`, its inner blocks have already lost their links. The filter
 * therefore keeps a stack of the blocks being rendered, opened in
 * `pre_render_block`, and counts every link that isn't live as it goes: a
 * marked block is dropped when the count grew while it rendered (spec §4.3).
 * Blocks that core renders without `pre_render_block`, such as the items of a
 * navigation block, get no frame and are never mistaken for the block on top.
 *
 * @since 0.1.0
 */
final class Content_Filter {

	/**
	 * The blocks being rendered, innermost last.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, array{name: string, seen: int, child_dropped: bool}>
	 */
	private array $frames = [];

	/**
	 * How many links that aren't live have been met in this request.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $not_live_seen = 0;

	/**
	 * Creates the filter.
	 *
	 * @since 0.1.0
	 *
	 * @param Index_Store     $store      Serves the index.
	 * @param Liveness        $liveness   Decides whether a link is live.
	 * @param Href_Normaliser $normaliser Normalises hrefs.
	 * @param Link_Rewriter   $rewriter   Does the surgery.
	 */
	public function __construct(
		private readonly Index_Store $store,
		private readonly Liveness $liveness,
		private readonly Href_Normaliser $normaliser,
		private readonly Link_Rewriter $rewriter,
	) {}

	/**
	 * Registers the hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {

		// Blocks, with the stack of blocks being rendered around them.
		add_filter( 'pre_render_block', [ $this, 'open_block' ], PHP_INT_MAX, 2 );
		add_filter( 'render_block', [ $this, 'render_block' ], 999, 3 );

		// Classic content, excerpts and widgets.
		foreach ( [ 'the_content', 'the_excerpt', 'widget_text', 'widget_block_content' ] as $hook ) {
			add_filter( $hook, [ $this, 'filter_html' ], 999 );
		}

		// Navigation, where core decides first (spec §4.6).
		add_filter( 'render_block_core_navigation_link_allowed_post_status', [ $this, 'allow_navigation_status' ], 10, 2 );
		add_filter( 'block_core_navigation_render_inner_blocks', [ $this, 'filter_navigation_items' ] );
		add_filter( 'wp_nav_menu_objects', [ $this, 'filter_menu_items' ], 999 );

	}

	/**
	 * Opens a frame for a block about to be rendered.
	 *
	 * Runs last on `pre_render_block`, so a block another filter renders in
	 * advance gets no frame.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed        $pre          A pre-rendered block, or null.
	 * @param array<mixed> $parsed_block The block about to be rendered.
	 * @return mixed The pre-rendered block, untouched.
	 */
	public function open_block( mixed $pre, array $parsed_block ): mixed {
		if ( $pre === null ) {
			$this->frames[] = [
				'name' => self::block_name( $parsed_block ),
				'seen' => $this->not_live_seen,
				'child_dropped' => false,
			];
		}
		return $pre;
	}

	/**
	 * Rewrites a rendered block and closes its frame.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed         $content      The rendered block.
	 * @param array<mixed>  $parsed_block The block.
	 * @param WP_Block|null $instance     The block instance.
	 * @return string
	 */
	public function render_block( mixed $content, array $parsed_block, ?WP_Block $instance = null ): string {

		// Close the frame this block opened, if it opened one.
		$content = is_string( $content ) ? $content : '';
		$name = self::block_name( $parsed_block );
		$frame = null;
		if ( $this->frames !== [] && $this->frames[ array_key_last( $this->frames ) ]['name'] === $name ) {
			$frame = array_pop( $this->frames );
		}

		// Tell the parent when a block that had content is dropped; a block's
		// HTML often keeps the line breaks around a dropped element.
		$result = $this->rewrite_block( $content, $name, $parsed_block, $instance, $frame );
		if ( trim( $result ) === '' && trim( $content ) !== '' && $this->frames !== [] ) {
			$this->frames[ array_key_last( $this->frames ) ]['child_dropped'] = true;
		}

		return $result;

	}

	/**
	 * Rewrites HTML outside blocks: classic content, excerpts and widgets.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $html The HTML.
	 * @return mixed
	 */
	public function filter_html( mixed $html ): mixed {
		return is_string( $html ) ? $this->process( $html )->html : $html;
	}

	/**
	 * Lets core render a navigation link to a target that is live although it
	 * isn't published, such as a private page for a user who can read it.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $statuses   The statuses core renders.
	 * @param mixed $attributes The navigation link's attributes.
	 * @return mixed
	 */
	public function allow_navigation_status( mixed $statuses, mixed $attributes ): mixed {

		// Add the target's status when the target is live for this visitor.
		$id = is_array( $attributes ) ? ( $attributes['id'] ?? null ) : null;
		$post = is_numeric( $id ) ? get_post( (int) $id ) : null;
		if ( is_array( $statuses ) && $post instanceof WP_Post && ! in_array( $post->post_status, $statuses, true ) && $this->liveness->is_live_post( $post ) ) {
			$statuses[] = $post->post_status;
		}

		return $statuses;

	}

	/**
	 * Removes the top-level navigation links that won't render before the
	 * navigation block renders them.
	 *
	 * The navigation block closes its list at every item that renders as
	 * nothing and opens a new one at the next, so an item dropped in the middle
	 * would split the navigation in two lists. Links whose target isn't live
	 * are therefore taken out of the list beforehand – those core would refuse
	 * as well as those the plugin would drop. Submenus stay; they keep their
	 * children.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $inner_blocks The navigation's inner blocks.
	 * @return mixed
	 */
	public function filter_navigation_items( mixed $inner_blocks ): mixed {

		// Nothing to do without a block list or an index.
		$index = $this->store->index();
		if ( ! $inner_blocks instanceof WP_Block_List || $index->is_empty() ) {
			return $inner_blocks;
		}

		// Find the links that aren't live, then take them out.
		$dropped = [];
		foreach ( $inner_blocks as $key => $block ) {
			if ( ! $block instanceof WP_Block || $block->name !== 'core/navigation-link' ) {
				continue;
			}
			$attributes = is_array( $block->parsed_block['attrs'] ?? null ) ? $block->parsed_block['attrs'] : [];
			$post = is_numeric( $attributes['id'] ?? null ) ? get_post( (int) $attributes['id'] ) : null;
			$url = is_string( $attributes['url'] ?? null ) ? $attributes['url'] : '';
			$refused = $post instanceof WP_Post && ! Targets::is_published( $post->post_status ) && ! $this->liveness->is_live_post( $post );
			if ( $refused || ( $url !== '' && $this->verdict( $url, $index ) === false ) ) {
				$dropped[] = $key;
			}
		}
		foreach ( $dropped as $key ) {
			unset( $inner_blocks[ $key ] );
			++$this->not_live_seen;
		}

		return $inner_blocks;

	}

	/**
	 * Drops classic menu items whose link isn't live; a parent item keeps its
	 * children and becomes a placeholder link.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $items The menu items.
	 * @return mixed
	 */
	public function filter_menu_items( mixed $items ): mixed {

		// Nothing to do without items or an index.
		$index = $this->store->index();
		if ( ! is_array( $items ) || $index->is_empty() ) {
			return $items;
		}

		// Find the items that have children.
		$parents = [];
		foreach ( $items as $item ) {
			$parent = is_object( $item ) && isset( $item->menu_item_parent ) ? $item->menu_item_parent : 0;
			if ( is_numeric( $parent ) && (int) $parent > 0 ) {
				$parents[ (int) $parent ] = true;
			}
		}

		// Keep live items, empty the link of a parent and drop the rest. The
		// menu walker leaves out an empty href, which makes a placeholder link.
		$kept = [];
		foreach ( $items as $key => $item ) {
			$url = is_object( $item ) && isset( $item->url ) && is_string( $item->url ) ? $item->url : '';
			if ( $url === '' || $this->verdict( $url, $index ) !== false ) {
				$kept[ $key ] = $item;
				continue;
			}
			++$this->not_live_seen;
			$item_id = isset( $item->ID ) && is_numeric( $item->ID ) ? (int) $item->ID : 0;
			if ( isset( $parents[ $item_id ] ) ) {
				if ( property_exists( $item, 'target' ) ) {
					$item->target = '';
				}
				if ( property_exists( $item, 'xfn' ) ) {
					$item->xfn = '';
				}
				$item->url = '';
				$kept[ $key ] = $item;
			}
		}

		return $kept;

	}

	/**
	 * Applies the block-level rules to a rendered block.
	 *
	 * @since 0.1.0
	 *
	 * @param string                                                   $content      The rendered block.
	 * @param string                                                   $name         The block name.
	 * @param array<mixed>                                             $parsed_block The block.
	 * @param WP_Block|null                                            $instance     The block instance.
	 * @param array{name: string, seen: int, child_dropped: bool}|null $frame   The block's frame.
	 * @return string
	 */
	private function rewrite_block( string $content, string $name, array $parsed_block, ?WP_Block $instance, ?array $frame ): string {

		// Core renders nothing for a submenu whose post isn't published; render
		// it again without the post ID, so its children stay.
		if ( $name === 'core/navigation-submenu' && $content === '' && $instance !== null ) {
			return $this->render_submenu_again( $instance );
		}

		// A navigation item or a button that is nothing but a link that isn't
		// live is dropped whole; any other block has its links rewritten.
		$result = $this->process( $content, $name === 'core/navigation-submenu' );
		if ( $result->not_live > 0 && in_array( $name, [ 'core/navigation-link', 'core/button' ], true ) ) {
			return '';
		}

		// A buttons block or a list emptied by drops goes too.
		$html = $result->html;
		if ( $frame !== null && $frame['child_dropped'] ) {
			if ( $name === 'core/buttons' && preg_match( '/<(?:a|button)\b/i', $html ) !== 1 ) {
				return '';
			}
			if ( $name === 'core/list' && preg_match( '/<li\b/i', $html ) !== 1 ) {
				return '';
			}
		}

		// A marked block is dropped when a link that isn't live rendered inside it.
		if ( $frame !== null && $this->not_live_seen > $frame['seen'] && $this->is_marked( $parsed_block ) ) {
			return '';
		}

		return $html;

	}

	/**
	 * Renders a submenu again without the post ID core refused it for.
	 *
	 * The instance is cloned so its block context comes along.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Block $instance The submenu core rendered as nothing.
	 * @return string
	 */
	private function render_submenu_again( WP_Block $instance ): string {

		// Only a submenu that links to a post that isn't published is refused
		// for its post ID; anything else stays as core rendered it.
		$attributes = $instance->parsed_block['attrs'] ?? null;
		$attributes = is_array( $attributes ) ? $attributes : [];
		$id = $attributes['id'] ?? null;
		if ( ! is_numeric( $id ) || Targets::is_published( (string) get_post_status( (int) $id ) ) ) {
			return '';
		}

		// Render a copy without the ID; its label is then handled as a link.
		// The attributes are computed lazily, so the stale ones must go too.
		$copy = clone $instance;
		$copy->parsed_block['attrs'] = array_diff_key( $attributes, [ 'id' => true ] );
		unset( $copy->attributes );
		$copy->refresh_parsed_block_dependents();

		return $copy->render();

	}

	/**
	 * Rewrites a fragment if it can hold anything to rewrite.
	 *
	 * @since 0.1.0
	 *
	 * @param string $html          The fragment.
	 * @param bool   $submenu_label Whether its first navigation label is a
	 *                              submenu label.
	 * @return Surgery_Result
	 */
	private function process( string $html, bool $submenu_label = false ): Surgery_Result {

		// The fast path: no drop marker and no href that isn't live.
		$index = $this->store->index();
		if ( $html === '' || ( ! str_contains( $html, Tree_Surgeon::DROP_MARKER ) && ! $this->has_link_not_live( $html, $index ) ) ) {
			return new Surgery_Result( $html, 0, false );
		}

		// Do the surgery and count what isn't live.
		$result = $this->rewriter->rewrite( $html, fn( string $href ): ?bool => $this->verdict( $href, $index ), $submenu_label );
		$this->not_live_seen += $result->not_live;

		return $result;

	}

	/**
	 * Tells whether a fragment holds an href that isn't live.
	 *
	 * @since 0.1.0
	 *
	 * @param string $html  The fragment.
	 * @param Index  $index The index.
	 * @return bool
	 */
	private function has_link_not_live( string $html, Index $index ): bool {

		// Most fragments have no link at all.
		if ( $index->is_empty() || ! str_contains( $html, 'href' ) ) {
			return false;
		}

		// Read each href value, quoted or not, and decode its entities.
		preg_match_all( '/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/i', $html, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$href = html_entity_decode( ( $match[1] ?? '' ) . ( $match[2] ?? '' ) . ( $match[3] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $this->verdict( $href, $index ) === false ) {
				return true;
			}
		}

		return false;

	}

	/**
	 * Tells whether an href is live (true), not live (false) or no target (null).
	 *
	 * @since 0.1.0
	 *
	 * @param string $href  The href, entities decoded.
	 * @param Index  $index The index.
	 * @return bool|null
	 */
	private function verdict( string $href, Index $index ): ?bool {
		$normalised = $this->normaliser->normalise( $href );
		$entry = $normalised === null ? null : $index->lookup( $normalised );
		return $entry === null ? null : $this->liveness->is_live( $entry );
	}

	/**
	 * Tells whether a block carries the drop marker in its class name.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $parsed_block The block.
	 * @return bool
	 */
	private function is_marked( array $parsed_block ): bool {
		$attributes = $parsed_block['attrs'] ?? null;
		$class_name = is_array( $attributes ) ? ( $attributes['className'] ?? '' ) : '';
		$classes = is_string( $class_name ) ? preg_split( '/\s+/', $class_name ) : false;
		return is_array( $classes ) && in_array( Tree_Surgeon::DROP_MARKER, $classes, true );
	}

	/**
	 * Returns the name of a parsed block.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $parsed_block The block.
	 * @return string The name, or '' for freeform content.
	 */
	private static function block_name( array $parsed_block ): string {
		$name = $parsed_block['blockName'] ?? '';
		return is_string( $name ) ? $name : '';
	}

}
