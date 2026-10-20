<?php
/**
 * Reduces hrefs to the keys the index is built from.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

/**
 * Normalises same-site hrefs (spec §4.1).
 *
 * Absolute URLs on the home host with `http` or `https`, protocol-relative
 * URLs and root-relative paths are same-site; the `www.` and bare host count
 * as the same host, and a port is part of the host. The filter
 * `kntnt_unlink_unpublished_hosts` adds hosts that serve the same site, such
 * as a multilingual plugin's language domains. Document-relative hrefs
 * and every other scheme are ignored, because the block editor never writes
 * them. A path key is the percent-decoded, lower-cased path without a trailing
 * slash, so a link written with raw characters, upper-case escapes or no
 * trailing slash matches the stored permalink.
 *
 * @since 0.1.0
 */
final class Href_Normaliser {

	/**
	 * The authorities (host and optional port) that count as same-site, once
	 * the hosts filter has been applied.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]|null
	 */
	private ?array $authorities = null;

	/**
	 * The authority of the home URL.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $home_authority;

	/**
	 * The path key of the home URL, where ID forms live.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private readonly string $home_path;

	/**
	 * Creates the normaliser for a site.
	 *
	 * The hosts filter is applied on first use rather than here, because the
	 * plugin is constructed before multilingual plugins have set up their
	 * languages.
	 *
	 * @since 0.1.0
	 *
	 * @param string $home_url The site's home URL.
	 */
	public function __construct( string $home_url ) {
		$this->home_authority = self::authority_of( $home_url ) ?? '';
		$path = wp_parse_url( $home_url, PHP_URL_PATH );
		$this->home_path = self::path_key( is_string( $path ) ? $path : '' );
	}

	/**
	 * Returns the authorities that count as same-site.
	 *
	 * The home authority and the hosts the filter
	 * `kntnt_unlink_unpublished_hosts` adds, each with its `www.` twin.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Lower-case hosts with an optional port.
	 */
	public function authorities(): array {

		// Apply the filter once per request.
		if ( $this->authorities !== null ) {
			return $this->authorities;
		}
		$hosts = apply_filters( 'kntnt_unlink_unpublished_hosts', [ $this->home_authority, self::twin_of( $this->home_authority ) ] );

		// Every host comes with its www. twin, the home host first.
		$authorities = [];
		foreach ( [ $this->home_authority, ...( is_array( $hosts ) ? $hosts : [] ) ] as $host ) {
			if ( is_string( $host ) && $host !== '' ) {
				$host = strtolower( $host );
				array_push( $authorities, $host, self::twin_of( $host ) );
			}
		}
		$this->authorities = array_values( array_unique( $authorities ) );

		return $this->authorities;

	}

	/**
	 * Normalises an href, or returns null when it is not a same-site link.
	 *
	 * @since 0.1.0
	 *
	 * @param string $href The href as written, entities already decoded.
	 * @return Href|null
	 */
	public function normalise( string $href ): ?Href {

		// Accept absolute, protocol-relative and root-relative hrefs only.
		$href = trim( $href );
		if ( preg_match( '~^(?:https?:)?//~i', $href ) === 1 ) {
			if ( ! in_array( self::authority_of( $href ), $this->authorities(), true ) ) {
				return null;
			}
		} elseif ( ! str_starts_with( $href, '/' ) ) {
			return null;
		}

		// Split off the path and the query; the fragment never matters. Raw
		// non-ASCII bytes are escaped first, because parse_url() mangles them.
		$href = (string) preg_replace_callback( '/[^\x00-\x7F]+/', static fn( array $m ): string => rawurlencode( $m[0] ), $href );
		$path = wp_parse_url( $href, PHP_URL_PATH );
		$query = wp_parse_url( $href, PHP_URL_QUERY );
		$path = self::path_key( is_string( $path ) ? $path : '' );

		// An ID form at the home path matches its target directly.
		$id = null;
		$index_php = rtrim( $this->home_path, '/' ) . '/index.php';
		if ( is_string( $query ) && in_array( $path, [ $this->home_path, $index_php ], true ) ) {
			parse_str( $query, $parameters );
			$candidate = $parameters['page_id'] ?? $parameters['p'] ?? null;
			if ( is_string( $candidate ) && ctype_digit( $candidate ) && (int) $candidate > 0 ) {
				$id = (int) $candidate;
			}
		}

		return new Href( $path, $id );

	}

	/**
	 * Returns the path key of a URL path.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path A URL path, encoded or not.
	 * @return string The decoded, lower-cased path without a trailing slash;
	 *                `/` for the root.
	 */
	public static function path_key( string $path ): string {
		$key = rtrim( mb_strtolower( rawurldecode( $path ), 'UTF-8' ), '/' );
		return $key === '' ? '/' : ( str_starts_with( $key, '/' ) ? $key : '/' . $key );
	}

	/**
	 * Returns the `www.` twin of a host: the bare host for a `www.` host, and
	 * the other way round.
	 *
	 * @since 0.1.0
	 *
	 * @param string $host A lower-case host with an optional port.
	 * @return string
	 */
	private static function twin_of( string $host ): string {
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
	}

	/**
	 * Returns the lower-cased authority (host and optional port) of a URL.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url An absolute or protocol-relative URL.
	 * @return string|null
	 */
	private static function authority_of( string $url ): ?string {

		// Protocol-relative URLs parse once a scheme is in front of them.
		$parts = wp_parse_url( str_starts_with( $url, '//' ) ? 'http:' . $url : $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return null;
		}

		return strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

	}

}
