<?php
/**
 * Stand-ins for the WordPress functions the order sensor calls to tell the
 * site, the type of a post and to report its own failures.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Sensors\Integrations\Fixtures\OrderNoteState;

	function get_current_blog_id(): int {
		return OrderNoteState::$blog_id;
	}

	/**
	 * @param int|null $post Post id.
	 * @return string|false
	 */
	function get_post_type( $post = null ) {
		return OrderNoteState::$post_types[ (int) $post ] ?? false;
	}

	/**
	 * Records the call; no callback runs.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  ...$args   Arguments.
	 */
	function do_action( string $hook_name, ...$args ): void {
		OrderNoteState::$fired[] = [ $hook_name, $args ];
	}
}
