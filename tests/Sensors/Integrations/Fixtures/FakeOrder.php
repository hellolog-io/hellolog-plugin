<?php
/**
 * A WooCommerce order stand-in for the order sensor tests.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Integrations\Fixtures;

/**
 * Answers what the order sensor asks an order: its id, number and edit
 * screen, its stored values (`get_data()`), the values a save is about to
 * write (`get_changes()`) and the details of a new order. It is no
 * `WC_Order`: the sensor tells an order by what it can answer.
 */
class FakeOrder {

	/**
	 * @param int                  $id      Order id.
	 * @param string|null          $number  Order number; the id when not given.
	 * @param string|null          $edit    Edit screen URL; the stand-in's default when not given.
	 * @param array<string, mixed> $data    What `get_data()` returns: the values as stored.
	 * @param array<string, mixed> $changes What `get_changes()` returns: the values the save is about to write.
	 * @param array<string, mixed> $details Getter name without `get_` => what it returns (`total`, `currency`,
	 *                                      `created_via`, `payment_method`); a getter not listed returns ''.
	 */
	public function __construct(
		private int $id,
		private ?string $number = null,
		private ?string $edit = null,
		private array $data = [],
		private array $changes = [],
		private array $details = []
	) {
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_order_number(): string {
		return $this->number ?? (string) $this->id;
	}

	public function get_edit_order_url(): string {
		return $this->edit ?? 'https://shop.test/wp-admin/order/' . $this->id;
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

	/**
	 * @return mixed
	 */
	public function get_total() {
		return $this->details['total'] ?? '';
	}

	/**
	 * @return mixed
	 */
	public function get_currency() {
		return $this->details['currency'] ?? '';
	}

	/**
	 * @return mixed
	 */
	public function get_created_via() {
		return $this->details['created_via'] ?? '';
	}

	/**
	 * @return mixed
	 */
	public function get_payment_method() {
		return $this->details['payment_method'] ?? '';
	}
}
