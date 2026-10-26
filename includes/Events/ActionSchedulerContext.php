<?php
/**
 * Tracks whether an Action Scheduler job is executing right now.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler runs its jobs from WP-Cron, from an async loopback request
 * or from WP-CLI, so none of the usual request flags say "this is a queued
 * job". The queue runner does announce each job though: we count the
 * begin/end announcements and let {@see RequestSource} ask whether a job is
 * in flight.
 *
 * A counter rather than a boolean keeps nested runs honest — the outer job is
 * still running when an inner one finishes.
 */
final class ActionSchedulerContext {

	/** Number of jobs currently executing in this request. Never negative. */
	private static int $depth = 0;

	/**
	 * Hook the three queue-runner actions. Cheap and safe to call whether or
	 * not Action Scheduler is installed — the hooks simply never fire.
	 */
	public static function register(): void {
		add_action( 'action_scheduler_begin_execute', [ self::class, 'begin' ], 0, 0 );
		add_action( 'action_scheduler_after_execute', [ self::class, 'end' ], PHP_INT_MAX, 0 );
		add_action( 'action_scheduler_failed_execution', [ self::class, 'end' ], PHP_INT_MAX, 0 );
	}

	/** A job started executing. */
	public static function begin(): void {
		++self::$depth;
	}

	/** A job finished, successfully or not. Unmatched calls are ignored. */
	public static function end(): void {
		if ( self::$depth > 0 ) {
			--self::$depth;
		}
	}

	public static function is_running(): bool {
		return self::$depth > 0;
	}
}
