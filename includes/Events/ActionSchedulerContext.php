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
 *
 * The announcements carry the job's action id, and an end only counts for a
 * job that began and has not ended yet: when marking a job complete fails,
 * the queue runner announces a failure after the success, and that second
 * end must not close the job around it.
 */
final class ActionSchedulerContext {

	/** Number of jobs currently executing in this request. Never negative. */
	private static int $depth = 0;

	/**
	 * Action id => how many times that job began and has not ended yet.
	 *
	 * @var array<string, int>
	 */
	private static array $open = [];

	/**
	 * Hook the three queue-runner actions. Cheap and safe to call whether or
	 * not Action Scheduler is installed — the hooks simply never fire.
	 *
	 * The job's own `begin_execute` callbacks already run inside the job, so
	 * the context opens before all of them and closes after everything else.
	 */
	public static function register(): void {
		add_action( 'action_scheduler_begin_execute', [ self::class, 'begin' ], PHP_INT_MIN, 1 );
		add_action( 'action_scheduler_after_execute', [ self::class, 'end' ], PHP_INT_MAX, 1 );
		add_action( 'action_scheduler_failed_execution', [ self::class, 'end' ], PHP_INT_MAX, 1 );
	}

	/**
	 * A job started executing.
	 *
	 * @param mixed $action_id Id of the job's action; without one the job counts but cannot be told apart.
	 */
	public static function begin( $action_id = null ): void {
		++self::$depth;

		$id = self::id( $action_id );
		if ( null !== $id ) {
			self::$open[ $id ] = ( self::$open[ $id ] ?? 0 ) + 1;
		}
	}

	/**
	 * A job finished, successfully or not. Unmatched calls are ignored: an
	 * end without a running job, and an end for a job id that already ended
	 * or never began.
	 *
	 * @param mixed $action_id Id of the job's action, as given to {@see self::begin()}.
	 */
	public static function end( $action_id = null ): void {
		$id = self::id( $action_id );
		if ( null !== $id ) {
			if ( empty( self::$open[ $id ] ) ) {
				return;
			}
			--self::$open[ $id ];
			if ( 0 === self::$open[ $id ] ) {
				unset( self::$open[ $id ] );
			}
		}

		if ( self::$depth > 0 ) {
			--self::$depth;
		}
	}

	public static function is_running(): bool {
		return self::$depth > 0;
	}

	/**
	 * How many jobs are executing right now, one inside the other; 0 outside
	 * any job. Something noted at a depth of at least the current one, while
	 * a job ends, was done by that job.
	 */
	public static function depth(): int {
		return self::$depth;
	}

	/**
	 * An action id as text; null when there is none.
	 *
	 * @param mixed $action_id What the hook passed.
	 */
	private static function id( $action_id ): ?string {
		if ( is_int( $action_id ) ) {
			return (string) $action_id;
		}

		return is_string( $action_id ) && '' !== $action_id ? $action_id : null;
	}
}
