<?php
/**
 * CRUD over `{$wpdb->prefix}hellolog_queue`.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around `$wpdb` so the rest of the plugin never builds raw
 * SQL. Keeps queries narrow and prepared, and keeps `attempts` / `status`
 * / `next_try` housekeeping in one place.
 */
final class QueueRepository {

	public const STATUS_PENDING = 'pending';
	public const STATUS_SENDING = 'sending';
	public const STATUS_DEAD    = 'dead';

	public function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'hellolog_queue';
	}

	public function insert( string $payload ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$this->table(),
			[
				'payload'    => $payload,
				'attempts'   => 0,
				'next_try'   => $now,
				'status'     => self::STATUS_PENDING,
				'created_at' => $now,
			],
			[ '%s', '%d', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<int, QueueRow>
	 */
	public function pick_batch( int $limit ): array {
		global $wpdb;

		$table = $this->table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read from our own queue table, deliberately not cached (must see fresh rows every drain tick).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is our own prefixed table name, not user input; cannot be a %s/%d placeholder.
				"SELECT * FROM {$table}
				 WHERE status = %s AND next_try <= %s
				 ORDER BY id ASC
				 LIMIT %d",
				self::STATUS_PENDING,
				$now,
				$limit
			)
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}
		return array_map( static fn( $r ) => QueueRow::from_db( $r ), $rows );
	}

	/**
	 * @param array<int, int> $ids
	 */
	public function mark_sending( array $ids ): void {
		$this->bulk_status_update( $ids, self::STATUS_SENDING );
	}

	/**
	 * Wipe rows from the queue, optionally limited to one status.
	 * Used by `wp hellolog clear-queue` to drain a dead-pile-up that
	 * accumulated while the license was misconfigured.
	 *
	 * @return int rows deleted
	 */
	public function purge( ?string $status = null ): int {
		global $wpdb;
		$table = $this->table();
		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = $wpdb->query( "DELETE FROM {$table}" );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = %s", $status ) );
		}
		return (int) $deleted;
	}

	/**
	 * Reset every `dead` row back to `pending` so the flusher picks
	 * them up again. The backend went away (Redis OOM, transient
	 * outage, expired token, …), the rows exceeded max retries and
	 * landed in `dead` — once the operator confirms the backend is
	 * healthy again, this brings them back into rotation in one shot.
	 *
	 * @return int rows requeued
	 */
	public function requeue_dead(): int {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- flush write, not a read; nothing to cache.
		$affected = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is our own prefixed table name, not user input; cannot be a %s/%d placeholder.
				"UPDATE {$table}
				 SET status     = %s,
				     attempts   = 0,
				     next_try   = %s,
				     last_error = NULL
				 WHERE status   = %s",
				self::STATUS_PENDING,
				current_time( 'mysql', true ),
				self::STATUS_DEAD
			)
		);
		return (int) $affected;
	}

	/**
	 * @param array<int, int> $ids
	 */
	public function delete_many( array $ids ): void {
		global $wpdb;
		if ( empty( $ids ) ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the %d tokens are inside $placeholders, built dynamically from count($ids); the sniff can't see through the interpolation to confirm they're there, but $ids supplies exactly that many values to $wpdb->prepare().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
	}

	public function mark_retry( int $id, int $attempts, string $next_try, string $error ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table(),
			[
				'attempts'   => $attempts,
				'next_try'   => $next_try,
				'status'     => self::STATUS_PENDING,
				'last_error' => substr( $error, 0, 512 ),
			],
			[ 'id' => $id ],
			[ '%d', '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	public function mark_dead( int $id, string $error ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table(),
			[
				'status'     => self::STATUS_DEAD,
				'last_error' => substr( $error, 0, 512 ),
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * @return array<string, int>  status => count
	 */
	public function counts_by_status(): array {
		global $wpdb;

		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A );
		$out  = [];
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['status'] ] = (int) $r['c'];
		}
		return $out;
	}

	/**
	 * @param array<int, int> $ids
	 */
	private function bulk_status_update( array $ids, string $status ): void {
		global $wpdb;
		if ( empty( $ids ) ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id IN ({$placeholders})", array_merge( [ $status ], $ids ) ) );
	}
}
