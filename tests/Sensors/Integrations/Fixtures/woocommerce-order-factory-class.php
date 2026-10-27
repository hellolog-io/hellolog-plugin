<?php
/**
 * Stand-in for WooCommerce's `WC_Order_Factory`, which the order sensor asks
 * for an order item that is about to be deleted.
 *
 * Loaded only by tests that run in a separate process: defining it makes
 * `class_exists( 'WC_Order_Factory' )` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Sensors\Integrations\Fixtures\OrderNoteState;

	if ( ! class_exists( 'WC_Order_Factory', false ) ) {

		/**
		 * Finds the items listed in {@see OrderNoteState::$items}.
		 */
		final class WC_Order_Factory {

			/**
			 * @param mixed $item_id Item id.
			 * @return object|false
			 */
			public static function get_order_item( $item_id = 0 ) {
				return is_numeric( $item_id ) ? ( OrderNoteState::$items[ (int) $item_id ] ?? false ) : false;
			}
		}
	}
}
