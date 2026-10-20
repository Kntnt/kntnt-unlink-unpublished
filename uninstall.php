<?php
/**
 * Plugin uninstall script.
 *
 * Removes everything the plugin persists when it is deleted through the
 * WordPress admin (docs/spec.md §14): the tombstones, the redirect-check
 * records, the index version and its cached copies, the last public URLs in
 * post meta, the update-check cache and the cron events. The stored content
 * of posts was never changed, so there is nothing to restore. WordPress runs
 * this file without loading the plugin, so it loads the autoloader itself to
 * reuse the plugin's own keys.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Unlink_Unpublished\Housekeeping;
use Kntnt\Unlink_Unpublished\Index_Store;
use Kntnt\Unlink_Unpublished\Last_Public_Url;
use Kntnt\Unlink_Unpublished\Redirect_Check;
use Kntnt\Unlink_Unpublished\Tombstones;

// Bail unless WordPress is performing a genuine uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/autoloader.php';

// Options, transients and post meta.
delete_option( Tombstones::OPTION );
delete_option( Redirect_Check::OPTION );
delete_option( Index_Store::VERSION_OPTION );
delete_transient( Index_Store::TRANSIENT );
delete_site_transient( 'kntnt_unlink_unpublished_update_check' );
delete_post_meta_by_key( Last_Public_Url::META );

// Cached copies of the index in a persistent object cache.
if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
	wp_cache_flush_group( 'kntnt_unlink_unpublished' );
}

// Cron events.
Housekeeping::unschedule();
