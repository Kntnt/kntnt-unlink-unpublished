<?php
/**
 * The block that tells visitors a page is unavailable.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

/**
 * Registers `kntnt-unlink-unpublished/notice` (spec §6).
 *
 * A container whose inner blocks render only when the request state matches
 * its `when` attribute. Placed in a block theme's 404 template, it lets a site
 * say "This page is temporarily unavailable" without code.
 *
 * @since 0.1.0
 */
final class Notice_Block {

	/**
	 * The block's name, as in its block.json.
	 *
	 * @since 0.1.0
	 */
	private const string NAME = 'kntnt-unlink-unpublished/notice';

	/**
	 * Creates the block registrar.
	 *
	 * @since 0.1.0
	 *
	 * @param Request_Handler $handler   Knows the request state.
	 * @param string          $block_dir The directory holding block.json.
	 */
	public function __construct(
		private readonly Request_Handler $handler,
		private readonly string $block_dir,
	) {}

	/**
	 * Registers the hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_block' ] );
	}

	/**
	 * Registers the block type from its block.json.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_block(): void {

		// WordPress looks for a block's script translations among the language
		// packs only; the plugin ships its own.
		if ( register_block_type( $this->block_dir, [ 'render_callback' => [ $this, 'render' ] ] ) !== false ) {
			wp_set_script_translations( generate_block_asset_handle( self::NAME, 'editorScript' ), 'kntnt-unlink-unpublished', dirname( $this->block_dir, 2 ) . '/languages' );
		}

	}

	/**
	 * Renders the inner blocks when the request state matches `when`.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $attributes The block attributes.
	 * @param string       $content    The rendered inner blocks.
	 * @return string
	 */
	public function render( array $attributes, string $content ): string {
		$state = $this->handler->request_state();
		$when = $attributes['when'] ?? 'both';
		return $state !== null && ( $when === 'both' || $when === $state ) ? $content : '';
	}

}
