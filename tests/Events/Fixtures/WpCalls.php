<?php
/**
 * Records what the WordPress function stubs were called with.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Events\Fixtures;

/**
 * Shared state of `wordpress-functions.php`. Only ever used inside a test
 * that runs in its own process, together with those stubs.
 */
final class WpCalls {

	/**
	 * Every `do_action()` call as `[ hook, args ]`.
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	public static array $actions = [];

	/**
	 * The `$flags` of every `wp_json_encode()` call.
	 *
	 * @var array<int, int>
	 */
	public static array $json_flags = [];

	/** Whether the `do_action()` stub plays a callback that throws. */
	public static bool $action_throws = false;
}
