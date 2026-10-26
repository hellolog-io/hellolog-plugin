<?php
/**
 * No-op dispatcher — used while the plugin is not configured to deliver events.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Swallows every event. Stands in for {@see BufferedDispatcher} while the
 * plugin has no endpoint or token, so nothing fills a queue that can never
 * drain.
 */
final class NullEventDispatcher implements EventDispatcher {

	public function dispatch( int $code, array $fields = [] ): void {
		// no-op
	}
}
