<?php
/**
 * Sensors publish events through this contract.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Decouples sensors from the queue/transport implementation. A sensor never
 * touches the DB directly — it hands the event payload to a dispatcher,
 * which is the queue producer in production and a recording stub in tests.
 */
interface EventDispatcher {

	/**
	 * Persist an audit event for later transmission.
	 *
	 * Implementations MUST never throw under normal operation. A sensor
	 * firing on a hot path (e.g. `save_post`) must never bring the
	 * surrounding request down because of a logging backend problem.
	 *
	 * @param int                  $code   Catalog code; ignored events should silently no-op.
	 * @param array<string, mixed> $fields Sensor-supplied overrides (user, post, message, metadata, ...).
	 */
	public function dispatch( int $code, array $fields = [] ): void;
}
