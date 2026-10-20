<?php
/**
 * Finds the sources that link to a target.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_Post;

/**
 * Searches stored content for links to a set of URLs and a post ID (spec §7).
 *
 * The database search is coarse – a substring of the path or the ID form – and
 * every hit is confirmed by normalising the hrefs and block URLs it holds, so a
 * partial path or another site's URL is never reported as a source.
 *
 * @since 0.1.0
 */
final class Source_Finder {

	/**
	 * The widget options whose content can hold links.
	 *
	 * @since 0.1.0
	 */
	private const array WIDGET_OPTIONS = [ 'widget_block', 'widget_text', 'widget_custom_html' ];

	/**
	 * Creates the finder.
	 *
	 * @since 0.1.0
	 *
	 * @param Href_Normaliser $normaliser Normalises the hrefs found.
	 */
	public function __construct( private readonly Href_Normaliser $normaliser ) {}

	/**
	 * Finds what links to any of the URLs or to the post ID.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $urls The target's URLs.
	 * @param int|null $id   The target's post ID, for the ID forms.
	 * @return Sources
	 */
	public function find( array $urls, ?int $id ): Sources {

		// Collect the path keys to confirm and the substrings to search for.
		$keys = [];
		$needles = [];
		foreach ( $urls as $url ) {
			$href = $this->normaliser->normalise( $url );
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( $href !== null && $href->path !== '/' ) {
				$keys[ $href->path ] = true;
				$needles[] = $href->path;
				$needles[] = rtrim( is_string( $path ) ? $path : '', '/' );
			}
		}
		if ( $id !== null ) {
			array_push( $needles, 'page_id=' . $id, 'p=' . $id, '"id":' . $id );
		}
		$needles = array_values( array_unique( array_filter( $needles ) ) );
		if ( $needles === [] ) {
			return new Sources( [], false, false );
		}

		// Search post content of every status but trash, then confirm each hit.
		$wpdb = Database::get();
		$likes = implode( ' OR ', array_map( static fn( string $needle ): string => (string) $wpdb->prepare( 'post_content LIKE %s', '%' . $wpdb->esc_like( $needle ) . '%' ), $needles ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every condition is prepared above.
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status NOT IN ('trash', 'auto-draft', 'inherit') AND post_type NOT IN ('revision', 'nav_menu_item') AND ( {$likes} )" );
		$posts = [];
		foreach ( array_filter( $ids, 'is_numeric' ) as $source_id ) {
			$post = get_post( (int) $source_id );
			if ( $post instanceof WP_Post && $this->links_to( $post->post_content, $keys, $id ) ) {
				$posts[] = $post;
			}
		}

		// Classic menu items link by object ID or by a typed URL.
		$in_menus = false;
		$menu_urls = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT meta_value FROM %i WHERE ( meta_key = %s AND meta_value = %s ) OR ( meta_key = %s AND meta_value <> %s )',
				$wpdb->postmeta,
				'_menu_item_object_id',
				(string) ( $id ?? 0 ),
				'_menu_item_url',
				'',
			),
		);
		foreach ( array_filter( $menu_urls, 'is_string' ) as $value ) {
			if ( ( $id !== null && $value === (string) $id ) || $this->links_to( 'href="' . $value . '"', $keys, $id ) ) {
				$in_menus = true;
				break;
			}
		}

		// Widgets keep their content in options.
		$in_widgets = array_any(
			self::WIDGET_OPTIONS,
			fn( string $option ): bool => $this->links_to( (string) wp_json_encode( get_option( $option, [] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $keys, $id ),
		);

		return new Sources( $posts, $in_menus, $in_widgets );

	}

	/**
	 * Tells whether content holds a link to one of the path keys or the ID.
	 *
	 * Reads hrefs, block `url` attributes (navigation links, buttons) and the
	 * post IDs of block navigation items.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $content The stored content.
	 * @param array<string, true> $keys  The path keys.
	 * @param int|null            $id      The post ID.
	 * @return bool
	 */
	private function links_to( string $content, array $keys, ?int $id ): bool {

		// Normalise every href and block URL in the content.
		preg_match_all( '/(?:\bhref\s*=\s*|"url"\s*:\s*)(["\'])(.*?)\1/i', $content, $matches );
		foreach ( $matches[2] as $raw ) {
			$decoded = html_entity_decode( str_replace( [ '&', '\/' ], [ '&', '/' ], $raw ), ENT_QUOTES | ENT_HTML5 );
			$href = $this->normaliser->normalise( $decoded );
			if ( $href !== null && ( ( $href->id === null && isset( $keys[ $href->path ] ) ) || ( $id !== null && $href->id === $id ) ) ) {
				return true;
			}
		}

		// Block navigation items reference their post by ID.
		return $id !== null && preg_match( '/<!-- wp:navigation-(?:link|submenu) \{[^}]*"id":' . $id . '\b/', $content ) === 1;

	}

}
