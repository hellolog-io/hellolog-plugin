<?php
/**
 * Drains the local queue by sending batches to the backend.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Transport;

use HelloLog\Queue\QueueRepository;
use HelloLog\Queue\QueueRow;

defined( 'ABSPATH' ) || exit;

/**
 * Glue between {@see QueueRepository}, {@see PayloadBuilder}, {@see ApiClient},
 * and {@see RetryPolicy}. Picks a batch, ships it, then either deletes the
 * rows (success), reschedules them (retryable failure), or dead-letters them
 * (permanent failure).
 *
 * Designed for repeated invocation on a 30-second Action Scheduler tick;
 * `run()` is idempotent and short-circuits when there's nothing to do.
 */
final class QueueFlusher {

	/** Most events one run sends. */
	public const BATCH_SIZE = 500;

	public function __construct(
		private QueueRepository $repository,
		private PayloadBuilder $payload_builder,
		private ApiClient $client,
		private RetryPolicy $retry_policy
	) {
	}

	/**
	 * Send one batch: the oldest events due.
	 *
	 * @return int How many events the backend accepted. A full batch
	 *             ({@see self::BATCH_SIZE}) means more may be waiting.
	 */
	public function run(): int {
		if ( ! $this->client->is_configured() ) {
			return 0;
		}

		$rows = $this->repository->pick_batch( self::BATCH_SIZE );
		if ( empty( $rows ) ) {
			return 0;
		}

		$bundle = $this->payload_builder->build( $rows );
		if ( empty( $bundle['events'] ) ) {
			$this->repository->delete_many( $bundle['ids'] );
			return 0;
		}

		$this->repository->mark_sending( $bundle['ids'] );

		$wire   = wp_json_encode(
			[
				'batch_id' => $bundle['batch_id'],
				'events'   => $bundle['events'],
			]
		);
		$result = $this->client->post_batch( is_string( $wire ) ? $wire : '' );

		if ( $result->ok ) {
			$this->repository->delete_many( $bundle['ids'] );
			return count( $bundle['ids'] );
		}

		$this->handle_failure( $rows, $bundle['ids'], $result );

		return 0;
	}

	/**
	 * @param array<int, QueueRow> $rows
	 * @param array<int, int>      $ids
	 */
	private function handle_failure( array $rows, array $ids, ApiResult $result ): void {
		$error = sprintf( 'HTTP %d: %s', $result->status, substr( $result->body, 0, 256 ) );

		if ( ! $result->retryable ) {
			foreach ( $ids as $id ) {
				$this->repository->mark_dead( $id, $error );
			}
			return;
		}

		foreach ( $rows as $row ) {
			$attempts = $row->attempts + 1;
			if ( $this->retry_policy->should_dead_letter( $attempts ) ) {
				$this->repository->mark_dead( $row->id, $error );
				continue;
			}
			$this->repository->mark_retry(
				$row->id,
				$attempts,
				$this->retry_policy->next_try( $attempts ),
				$error
			);
		}
	}
}
