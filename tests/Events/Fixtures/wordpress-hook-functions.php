<?php
/**
 * Stand-in for the WordPress call that registers a hook callback.
 *
 * Loaded only by tests that run in a separate process: defining it makes
 * `function_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Events\Fixtures\WpCalls;

	/**
	 * @param string   $hook_name     Hook.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Arguments the callback takes.
	 */
	function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		WpCalls::$hooks[ $hook_name ] = [
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		];

		return true;
	}
}
