<?php
/**
 * Stand-in for WooCommerce's `WC_Coupon`, which the coupon sensor tells from
 * the other objects the shop saves through the same hook.
 *
 * Loaded only by tests that run in a separate process: defining it makes
 * `class_exists( 'WC_Coupon' )` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	if ( ! class_exists( 'WC_Coupon', false ) ) {

		/**
		 * Answers what the coupon sensor asks a coupon: its id, its code, its
		 * stored values (`get_data()`) and the values a save is about to write
		 * (`get_changes()`). Like WooCommerce's, the code is the pending one
		 * when the save changes it. Like WooCommerce's, it has no `get_type()`:
		 * a sensor that asks for it fails.
		 */
		class WC_Coupon {

			/**
			 * @param int                  $id      Coupon id; 0 for a coupon being inserted.
			 * @param array<string, mixed> $data    What `get_data()` returns: the values as stored.
			 * @param array<string, mixed> $changes What `get_changes()` returns: the values the save is about to write.
			 */
			public function __construct(
				private int $id,
				private array $data = [],
				private array $changes = []
			) {
			}

			public function get_id(): int {
				return $this->id;
			}

			/**
			 * @param string $context `view` or `edit`; both read the same here.
			 * @return mixed
			 */
			public function get_code( string $context = 'view' ) {
				return $this->changes['code'] ?? $this->data['code'] ?? '';
			}

			/**
			 * @return array<string, mixed>
			 */
			public function get_data(): array {
				return $this->data;
			}

			/**
			 * @return array<string, mixed>
			 */
			public function get_changes(): array {
				return $this->changes;
			}
		}
	}
}
