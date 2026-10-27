<?php
/**
 * Tests for what the order sensor tells about an order's fields, status,
 * trash, restore and delete.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Integrations;

use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\RequestContext;
use HelloLog\Events\RequestSource;
use HelloLog\Events\SuppressionRules;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Core\ContentSensor;
use HelloLog\Sensors\Integrations\WooCommerceOrderSensor;
use HelloLog\Tests\Sensors\Integrations\Fixtures\FakeOrder;
use HelloLog\Tests\Sensors\Integrations\Fixtures\OrderNoteState;
use PHPUnit\Framework\TestCase;

/**
 * A save of an existing order tells which of its fields changed (9105); a
 * status change carries the status as a change (9101); a new order tells
 * its total, currency, channel and payment method (9100); trash (9109),
 * restore (9110) and delete (9102) name the order.
 *
 * The sensor calls WordPress and WooCommerce, so every test that runs it
 * runs in its own process with the stand-ins of `Fixtures/`.
 */
final class WooCommerceOrderSensorTest extends TestCase {

	/**
	 * The city is the customer's address: it is told as changed, without its
	 * values.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_edited_billing_city_is_one_edit_event_with_that_change(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_before_save(
			$this->order( 5, self::stored(), [ 'billing' => [ 'city' => 'Szeged' ] ] )
		);

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9105, $code );
		$this->assertSame( [ self::change( 'billing.city', null, null ) ], $fields['changes'] );
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, 'A-17' ), $fields['target'] );
		$this->assertSame( [ 'edit' => 'https://shop.test/wp-admin/order/5' ], $fields['links'] );
		$this->assertSame( 5, $fields['id'] );
		$this->assertSame( [ 'order_id' => 5 ], $fields['metadata'] );

		$payloads = $this->build( $recorder );
		$this->assertSame( 'WC order #5 edited.', $payloads[0]['message'] );
	}

	/**
	 * An order that is being inserted has no id yet: its creation is told by
	 * the new order event, not as an edit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_of_an_order_without_an_id_is_no_edit(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_before_save(
			$this->order( 0, self::stored(), [ 'billing' => [ 'city' => 'Szeged' ] ] )
		);

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * Only what can be asked for its stored and pending values is an order;
	 * the sensor does not need it to be of WooCommerce's order class.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_something_that_cannot_tell_its_stored_and_pending_values_is_no_order(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_before_save(
			new class() {
				public function get_id(): int {
					return 5;
				}
			}
		);
		$sensor->on_before_save( null );
		$sensor->on_before_save( 'order' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * Every save touches `date_modified`. A save that changed nothing else is
	 * dispatched without changes, and the buffer drops it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_that_only_moves_the_modification_date_is_dropped_by_the_buffer(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_before_save(
			$this->order( 5, self::stored(), [ 'date_modified' => new \DateTimeImmutable( '2026-10-26T10:00:00+00:00' ) ] )
		);

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( 9105, $recorder->calls[0][0] );
		$this->assertSame( [], $recorder->calls[0][1]['changes'] );

		$this->assertSame( [], $this->survive( $recorder ) );
	}

	/**
	 * A checkout creates an order and saves it again in the same request:
	 * the creation says it all.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_edit_in_the_request_that_created_the_order_yields_to_the_creation(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_created( 5, $this->order( 5 ) );
		$sensor->on_before_save( $this->order( 5, self::stored(), [ 'billing' => [ 'city' => 'Szeged' ] ] ) );

		$this->assertSame( [ 9100, 9105 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ 9100 ], array_column( $this->survive( $recorder ), 'code' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function creation_statuses(): array {
		return [
			'not set yet'    => [ '' ],
			'new'            => [ 'new' ],
			'editor draft'   => [ 'auto-draft' ],
			'admin draft'    => [ 'draft' ],
			'checkout draft' => [ 'checkout-draft' ],
			'prefixed draft' => [ 'wc-checkout-draft' ],
		];
	}

	/**
	 * A save of an order that is still being created fills its fields in:
	 * nothing of it is an edit.
	 *
	 * @dataProvider creation_statuses
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_of_an_order_still_being_created_is_no_edit( string $status ): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_before_save(
			$this->order(
				5,
				self::stored( [ 'status' => $status ] ),
				[
					'billing' => [ 'city' => 'Szeged' ],
					'total'   => '30.00',
				]
			)
		);

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_of_a_pending_order_is_an_edit(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_before_save(
			$this->order( 5, self::stored( [ 'status' => 'pending' ] ), [ 'total' => '30.00' ] )
		);

		$this->assertSame( [ self::change( 'total', '25.00', '30.00' ) ], $recorder->calls[0][1]['changes'] );
	}

	/**
	 * A payment gateway's callback has nobody logged in: it records the
	 * transaction id and the payment date, and moves the status. The status
	 * event carries the edits of the same save.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_gateway_callback_is_told_without_an_actor_and_with_the_transaction_id(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$order    = $this->order(
			5,
			self::stored( [ 'status' => 'pending' ] ),
			[
				'status'         => 'processing',
				'transaction_id' => 'txn_1001',
				'date_paid'      => new \DateTimeImmutable( '2026-10-26T12:00:00+02:00' ),
			]
		);
		$sensor->on_before_save( $order );

		$payloads = $this->build( $recorder, RequestSource::REST, null );
		$this->assertSame( 'rest', $payloads[0]['source'] );
		$this->assertArrayNotHasKey( 'user', $payloads[0] );
		$this->assertSame(
			[
				self::change( 'transaction_id', '', 'txn_1001' ),
				self::change( 'date_paid', null, '2026-10-26T10:00:00+00:00' ),
			],
			$payloads[0]['changes']
		);

		$sensor->on_status_changed( 5, 'pending', 'processing', $order );

		$survivors = SuppressionRules::defaults()->apply( $this->build( $recorder, RequestSource::REST, null ) );
		$this->assertSame( [ 9101 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 'rest', $survivors[0]['source'] );
		$this->assertArrayNotHasKey( 'user', $survivors[0] );
		$this->assertSame( [ 'transaction_id', 'date_paid', 'status' ], array_column( $survivors[0]['changes'], 'field' ) );
	}

	/**
	 * An admin who edits the address and moves the status in one save gets
	 * one event: the status change, carrying the address edit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_status_change_carries_the_edits_of_its_save(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$order    = $this->order(
			5,
			self::stored( [ 'status' => 'on-hold' ] ),
			[
				'status'  => 'completed',
				'billing' => [ 'city' => 'Szeged' ],
			]
		);
		$sensor->on_before_save( $order );
		$sensor->on_status_changed( 5, 'on-hold', 'completed', $order );

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 9101 ], array_column( $survivors, 'code' ) );
		$this->assertSame(
			[ self::change( 'billing.city', null, null ), self::change( 'status', 'on-hold', 'completed' ) ],
			$survivors[0]['changes']
		);
	}

	/**
	 * The edits of one request (the admin screen saves an order several
	 * times) are one event; an edit that was undone is none.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_edits_of_one_request_are_one_event(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_before_save( $this->order( 5, self::stored(), [ 'billing' => [ 'city' => 'Szeged' ] ] ) );
		$sensor->on_before_save( $this->order( 5, self::stored( [ 'billing' => [ 'city' => 'Szeged' ] ] ), [ 'total' => '30.00' ] ) );
		$sensor->on_before_save( $this->order( 6, self::stored(), [ 'total' => '25.00' ] ) );

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 9105 ], array_column( $survivors, 'code' ) );
		$this->assertSame( '5', $survivors[0]['target']['id'] );
		$this->assertSame( [ 'billing.city', 'total' ], array_column( $survivors[0]['changes'], 'field' ) );
	}

	/**
	 * Only the fields of the order are told: its status has its own event,
	 * its items theirs, and WooCommerce's copies of the address under flat
	 * names (`billing_city`) are not told twice.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_only_the_order_fields_are_told_once_each(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_before_save(
			$this->order(
				5,
				self::stored(),
				[
					'status'       => 'completed',
					'billing'      => [ 'city' => 'Szeged' ],
					'billing_city' => 'Szeged',
					'cart_tax'     => '5.00',
					'version'      => '11.2.1',
					'order_key'    => 'wc_order_abc',
				]
			)
		);

		$this->assertSame( [ self::change( 'billing.city', null, null ) ], $recorder->calls[0][1]['changes'] );
	}

	/**
	 * Every field of the list is told, the addresses field by field.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_each_order_field_is_told(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_before_save(
			$this->order(
				5,
				self::stored(),
				[
					'billing'              => [ 'email' => 'anna@example.com' ],
					'shipping'             => [ 'postcode' => '6720' ],
					'total'                => '30.00',
					'discount_total'       => '2.00',
					'shipping_total'       => '3.00',
					'payment_method'       => 'cod',
					'payment_method_title' => 'Cash on delivery',
					'customer_id'          => 12,
					'customer_note'        => 'Ring twice',
					'currency'             => 'HUF',
					'date_created'         => new \DateTimeImmutable( '2026-10-01T08:00:00+00:00' ),
					'transaction_id'       => 'T-1',
					'date_paid'            => new \DateTimeImmutable( '2026-10-26T10:00:00+00:00' ),
					'date_completed'       => new \DateTimeImmutable( '2026-10-27T10:00:00+00:00' ),
				]
			)
		);

		$this->assertSame(
			[
				'billing.email',
				'shipping.postcode',
				'total',
				'discount_total',
				'shipping_total',
				'payment_method',
				'payment_method_title',
				'customer_id',
				'customer_note',
				'currency',
				'date_created',
				'transaction_id',
				'date_paid',
				'date_completed',
			],
			array_column( $recorder->calls[0][1]['changes'], 'field' )
		);
		$this->assertSame( self::change( 'customer_note', null, null ), $recorder->calls[0][1]['changes'][8] );
	}

	/**
	 * An amount written in another form, an empty value that was null, or a
	 * date set again to the same moment in another zone is no change; a
	 * phone number losing its leading zero is one (told without its values).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_same_value_in_another_form_is_no_change(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_before_save(
			$this->order(
				5,
				self::stored(
					[
						'billing'   => [
							'city'    => 'Budapest',
							'company' => null,
							'phone'   => '0612345',
						],
						'total'     => '25.00',
						'date_paid' => new \DateTimeImmutable( '2026-10-26T12:00:00+02:00' ),
					]
				),
				[
					'billing'        => [
						'company' => '',
						'phone'   => '612345',
					],
					'total'          => 25,
					'shipping_total' => 0.0,
					'date_paid'      => new \DateTimeImmutable( '2026-10-26T10:00:00+00:00' ),
				]
			)
		);

		$this->assertSame( [ self::change( 'billing.phone', null, null ) ], $recorder->calls[0][1]['changes'] );
	}

	/**
	 * The stored order holds its items, objects that turn themselves into
	 * text expensively; an edit of the order never reads them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_items_of_the_order_are_never_read_for_an_edit(): void {
		$this->stubs();
		$item = new class() {
			public function __toString(): string {
				throw new \RuntimeException( 'An item was turned into text.' );
			}
		};

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_before_save(
			$this->order(
				5,
				self::stored( [ 'line_items' => [ $item ] ] ),
				[
					'billing'    => [ 'city' => 'Szeged' ],
					'line_items' => [ $item ],
				]
			)
		);

		$this->assertSame( [ self::change( 'billing.city', null, null ) ], $recorder->calls[0][1]['changes'] );
		$this->assertSame( [], OrderNoteState::$fired );
	}

	/**
	 * The handler runs inside WooCommerce's save, where an exception aborts
	 * the write of the order. Whatever fails while reading the order is
	 * reported, never thrown.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_order_that_fails_to_answer_never_breaks_its_save(): void {
		$this->stubs();
		$order = new class( 5 ) extends FakeOrder {
			public function get_changes(): array {
				throw new \TypeError( 'A filter broke.' );
			}
		};

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_before_save( $order );

		$this->assertSame( [], $recorder->calls );
		$this->assertCount( 1, OrderNoteState::$fired );
		[ $hook, $args ] = OrderNoteState::$fired[0];
		$this->assertSame( 'hellolog_dispatch_failed', $hook );
		$this->assertInstanceOf( \TypeError::class, $args[0] );
		$this->assertSame( 9105, $args[1] );
		$this->assertSame( [ 'order_id' => 5 ], $args[2] );
	}

	/**
	 * @return array<string, array{0: int, 1: string}> Code the handler works on, handler.
	 */
	public static function order_hooks(): array {
		return [
			'created'        => [ 9100, 'on_created' ],
			'status changed' => [ 9101, 'on_status_changed' ],
			'trashed'        => [ 9109, 'on_trashed' ],
			'restored'       => [ 9110, 'on_untrashed' ],
			'post restored'  => [ 9110, 'on_post_untrashed' ],
			'before delete'  => [ 9102, 'on_before_delete' ],
		];
	}

