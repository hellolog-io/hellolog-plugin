<?php
/**
 * Stand-ins for the WordPress transient calls, kept in memory.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Sensors\Core\Fixtures\WpState;

	/**
	 * @param string $transient Name.
	 * @return mixed The value, or false when there is none.
	 */
	function get_transient( string $transient ) {
		return WpState::$transients[ $transient ][0] ?? false;
	}

	/**
	 * @param string $transient  Name.
	 * @param mixed  $value      Value.
	 * @param int    $expiration Lifetime in seconds.
	 */
	function set_transient( string $transient, $value, int $expiration = 0 ): bool {
		WpState::$transients[ $transient ] = [ $value, $expiration ];

		return true;
	}
}
