<?php
/**
 * Keeps the local outgoing queue bounded.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Several sensors fire on visitor actions (comments, form submissions,
 * failed logins, 404s), so the queue grows with outside traffic. While the
 * backend is healthy the drain keeps up; while it is unreachable or rejects
 * the token, rows pile up as `pending`/`dead`. Run after every drain tick:
 * dead rows past the retention window go first, then the oldest rows above
 * the hard cap.
 */
final class QueueRetention {

	public const MAX_ROWS            = 50000;
	public const MIN_ROWS            = 1000;
	public const DEAD_RETENTION_DAYS = 7;

	public function __construct(
		private QueueRepository $repository
	) {
	}

	public function run(): void {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $this->dead_retention_days() * \DAY_IN_SECONDS );
		$this->repository->purge_dead_before( $cutoff );

		$excess = self::overflow( $this->repository->count_all(), $this->max_rows() );
		if ( $excess > 0 ) {
			$this->repository->delete_oldest( $excess );
		}
	}

	public static function overflow( int $count, int $max ): int {
		return max( 0, $count - $max );
	}

	public static function clamp_max_rows( int $rows ): int {
		return max( self::MIN_ROWS, $rows );
	}

	public static function clamp_days( int $days ): int {
		return max( 1, $days );
	}

	private function max_rows(): int {
		/**
		 * Filter the hard cap on queued rows. Floored at 1,000.
		 *
		 * @since 0.4.4
		 *
		 * @param int $rows Default cap.
		 */
		return self::clamp_max_rows( (int) apply_filters( 'hellolog_queue_max_rows', self::MAX_ROWS ) );
	}

	private function dead_retention_days(): int {
		/**
		 * Filter how many days undeliverable (`dead`) rows are kept for
		 * `wp hellolog requeue-dead`. Floored at 1 day.
		 *
		 * @since 0.4.4
		 *
		 * @param int $days Default retention in days.
		 */
		return self::clamp_days( (int) apply_filters( 'hellolog_queue_dead_retention_days', self::DEAD_RETENTION_DAYS ) );
	}
}