	/**
	 * The other handlers that read an order are just as safe.
	 *
	 * @dataProvider order_hooks
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_every_order_hook_reports_instead_of_throwing( int $code, string $handler ): void {
		$this->stubs();
		$order                         = new class( 5 ) extends FakeOrder {
			public function get_order_number(): string {
				throw new \RuntimeException( 'A filter broke.' );
			}
		};
		OrderNoteState::$orders[5]     = $order;
		OrderNoteState::$post_types[5] = 'shop_order';

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		switch ( $handler ) {
			case 'on_created':
			case 'on_before_delete':
				$sensor->$handler( 5, $order );
				break;
			case 'on_status_changed':
				$sensor->on_status_changed( 5, 'trash', 'processing', $order );
				break;
			case 'on_trashed':
				$sensor->on_trashed( 5 );
				break;
			default:
				$sensor->$handler( 5, 'wc-processing' );
		}

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 'hellolog_dispatch_failed', OrderNoteState::$fired[0][0] ?? null );
		$this->assertSame( $code, OrderNoteState::$fired[0][1][1] ?? null );
	}

	/**
	 * A new order tells its total and currency in its vars and how it was
	 * placed and paid in its metadata.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_new_order_tells_its_total_currency_channel_and_payment_method(): void {
		$this->stubs();
		$order = $this->order(
			5,
			[],
			[],
			[
				'total'          => '25.00',
				'currency'       => 'EUR',
				'created_via'    => 'checkout',
				'payment_method' => 'bacs',
			]
		);

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_created( 5, $order );

		$payloads = $this->build( $recorder );
		$this->assertSame(
			[
				'id'       => '5',
				'total'    => '25.00',
				'currency' => 'EUR',
			],
			$payloads[0]['vars']
		);
		$this->assertSame(
			[
				'order_id'       => 5,
				'created_via'    => 'checkout',
				'payment_method' => 'bacs',
			],
			$payloads[0]['metadata']
		);
		$this->assertSame( 'WC order #5 created.', $payloads[0]['message'] );
	}

	/**
	 * Details the order does not have are left out, not sent empty.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_new_order_without_details_sends_none(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_created( 5, $this->order( 5 ) );

		$payloads = $this->build( $recorder );
		$this->assertSame( [ 'id' => '5' ], $payloads[0]['vars'] );
		$this->assertSame( [ 'order_id' => 5 ], $payloads[0]['metadata'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_status_change_carries_the_status_as_a_change(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_status_changed( 5, 'pending', 'processing', $this->order( 5 ) );

		$this->assertCount( 1, $recorder->calls );
		$payloads = $this->build( $recorder );
		$this->assertSame( 9101, $payloads[0]['code'] );
		$this->assertSame( [ self::change( 'status', 'pending', 'processing' ) ], $payloads[0]['changes'] );
		$this->assertSame( 'processing', $payloads[0]['vars']['new'] );
		$this->assertSame( 'pending', $payloads[0]['vars']['old'] );
		$this->assertSame( 'WC order #5 status: pending → processing.', $payloads[0]['message'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_order_moved_to_the_trash_is_told(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_trashed( 5 );

		$payloads = $this->build( $recorder );
		$this->assertSame( 9109, $payloads[0]['code'] );
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, 'A-17' ), $payloads[0]['target'] );
		$this->assertSame( [ 'edit' => 'https://shop.test/wp-admin/order/5' ], $payloads[0]['links'] );
		$this->assertSame( [ 'order_id' => 5 ], $payloads[0]['metadata'] );
		$this->assertSame( 'WC order #5 moved to trash.', $payloads[0]['message'] );
		$this->assertSame( 'medium', $payloads[0]['severity'] );
		$this->assertSame( 'trashed', $payloads[0]['event_type'] );
	}

	/**
	 * WooCommerce's own restore names the status the order goes back to
	 * the way it stores it (`wc-processing`); the event names it by its
	 * slug.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_order_restored_from_the_trash_tells_its_status_before_the_trash(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_untrashed( 5, 'wc-processing' );

		$payloads = $this->build( $recorder );
		$this->assertSame( 9110, $payloads[0]['code'] );
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, 'A-17' ), $payloads[0]['target'] );
		$this->assertSame(
			[
				'order_id'        => 5,
				'previous_status' => 'processing',
			],
			$payloads[0]['metadata']
		);
		$this->assertSame( 'WC order #5 restored from trash.', $payloads[0]['message'] );
		$this->assertSame( 'low', $payloads[0]['severity'] );
		$this->assertSame( 'restored', $payloads[0]['event_type'] );
	}

	/**
	 * An order stored as a post, restored on the classic order screen, only
	 * comes back through WordPress's own restore of the post.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_order_post_restored_by_wordpress_is_a_restored_order(): void {
		$this->stubs();
		OrderNoteState::$orders[5]     = $this->order( 5 );
		OrderNoteState::$post_types[5] = 'shop_order';
		OrderNoteState::$post_types[6] = 'post';

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_post_untrashed( 5, 'wc-on-hold' );
		$sensor->on_post_untrashed( 6, 'publish' );
		$sensor->on_post_untrashed( 7, 'publish' );

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( 9110, $recorder->calls[0][0] );
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, 'A-17' ), $recorder->calls[0][1]['target'] );
		$this->assertSame( 'on-hold', $recorder->calls[0][1]['metadata']['previous_status'] );
	}

	/**
	 * A status change out of the trash is a restore, whichever way it came
	 * (a status update through the REST API fires no restore hook): the
	 * restore is told, the status change is not told again.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_status_change_out_of_the_trash_is_a_restore(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_status_changed( 5, 'trash', 'processing', $this->order( 5 ) );

		$this->assertSame( [ 9101, 9110 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 'processing', $recorder->calls[1][1]['metadata']['previous_status'] );
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, 'A-17' ), $recorder->calls[1][1]['target'] );

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 9110 ], array_column( $survivors, 'code' ) );
	}

	/**
	 * WooCommerce's restore (or WordPress's restore of the order post)
	 * followed by its status change out of the trash is one restore.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_restore_and_its_status_change_are_one_restore(): void {
		$this->stubs();
		OrderNoteState::$orders[5]     = $this->order( 5 );
		OrderNoteState::$post_types[5] = 'shop_order';

		foreach ( [ 'on_untrashed', 'on_post_untrashed' ] as $restore ) {
			$recorder = $this->recorder();
			$sensor   = new WooCommerceOrderSensor( $recorder );
			$sensor->$restore( 5, 'wc-processing' );
			$sensor->on_status_changed( 5, 'trash', 'processing', $this->order( 5 ) );

			$survivors = $this->survive( $recorder );
			$this->assertSame( [ 9110 ], array_column( $survivors, 'code' ), $restore );
			$this->assertSame( 'processing', $survivors[0]['metadata']['previous_status'], $restore );
		}
	}

	/**
	 * An order put back from the trash and edited in the same save keeps
	 * its edits: the restore carries them, also when WordPress restored the
	 * order post before WooCommerce saved the order (an order stored as a
	 * post restored in code): still one restore.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_restore_carries_the_edits_of_its_save(): void {
		$this->stubs();
		OrderNoteState::$orders[5]     = $this->order( 5 );
		OrderNoteState::$post_types[5] = 'shop_order';

		foreach ( [ false, true ] as $post_restored_first ) {
			$recorder = $this->recorder();
			$sensor   = new WooCommerceOrderSensor( $recorder );
			$order    = $this->order(
				5,
				self::stored( [ 'status' => 'trash' ] ),
				[
					'status'  => 'processing',
					'billing' => [ 'city' => 'Szeged' ],
				]
			);
			if ( $post_restored_first ) {
				$sensor->on_post_untrashed( 5, 'wc-processing' );
			}
			$sensor->on_before_save( $order );
			$sensor->on_status_changed( 5, 'trash', 'processing', $order );

			$survivors = $this->survive( $recorder );
			$this->assertSame( [ 9110 ], array_column( $survivors, 'code' ) );
			$this->assertSame( [ self::change( 'billing.city', null, null ) ], $survivors[0]['changes'] );
		}
	}

	/**
	 * An order edited and then trashed or deleted in the same request is one
	 * event, the trash or the delete, and it carries the edit: moving an
	 * order to the trash cannot hide a change of its total.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_edit_of_an_order_trashed_or_deleted_in_the_same_request_is_carried_by_it(): void {
		$this->stubs();
		OrderNoteState::$orders[5] = $this->order( 5 );

		foreach ( [
			'on_trashed' => 9109,
			'on_deleted' => 9102,
		] as $hook => $code ) {
			$recorder = $this->recorder();
			$sensor   = new WooCommerceOrderSensor( $recorder );
			$sensor->on_before_save( $this->order( 5, self::stored(), [ 'total' => '30.00' ] ) );
			$sensor->$hook( 5 );

			$survivors = $this->survive( $recorder );
			$this->assertSame( [ $code ], array_column( $survivors, 'code' ), $hook );
			$this->assertSame( [ self::change( 'total', '25.00', '30.00' ) ], $survivors[0]['changes'], $hook );
		}
	}

	/**
	 * The customer's contact details (names, company, address, e-mail,
	 * phone) and the customer's note are told as changed, never with their
	 * values; what the order is worth and how it was paid stay readable.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_customers_details_are_told_without_their_values(): void {
		$this->stubs();
		$stored  = self::stored(
			[
				'billing'       => [
					'first_name' => 'Anna',
					'last_name'  => 'Kovacs',
					'company'    => 'Kovacs Ltd',
					'address_1'  => 'Fo utca 1',
					'address_2'  => '2nd floor',
					'city'       => 'Budapest',
					'state'      => 'BU',
					'postcode'   => '1011',
					'country'    => 'HU',
					'email'      => 'anna@example.test',
					'phone'      => '+36301234567',
				],
				'shipping'      => [
					'first_name' => 'Anna',
					'address_1'  => 'Fo utca 1',
					'city'       => 'Budapest',
					'postcode'   => '1011',
					'phone'      => '+36301234567',
				],
				'customer_note' => 'Leave it at the door',
			]
		);
		$pending = [
			'billing'        => [
				'first_name' => 'Bea',
				'last_name'  => 'Nagy',
				'company'    => 'Nagy Bt',
				'address_1'  => 'Kossuth ter 9',
				'address_2'  => 'Door 4',
				'city'       => 'Szeged',
				'state'      => 'CS',
				'postcode'   => '6720',
				'country'    => 'AT',
				'email'      => 'bea@example.com',
				'phone'      => '+36709876543',
			],
			'shipping'       => [
				'first_name' => 'Bea',
				'address_1'  => 'Kossuth ter 9',
				'city'       => 'Szeged',
				'postcode'   => '6720',
				'phone'      => '+36709876543',
			],
			'customer_note'  => 'Ring at Nagy',
			'transaction_id' => 'txn_1001',
			'total'          => '30.00',
		];

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_before_save( $this->order( 5, $stored, $pending ) );

		$payloads = $this->build( $recorder );
		$changes  = array_column( $payloads[0]['changes'], null, 'field' );
		foreach ( [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' ] as $key ) {
			$this->assertSame( self::change( 'billing.' . $key, null, null ), $changes[ 'billing.' . $key ] ?? null, 'billing.' . $key );
		}
		foreach ( [ 'first_name', 'address_1', 'city', 'postcode', 'phone' ] as $key ) {
			$this->assertSame( self::change( 'shipping.' . $key, null, null ), $changes[ 'shipping.' . $key ] ?? null, 'shipping.' . $key );
		}
		$this->assertSame( self::change( 'customer_note', null, null ), $changes['customer_note'] );
		$this->assertSame( self::change( 'transaction_id', '', 'txn_1001' ), $changes['transaction_id'] );
		$this->assertSame( self::change( 'total', '25.00', '30.00' ), $changes['total'] );

		$json = (string) json_encode( $payloads[0] );
		foreach ( [ $stored['billing'], $stored['shipping'], $pending['billing'], $pending['shipping'] ] as $values ) {
			foreach ( $values as $key => $value ) {
				$this->assertStringNotContainsString( json_encode( $value ), $json, $key );
			}
		}
		foreach ( [ 'Leave it at the door', 'Ring at Nagy', 'anna@example', 'bea@example', '36301234567', '36709876543', 'Kovacs', 'Nagy' ] as $value ) {
			$this->assertStringNotContainsString( $value, $json );
		}
	}

	/**
	 * A new order tells nothing of the customer's contact details, whatever
	 * the order can answer about them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_new_order_tells_nothing_of_the_customers_contact_details(): void {
		$this->stubs();
		$details = [
			'total'          => '25.00',
			'payment_method' => 'bacs',
		];
		$order   = new class( 5, 'A-17', null, self::stored(), [], $details ) extends FakeOrder {
			public function get_billing_email(): string {
				return 'anna@example.test';
			}

			public function get_billing_phone(): string {
				return '+36301234567';
			}

			public function get_formatted_billing_full_name(): string {
				return 'Anna Kovacs';
			}

			public function get_formatted_billing_address(): string {
				return 'Fo utca 1, Budapest';
			}
		};

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_created( 5, $order );

		$json = (string) json_encode( $this->build( $recorder )[0] );
		foreach ( [ 'anna@example', '36301234567', 'Anna', 'Kovacs', 'Fo utca', 'Budapest' ] as $value ) {
			$this->assertStringNotContainsString( $value, $json );
		}
		$this->assertStringContainsString( '"payment_method":"bacs"', $json );
	}

	/**
	 * By the time WooCommerce says an order was deleted it can no longer be
	 * loaded: the number is read just before the delete.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deleted_order_is_labelled_with_the_number_read_before_the_delete(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_before_delete( 5, $this->order( 5 ) );
		$sensor->on_deleted( 5 );

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( 9102, $recorder->calls[0][0] );
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, 'A-17' ), $recorder->calls[0][1]['target'] );
		$this->assertArrayNotHasKey( 'links', $recorder->calls[0][1] );
	}

	/**
	 * The number read before a delete belongs to that order on that site:
	 * the same id on another site, or a later delete of the same id, falls
	 * back to the id.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_number_read_before_a_delete_is_only_used_for_that_order_on_that_site(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_before_delete( 5, $this->order( 5 ) );
		OrderNoteState::$blog_id = 2;
		$sensor->on_deleted( 5 );
		OrderNoteState::$blog_id = 1;
		$sensor->on_deleted( 6 );
		$sensor->on_deleted( 5 );
		$sensor->on_deleted( 5 );

		$this->assertSame(
			[ '#5', '#6', '#A-17', '#5' ],
			array_map( static fn( array $call ): string => $call[1]['target']['label'], $recorder->calls )
		);
	}

	/**
	 * Each hook reaches its handler with the arguments WooCommerce and
	 * WordPress pass.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_sensor_listens_to_the_order_hooks_with_their_arguments(): void {
		$this->stubs();

		$sensor = new WooCommerceOrderSensor( $this->recorder() );
		$sensor->boot();

		$this->assertSame(
			[
				'woocommerce_new_order'                => [ 'on_created', 2 ],
				'woocommerce_before_order_object_save' => [ 'on_before_save', 1 ],
				'woocommerce_order_status_changed'     => [ 'on_status_changed', 4 ],
				'woocommerce_trash_order'              => [ 'on_trashed', 1 ],
				'woocommerce_untrash_order'            => [ 'on_untrashed', 2 ],
				'untrashed_post'                       => [ 'on_post_untrashed', 2 ],
				'woocommerce_before_delete_order'      => [ 'on_before_delete', 2 ],
				'woocommerce_delete_order'             => [ 'on_deleted', 1 ],
				'woocommerce_order_note_added'         => [ 'on_note_added', 2 ],
				'woocommerce_order_refunded'           => [ 'on_refunded', 2 ],
			],
			array_map( static fn( array $action ): array => [ $action['callback'][1], $action['accepted_args'] ], OrderNoteState::$actions )
		);
		foreach ( OrderNoteState::$actions as $hook => $action ) {
			$this->assertSame( $sensor, $action['callback'][0], $hook );
		}
	}

	/**
	 * WooCommerce's own delete also deletes the order's post with
	 * WordPress's delete (an order stored as a post): the shop's delete event
	 * is the one kept.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_delete_by_woocommerce_is_one_delete_event(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_before_delete( 5, $this->order( 5 ) );
		// What the post sensor dispatches for the order's post, on its target.
		$recorder->dispatch(
			2005,
			[
				'post'      => [
					'id'     => 5,
					'type'   => 'shop_order',
					'status' => 'trash',
				],
				'target'    => ContentSensor::object_target( 'shop_order', 5, 'Order', true ),
				'title'     => 'Order',
				'post_type' => 'Shop order',
			]
		);
		$sensor->on_deleted( 5 );

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 9102 ], array_column( $survivors, 'code' ) );
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, 'A-17' ), $survivors[0]['target'] );
	}

	/**
	 * Ids come as text from some callers (the REST API's note routes, other
	 * plugins); a handler takes them, and never fails on an argument of
	 * another type before it can report.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_hooks_take_ids_as_text_and_never_fail_on_an_argument(): void {
		$this->stubs();
		OrderNoteState::$orders[5]     = $this->order( 5 );
		OrderNoteState::$post_types[5] = 'shop_order';

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$order    = $this->order( 5, self::stored(), [ 'total' => '30.00' ] );
		$sensor->on_created( '5', $order );
		$sensor->on_status_changed( '5', 'pending', 'processing', $order );
		$sensor->on_trashed( '5' );
		$sensor->on_untrashed( '5', 'wc-processing' );
		$sensor->on_post_untrashed( '5', 'wc-processing' );
		$sensor->on_before_delete( '5', $order );
		$sensor->on_deleted( '5' );
		$sensor->on_note_added( '11', $order );
		$sensor->on_refunded( '5', '900' );

		$this->assertSame( [ 9100, 9101, 9109, 9110, 9110, 9102, 9103, 9104 ], array_column( $recorder->calls, 0 ) );
		foreach ( $recorder->calls as [ $code, $fields ] ) {
			$this->assertSame( 5, $fields['id'], (string) $code );
			$this->assertSame( '5', $fields['target']['id'], (string) $code );
		}
		$this->assertSame( '#A-17', $recorder->calls[5][1]['target']['label'] );
		$this->assertSame( 11, $recorder->calls[6][1]['metadata']['note_id'] );
		$this->assertSame( 900, $recorder->calls[7][1]['metadata']['refund_id'] );

		$recorder->calls = [];
		foreach ( [ null, [ 5 ], new \stdClass(), 5.0, true ] as $odd ) {
			$sensor->on_created( $odd, $odd );
			$sensor->on_status_changed( $odd, $odd, $odd, $odd );
			$sensor->on_trashed( $odd );
			$sensor->on_untrashed( $odd, $odd );
			$sensor->on_post_untrashed( $odd, $odd );
			$sensor->on_before_delete( $odd, $odd );
			$sensor->on_deleted( $odd );
			$sensor->on_note_added( $odd, $odd );
			$sensor->on_refunded( $odd, $odd );
		}
		$this->assertSame( [], OrderNoteState::$fired );
		foreach ( $recorder->calls as [ $code, $fields ] ) {
			$this->assertSame( 0, $fields['id'], (string) $code );
			$this->assertArrayNotHasKey( 'target', $fields, (string) $code );
		}
	}

	/**
	 * Reading the stored order can fail too: it is reported, never thrown.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_stored_order_that_fails_to_be_read_never_breaks_its_save(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_before_save( $this->unreadable_order( [ 'total' => '30.00' ] ) );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 'hellolog_dispatch_failed', OrderNoteState::$fired[0][0] ?? null );
		$this->assertSame( 9105, OrderNoteState::$fired[0][1][1] ?? null );
		$this->assertSame( [ 'order_id' => 5 ], OrderNoteState::$fired[0][1][2] ?? null );
	}

	/**
	 * A save that changes none of the order's fields never reads the stored
	 * order (that loads every item of the order).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_without_field_changes_never_reads_the_stored_order(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_before_save(
			$this->unreadable_order(
				[
					'date_modified' => new \DateTimeImmutable( '2026-10-26T10:00:00+00:00' ),
					'version'       => '11.2.1',
				]
			)
		);

		$this->assertSame( [], OrderNoteState::$fired );
		$this->assertSame( [ 9105 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [], $recorder->calls[0][1]['changes'] );
	}

	/**
	 * Load the stand-ins and reset what they answer.
	 */
	private function stubs(): void {
		require_once __DIR__ . '/Fixtures/wordpress-order-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-site-functions.php';

		OrderNoteState::$comments     = [];
		OrderNoteState::$comment_meta = [];
		OrderNoteState::$orders       = [];
		OrderNoteState::$translations = [];
		OrderNoteState::$actions      = [];
		OrderNoteState::$statuses     = OrderNoteState::DEFAULT_STATUSES;
		OrderNoteState::$blog_id      = 1;
		OrderNoteState::$post_types   = [];
		OrderNoteState::$fired        = [];

		$_SERVER['HTTP_HOST'] = 'www.shop.example.com';
	}

