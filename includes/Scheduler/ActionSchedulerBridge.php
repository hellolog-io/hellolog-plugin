<?php
/**
 * Owns the lifecycle of the queue-flush Action Scheduler job.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Scheduler;

use HelloLog\Settings\Options;
use HelloLog\Transport\QueueFlusher;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler is shipped as a Composer dependency
 * ({@see https://actionscheduler.org/}). We use its recurring action API to
 * drain the queue out-of-band, freeing user requests from any HTTP work.
 *
 * Hardened after the 0.3.1 outage (see
 * `issues/2026-06-19-action-scheduler-flush-queue-runaway.md`):
 *  - the recurring action exists ONLY while the plugin is active (valid +
 *    verified token); deconfigure tears it down;
 *  - the `HOOK` callback is bound unconditionally so an orphaned action left
 *    by an older build self-heals (unschedules itself) instead of ticking
 *    forever with no handler;
 *  - a single-flight advisory lock keeps concurrent async/cron runners from
 *    piling onto the same tick.
 */
final class ActionSchedulerBridge {

	private const HOOK     = 'hellolog_flush_queue';
	private const GROUP    = 'hellolog';
	private const INTERVAL = 60;

	/**
	 * Bumped whenever the schedule SHAPE changes (e.g. the interval). On a
	 * mismatch the stale recurring action is dropped once so `reconcile()`
	 * recreates it with the current cadence — `as_has_scheduled_action()`
	 * only reports existence, not the stored interval.
	 */
	private const SCHEDULE_VERSION     = 2;
	private const SCHEDULE_VERSION_KEY = 'hellolog_flush_sched_ver';

	public function __construct(
		private QueueFlusher $flusher,
		private Options $options,
		private FlushLock $lock,
		private AsLogPruner $pruner
	) {
	}

	public function register(): void {
		add_action( self::HOOK, [ $this, 'maybe_run' ] );
		$this->pruner->register();
		add_action( 'init', [ $this, 'reconcile' ] );
	}

	/**
	 * Bring the schedule in line with the current activation state. Runs on
	 * every `init`, so deconfigure is reconciled within one request.
	 */
	public function reconcile(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		$this->migrate_schedule_version();

		$active    = $this->options->is_active();
		$scheduled = as_has_scheduled_action( self::HOOK, [], self::GROUP );

		switch ( FlushSchedule::reconcile( $active, $scheduled ) ) {
			case 'schedule':
				$interval = $this->interval();
				as_schedule_recurring_action( time() + $interval, $interval, self::HOOK, [], self::GROUP );
				break;
			case 'unschedule':
				as_unschedule_all_actions( self::HOOK, [], self::GROUP );
				break;
		}

		$this->pruner->reconcile( $active );
	}

	/**
	 * The `HOOK` callback. Self-heals an orphaned action when inactive,
	 * otherwise drains one batch under a single-flight lock.
	 */
	public function maybe_run(): void {
		if ( ! $this->options->is_active() ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::HOOK, [], self::GROUP );
			}
			return;
		}
		if ( ! $this->lock->acquire() ) {
			return;
		}
		try {
			$this->flusher->run();
		} finally {
			$this->lock->release();
		}
	}

	private function interval(): int {
		/**
		 * Filter the queue-drain cadence, in seconds. Floored at 60s — an
		 * audit-log drain has no business running sub-minute.
		 *
		 * @since 0.3.2
		 *
		 * @param int $seconds Default interval.
		 */
		$seconds = (int) apply_filters( 'hellolog_flush_interval', self::INTERVAL );
		return FlushSchedule::clamp_interval( $seconds );
	}

	private function migrate_schedule_version(): void {
		if ( (int) get_option( self::SCHEDULE_VERSION_KEY, 0 ) === self::SCHEDULE_VERSION ) {
			return;
		}
		as_unschedule_all_actions( self::HOOK, [], self::GROUP );
		update_option( self::SCHEDULE_VERSION_KEY, self::SCHEDULE_VERSION, false );
	}
}
