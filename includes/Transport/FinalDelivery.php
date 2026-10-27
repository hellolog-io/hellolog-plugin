<?php
/**
 * Sends the queue within the request, for when no job will send it again.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Transport;

use HelloLog\Events\BufferedDispatcher;
use HelloLog\Events\EventDispatcher;
use HelloLog\Scheduler\FlushLock;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * The queue is normally sent by a background job a minute or so after an
 * event happens. When helloLOG is deactivated that job is unscheduled, so the
 * deactivation itself, the last event the site sends, would wait in the queue
 * until the plugin is turned on again. This sends it right away instead:
 *
 *   1. the event is dispatched and everything the request has collected so
 *      far is written to the queue (a {@see BufferedDispatcher} is flushed;
 *      another dispatcher writes at once);
 *   2. the queue is sent, under the same single-flight lock as the job
 *      ({@see FlushLock}), batch after batch while full batches go through,
 *      until it is empty or {@see self::BUDGET} seconds are spent;
 *   3. it is sent once more at the very end of the request, after the
 *      dispatcher's final flush, for whatever the rest of the request queued
 *      (plugins deactivated after helloLOG in the same bulk action, say).
 *
 * When another run holds the lock, that run is sending the queue and this
 * send is skipped. A failure while sending is reported through the
 * `hellolog_dispatch_failed` action, like a failed flush (code 0, no fields),
 * and never reaches the request.
 */
final class FinalDelivery {

	/** Longest time spent sending, in seconds, before another batch is started. */
	public const BUDGET = 2.0;

	/** @var callable(): int */
	private $send_batch;

	/** @var callable(): float */
	private $clock;

	/**
	 * @param EventDispatcher    $dispatcher Where the event is dispatched.
	 * @param callable(): int    $send_batch Sends one batch and returns how many events the backend accepted,
	 *                                       like {@see QueueFlusher::run()}.
	 * @param FlushLock          $lock       The lock the background job sends under.
	 * @param float              $budget     Longest time spent sending, in seconds.
	 * @param ?callable(): float $clock      The time in seconds; `microtime( true )` when not given.
	 */
	public function __construct(
		private EventDispatcher $dispatcher,
		callable $send_batch,
		private FlushLock $lock,
		private float $budget = self::BUDGET,
		?callable $clock = null
	) {
		$this->send_batch = $send_batch;
		$this->clock      = $clock ?? static fn(): float => microtime( true );
	}

	/**
	 * Dispatch the event, write the request's events, send the queue now and
	 * once more at the very end of the request.
	 *
	 * @param int                  $code   Event code.
	 * @param array<string, mixed> $fields Event fields.
	 */
	public function deliver( int $code, array $fields ): void {
		$this->dispatcher->dispatch( $code, $fields );
		if ( $this->dispatcher instanceof BufferedDispatcher ) {
			$this->dispatcher->flush();
		}

		$this->send();

		// After the dispatcher's final flush, also at PHP_INT_MAX: callbacks
		// of one priority run in the order they were added.
		add_action( 'shutdown', [ $this, 'send' ], PHP_INT_MAX );
	}

	/**
	 * Send the queue under the flush lock: batch after batch while full ones
	 * go through, until it is empty or the time is up. Never throws.
	 */
	public function send(): void {
		try {
			if ( ! $this->lock->acquire() ) {
				return;
			}
		} catch ( Throwable $e ) {
			$this->report( $e );
			return;
		}

		try {
			$start = ( $this->clock )();
			do {
				$accepted = (int) ( $this->send_batch )();
			} while ( $accepted >= QueueFlusher::BATCH_SIZE && ( $this->clock )() - $start < $this->budget );
		} catch ( Throwable $e ) {
			$this->report( $e );
		} finally {
			$this->release();
		}
	}

	/**
	 * Let the lock go; a failure doing so is reported. The lock also goes
	 * with the database connection at the end of the request.
	 */
	private function release(): void {
		try {
			$this->lock->release();
		} catch ( Throwable $e ) {
			$this->report( $e );
		}
	}

	/**
	 * Report a failure the way the dispatcher reports a failed flush. A
	 * listener that throws is swallowed too.
	 *
	 * @param Throwable $e What went wrong.
	 */
	private function report( Throwable $e ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		try {
			/** This action is documented in includes/Events/BufferedDispatcher.php */
			do_action( 'hellolog_dispatch_failed', $e, 0, [] );
		} catch ( Throwable $callback_error ) {
			return;
		}
	}
}
