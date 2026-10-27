<?php
/**
 * A WooCommerce product stand-in for the product sensor tests.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Integrations\Fixtures;

/**
 * Answers what the product sensor asks a product or a variation: its id,
 * name and parent, its stored values (`get_data()`) and the values a save is
 * about to write (`get_changes()`). Like WooCommerce's, the name is the
 * pending one when the save renames the product. It is no `WC_Product`: the
 * sensor tells a product by what it can answer.
 */
class FakeProduct {

	/**
	 * @param int                  $id        Product id; 0 for a product being inserted.
	 * @param array<string, mixed> $data      What `get_data()` returns: the values as stored.
	 * @param array<string, mixed> $changes   What `get_changes()` returns: the values the save is about to write.
	 * @param int                  $parent_id Id of the variable product a variation belongs to; 0 for none.
	 */
	public function __construct(
		private int $id,
		private array $data = [],
		private array $changes = [],
		private int $parent_id = 0
	) {
	}

	public function get_id(): int {
		return $this->id;
	}

	/**
	 * @return mixed
	 */
	public function get_name() {
		return $this->changes['name'] ?? $this->data['name'] ?? '';
	}

	public function get_parent_id(): int {
		return $this->parent_id;
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