	/**
	 * An order numbered `A-17`, as stored and as a save is about to write it.
	 *
	 * @param int                  $id      Order id.
	 * @param array<string, mixed> $data    Stored values.
	 * @param array<string, mixed> $changes Values the save is about to write.
	 * @param array<string, mixed> $details Details of a new order.
	 */
	private function order( int $id, array $data = [], array $changes = [], array $details = [] ): FakeOrder {
		return new FakeOrder( $id, 'A-17', null, $data, $changes, $details );
	}

	/**
	 * Order 5, whose stored values cannot be read (`get_data()` throws).
	 *
	 * @param array<string, mixed> $changes Values the save is about to write.
	 */
	private function unreadable_order( array $changes ): FakeOrder {
		return new class( 5, 'A-17', null, [], $changes ) extends FakeOrder {
			public function get_data(): array {
				throw new \RuntimeException( 'The stored order could not be read.' );
			}
		};
	}

	/**
	 * What `get_data()` of a processing order holds, with some values
	 * replaced.
	 *
	 * @param array<string, mixed> $over Values to replace.
	 * @return array<string, mixed>
	 */
	private static function stored( array $over = [] ): array {
		return array_merge(
			[
				'id'                   => 5,
				'status'               => 'processing',
				'currency'             => 'EUR',
				'discount_total'       => '0.00',
				'shipping_total'       => '0.00',
				'total'                => '25.00',
				'customer_id'          => 0,
				'billing'              => [
					'first_name' => 'Anna',
					'city'       => 'Budapest',
					'email'      => 'anna@example.test',
				],
				'shipping'             => [
					'first_name' => 'Anna',
					'city'       => 'Budapest',
					'postcode'   => '1011',
				],
				'payment_method'       => 'bacs',
				'payment_method_title' => 'Direct bank transfer',
				'transaction_id'       => '',
				'customer_note'        => '',
				'date_created'         => new \DateTimeImmutable( '2026-10-20T08:00:00+00:00' ),
				'date_paid'            => null,
				'date_completed'       => null,
			],
			$over
		);
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
	 * Build what the recorder saw.
	 *
	 * @param EventDispatcher $recorder The recording dispatcher.
	 * @param string          $source   Source of the request.
	 * @param int|null        $user_id  Logged-in user; null for nobody.
	 * @return array<int, array<string, mixed>> The payloads, before any rule.
	 */
	private function build( EventDispatcher $recorder, string $source = RequestSource::ADMIN, ?int $user_id = 1 ): array {
		$catalog = new EventCatalog();
		CatalogSeeder::seed( $catalog );

		$context = null === $user_id
			? new RequestContext( null, null, [], null, null, null )
			: new RequestContext( $user_id, 'admin', [ 'administrator' ], null, null, null );

		$builder = new class( $catalog, $source, $context ) extends EventBuilder {

			public function __construct( EventCatalog $catalog, string $source, private RequestContext $context ) {
				parent::__construct( $catalog, false, static fn(): string => $source );
			}

			protected function current_context(): RequestContext {
				return $this->context;
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
	 * Build what the recorder saw, as an admin on the dashboard, and run the
	 * default per-request rules over it.
	 *
	 * @param EventDispatcher $recorder The recording dispatcher.
	 * @return array<int, array<string, mixed>> The payloads that would be written.
	 */
	private function survive( EventDispatcher $recorder ): array {
		return SuppressionRules::defaults()->apply( $this->build( $recorder ) );
	}
}
