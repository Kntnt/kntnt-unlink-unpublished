<?php
/**
 * The daily housekeeping.
 *
 * @package Kntnt\Unlink_Unpublished
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Unlink_Unpublished;

/**
 * Prunes tombstones and re-checks redirecting pages once a day (spec §10).
 *
 * @since 0.1.0
 */
final class Housekeeping {

	/**
	 * The daily cron event.
	 *
	 * @since 0.1.0
	 */
	public const string HOOK = 'kntnt_unlink_unpublished_housekeeping';

	/**
	 * Creates the housekeeping.
	 *
	 * @since 0.1.0
	 *
	 * @param Tombstones     $tombstones Prunes tombstones.
	 * @param Source_Finder  $finder     Finds what still links to a tombstone.
	 * @param Redirect_Check $redirects  Re-checks the recorded redirects.
	 */
	public function __construct(
		private readonly Tombstones $tombstones,
		private readonly Source_Finder $finder,
		private readonly Redirect_Check $redirects,
	) {}

	/**
	 * Registers the hooks and keeps the daily event scheduled.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'init', [ $this, 'schedule' ] );
	}

	/**
	 * Schedules the daily event unless it is scheduled.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) === false ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Does the daily work.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function run(): void {
		$this->tombstones->prune( $this->finder );
		$this->redirects->recheck();
	}

	/**
	 * Removes every cron event the plugin schedules.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_unschedule_hook( Redirect_Check::HOOK );
	}

}
