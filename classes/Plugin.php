<?php
/**
 * Plugin singleton – bootstrap and metadata access.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use LogicException;

/**
 * Singleton entry point for Kntnt Unlink Unpublished.
 *
 * Constructed once by get_instance(); the constructor instantiates every
 * component in dependency order and registers its WordPress hooks, so it is
 * the single place to trace the hook graph.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * The sole instance of this class.
	 *
	 * @since 0.1.0
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private static string $plugin_file = '';

	/**
	 * Cached plugin header.
	 *
	 * @since 0.1.0
	 *
	 * @var array<mixed>|null
	 */
	private static ?array $plugin_data = null;

	/**
	 * Answers requests for URLs in the index and knows the request state.
	 *
	 * @since 0.1.0
	 *
	 * @var Request_Handler
	 */
	private readonly Request_Handler $requests;

	/**
	 * Records last public URLs; seeds them on activation.
	 *
	 * @since 0.1.0
	 *
	 * @var Last_Public_Url
	 */
	private readonly Last_Public_Url $last_public;

	/**
	 * Returns (and on the first call, creates) the singleton instance.
	 *
	 * @since 0.1.0
	 *
	 * @param string $plugin_file Absolute path to the main plugin file; ignored
	 *                            on calls after the first.
	 * @return self
	 */
	public static function get_instance( string $plugin_file = '' ): self {

		// Return early when already bootstrapped.
		if ( self::$instance !== null ) {
			return self::$instance;
		}

		self::$plugin_file = $plugin_file;
		self::$instance = new self();

		return self::$instance;

	}

	/**
	 * Returns the absolute path to the main plugin file.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function get_plugin_file(): string {
		return self::$plugin_file;
	}

	/**
	 * Returns the parsed plugin header, cached after the first call.
	 *
	 * @since 0.1.0
	 *
	 * @return array<mixed>
	 */
	public static function get_plugin_data(): array {

		// Return the cached result to avoid repeated file reads.
		if ( self::$plugin_data !== null ) {
			return self::$plugin_data;
		}

		// Prefer the WordPress function when it is loaded; fall back to
		// get_file_data() outside admin. Translation is off, so no textdomain
		// is loaded before `init`.
		if ( function_exists( 'get_plugin_data' ) ) {
			self::$plugin_data = get_plugin_data( self::$plugin_file, false, false );
		} else {
			self::$plugin_data = get_file_data(
				self::$plugin_file,
				[
					'Name' => 'Plugin Name',
					'PluginURI' => 'Plugin URI',
					'Version' => 'Version',
					'RequiresWP' => 'Requires at least',
					'RequiresPHP' => 'Requires PHP',
				]
			);
		}

		return self::$plugin_data;

	}

	/**
	 * Returns the plugin version from the plugin header.
	 *
	 * @since 0.1.0
	 *
	 * @return string The version, or '' when the header is unreadable.
	 */
	public static function get_version(): string {
		$data = self::get_plugin_data();
		return isset( $data['Version'] ) && is_string( $data['Version'] ) ? $data['Version'] : '';
	}

	/**
	 * Returns the state of the current request.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null 'unpublished', 'deleted' or null.
	 */
	public function request_state(): ?string {
		return $this->requests->request_state();
	}

	/**
	 * Runs on activation: seeds the last public URLs of targets unpublished
	 * before the plugin was installed (spec §5.2).
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function activate(): void {
		$this->last_public->seed();
	}

	/**
	 * Runs on deactivation: removes the plugin's cron events.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Housekeeping::unschedule();
	}

	/**
	 * Wires the components and registers their hooks.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {

		// Self-updates from the project's GitHub releases.
		add_filter( 'pre_set_site_transient_update_plugins', [ new Updater(), 'check_for_updates' ] );

		// Translations ship in the plugin's own languages directory.
		add_action(
			'init',
			static function (): void {
				load_plugin_textdomain( 'kntnt-unlink-unpublished', false, dirname( plugin_basename( self::$plugin_file ) ) . '/languages' );
			},
		);

		// The index of targets that aren't published, and what feeds it. The
		// site root is read unfiltered: multilingual plugins such as Bogo filter
		// home_url() with the visitor's language.
		$home = Index_Builder::home();
		$normaliser = new Href_Normaliser( $home );
		$permalinks = new Permalinks();
		$store = new Index_Store( new Index_Builder( $permalinks, $normaliser ) );
		$store->register();
		( new Language_Plugins() )->register();
		$this->last_public = new Last_Public_Url( $store, $permalinks );
		$this->last_public->register();
		$tombstones = new Tombstones( $store );
		$tombstones->register();

		// Links in rendered output, requests for URLs in the index and redirects
		// to them. Vetoed redirects reach the debug log only when it is on.
		$liveness = new Liveness();
		( new Content_Filter( $store, $liveness, $normaliser, new Link_Rewriter() ) )->register();
		$this->requests = new Request_Handler( $store, $normaliser );
		$this->requests->register();
		( new Redirect_Guard( $store, $liveness, $normaliser, new Not_Found_Page(), logs_vetoes: WP_DEBUG && (bool) WP_DEBUG_LOG ) )->register();
		( new Notice_Block( $this->requests, dirname( self::$plugin_file ) . '/blocks/notice' ) )->register();

		// Announcements to caches.
		$finder = new Source_Finder( $normaliser );
		( new Purge_Announcer( $permalinks, $finder ) )->register();

		// Editors: markers, drop-marker controls, Site Health and housekeeping.
		( new Editor_Assets( $store, new Editor_Stylesheet( $normaliser ), self::$plugin_file ) )->register();
		$redirects = new Redirect_Check();
		$redirects->register();
		( new Site_Health( $store, $finder, $redirects, $normaliser ) )->register();
		( new Housekeeping( $tombstones, $finder, $redirects ) )->register();

	}

	/**
	 * Prevents cloning of the singleton.
	 *
	 * @since 0.1.0
	 *
	 * @throws LogicException Always.
	 *
	 * @return void
	 */
	public function __clone() {
		throw new LogicException( 'Cannot clone a singleton.' );
	}

	/**
	 * Prevents unserialisation of the singleton.
	 *
	 * @since 0.1.0
	 *
	 * @throws LogicException Always.
	 *
	 * @return void
	 */
	public function __wakeup() {
		throw new LogicException( 'Cannot unserialize a singleton.' );
	}

}
