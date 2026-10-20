<?php
/**
 * Checks whether a newly published page's address still redirects.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

use WP_Post;

/**
 * Detects redirect rules left over from when a page was unpublished (spec §10.2).
 *
 * When a target becomes published, a one-off cron event requests its
 * permalink with `HEAD` from the site itself, without following redirects. A
 * 3xx is recorded for Site Health; a 2xx clears the record; anything else,
 * including a failed loopback, is recorded as "could not check" rather than as
 * a failure. Recorded pages are checked again by the housekeeping event and
 * when Site Health is viewed.
 *
 * @since 0.1.0
 */
final class Redirect_Check {

	/**
	 * The option holding the records, by post ID.
	 *
	 * @since 0.1.0
	 */
	public const string OPTION = 'kntnt_unlink_unpublished_redirect_checks';

	/**
	 * The one-off cron event that checks a page.
	 *
	 * @since 0.1.0
	 */
	public const string HOOK = 'kntnt_unlink_unpublished_check_redirect';

	/**
	 * Registers the hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_post_status', [ $this, 'schedule' ], 10, 3 );
		add_action( self::HOOK, [ $this, 'check' ] );
	}

	/**
	 * Schedules a check of a target that becomes published.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $new_status The new status.
	 * @param string  $old_status The old status.
	 * @param WP_Post $post       The post.
	 * @return void
	 */
	public function schedule( string $new_status, string $old_status, WP_Post $post ): void {
		if ( Targets::is_published( $new_status ) && ! Targets::is_published( $old_status ) && Targets::is_target( $post ) ) {
			wp_schedule_single_event( time(), self::HOOK, [ $post->ID ] );
		}
	}

	/**
	 * Checks a page and updates its record.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function check( int $post_id ): void {

		// A page that is no longer published has nothing to check.
		$records = self::records();
		$post = get_post( $post_id );
		$url = $post instanceof WP_Post && Targets::is_published( $post->post_status ) ? get_permalink( $post ) : false;
		if ( ! is_string( $url ) ) {
			unset( $records[ $post_id ] );
			update_option( self::OPTION, $records, false );
			return;
		}

		// Ask the site itself, without following redirects.
		$response = wp_remote_head(
			$url,
			[
				'redirection' => 0,
				'timeout' => 10,
				'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ),
			]
		);
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		// Record a redirect or a failed check; a success clears the record.
		if ( $code >= 300 && $code < 400 ) {
			$location = is_wp_error( $response ) ? '' : wp_remote_retrieve_header( $response, 'location' );
			$records[ $post_id ] = [
				'status' => 'redirect',
				'code' => $code,
				'location' => is_string( $location ) ? $location : '',
				'checked' => time(),
			];
		} elseif ( $code >= 200 && $code < 300 ) {
			unset( $records[ $post_id ] );
		} else {
			$records[ $post_id ] = [
				'status' => 'unchecked',
				'code' => $code,
				'location' => '',
				'checked' => time(),
			];
		}
		update_option( self::OPTION, $records, false );

	}

	/**
	 * Checks every recorded page again.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function recheck(): void {
		foreach ( array_keys( self::records() ) as $post_id ) {
			$this->check( $post_id );
		}
	}

	/**
	 * Returns the records, by post ID.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array{status: string, code: int, location: string, checked: int}>
	 */
	public static function records(): array {

		// Keep only well-formed records.
		$stored = get_option( self::OPTION, [] );
		$records = [];
		foreach ( is_array( $stored ) ? $stored : [] as $id => $record ) {
			$status = is_array( $record ) ? ( $record['status'] ?? null ) : null;
			if ( is_int( $id ) && is_array( $record ) && ( $status === 'redirect' || $status === 'unchecked' ) ) {
				$records[ $id ] = [
					'status' => $status,
					'code' => is_int( $record['code'] ?? null ) ? $record['code'] : 0,
					'location' => is_string( $record['location'] ?? null ) ? $record['location'] : '',
					'checked' => is_int( $record['checked'] ?? null ) ? $record['checked'] : 0,
				];
			}
		}

		return $records;

	}

}
