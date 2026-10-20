<?php
/**
 * Plugin activation script.
 *
 * Runs on activation via register_activation_hook(), in the loaded plugin
 * context. It seeds the last public URL of targets that were unpublished
 * before the plugin was installed (docs/spec.md §5.2). The daily housekeeping
 * event schedules itself on `init`.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Prevent direct file access outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

\Kntnt\Unlink_Unpublished\Plugin::get_instance()->activate();
