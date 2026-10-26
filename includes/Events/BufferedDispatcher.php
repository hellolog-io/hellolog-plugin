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
 * On a network a request can switch to another site and act there, and by
 * shutdown it is back on its own. So every event remembers the site it was
 * dispatched on: the rules only compare events of one site, and the sink is
 * told the site, so the event lands in that site's queue. The site is kept
 * under {@see SuppressionRules::BLOG_KEY} while buffered and never sent.
 *
 * A request that produces more than `$max` events flushes every `$max`, so
 * the buffer stays small. Rules then apply per batch, not across batches.
 *
 * The final flush ({@see self::flush_final()}) is the last thing a request
 * does; an event dispatched after it would never see another flush, so from
 * then on every event is written at once (still through the rules, one at a
 * time).
 *
 * The rules are given as a provider and resolved at the first flush, not when
 * the dispatcher is built: loaders and sensors attach after that and may add
 * theirs in between.
 */
final class BufferedDispatcher implements EventDispatcher {

	/** @var \Closure(string, ?int): mixed */
	private \Closure $sink;

	/** The rules, or a provider of them until they are first needed. */
	private SuppressionRules|\Closure $rules;

	/**
	 * Built payloads waiting for the next flush, in dispatch order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $buffer = [];

	/** Whether the final flush has run: nothing will flush again, so write at once. */
	private bool $final = false;

	/**
	 * @param EventBuilder                   $builder Turns a code and fields into a payload.
	 * @param callable                       $sink    Receives the JSON of one event and the id of the site it was
	 *                                                dispatched on (null outside WordPress); may throw.
	 * @param SuppressionRules|callable      $rules   Decides which buffered events survive; a callable returns
	 *                                                them and is called once, at the first flush.
	 * @param int                            $max     Buffered events that trigger an immediate flush.
	 */
	public function __construct(
		private EventBuilder $builder,
		callable $sink,
		SuppressionRules|callable $rules,
		private int $max = 200
	) {
		$this->sink  = \Closure::fromCallable( $sink );
		$this->rules = $rules instanceof SuppressionRules ? $rules : \Closure::fromCallable( $rules );
	}

	public function dispatch( int $code, array $fields = [] ): void {
		try {
			$payload = $this->builder->build( $code, $fields );
			if ( null === $payload ) {
				return; // Unknown code — silently drop.
			}

			if ( function_exists( 'get_current_blog_id' ) ) {
				$payload[ SuppressionRules::BLOG_KEY ] = (int) get_current_blog_id();
			}
			$this->buffer[] = $payload;
		} catch ( Throwable $e ) {
			$this->report( $e, $code, $fields );
			return;
		}

		if ( $this->final || count( $this->buffer ) >= $this->max ) {
			$this->flush();
		}
	}

	/**
	 * Flush for the last time. Call it at the very end of the request: events
	 * dispatched afterwards are written at once instead of waiting for a flush
	 * that will not come.
	 */
	public function flush_final(): void {
		$this->flush();
		$this->final = true;
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
			$survivors = $this->rules()->apply( $payloads );
		} catch ( Throwable $e ) {
			// A rule that fails (a broken condition from a filter, say) must
			// not cost the audit trail: better a duplicate than a lost event.
			$this->report( $e );
			$survivors = $payloads;
		}

		foreach ( $survivors as $payload ) {
			try {
				$blog_id = isset( $payload[ SuppressionRules::BLOG_KEY ] ) ? (int) $payload[ SuppressionRules::BLOG_KEY ] : null;
				unset( $payload[ SuppressionRules::BLOG_KEY ] );

				$json = self::encode( $payload );
				if ( null !== $json ) {
					( $this->sink )( $json, $blog_id );
				}
			} catch ( Throwable $e ) {
				$this->report( $e );
			}
		}
	}

	/**
	 * The rules in force, resolved on first use.
	 *
	 * A provider that fails or returns anything but rules (a filter callback
	 * that threw, say) leaves the built-in table in place.
	 */
	private function rules(): SuppressionRules {
		if ( $this->rules instanceof SuppressionRules ) {
			return $this->rules;
		}

		try {
			$rules = ( $this->rules )();
		} catch ( Throwable $e ) {
			$this->report( $e );
			$rules = null;
		}

		$this->rules = $rules instanceof SuppressionRules ? $rules : SuppressionRules::defaults();

		return $this->rules;
	}

	/**
	 * JSON text of a payload; null when it can not be encoded.
	 *
	 * @param array<string, mixed> $payload Payload.
	 */
	private static function encode( array $payload ): ?string {
		if ( function_exists( 'wp_json_encode' ) ) {
			// Default flags on purpose: the escaped form keeps 4-byte characters
			// intact in a table whose charset can not store them.
			$json = wp_json_encode( $payload );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Only reached where WordPress is not loaded.
			$json = json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		return is_string( $json ) ? $json : null;
	}

	/**
	 * Let operators observe a failure without bringing the request down. A
	 * listener that throws is swallowed too.
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
		try {
			do_action( 'hellolog_dispatch_failed', $e, $code, $fields );
		} catch ( Throwable $callback_error ) {
			// A listener that fails must not make the dispatcher throw.
			return;
		}
	}
}
