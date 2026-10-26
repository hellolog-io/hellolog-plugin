<?php
/**
 * Stand-ins for the WordPress calls that register a hook callback.
 *
 * Loaded only by tests that run in a separate process: defining them makes
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

	/**
	 * @param string   $hook_name     Hook.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Arguments the callback takes.
	 */
	function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return add_action( $hook_name, $callback, $priority, $accepted_args );
	}
}
