<?php
/**
 * The index of URLs and post IDs whose targets aren't published.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

/**
 * Maps path keys and post IDs to the targets that aren't published (spec §5.1).
 *
 * An immutable value built by Index_Builder and cached by Index_Store in its
 * array form. A href absent from the index is either published, external or
 * no target at all, and is never touched.
 *
 * @since 0.1.0
 */
final readonly class Index {

	/**
	 * Creates the index.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array{int, string, bool}> $paths Path key => target
	 *                                                      ID, state value and
	 *                                                      was-public flag.
	 * @param array<int, array{string, bool}>         $ids   Post ID => state
	 *                                                      value and was-public
	 *                                                      flag.
	 */
	private function __construct(
		private array $paths,
		private array $ids,
	) {}

	/**
	 * Restores an index from its array form.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $data The array form; missing parts count as empty.
	 * @return self
	 */
	public static function from_array( array $data ): self {

		// Keep only well-formed entries; the cached form is ours, but a stale
		// cache entry of another shape must not break rendering.
		$paths = [];
		$stored_paths = $data['paths'] ?? [];
		foreach ( is_array( $stored_paths ) ? $stored_paths : [] as $path => $entry ) {
			if ( is_string( $path ) && is_array( $entry ) && is_int( $entry[0] ?? null ) && is_string( $entry[1] ?? null ) && Target_State::tryFrom( $entry[1] ) !== null && is_bool( $entry[2] ?? null ) ) {
				$paths[ $path ] = [ $entry[0], $entry[1], $entry[2] ];
			}
		}
		$ids = [];
		$stored_ids = $data['ids'] ?? [];
		foreach ( is_array( $stored_ids ) ? $stored_ids : [] as $id => $entry ) {
			if ( is_int( $id ) && is_array( $entry ) && is_string( $entry[0] ?? null ) && Target_State::tryFrom( $entry[0] ) !== null && is_bool( $entry[1] ?? null ) ) {
				$ids[ $id ] = [ $entry[0], $entry[1] ];
			}
		}

		return new self( $paths, $ids );

	}

	/**
	 * Returns the array form, for caching.
	 *
	 * @since 0.1.0
	 *
	 * @return array{paths: array<string, array{int, string, bool}>, ids: array<int, array{string, bool}>}
	 */
	public function to_array(): array {
		return [
			'paths' => $this->paths,
			'ids' => $this->ids,
		];
	}

	/**
	 * Tells whether the index holds nothing, so no link can be touched.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return $this->paths === [] && $this->ids === [];
	}

	/**
	 * Looks up a normalised href.
	 *
	 * An ID form matches by its post ID only; any other href by its path key.
	 *
	 * @since 0.1.0
	 *
	 * @param Href $href The normalised href.
	 * @return Index_Entry|null
	 */
	public function lookup( Href $href ): ?Index_Entry {

		// An ID form names its target directly.
		if ( $href->id !== null ) {
			$entry = $this->ids[ $href->id ] ?? null;
			return $entry === null ? null : new Index_Entry( $href->id, Target_State::from( $entry[0] ), $entry[1] );
		}

		$entry = $this->paths[ $href->path ] ?? null;
		return $entry === null ? null : new Index_Entry( $entry[0], Target_State::from( $entry[1] ), $entry[2] );

	}

	/**
	 * Returns the path keys of the targets in a state.
	 *
	 * @since 0.1.0
	 *
	 * @param Target_State $state The state.
	 * @return string[]
	 */
	public function paths( Target_State $state ): array {
		return array_keys( array_filter( $this->paths, static fn( array $entry ): bool => $entry[1] === $state->value ) );
	}

	/**
	 * Returns the post IDs of the targets in a state.
	 *
	 * @since 0.1.0
	 *
	 * @param Target_State $state The state.
	 * @return int[]
	 */
	public function ids( Target_State $state ): array {
		return array_keys( array_filter( $this->ids, static fn( array $entry ): bool => $entry[0] === $state->value ) );
	}

}
