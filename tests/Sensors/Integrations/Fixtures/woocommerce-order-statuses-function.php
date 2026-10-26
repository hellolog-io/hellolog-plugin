<?php
/**
 * Stand-in for WooCommerce's `wc_get_order_statuses()`, which the order sensor
 * uses when it exists.
 *
 * Loaded only by tests that run in a separate process: defining it makes
 * `function_exists( 'wc_get_order_statuses' )` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Sensors\Integrations\Fixtures\OrderNoteState;

	/**
	 * @return array<string, string> Status slug => label.
	 */
	function wc_get_order_statuses(): array {
		return OrderNoteState::$statuses;
	}
}
