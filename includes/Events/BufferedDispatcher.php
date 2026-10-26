<?php
/**
 * Production dispatcher — collects a request's events, thins them out, then writes.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Events;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Default implementation of {@see EventDispatcher}. A sensor hook only builds
 * the event and puts it in memory; nothing reaches the sink until
 * {@see self::flush()}, which runs once per request at shutdown. That is what
 * lets {@see SuppressionRules} see everything one action produced and keep
 * only the most specific events, instead of every hook writing its own row.
 *
 * Each survivor is JSON-encoded and handed to the sink on its own, which in
 * production is a single `INSERT` into the local queue; the transport layer
 * drains that queue out-of-band.
 *
 * A request that produces more than `$max` events flushes every `$max`, so
 * the buffer stays small. Rules then apply per batch, not across batches.
 */
final class BufferedDispatcher implements EventDispatcher {

	/** @var \Closure(string): mixed */
	private \Closure $sink;

	/**
	 * Built payloads waiting for the next flush, in dispatch order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $buffer = [];

	/**
	 * @param EventBuilder      $builder Turns a code and fields into a payload.
	 * @param callable          $sink    Receives the JSON of one event; may throw.
	 * @param SuppressionRules  $rules   Decides which buffered events survive.
	 * @param int               $max     Buffered events that trigger an immediate flush.
	 */
	public function __construct(
		private EventBuilder $builder,
		callable $sink,
		private SuppressionRules $rules,
		private int $max = 200
	) {
		$this->sink = \Closure::fromCallable( $sink );
	}

	public function dispatch( int $code, array $fields = [] ): void {
		try {
			$payload = $this->builder->build( $code, $fields );
			if ( null === $payload ) {
				return; // Unknown code — silently drop.
			}

			$this->buffer[] = $payload;
		} catch ( Throwable $e ) {
			$this->report( $e, $code, $fields );
			return;
		}

		if ( count( $this->buffer ) >= $this->max ) {
			$this->flush();
		}
	}

	/**
	 * Apply the rules to everything buffered and write the survivors.
	 *
	 * Never throws. The buffer is emptied before anything is written, so a
	 * failure can not make the same events go out twice; a survivor that can
	 * not be encoded or written is reported and skipped, and the rest are
	 * still written.
	 */
	public function flush(): void {
		if ( [] === $this->buffer ) {
			return;
		}

		$payloads     = $this->buffer;
		$this->buffer = [];

		try {
			$survivors = $this->rules->apply( $payloads );
		} catch ( Throwable $e ) {
			// A rule that fails (a broken condition from a filter, say) must
			// not cost the audit trail: better a duplicate than a lost event.
			$this->report( $e );
			$survivors = $payloads;
		}

		foreach ( $survivors as $payload ) {
			try {
				$json = self::encode( $payload );
				if ( null !== $json ) {
					( $this->sink )( $json );
				}
			} catch ( Throwable $e ) {
				$this->report( $e );
			}
		}
	}

	/**
	 * JSON text of a payload; null when it can not be encoded.
	 *
	 * @param array<string, mixed> $payload Payload.
	 */
	private static function encode( array $payload ): ?string {
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

		if ( function_exists( 'wp_json_encode' ) ) {
			$json = wp_json_encode( $payload, $flags );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Only reached where WordPress is not loaded.
			$json = json_encode( $payload, $flags );
		}

		return is_string( $json ) ? $json : null;
	}

	/**
	 * Let operators observe a failure without bringing the request down.
	 *
	 * @param Throwable            $e      The captured throwable.
	 * @param int                  $code   Event code that was being dispatched; 0 when a flush failed.
	 * @param array<string, mixed> $fields Sensor-supplied fields; empty when a flush failed.
	 */
	private function report( Throwable $e, int $code = 0, array $fields = [] ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		/**
		 * Allow operators to observe internal dispatcher failures
		 * (e.g. via Query Monitor) without bringing the surrounding
		 * request down. We intentionally swallow the throwable here.
		 *
		 * @since 0.1.0
		 *
		 * @param Throwable            $error  The captured exception.
		 * @param int                  $code   Event code that was being dispatched, or 0 when a flush failed.
		 * @param array<string, mixed> $fields Sensor-supplied fields, or an empty array when a flush failed.
		 */
		do_action( 'hellolog_dispatch_failed', $e, $code, $fields );
	}
}
