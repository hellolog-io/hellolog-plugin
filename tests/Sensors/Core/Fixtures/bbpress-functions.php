<?php
/**
 * Stand-in for bbPress's version function: the other thing a sensor checks to
 * tell whether the forums are active.
 *
 * Loaded only by tests that run in a separate process: defining it makes
 * `function_exists( 'bbp_get_version' )` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	function bbp_get_version(): string {
		return '2.6.0';
	}
}
