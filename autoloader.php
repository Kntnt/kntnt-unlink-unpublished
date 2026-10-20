<?php
/**
 * PSR-4 autoloader for the plugin namespace.
 *
 * Maps `Kntnt\Unlink_Unpublished\Class_Name` to `classes/Class_Name.php`. The
 * plugin has no runtime dependencies, so the release ships without Composer's
 * vendor directory.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Prevent direct file access outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( string $class_name ): void {

		// Only the plugin's own namespace.
		$prefix = 'Kntnt\\Unlink_Unpublished\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		// Map the rest of the name 1:1 to a file under classes/.
		$file = __DIR__ . '/classes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}

	},
);
