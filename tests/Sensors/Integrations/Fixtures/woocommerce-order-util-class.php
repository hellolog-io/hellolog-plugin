<?php
/**
 * Stand-in for WooCommerce's `OrderUtil`, which the order sensor asks for the
 * type of an order it cannot load.
 *
 * Loaded only by tests that run in a separate process: defining it makes
 * `class_exists()` true for it for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Utilities {

	use HelloLog\Tests\Sensors\Integrations\Fixtures\OrderNoteState;

	if ( ! class_exists( OrderUtil::class, false ) ) {

		/**
		 * Answers the types listed in {@see OrderNoteState::$order_types}.
		 */
		final class OrderUtil {

			/**
			 * @param mixed $order_id Order id.
			 */
			public static function get_order_type( $order_id ): ?string {
				return is_numeric( $order_id ) ? ( OrderNoteState::$order_types[ (int) $order_id ] ?? null ) : null;
			}
		}
	}
}
