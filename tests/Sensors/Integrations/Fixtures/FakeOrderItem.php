<?php
/**
 * A WooCommerce order item stand-in for the order sensor tests.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Integrations\Fixtures;

/**
 * Answers what the order sensor asks an order item: its id, its order, its
 * type, name and quantity, its stored values (`get_data()`) and the values a
 * save is about to write (`get_changes()`). Like WooCommerce's, the name and
 * quantity are the pending ones when a save changes them. It is no
 * `WC_Order_Item`: the sensor tells an item by what it can answer.
 */
class FakeOrderItem {

	/**
	 * @param int                  $id       Item id; 0 for an item being inserted.
	 * @param int                  $order_id Id of the order (or refund) it belongs to.
	 * @param string               $type     `line_item`, `fee`, `shipping`, `coupon` or `tax`.
	 * @param array<string, mixed> $data     What `get_data()` returns: the values as stored.
	 * @param array<string, mixed> $changes  What `get_changes()` returns: the values the save is about to write.
	 */
	public function __construct(
		private int $id,
		private int $order_id,
		private string $type = 'line_item',
		private array $data = [],
		private array $changes = []
	) {
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_order_id(): int {
		return $this->order_id;
	}

	public function get_type(): string {
		return $this->type;
	}

	/**
	 * @return mixed
	 */
	public function get_name() {
		return $this->changes['name'] ?? $this->data['name'] ?? '';
	}

	/**
	 * @return mixed
	 */
	public function get_quantity() {
		return $this->changes['quantity'] ?? $this->data['quantity'] ?? 1;
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
