<?php
/**
 * Tests for what the order sensor tells about an order's items, its refunds
 * and its deleted notes.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Integrations;

use HelloLog\Events\ActionSchedulerContext;
use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\RequestContext;
use HelloLog\Events\RequestSource;
use HelloLog\Events\SuppressionRules;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Integrations\WooCommerceOrderSensor;
use HelloLog\Tests\Sensors\Integrations\Fixtures\FakeOrder;
use HelloLog\Tests\Sensors\Integrations\Fixtures\FakeOrderItem;
use HelloLog\Tests\Sensors\Integrations\Fixtures\OrderNoteState;
use PHPUnit\Framework\TestCase;

/**
 * An item added to an order (9106), changed (9108) or removed (9107) is told
 * on the order; a refund (9104) tells its amount, currency, reason and
 * whether money went back; a deleted note (9111) tells its text. The items of
 * a refund and of an order still being created are not told.
 *
 * The sensor calls WordPress and WooCommerce, so every test that runs it
 * runs in its own process with the stand-ins of `Fixtures/`.
 */
final class WooCommerceOrderItemsTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_item_added_to_an_order_is_told_on_the_order(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_item_added( 31, $this->line_item( 31, 5, [ 'quantity' => 2 ] ), 5 );

		$this->assertSame( [ 9106 ], array_column( $recorder->calls, 0 ) );
		$fields = $recorder->calls[0][1];
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, 'A-17' ), $fields['target'] );
		$this->assertSame( [ 'edit' => 'https://shop.test/wp-admin/order/5' ], $fields['links'] );
		$this->assertSame( 5, $fields['id'] );
		$this->assertSame(
			[
				'name' => 'Mug',
				'qty'  => '2',
			],
			$fields['vars']
		);
		$this->assertSame(
			[
				'order_id'  => 5,
				'item_id'   => 31,
				'item_type' => 'line_item',
			],
			$fields['metadata']
		);
		$this->assertArrayNotHasKey( 'changes', $fields );

		$this->assertSame( 'Item "Mug" added to WC order #5.', $this->build( $recorder )[0]['message'] );
	}

	/**
	 * @return array<string, array{0: string, 1: string}> Item type, name.
	 */
	public static function other_items(): array {
		return [
			'fee'      => [ 'fee', 'Gift wrap' ],
			'shipping' => [ 'shipping', 'Flat rate' ],
			'coupon'   => [ 'coupon', 'summer10' ],
			'tax'      => [ 'tax', 'HU-VAT-1' ],
		];
	}

	/**
	 * Fees, shipping, coupons and taxes are items too: they are told with
	 * their type; only a product line has a quantity.
	 *
	 * @dataProvider other_items
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_item_of_another_type_is_told_with_its_type_and_no_quantity( string $type, string $name ): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_item_added( 32, new FakeOrderItem( 32, 5, $type, [ 'name' => $name ] ), 5 );

		$this->assertSame( [ 9106 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ 'name' => $name ], $recorder->calls[0][1]['vars'] );
		$this->assertSame( $type, $recorder->calls[0][1]['metadata']['item_type'] );
	}

	/**
	 * A checkout or the REST API creates an order with its items in one
	 * request: the creation says it all.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_items_added_in_the_request_that_created_the_order_yield_to_the_creation(): void {
		$this->stubs();
		$order                     = $this->order(
			5,
			[
				'status'   => 'pending',
				'total'    => '20.00',
				'currency' => 'EUR',
			]
		);
		OrderNoteState::$orders[5] = $order;

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_created( 5, $order );
		$sensor->on_item_added( 31, $this->line_item( 31, 5 ), 5 );
		$sensor->on_item_added( 32, new FakeOrderItem( 32, 5, 'shipping', [ 'name' => 'Flat rate' ] ), 5 );
		$sensor->flush_new_orders();

		$this->assertSame( [ 9106, 9106, 9100 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ 9100 ], array_column( $this->survive( $recorder ), 'code' ) );
	}

	/**
	 * @return array<string, array{0: string}> Status the order has.
	 */
	public static function creation_statuses(): array {
		return [
			'not set yet'       => [ '' ],
			'new'               => [ 'new' ],
			'auto-draft'        => [ 'auto-draft' ],
			'draft'             => [ 'draft' ],
			'checkout draft'    => [ 'checkout-draft' ],
			'prefixed checkout' => [ 'wc-checkout-draft' ],
		];
	}

	/**
	 * The admin screen adds items to a draft before "Create", the checkout to
	 * its draft before the order is placed: those items are part of making
	 * the order, not edits of it, whichever request they come in.
	 *
	 * @dataProvider creation_statuses
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_items_of_an_order_still_being_created_are_not_told( string $status ): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5, [ 'status' => $status ] );
		OrderNoteState::$items[31] = $this->line_item( 31, 5 );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_item_added( 31, $this->line_item( 31, 5 ), 5 );
		$sensor->on_item_saving( $this->line_item( 31, 5, [], [ 'quantity' => 3 ] ) );
		$sensor->on_item_deleting( 31 );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( [], OrderNoteState::$fired );
	}

	/**
	 * A refund copies the refunded items onto itself; the refund event says
	 * it, not one item event per copy.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_items_of_a_refund_are_not_told(): void {
		$this->stubs();
		OrderNoteState::$orders[900] = $this->order(
			900,
			[
				'type'   => 'shop_order_refund',
				'status' => 'completed',
			]
		);
		OrderNoteState::$items[41]   = $this->line_item(
			41,
			900,
			[
				'quantity' => -1,
				'total'    => '-10',
			]
		);

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_item_added( 41, OrderNoteState::$items[41], 900 );
		$sensor->on_item_saving( $this->line_item( 41, 900, [ 'quantity' => -1 ], [ 'quantity' => -2 ] ) );
		$sensor->on_item_deleting( 41 );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( [], OrderNoteState::$fired );
	}

	/**
	 * When the order of an item cannot be loaded, WooCommerce still tells
	 * whether it is a refund; an order's item is told, on a target named by
	 * the order id.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_items_of_a_refund_that_cannot_be_loaded_are_not_told(): void {
		$this->stubs();
		require_once __DIR__ . '/Fixtures/woocommerce-order-util-class.php';
		OrderNoteState::$order_types[900] = 'shop_order_refund';
		OrderNoteState::$order_types[5]   = 'shop_order';

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_item_added( 41, $this->line_item( 41, 900 ), 900 );
		$sensor->on_item_added( 31, $this->line_item( 31, 5 ), 5 );

		$this->assertSame( [ 9106 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, '' ), $recorder->calls[0][1]['target'] );
		$this->assertArrayNotHasKey( 'links', $recorder->calls[0][1] );
	}

	/**
	 * `wc_add_order_item()` may pass no item (or no order id): the item is
	 * read by its id, its order taken from it. An item that is nowhere to be
	 * found, or that belongs to no order, is not told.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_item_the_hook_does_not_pass_is_read_by_its_id(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );
		OrderNoteState::$items[31] = $this->line_item( 31, 5 );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_item_added( 31, false, 5 );
		$sensor->on_item_added( 31, $this->line_item( 31, 5 ), 0 );
		$sensor->on_item_added( 99, false, 5 );
		$sensor->on_item_added( 33, $this->line_item( 33, 0 ), 0 );

		$this->assertSame( [ 9106, 9106 ], array_column( $recorder->calls, 0 ) );
		foreach ( $recorder->calls as [ , $fields ] ) {
			$this->assertSame( 5, $fields['metadata']['order_id'] );
			$this->assertSame( 'Mug', $fields['vars']['name'] );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_changed_quantity_is_one_item_change_on_the_order(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_item_saving(
			$this->line_item(
				31,
				5,
				[],
				[
					'quantity' => 3,
					'subtotal' => '30',
					'total'    => '30',
				]
			)
		);

		$this->assertSame( [ 9108 ], array_column( $recorder->calls, 0 ) );
		$fields = $recorder->calls[0][1];
		$this->assertSame(
			[
				self::change( 'quantity', '1', '3' ),
				self::change( 'subtotal', '10', '30' ),
				self::change( 'total', '10', '30' ),
			],
			$fields['changes']
		);
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, 'A-17' ), $fields['target'] );
		$this->assertSame( [ 'edit' => 'https://shop.test/wp-admin/order/5' ], $fields['links'] );
		$this->assertSame( 5, $fields['id'] );
		$this->assertSame( [ 'name' => 'Mug' ], $fields['vars'] );
		$this->assertSame(
			[
				'order_id'  => 5,
				'item_id'   => 31,
				'item_type' => 'line_item',
			],
			$fields['metadata']
		);

		$this->assertSame( 'Item "Mug" changed on WC order #5.', $this->build( $recorder )[0]['message'] );
	}

	/**
	 * Only the name, quantity and amounts of an item are told, in that
	 * order; taxes by rate, product ids and meta are not. A renamed item is
	 * named by its new name.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_only_the_item_fields_are_told_in_their_order(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_item_saving(
			$this->line_item(
				31,
				5,
				[],
				[
					'total_tax'    => '5.4',
					'taxes'        => [
						'subtotal' => [ 1 => '5.4' ],
						'total'    => [ 1 => '5.4' ],
					],
					'product_id'   => 8,
					'subtotal_tax' => '5.4',
					'name'         => 'Big mug',
					'meta_data'    => [ 'key' => '_api_token' ],
				]
			)
		);

		$fields = $recorder->calls[0][1];
		$this->assertSame(
			[
				self::change( 'name', 'Mug', 'Big mug' ),
				self::change( 'total_tax', '2.7', '5.4' ),
			],
			$fields['changes']
		);
		$this->assertSame( [ 'name' => 'Big mug' ], $fields['vars'] );
	}

	/**
	 * `10` for `10.00`, `'1'` for `1`, `0` for an empty amount: the same
	 * value written in another form is no change, and a save without a real
	 * change is not told at all.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_same_amount_or_quantity_in_another_form_is_no_item_change(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_item_saving(
			$this->line_item(
				31,
				5,
				[ 'total_tax' => '' ],
				[
					'quantity'  => '1',
					'subtotal'  => '10.00',
					'total'     => 10,
					'total_tax' => 0,
				]
			)
		);

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * Every order save saves its items again, mostly without changes: such a
	 * save reads no order at all (and tells nothing). Neither does the save
	 * that inserts an item: that is its addition.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_item_save_without_changes_of_its_fields_reads_no_order(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = new class( 5 ) extends FakeOrder {
			public function get_status( string $context = 'view' ) {
				throw new \RuntimeException( 'The order must not be loaded.' );
			}
		};

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_item_saving( $this->line_item( 31, 5 ) );
		$sensor->on_item_saving( $this->line_item( 31, 5, [], [ 'meta_data' => [ 'key' => '_reduced_stock' ] ] ) );
		$sensor->on_item_saving(
			new FakeOrderItem(
				0,
				5,
				'line_item',
				[],
				[
					'name'     => 'Mug',
					'quantity' => 2,
				]
			)
		);

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( [], OrderNoteState::$fired );
	}

	/**
	 * @return array<string, array{0: string}> The hook that says the order's items are rebuilt.
	 */
	public static function rebuilds(): array {
		return [
			'checkout resumes the order' => [ 'resumed' ],
			'all items removed'          => [ 'removed' ],
		];
	}

	/**
	 * A checkout retried after a failed payment resumes the pending order:
	 * WooCommerce removes all its items at once (no item hook fires) and adds
	 * them again from the cart. Other code that rebuilds an order's items
	 * from the cart removes them the same way. Nothing changed: no item of
	 * that order is told for the rest of the request. Another order, or the
	 * same id on another site, is.
	 *
	 * @dataProvider rebuilds
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_items_rebuilt_from_the_cart_are_not_told( string $rebuild ): void {
		$this->stubs();
		$order                     = $this->order( 5, [ 'status' => 'failed' ] );
		OrderNoteState::$orders[5] = $order;
		OrderNoteState::$orders[6] = $this->order( 6 );
		OrderNoteState::$items[31] = $this->line_item( 31, 5 );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		if ( 'resumed' === $rebuild ) {
			$sensor->on_order_resumed( '5' );
		} else {
			$sensor->on_items_removing( $order, null );
		}
		$sensor->on_item_added( 41, $this->line_item( 41, 5 ), 5 );
		$sensor->on_item_added( 42, new FakeOrderItem( 42, 5, 'shipping', [ 'name' => 'Flat rate' ] ), 5 );
		$sensor->on_item_deleting( 31 );
		$this->assertSame( [], $recorder->calls );

		$sensor->on_item_added( 51, $this->line_item( 51, 6 ), 6 );
		OrderNoteState::$blog_id = 2;
		$sensor->on_item_added( 43, $this->line_item( 43, 5 ), 5 );

		$this->assertSame( [ 9106, 9106 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 6, $recorder->calls[0][1]['metadata']['order_id'] );
		$this->assertSame( 43, $recorder->calls[1][1]['metadata']['item_id'] );
		$this->assertSame( [], OrderNoteState::$fired );
	}

	/**
	 * Removing the items of an order that is not saved yet rebuilds nothing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_removing_the_items_of_an_unsaved_order_rebuilds_nothing(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_items_removing( $this->order( 0 ), 'line_item' );
		$sensor->on_order_resumed( 0 );
		$sensor->on_item_added( 41, $this->line_item( 41, 5 ), 5 );

		$this->assertSame( [ 9106 ], array_column( $recorder->calls, 0 ) );
	}

	/**
	 * An item added in a request is often saved again in it (the order's
	 * taxes and totals are recalculated after the addition): that is part of
	 * adding it, not a change. So is an item added to an order that was still
	 * being created. Other items, and the same id on another site, are told.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_item_added_in_the_request_is_not_also_changed(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );
		OrderNoteState::$orders[7] = $this->order( 7, [ 'status' => 'checkout-draft' ] );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_item_added( 31, $this->line_item( 31, 5 ), 5 );
		$sensor->on_item_added( 33, $this->line_item( 33, 7 ), 7 );
		OrderNoteState::$orders[7] = $this->order( 7 );

		$sensor->on_item_saving( $this->line_item( 31, 5, [], [ 'total_tax' => '5.4' ] ) );
		$sensor->on_item_saving( $this->line_item( 33, 7, [], [ 'total_tax' => '5.4' ] ) );
		$sensor->on_item_saving( $this->line_item( 32, 5, [], [ 'total_tax' => '5.4' ] ) );
		OrderNoteState::$blog_id = 2;
		$sensor->on_item_saving( $this->line_item( 31, 5, [], [ 'total_tax' => '5.4' ] ) );

		$this->assertSame( [ 9106, 9108, 9108 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 32, $recorder->calls[1][1]['metadata']['item_id'] );
		$this->assertSame( 31, $recorder->calls[2][1]['metadata']['item_id'] );
	}

	/**
	 * What an Action Scheduler job noted about items (the items it added, the
	 * orders whose items it rebuilt) ends with the job: a later job of the
	 * same run (the command line runs many in one process) that changes such
	 * an item, or removes an item of such an order, logs it. What the request
	 * noted before running a job holds for the rest of the request.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_items_a_job_added_or_rebuilt_are_told_by_a_later_job_of_the_same_run(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );
		OrderNoteState::$orders[6] = $this->order( 6 );
		OrderNoteState::$orders[8] = $this->order( 8 );
		OrderNoteState::$items[32] = $this->line_item( 32, 6 );
		OrderNoteState::$items[35] = $this->line_item( 35, 8 );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_item_added( 34, $this->line_item( 34, 5 ), 5 );
		$sensor->on_order_resumed( 8 );

		ActionSchedulerContext::begin( 41 );
		$sensor->on_item_added( 31, $this->line_item( 31, 5 ), 5 );
		$sensor->on_order_resumed( 6 );
		$sensor->flush_job_orders();
		ActionSchedulerContext::end( 41 );
		$this->assertSame( [ 9106, 9106 ], array_column( $recorder->calls, 0 ) );

		$recorder->calls = [];
		ActionSchedulerContext::begin( 42 );
		$sensor->on_item_saving( $this->line_item( 31, 5, [], [ 'quantity' => 3 ] ) );
		$sensor->on_item_deleting( 32 );
		$sensor->on_item_saving( $this->line_item( 34, 5, [], [ 'quantity' => 3 ] ) );
		$sensor->on_item_deleting( 35 );
		$sensor->flush_job_orders();
		ActionSchedulerContext::end( 42 );

		$this->assertSame( [ 9108, 9107 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 31, $recorder->calls[0][1]['metadata']['item_id'] );
		$this->assertSame( 32, $recorder->calls[1][1]['metadata']['item_id'] );
	}

	/**
	 * WooCommerce's items hold on to the order they were read or added with:
	 * that order is used, not loaded again for every item of a save. An
	 * order held that is not the item's (moved since) is not.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_item_tells_on_the_order_it_holds_without_loading_it_again(): void {
		$this->stubs();
		OrderNoteState::$orders[6] = $this->order( 6 );
		$held                      = new FakeOrder( 5, 'B-2', 'https://shop.test/wp-admin/order/held/5' );
		$item                      = new class( 31, 5, 'line_item', [ 'quantity' => 1 ], [ 'quantity' => 2 ] ) extends FakeOrderItem {

			public ?object $order = null;

			public function get_order(): ?object {
				return $this->order;
			}
		};
		$item->order               = $held;
		$moved                     = new class( 32, 6, 'line_item', [ 'quantity' => 1 ], [ 'quantity' => 2 ] ) extends FakeOrderItem {

			public ?object $order = null;

			public function get_order(): ?object {
				return $this->order;
			}
		};
		$moved->order              = $held;

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_item_saving( $item );
		$sensor->on_item_saving( $moved );

		$this->assertSame( [ 9108, 9108 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, 'B-2' ), $recorder->calls[0][1]['target'] );
		$this->assertSame( [ 'edit' => 'https://shop.test/wp-admin/order/held/5' ], $recorder->calls[0][1]['links'] );
		$this->assertSame( WooCommerceOrderSensor::order_target( 6, 'A-17' ), $recorder->calls[1][1]['target'] );
	}

	/**
	 * Two items changed in one save are two events, each with its own
	 * changes and name: the rules never fold them into one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_items_changed_in_one_request_are_one_event_each(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_item_saving( $this->line_item( 31, 5, [], [ 'quantity' => 3 ] ) );
		$sensor->on_item_saving(
			$this->line_item(
				32,
				5,
				[
					'name'     => 'Plate',
					'quantity' => 2,
				],
				[ 'quantity' => 1 ]
			)
		);

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 9108, 9108 ], array_column( $survivors, 'code' ) );
		$this->assertSame( [ self::change( 'quantity', '1', '3' ) ], $survivors[0]['changes'] );
		$this->assertSame( 'Mug', $survivors[0]['vars']['name'] );
		$this->assertSame( [ self::change( 'quantity', '2', '1' ) ], $survivors[1]['changes'] );
		$this->assertSame( 'Plate', $survivors[1]['vars']['name'] );
	}

	/**
	 * The item is read before WooCommerce deletes it, so its name and order
	 * are still known.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_removed_item_is_told_on_its_order(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );
		OrderNoteState::$items[31] = $this->line_item( 31, 5, [ 'quantity' => 2 ] );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_item_deleting( 31 );

		$this->assertSame( [ 9107 ], array_column( $recorder->calls, 0 ) );
		$fields = $recorder->calls[0][1];
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, 'A-17' ), $fields['target'] );
		$this->assertSame( [ 'edit' => 'https://shop.test/wp-admin/order/5' ], $fields['links'] );
		$this->assertSame( 5, $fields['id'] );
		$this->assertSame( [ 'name' => 'Mug' ], $fields['vars'] );
		$this->assertSame(
			[
				'order_id'  => 5,
				'item_id'   => 31,
				'item_type' => 'line_item',
			],
			$fields['metadata']
		);

		$payload = $this->build( $recorder )[0];
		$this->assertSame( 'Item removed from WC order #5.', $payload['message'] );
		$this->assertSame( 'Mug', $payload['vars']['name'] );
	}

	/**
	 * An item that cannot be read has no order to tell it on.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_item_that_cannot_be_read_before_its_removal_is_not_told(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_item_deleting( 31 );
		$sensor->on_item_deleting( 0 );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( [], OrderNoteState::$fired );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_refund_tells_its_amount_currency_reason_and_whether_money_went_back(): void {
		$this->stubs();
		OrderNoteState::$orders[5]   = $this->order( 5 );
		OrderNoteState::$orders[900] = $this->refund( '12.50', 'EUR', 'Damaged in transit', true );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_refunded( 5, 900 );

		$this->assertSame( [ 9104 ], array_column( $recorder->calls, 0 ) );
		$fields = $recorder->calls[0][1];
		$this->assertSame( '12.50', $fields['amount'] );
		$this->assertSame(
			[
				'currency' => 'EUR',
				'reason'   => 'Damaged in transit',
			],
			$fields['vars']
		);
		$this->assertSame(
			[
				'order_id'         => 5,
				'refund_id'        => 900,
				'amount'           => '12.50',
				'refunded_payment' => true,
			],
			$fields['metadata']
		);

		$payload = $this->build( $recorder )[0];
		$this->assertSame( 'Refund issued on WC order #5: 12.50.', $payload['message'] );
		$this->assertSame(
			[
				'id'       => '5',
				'amount'   => '12.50',
				'currency' => 'EUR',
				'reason'   => 'Damaged in transit',
			],
			$payload['vars']
		);
	}

	/**
	 * A refund without a reason sends none; one the shop recorded without
	 * returning money through the gateway says so.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_refund_without_a_reason_or_a_gateway_refund_says_so(): void {
		$this->stubs();
		OrderNoteState::$orders[5]   = $this->order( 5 );
		OrderNoteState::$orders[900] = $this->refund( '5.00', 'HUF', '', false );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_refunded( 5, 900 );

		$fields = $recorder->calls[0][1];
		$this->assertSame( [ 'currency' => 'HUF' ], $fields['vars'] );
		$this->assertFalse( $fields['metadata']['refunded_payment'] );
	}

	/**
	 * Only what the refund can tell is sent.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_refund_that_tells_only_its_amount_sends_only_that(): void {
		$this->stubs();
		OrderNoteState::$orders[5]   = $this->order( 5 );
		OrderNoteState::$orders[900] = new class() {
			public function get_amount(): string {
				return '12.50';
			}
		};

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_refunded( 5, 900 );

		$fields = $recorder->calls[0][1];
		$this->assertSame( '12.50', $fields['amount'] );
		$this->assertSame( [], $fields['vars'] );
		$this->assertSame(
			[
				'order_id'  => 5,
				'refund_id' => 900,
				'amount'    => '12.50',
			],
			$fields['metadata']
		);
	}

	/**
	 * The REST API passes the note id as text; the note was read before its
	 * delete, so its order and text are known.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deleted_note_is_told_on_its_order_with_its_text(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_note_deleted( '42', $this->deleted_note( 42, 5, '<p>Customer &amp; courier called.</p>' ) );

		$this->assertSame( [ 9111 ], array_column( $recorder->calls, 0 ) );
		$fields = $recorder->calls[0][1];
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, 'A-17' ), $fields['target'] );
		$this->assertSame( [ 'edit' => 'https://shop.test/wp-admin/order/5' ], $fields['links'] );
		$this->assertSame( 5, $fields['id'] );
		$this->assertSame( [ 'note' => 'Customer & courier called.' ], $fields['vars'] );
		$this->assertSame(
			[
				'order_id' => 5,
				'note_id'  => 42,
			],
			$fields['metadata']
		);

		$this->assertSame( 'Note deleted from WC order #5.', $this->build( $recorder )[0]['message'] );
	}

	/**
	 * The text of a deleted note goes the way of an added one's: masked,
	 * then cut to 500 characters.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deleted_notes_text_is_masked_before_it_is_cut(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );
		$content                   = str_repeat( 'x', 470 ) . ' https://admin:' . str_repeat( 'hunter2-', 5 ) . '@h/x';

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_note_deleted( 42, $this->deleted_note( 42, 5, $content ) );

		$note = $recorder->calls[0][1]['vars']['note'];
		$this->assertSame( WooCommerceOrderSensor::note_text( $content ), $note );
		$this->assertStringNotContainsString( 'hunt', $note );
		$this->assertLessThanOrEqual( 500, mb_strlen( $note, 'UTF-8' ) );
	}

	/**
	 * A note whose text is not known sends none rather than an empty one; a
	 * note of no order is not told.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deleted_note_without_text_or_order_sends_what_is_known(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_note_deleted( 42, (object) [ 'order_id' => 5 ] );
		$sensor->on_note_deleted( 43, (object) [ 'content' => 'No order.' ] );
		$sensor->on_note_deleted( 44, null );

		$this->assertSame( [ 9111 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [], $recorder->calls[0][1]['vars'] );
		$this->assertSame( 42, $recorder->calls[0][1]['metadata']['note_id'] );
	}

	/**
	 * Erasing a customer's personal data deletes every note of the order,
	 * because notes can hold it: those deletions are tagged, and their text
	 * is not sent. Only that order's, on that site, while the erasure runs.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_notes_deleted_by_a_privacy_erasure_are_tagged_and_keep_no_text(): void {
		$this->stubs();
		$order                     = $this->order( 5 );
		OrderNoteState::$orders[5] = $order;
		OrderNoteState::$orders[6] = $this->order( 6 );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_privacy_erasing( $order );
		$sensor->on_note_deleted( 42, $this->deleted_note( 42, 5, 'Deliver to 1 Main Street, call 555 0100.' ) );
		$sensor->on_note_deleted( 43, $this->deleted_note( 43, 6, 'Gift wrap.' ) );
		OrderNoteState::$blog_id = 2;
		$sensor->on_note_deleted( 44, $this->deleted_note( 44, 5, 'On another site.' ) );
		OrderNoteState::$blog_id = 1;
		$sensor->on_privacy_erased( $order );
		$sensor->on_note_deleted( 45, $this->deleted_note( 45, 5, 'Written later.' ) );

		$this->assertSame( [ 9111, 9111, 9111, 9111 ], array_column( $recorder->calls, 0 ) );
		$erased = $recorder->calls[0][1];
		$this->assertSame( 'privacy-erasure', $erased['metadata']['context'] );
		$this->assertSame( [], $erased['vars'] );
		$this->assertStringNotContainsString( 'Main Street', (string) json_encode( $this->build( $recorder )[0] ) );

		foreach ( [ 1 => 'Gift wrap.', 2 => 'On another site.', 3 => 'Written later.' ] as $index => $text ) {
			$this->assertArrayNotHasKey( 'context', $recorder->calls[ $index ][1]['metadata'], $text );
			$this->assertSame( [ 'note' => $text ], $recorder->calls[ $index ][1]['vars'] );
		}
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: array<string, int>}> Code, handler, what the report names.
	 */
	public static function item_hooks(): array {
		return [
			'item added'      => [
				9106,
				'on_item_added',
				[
					'order_id' => 5,
					'item_id'  => 31,
				],
			],
			'item saved'      => [
				9108,
				'on_item_saving',
				[
					'order_id' => 5,
					'item_id'  => 31,
				],
			],
			'item deleted'    => [
				9107,
				'on_item_deleting',
				[
					'order_id' => 5,
					'item_id'  => 31,
				],
			],
			'note deleted'    => [
				9111,
				'on_note_deleted',
				[
					'order_id' => 5,
					'note_id'  => 42,
				],
			],
			'refunded'        => [ 9104, 'on_refunded', [ 'order_id' => 5 ] ],
			'erasure started' => [ 9111, 'on_privacy_erasing', [] ],
			'erasure ended'   => [ 9111, 'on_privacy_erased', [] ],
			'items removed'   => [ 9106, 'on_items_removing', [] ],
		];
	}

	/**
	 * An item is saved and deleted inside WooCommerce's own work (an
	 * exception there breaks the save of the order): whatever fails while
	 * reading it is reported, never thrown.
	 *
	 * @dataProvider item_hooks
	 * @param int                $code    Code the handler works on.
	 * @param string             $handler Handler.
	 * @param array<string, int> $named   What the report names.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_every_item_refund_and_note_hook_reports_instead_of_throwing( int $code, string $handler, array $named ): void {
		$this->stubs();
		$broken                      = new class( 5 ) extends FakeOrder {
			public function get_order_number(): string {
				throw new \RuntimeException( 'A filter broke.' );
			}

			public function get_id(): int {
				throw new \RuntimeException( 'A filter broke.' );
			}
		};
		$item                        = new class( 31, 5, 'line_item', [ 'name' => 'Mug' ], [ 'quantity' => 3 ] ) extends FakeOrderItem {
			public function get_changes(): array {
				throw new \RuntimeException( 'A filter broke.' );
			}

			public function get_name() {
				throw new \RuntimeException( 'A filter broke.' );
			}
		};
		OrderNoteState::$orders[5]   = $broken;
		OrderNoteState::$items[31]   = $item;
		OrderNoteState::$orders[900] = new class() {
			public function get_amount(): string {
				return '1.00';
			}

			public function get_reason(): string {
				throw new \RuntimeException( 'A filter broke.' );
			}
		};

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		switch ( $handler ) {
			case 'on_item_added':
				$sensor->on_item_added( 31, $item, 5 );
				break;
			case 'on_item_saving':
				$sensor->on_item_saving( $item );
				break;
			case 'on_item_deleting':
				$sensor->on_item_deleting( 31 );
				break;
			case 'on_note_deleted':
				$sensor->on_note_deleted( 42, $this->deleted_note( 42, 5, 'Text.' ) );
				break;
			case 'on_refunded':
				$sensor->on_refunded( 5, 900 );
				break;
			default:
				$sensor->$handler( $broken );
		}

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 'hellolog_dispatch_failed', OrderNoteState::$fired[0][0] ?? null );
		$this->assertSame( $code, OrderNoteState::$fired[0][1][1] ?? null );
		$this->assertSame( $named, OrderNoteState::$fired[0][1][2] ?? null );
	}

	/**
	 * Ids come as text from some callers; anything else a hook may pass is
	 * ignored, never a failure.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_item_and_note_hooks_take_ids_as_text_and_never_fail_on_an_argument(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );
		OrderNoteState::$items[31] = $this->line_item( 31, 5 );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_item_added( '31', $this->line_item( 31, 5 ), '5' );
		$sensor->on_item_deleting( '31' );
		$sensor->on_note_deleted( '42', $this->deleted_note( 42, 5, 'Text.' ) );

		$this->assertSame( [ 9106, 9107, 9111 ], array_column( $recorder->calls, 0 ) );
		foreach ( $recorder->calls as [ $code, $fields ] ) {
			$this->assertSame( 5, $fields['id'], (string) $code );
			$this->assertSame( '5', $fields['target']['id'], (string) $code );
		}
		$this->assertSame( 31, $recorder->calls[1][1]['metadata']['item_id'] );

		$recorder->calls = [];
		foreach ( [ null, [ 5 ], new \stdClass(), 5.0, true, 'x' ] as $odd ) {
			$sensor->on_item_added( $odd, $odd, $odd );
			$sensor->on_item_saving( $odd );
			$sensor->on_item_deleting( $odd );
			$sensor->on_note_deleted( $odd, $odd );
			$sensor->on_privacy_erasing( $odd );
			$sensor->on_privacy_erased( $odd );
			$sensor->on_order_resumed( $odd );
			$sensor->on_items_removing( $odd, $odd );
		}
		$this->assertSame( [], $recorder->calls );
		$this->assertSame( [], OrderNoteState::$fired );
	}

	/**
	 * Load the stand-ins and reset what they answer.
	 */
	private function stubs(): void {
		require_once __DIR__ . '/Fixtures/wordpress-order-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-site-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-strip-tags-function.php';
		require_once __DIR__ . '/Fixtures/woocommerce-order-factory-class.php';

		OrderNoteState::$comments     = [];
		OrderNoteState::$comment_meta = [];
		OrderNoteState::$orders       = [];
		OrderNoteState::$items        = [];
		OrderNoteState::$order_types  = [];
		OrderNoteState::$translations = [];
		OrderNoteState::$actions      = [];
		OrderNoteState::$statuses     = OrderNoteState::DEFAULT_STATUSES;
		OrderNoteState::$blog_id      = 1;
		OrderNoteState::$post_types   = [];
		OrderNoteState::$fired        = [];

		$_SERVER['HTTP_HOST'] = 'www.shop.example.com';
	}

	/**
	 * An order numbered `A-17`.
	 *
	 * @param int                  $id      Order id.
	 * @param array<string, mixed> $details Getter name without `get_` => what it returns (`status`, `type`, …).
	 */
	private function order( int $id, array $details = [] ): FakeOrder {
		return new FakeOrder( $id, 'A-17', null, [], [], $details );
	}

	/**
	 * A product line of a mug, one piece at 10 plus 2.7 tax, as stored and
	 * as a save is about to write it.
	 *
	 * @param int                  $id       Item id.
	 * @param int                  $order_id Order id.
	 * @param array<string, mixed> $data     Stored values to replace.
	 * @param array<string, mixed> $changes  Values the save is about to write.
	 */
	private function line_item( int $id, int $order_id, array $data = [], array $changes = [] ): FakeOrderItem {
		return new FakeOrderItem(
			$id,
			$order_id,
			'line_item',
			array_merge(
				[
					'id'           => $id,
					'order_id'     => $order_id,
					'name'         => 'Mug',
					'product_id'   => 7,
					'variation_id' => 0,
					'quantity'     => 1,
					'tax_class'    => '',
					'subtotal'     => '10',
					'subtotal_tax' => '2.7',
					'total'        => '10',
					'total_tax'    => '2.7',
					'taxes'        => [
						'subtotal' => [ 1 => '2.7' ],
						'total'    => [ 1 => '2.7' ],
					],
					'meta_data'    => [],
				],
				$data
			),
			$changes
		);
	}

	/**
	 * A refund as WooCommerce loads it.
	 *
	 * @param string $amount           Amount as stored.
	 * @param string $currency         Currency code.
	 * @param string $reason           Reason given; may be empty.
	 * @param bool   $refunded_payment Whether the gateway returned the money.
	 */
	private function refund( string $amount, string $currency, string $reason, bool $refunded_payment ): object {
		return new class( $amount, $currency, $reason, $refunded_payment ) {

			public function __construct(
				private string $amount,
				private string $currency,
				private string $reason,
				private bool $refunded_payment
			) {
			}

			public function get_type(): string {
				return 'shop_order_refund';
			}

			public function get_amount(): string {
				return $this->amount;
			}

			public function get_currency(): string {
				return $this->currency;
			}

			public function get_reason(): string {
				return $this->reason;
			}

			public function get_refunded_payment(): bool {
				return $this->refunded_payment;
			}
		};
	}

	/**
	 * A note as `wc_get_order_note()` read it before its delete.
	 *
	 * @param int    $id       Note id.
	 * @param int    $order_id Id of its order.
	 * @param string $content  Its text.
	 */
	private function deleted_note( int $id, int $order_id, string $content ): object {
		return (object) [
			'id'            => $id,
			'date_created'  => new \DateTimeImmutable( '2026-10-26T10:00:00+00:00' ),
			'content'       => $content,
			'customer_note' => false,
			'added_by'      => 'system',
			'order_id'      => $order_id,
		];
	}

	/**
	 * @return array{field: string, old: ?string, new: ?string}
	 */
	private static function change( string $field, ?string $old, ?string $new ): array {
		return [
			'field' => $field,
			'old'   => $old,
			'new'   => $new,
		];
	}

	/**
	 * A dispatcher that records every call as `[ code, fields ]`.
	 */
	private function recorder(): EventDispatcher {
		return new class() implements EventDispatcher {

			/** @var array<int, array{0: int, 1: array<string, mixed>}> */
			public array $calls = [];

			public function dispatch( int $code, array $fields = [] ): void {
				$this->calls[] = [ $code, $fields ];
			}
		};
	}

	/**
	 * Build what the recorder saw, as an admin (user 1) on the dashboard.
	 *
	 * @param EventDispatcher $recorder The recording dispatcher.
	 * @return array<int, array<string, mixed>> The payloads, before any rule.
	 */
	private function build( EventDispatcher $recorder ): array {
		$catalog = new EventCatalog();
		CatalogSeeder::seed( $catalog );

		$builder = new class( $catalog ) extends EventBuilder {

			public function __construct( EventCatalog $catalog ) {
				parent::__construct( $catalog, false, static fn(): string => RequestSource::ADMIN );
			}

			protected function current_context(): RequestContext {
				return new RequestContext( 1, 'admin', [ 'administrator' ], null, null, null );
			}
		};

		$payloads = [];
		foreach ( $recorder->calls as [ $code, $fields ] ) {
			$payload = $builder->build( $code, $fields );
			$this->assertIsArray( $payload, "code $code is in the catalog" );
			$payloads[] = $payload;
		}

		return $payloads;
	}

	/**
	 * Build what the recorder saw and run the default per-request rules over it.
	 *
	 * @param EventDispatcher $recorder The recording dispatcher.
	 * @return array<int, array<string, mixed>> The payloads that would be written.
	 */
	private function survive( EventDispatcher $recorder ): array {
		return SuppressionRules::defaults()->apply( $this->build( $recorder ) );
	}
}
