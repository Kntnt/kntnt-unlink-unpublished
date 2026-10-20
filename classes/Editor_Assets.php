<?php
/**
 * Puts the markers and the drop-marker controls into the editor.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

/**
 * Enqueues the editor assets (spec §4.3 and §9).
 *
 * The marker stylesheet is added inline through `enqueue_block_assets`,
 * limited to the editor, so it reaches the iframed canvas of the block editor
 * and the site editor. The editor script registers the inline drop-marker
 * format and the block-inspector toggle.
 *
 * @since 0.1.0
 */
final class Editor_Assets {

	/**
	 * The handle of the stylesheet and the script.
	 *
	 * @since 0.1.0
	 */
	private const string HANDLE = 'kntnt-unlink-unpublished-editor';

	/**
	 * Creates the asset loader.
	 *
	 * @since 0.1.0
	 *
	 * @param Index_Store       $store       Serves the index.
	 * @param Editor_Stylesheet $stylesheet  Generates the markers.
	 * @param string            $plugin_file The main plugin file.
	 */
	public function __construct(
		private readonly Index_Store $store,
		private readonly Editor_Stylesheet $stylesheet,
		private readonly string $plugin_file,
	) {}

	/**
	 * Registers the hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'enqueue_block_assets', [ $this, 'enqueue_canvas_styles' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_script' ] );
	}

	/**
	 * Adds the marker stylesheet to the editor canvas.
	 *
	 * `enqueue_block_assets` also fires on the front end, which never gets it.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function enqueue_canvas_styles(): void {
		if ( is_admin() ) {
			wp_register_style( self::HANDLE, false, [], Plugin::get_version() );
			wp_enqueue_style( self::HANDLE );
			wp_add_inline_style( self::HANDLE, $this->stylesheet->css( $this->store->index() ) );
		}
	}

	/**
	 * Adds the script with the drop-marker format and toggle.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function enqueue_editor_script(): void {
		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'js/editor.js', $this->plugin_file ),
			[ 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-compose', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-rich-text' ],
			Plugin::get_version(),
			true,
		);
		wp_set_script_translations( self::HANDLE, 'kntnt-unlink-unpublished', plugin_dir_path( $this->plugin_file ) . 'languages' );
	}

}
