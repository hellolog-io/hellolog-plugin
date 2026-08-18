<?php
/**
 * Pure decision logic for the flush-queue Action Scheduler lifecycle.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * No WP/Action Scheduler dependencies — just the rules the bridge applies.
 *
 * The 0.3.1 outage came from a recurring action that kept ticking on an
 * unconfigured install. The single invariant that prevents it: when the
 * plugin is not active there must be NO schedule. {@see self::reconcile()}
 * encodes that, and {@see self::clamp_interval()} keeps the cadence off the
 * sub-minute floor that fed the async-runner flood.
 */
final class FlushSchedule {

	/**
	 * Lower bound for the drain cadence, in seconds. An audit-log drain has
	 * no business running more often than once a minute.
	 */
	public const MIN_INTERVAL = 60;

	/**
	 * Resolve the desired schedule transition from the current state.
	 *
	 * @param bool $is_active    Plugin has a valid, verified token.
	 * @param bool $is_scheduled A recurring flush action already exists.
	 *
	 * @return string One of `schedule`, `unschedule`, `noop`.
	 */
	public static function reconcile( bool $is_active, bool $is_scheduled ): string {
		if ( $is_active ) {
			return $is_scheduled ? 'noop' : 'schedule';
		}
		return $is_scheduled ? 'unschedule' : 'noop';
	}

	/**
	 * Clamp a (possibly filtered) interval to the {@see self::MIN_INTERVAL} floor.
	 */
	public static function clamp_interval( int $seconds ): int {
		return max( self::MIN_INTERVAL, $seconds );
	}
}
