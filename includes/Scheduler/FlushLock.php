<?php
/**
 * Single-flight advisory lock for the queue flush.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps MySQL `GET_LOCK`/`RELEASE_LOCK`. On a busy site the Action Scheduler
 * async (loopback) runner and the WP-Cron runner can both fire the flush
 * action at once; without a lock they race on the same queue rows and spray
 * "action ignored" / "unable to mark this action" noise. A non-blocking
 * advisory lock lets exactly one runner proceed and the rest bail instantly.
 *
 * The lock is auto-released when the DB connection closes, so a fatal mid-run
 * can never leave it stuck.
 */
final class FlushLock {

	/** Non-blocking: return immediately if another runner holds the lock. */
	private const TIMEOUT = 0;

	public function acquire(): bool {
		global $wpdb;

		// GET_LOCK returns 1 (acquired), 0 (timeout) or NULL (error).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock, not a table read.
		return 1 === (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->name(), self::TIMEOUT )
		);
	}

	public function release(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock, not a table write.
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name() ) );
	}

	/**
	 * GET_LOCK names live in one MySQL-server-wide namespace, so include the
	 * table prefix: sibling installs on a shared server must not serialise on
	 * each other's flush.
	 */
	private function name(): string {
		global $wpdb;

		return $wpdb->prefix . 'hellolog_flush';
	}
}
