<?php
/**
 * Bounds helloLOG's own footprint in the shared Action Scheduler tables.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler shares the `actionscheduler_*` tables across every plugin
 * on the site; helloLOG's own rows differ only by `group = 'hellolog'`. The
 * 0.3.1 flood proved we can't rely on
 * Action Scheduler's global 30-day cleaner — by the time it would run, our
 * logs had already choked the whole scheduler, WooCommerce included.
 *
 * This is a good-citizen pruner: a low-frequency action that deletes ONLY our
 * own finished actions (and their logs, via the store) past a short retention,
 * using the public AS API. It never touches the global retention setting, so
 * other plugins' actions are left alone.
 */
final class AsLogPruner {

	private const HOOK           = 'hellolog_prune_as';
	private const GROUP          = 'hellolog';
	private const INTERVAL       = \DAY_IN_SECONDS;
	private const RETENTION_DAYS = 3;
	private const BATCH          = 5000;

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	public function reconcile( bool $active ): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		$scheduled = as_has_scheduled_action( self::HOOK, [], self::GROUP );
		if ( $active && ! $scheduled ) {
			as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::HOOK, [], self::GROUP );
		} elseif ( ! $active && $scheduled ) {
			as_unschedule_all_actions( self::HOOK, [], self::GROUP );
		}
	}

	/**
	 * Delete up to {@see self::BATCH} of our finished actions older than the
	 * retention window. At the 60s flush cadence a fully active install
	 * completes ~1,440 actions/day; 5,000/day covers that steady state with
	 * headroom to drain a backlog (e.g. after a retention-window change)
	 * within a few days, while still capping each run so it never turns into
	 * a long-running action itself.
	 */
	public function run(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler' ) ) {
			return;
		}
		$ids = as_get_scheduled_actions(
			[
				'group'        => self::GROUP,
				'status'       => [ \ActionScheduler_Store::STATUS_COMPLETE, \ActionScheduler_Store::STATUS_FAILED ],
				'date'         => gmdate( 'Y-m-d H:i:s', time() - $this->retention_seconds() ),
				'date_compare' => '<=',
				'per_page'     => self::BATCH,
			],
			'ids'
		);
		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return;
		}
		$store = \ActionScheduler::store();
		foreach ( $ids as $id ) {
			$this->delete_one( $store, (string) $id );
		}
	}

	/**
	 * Delete a single action, tolerating a lost race: another cleaner
	 * (Action Scheduler's own, or a concurrent run of this pruner) may have
	 * already removed the row between `as_get_scheduled_actions()` and here,
	 * which `delete_action()` reports as an `InvalidArgumentException`. That
	 * is the outcome we wanted anyway, so it's skipped, not an error.
	 */
	private function delete_one( \ActionScheduler_Store $store, string $id ): void {
		try {
			$store->delete_action( $id );
		} catch ( \InvalidArgumentException $exception ) {
			// Already gone — see the docblock above. Nothing to do.
			unset( $exception );
		}
	}

	private function retention_seconds(): int {
		/**
		 * Filter how many days of finished hellolog actions/logs to keep.
		 * Floored at 1 day.
		 *
		 * @since 0.3.2
		 *
		 * @param int $days Default retention in days.
		 */
		$days = max( 1, (int) apply_filters( 'hellolog_as_retention_days', self::RETENTION_DAYS ) );
		return $days * \DAY_IN_SECONDS;
	}
}
