<?php
/**
 * Plugin Name:       Kntnt Unlink Unpublished
 * Plugin URI:        https://github.com/Kntnt/kntnt-unlink-unpublished
 * Description:       Renders links to unpublished pages as plain text at render time, and brings them back when the pages are published – without changing stored content.
 * Version:           0.2.0
 * Requires at least: 6.9
 * Requires PHP:      8.4
 * Author:            Kntnt
 * Author URI:        https://www.kntnt.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kntnt-unlink-unpublished
 * Domain Path:       /languages
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Prevent direct file access outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guards against running on a PHP version older than the 8.4 floor.
 *
 * The plugin header already makes WordPress refuse activation on older PHP.
 * This is a second line of defence for environments that load the plugin
 * outside the normal activation path: it shows an admin notice and
 * deactivates the plugin before it reaches code that would fail.
 *
 * @since 0.1.0
 *
 * @return bool True when PHP is 8.4 or newer; false when the guard fires.
 */
function kntnt_unlink_unpublished_requirements_check(): bool {

	// Nothing to do when the runtime meets the requirement.
	if ( version_compare( PHP_VERSION, '8.4', '>=' ) ) {
		return true;
	}

	// Surface the problem as an admin notice.
	add_action(
		'admin_notices',
		static function (): void {
			$message = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'Kntnt Unlink Unpublished requires PHP %1$s or later. This server runs PHP %2$s. The plugin has been deactivated.', 'kntnt-unlink-unpublished' ),
				'8.4',
				PHP_VERSION,
			);
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
		},
	);

	// Deactivate the plugin so WordPress does not try to load it again.
	add_action(
		'admin_init',
		static function (): void {
			deactivate_plugins( plugin_basename( __FILE__ ) );
		},
	);

	return false;

}

// Abort the rest of the bootstrap when the PHP version guard fires.
if ( ! kntnt_unlink_unpublished_requirements_check() ) {
	return;
}

// Load the PSR-4 autoloader.
require_once __DIR__ . '/autoloader.php';

/**
 * Returns the state of the current request, for themes (spec §6).
 *
 * `'unpublished'` on the 404 at the address of a page that has been public
 * and is unpublished now, `'deleted'` at the address of a deleted page, and
 * null everywhere else.
 *
 * @since 0.1.0
 *
 * @return string|null
 */
function kntnt_unlink_unpublished_request_state(): ?string {
	return \Kntnt\Unlink_Unpublished\Plugin::get_instance()->request_state();
}

// Run the activation script on activation and the cleanup on deactivation.
// uninstall.php is invoked automatically by WordPress on delete.
register_activation_hook(
	__FILE__,
	static function (): void {
		require __DIR__ . '/install.php';
	},
);
register_deactivation_hook( __FILE__, [ \Kntnt\Unlink_Unpublished\Plugin::class, 'deactivate' ] );

// Bootstrap the plugin inside a safety net: a fatal error during
// initialisation is logged and shown as an admin notice instead of taking the
// site down.
try {
	\Kntnt\Unlink_Unpublished\Plugin::get_instance( __FILE__ );
} catch ( \Throwable $e ) {
	error_log(
		sprintf(
			'Kntnt Unlink Unpublished: fatal error during initialization – %s in %s on line %d',
			$e->getMessage(),
			$e->getFile(),
			$e->getLine(),
		),
	);
	add_action(
		'admin_notices',
		static function () use ( $e ): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: error message from the caught exception. */
						__( 'Kntnt Unlink Unpublished failed to initialize: %s', 'kntnt-unlink-unpublished' ),
						$e->getMessage(),
					),
				),
			);
		},
	);
}
