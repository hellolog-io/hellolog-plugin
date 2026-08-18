<?php
/**
 * Pure decision logic for the daily token-recheck Action Scheduler lifecycle.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * No WP/Action Scheduler dependencies — just the rules the bridge applies.
 *
 * A verified token can go bad with no local trigger at all (the operator's
 * subscription lapses, the backend suspends the key, ...). Nothing in the
 * plugin would notice until the next queue flush started failing — which
 * silently drops events rather than surfacing the problem. This recurring
 * check closes that gap.
 *
 * {@see self::reconcile()} deliberately keys the schedule on "a token is
 * configured" rather than "the token is active/verified" — the same class of
 * invariant {@see FlushSchedule::reconcile()} encodes for the flush job, but
 * with the opposite failure mode in mind: if scheduling required
 * `is_active()`, the very first failed check would clear the verified flag
 * and immediately unschedule the job that could notice the token working
 * again, permanently stranding the site. {@see self::decide()} is the
 * response-to-flag mapping the bridge applies after each check.
 */
final class VerifySchedule {

	/**
	 * Resolve the desired schedule transition from the current state.
	 *
	 * @param bool $is_configured A token with a valid shape is stored.
	 * @param bool $is_scheduled  A recurring verify action already exists.
	 *
	 * @return string One of `schedule`, `unschedule`, `noop`.
	 */
	public static function reconcile( bool $is_configured, bool $is_scheduled ): string {
		if ( $is_configured ) {
			return $is_scheduled ? 'noop' : 'schedule';
		}
		return $is_scheduled ? 'unschedule' : 'noop';
	}

	/**
	 * Map a `GET /verify` outcome to the action the bridge takes against
	 * `Options::KEY_TOKEN_VERIFIED`.
	 *
	 * @param int $status HTTP status code, or `0` for a transport-level
	 *                     failure (`WP_Error`: timeout, DNS, refused, ...).
	 *
	 * @return string One of `set`, `clear`, `unchanged`.
	 */
	public static function decide( int $status ): string {
		if ( 200 === $status ) {
			return 'set';
		}
		if ( 401 === $status || 403 === $status ) {
			return 'clear';
		}
		// WP_Error (0), 5xx, 429, or anything else ambiguous: leave the flag
		// as-is rather than flipping sensors off on a transient blip.
		return 'unchanged';
	}
}
