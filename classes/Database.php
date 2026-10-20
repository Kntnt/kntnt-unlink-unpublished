<?php
/**
 * Access to the WordPress database object.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use LogicException;
use wpdb;

/**
 * Returns the global `$wpdb` with its type.
 *
 * @since 0.1.0
 */
final class Database {

	/**
	 * Returns the database object.
	 *
	 * @since 0.1.0
	 *
	 * @throws LogicException When WordPress has not set up its database.
	 *
	 * @return wpdb
	 */
	public static function get(): wpdb {
		$database = $GLOBALS['wpdb'] ?? null;
		if ( ! $database instanceof wpdb ) {
			throw new LogicException( 'The WordPress database is unavailable.' );
		}
		return $database;
	}

	/**
	 * Returns values as a prepared, comma-separated list of SQL strings.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $values The values; at least one.
	 * @return string
	 */
	public static function list_of( array $values ): string {
		$database = self::get();
		return implode( ',', array_map( static fn( string $value ): string => (string) $database->prepare( '%s', $value ), $values ) );
	}

}
