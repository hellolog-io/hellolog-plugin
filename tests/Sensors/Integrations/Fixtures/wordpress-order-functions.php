<?php
/**
 * Stand-ins for the WordPress and WooCommerce functions the order sensor
 * calls from its hooks.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Sensors\Integrations\Fixtures\OrderNoteState;

	/**
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 */
	function __( string $text, string $domain = 'default' ): string {
		return OrderNoteState::$translations[ $text ] ?? $text;
	}

	/**
	 * @param int $comment_id Comment id.
	 */
	function get_comment( int $comment_id ): ?object {
		return OrderNoteState::$comments[ $comment_id ] ?? null;
	}

	/**
	 * @param int    $comment_id Comment id.
	 * @param string $key        Meta key.
	 * @param bool   $single     Whether to return one value.
	 * @return mixed What core answers for a missing single value: an empty string.
	 */
	function get_comment_meta( int $comment_id, string $key = '', bool $single = false ) {
		return OrderNoteState::$comment_meta[ $comment_id ][ $key ] ?? '';
	}

	/**
	 * @param int $order_id Order id.
	 * @return object|false
	 */
	function wc_get_order( int $order_id ) {
		return OrderNoteState::$orders[ $order_id ] ?? false;
	}

	/**
	 * Records the registration; nothing is ever fired.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of arguments the callback accepts.
	 */
	function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		OrderNoteState::$actions[ $hook_name ] = [
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		];

		return true;
	}
}
