<?php
/**
 * Stand-ins for the WordPress functions the queue calls besides `$wpdb`.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	/**
	 * @param string   $type Format; the queue only asks for `mysql`.
	 * @param bool|int $gmt  Whether to give UTC; the queue always does.
	 */
	function current_time( string $type, $gmt = 0 ): string {
		return '2026-10-26 12:00:00';
	}
}
