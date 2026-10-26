<?php
/**
 * Stand-in for the WordPress call that names the current site of a network.
 *
 * Loaded only by tests that run in a separate process: defining it makes
 * `function_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Events\Fixtures\WpCalls;

	function get_current_blog_id(): int {
		return WpCalls::$blog_id;
	}
}
