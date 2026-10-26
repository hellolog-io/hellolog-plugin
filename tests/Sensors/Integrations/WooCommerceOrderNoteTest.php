<?php
/**
 * Tests for what the order events say about the order and its notes.
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
use HelloLog\Sensors\Integrations\WooCommerceOrderSensor;
use HelloLog\Tests\Sensors\Integrations\Fixtures\OrderNoteState;
use PHPUnit\Framework\TestCase;

/**
 * An order note event carries the text and the kind of the note, and every
 * order event names its order as `target` and links to the order's edit
 * screen.
 *
 * The sensor calls WordPress and WooCommerce, so every test that does runs in
 * its own process with the stand-ins of `Fixtures/wordpress-order-functions.php`.
 */
final class WooCommerceOrderNoteTest extends TestCase {

	/** The address WooCommerce gives the notes it writes itself. */
	private const SYSTEM_PREFIX = 'woocommerce@';

	public function test_a_customer_note_is_a_customer_note_whoever_wrote_it(): void {
		$this->assertSame( 'customer', WooCommerceOrderSensor::note_type( true, 'woocommerce@shop.hu', self::SYSTEM_PREFIX ) );
		$this->assertSame( 'customer', WooCommerceOrderSensor::note_type( true, 'admin@shop.hu', self::SYSTEM_PREFIX ) );
	}

	public function test_a_note_from_the_shop_address_is_a_system_note(): void {
		$this->assertSame( 'system', WooCommerceOrderSensor::note_type( false, 'woocommerce@shop.hu', self::SYSTEM_PREFIX ) );
	}

	public function test_a_note_from_a_person_is_a_private_note(): void {
		$this->assertSame( 'private', WooCommerceOrderSensor::note_type( false, 'admin@shop.hu', self::SYSTEM_PREFIX ) );
	}

	public function test_a_note_with_no_author_address_is_private(): void {
		$this->assertSame( 'private', WooCommerceOrderSensor::note_type( false, '', self::SYSTEM_PREFIX ) );
	}

	public function test_a_note_is_never_system_without_a_prefix_to_compare_with(): void {
		$this->assertSame( 'private', WooCommerceOrderSensor::note_type( false, 'woocommerce@shop.hu', '' ) );
	}

	public function test_the_prefix_must_start_the_address(): void {
		$this->assertSame( 'private', WooCommerceOrderSensor::note_type( false, 'not-woocommerce@shop.hu', self::SYSTEM_PREFIX ) );
	}

	public function test_an_order_target_has_a_string_id_and_the_order_number_as_label(): void {
		$this->assertSame(
			[
				'type'  => 'order',
				'id'    => '462093',
				'label' => '#A-17',
			],
			WooCommerceOrderSensor::order_target( 462093, 'A-17' )
		);
	}

	public function test_an_order_target_without_a_number_is_labelled_with_the_id(): void {
		$this->assertSame( '#462093', WooCommerceOrderSensor::order_target( 462093, '' )['label'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_note_text_has_its_markup_stripped(): void {
		$this->stubs( true );

		$this->assertSame(
			'Paid by card, ref 42',
			WooCommerceOrderSensor::note_text( "<p>Paid by <b>card</b>, ref 42</p><script>alert('x')</script>\n" )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_note_text_has_its_markup_stripped_without_the_wordpress_helper(): void {
		$this->stubs();

		$this->assertFalse( function_exists( 'wp_strip_all_tags' ) );
		$this->assertSame( 'Paid by card', WooCommerceOrderSensor::note_text( '  <p>Paid by <b>card</b></p> ' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_note_text_over_500_characters_is_cut_to_500_with_an_ellipsis(): void {
		$this->stubs( true );

		$text = WooCommerceOrderSensor::note_text( str_repeat( 'a', 600 ) );

		$this->assertSame( 500, mb_strlen( $text, 'UTF-8' ) );
		$this->assertSame( str_repeat( 'a', 499 ) . '…', $text );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_note_text_of_exactly_500_characters_is_kept_whole(): void {
		$this->stubs( true );

		$this->assertSame( str_repeat( 'a', 500 ), WooCommerceOrderSensor::note_text( str_repeat( 'a', 500 ) ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_note_text_is_cut_on_a_character_not_in_the_middle_of_one(): void {
		$this->stubs( true );

		$text = WooCommerceOrderSensor::note_text( str_repeat( 'ő', 600 ) );

		$this->assertSame( 500, mb_strlen( $text, 'UTF-8' ) );
		$this->assertSame( str_repeat( 'ő', 499 ) . '…', $text );
		$this->assertTrue( mb_check_encoding( $text, 'UTF-8' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_added_note_carries_its_text_and_type(): void {
		$this->stubs( true );
		$order = $this->order( 462093, '462093', 'https://shop.test/wp-admin/admin.php?page=wc-orders&action=edit&id=462093' );
		$this->note( 11, 'Call the customer back.', 'admin@shop.hu' );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_note_added( 11, $order );

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( 9103, $recorder->calls[0][0] );
		$fields = $recorder->calls[0][1];
		$this->assertSame( 462093, $fields['id'] );
		$this->assertSame( [ 'note' => 'Call the customer back.' ], $fields['vars'] );
		$this->assertSame(
			[
				'order_id'  => 462093,
				'note_id'   => 11,
				'note_type' => 'private',
			],
			$fields['metadata']
		);
		$this->assertSame(
			[
				'type'  => 'order',
				'id'    => '462093',
				'label' => '#462093',
			],
			$fields['target']
		);
		$this->assertSame( [ 'edit' => 'https://shop.test/wp-admin/admin.php?page=wc-orders&action=edit&id=462093' ], $fields['links'] );
		$this->assertArrayNotHasKey( 'actor', $fields );
		$this->assertArrayNotHasKey( 'user', $fields );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_added_note_sends_at_most_500_characters_of_text(): void {
		$this->stubs( true );
		$this->note( 11, str_repeat( 'x', 700 ), 'admin@shop.hu' );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_note_added( 11, $this->order( 5 ) );

		$note = $recorder->calls[0][1]['vars']['note'];
		$this->assertSame( 500, mb_strlen( $note, 'UTF-8' ) );
		$this->assertStringEndsWith( '…', $note );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_added_note_has_its_markup_stripped(): void {
		$this->stubs( true );
		$this->note( 11, '<a href="https://x.test/">Refund</a> <em>agreed</em>', 'admin@shop.hu' );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_note_added( 11, $this->order( 5 ) );

		$this->assertSame( 'Refund agreed', $recorder->calls[0][1]['vars']['note'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_note_written_by_the_shop_is_a_system_note(): void {
		$this->stubs( true );
		$this->note( 11, 'Order status changed from Pending payment to Processing.', 'woocommerce@shop.hu' );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_note_added( 11, $this->order( 5 ) );

		$this->assertSame( 'system', $recorder->calls[0][1]['metadata']['note_type'] );
		$this->assertSame( 'Order status changed from Pending payment to Processing.', $recorder->calls[0][1]['vars']['note'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_note_flagged_for_the_customer_is_a_customer_note(): void {
		$this->stubs( true );
		$this->note( 11, 'Your parcel is on its way.', 'woocommerce@shop.hu', true );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_note_added( 11, $this->order( 5 ) );

		$this->assertSame( 'customer', $recorder->calls[0][1]['metadata']['note_type'] );
	}

	/**
	 * The shop writes its own address with the translated name, so the prefix
	 * to compare with is built the way WooCommerce builds it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_system_address_follows_the_translated_shop_name(): void {
		$this->stubs( true );
		OrderNoteState::$translations['WooCommerce'] = 'WooKereskedelem';
		$this->note( 11, 'Order status changed.', 'wookereskedelem@shop.hu' );
		$this->note( 12, 'Order status changed.', 'woocommerce@shop.hu' );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_note_added( 11, $this->order( 5 ) );
		$sensor->on_note_added( 12, $this->order( 5 ) );

		$this->assertSame( 'system', $recorder->calls[0][1]['metadata']['note_type'] );
		$this->assertSame( 'private', $recorder->calls[1][1]['metadata']['note_type'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_note_that_cannot_be_read_is_still_logged_as_a_private_note(): void {
		$this->stubs( true );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_note_added( 99, $this->order( 5 ) );

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( 'private', $recorder->calls[0][1]['metadata']['note_type'] );
		$this->assertSame( 99, $recorder->calls[0][1]['metadata']['note_id'] );
		$this->assertSame( '', $recorder->calls[0][1]['vars']['note'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_note_without_an_order_object_has_no_target_and_no_link(): void {
		$this->stubs( true );
		$this->note( 11, 'Hello.', 'admin@shop.hu' );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_note_added( 11, null );

		$this->assertCount( 1, $recorder->calls );
		$fields = $recorder->calls[0][1];
		$this->assertArrayNotHasKey( 'target', $fields );
		$this->assertArrayNotHasKey( 'links', $fields );
		$this->assertSame( 'Hello.', $fields['vars']['note'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_new_order_has_a_target_and_an_edit_link(): void {
		$this->stubs( true );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_created( 5, $this->order( 5, 'A-17' ) );

		$this->assertSame( 9100, $recorder->calls[0][0] );
		$fields = $recorder->calls[0][1];
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, 'A-17' ), $fields['target'] );
		$this->assertSame( [ 'edit' => 'https://shop.test/wp-admin/order/5' ], $fields['links'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_new_order_is_looked_up_when_the_hook_passes_no_order_object(): void {
		$this->stubs( true );
		OrderNoteState::$orders[5] = $this->order( 5, 'A-17' );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_created( 5 );

		$fields = $recorder->calls[0][1];
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, 'A-17' ), $fields['target'] );
		$this->assertSame( [ 'edit' => 'https://shop.test/wp-admin/order/5' ], $fields['links'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_status_change_has_a_target_and_an_edit_link(): void {
		$this->stubs( true );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_status_changed( 5, 'pending', 'processing', $this->order( 5, 'A-17' ) );

		$this->assertSame( 9101, $recorder->calls[0][0] );
		$fields = $recorder->calls[0][1];
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, 'A-17' ), $fields['target'] );
		$this->assertSame( [ 'edit' => 'https://shop.test/wp-admin/order/5' ], $fields['links'] );
		$this->assertSame( 'pending', $fields['old'] );
		$this->assertSame( 'processing', $fields['new'] );
	}

	/**
	 * A deleted order is gone: it still names its target, but there is no
	 * screen left to open.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deleted_order_has_a_target_and_no_link(): void {
		$this->stubs( true );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_deleted( 5 );

		$this->assertSame( 9102, $recorder->calls[0][0] );
		$fields = $recorder->calls[0][1];
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, '' ), $fields['target'] );
		$this->assertArrayNotHasKey( 'links', $fields );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_refund_has_a_target_and_an_edit_link_of_the_refunded_order(): void {
		$this->stubs( true );
		OrderNoteState::$orders[5]   = $this->order( 5, 'A-17' );
		OrderNoteState::$orders[900] = new class() {
			public function get_amount(): string {
				return '12.50';
			}
		};

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_refunded( 5, 900 );

		$this->assertSame( 9104, $recorder->calls[0][0] );
		$fields = $recorder->calls[0][1];
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, 'A-17' ), $fields['target'] );
		$this->assertSame( [ 'edit' => 'https://shop.test/wp-admin/order/5' ], $fields['links'] );
		$this->assertSame( '12.50', $fields['amount'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_order_that_cannot_be_loaded_still_has_a_target(): void {
		$this->stubs( true );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_refunded( 5, 900 );

		$fields = $recorder->calls[0][1];
		$this->assertSame( WooCommerceOrderSensor::order_target( 5, '' ), $fields['target'] );
		$this->assertArrayNotHasKey( 'links', $fields );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_order_without_an_edit_link_gets_no_links(): void {
		$this->stubs( true );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_status_changed( 5, 'pending', 'processing', $this->order( 5, 'A-17', '' ) );

		$this->assertArrayNotHasKey( 'links', $recorder->calls[0][1] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_new_order_hook_hands_over_the_order_too(): void {
		$this->stubs( true );

		( new WooCommerceOrderSensor( $this->recorder() ) )->boot();

		$this->assertSame( 2, OrderNoteState::$actions['woocommerce_new_order']['accepted_args'] );
		$this->assertSame( 4, OrderNoteState::$actions['woocommerce_order_status_changed']['accepted_args'] );
		$this->assertSame( 2, OrderNoteState::$actions['woocommerce_order_note_added']['accepted_args'] );
	}

	/**
	 * The message of a note event tells what the note said.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_note_event_message_tells_the_note(): void {
		$this->stubs( true );
		$this->note( 11, 'Call the customer back.', 'admin@shop.hu' );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_note_added( 11, $this->order( 462093 ) );

		$payloads = $this->build( $recorder );

		$this->assertSame( 'Note added to WC order #462093: Call the customer back.', $payloads[0]['message'] );
		$this->assertSame( [ 'id' => '462093', 'note' => 'Call the customer back.' ], $payloads[0]['vars'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_secret_inside_a_note_is_masked_on_the_way_out(): void {
		$this->stubs( true );
		$this->note( 11, 'Webhook set to https://api:s3cr3t-pass@gateway.test/hook', 'admin@shop.hu' );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_note_added( 11, $this->order( 5 ) );

		$payloads = $this->build( $recorder );

		$this->assertStringNotContainsString( 's3cr3t-pass', (string) json_encode( $payloads[0] ) );
		$this->assertStringContainsString( 'gateway.test/hook', $payloads[0]['vars']['note'] );
	}

	/**
	 * One status change writes a note of its own. Only the note the shop
	 * wrote is the same news as the status change; a note a person added
	 * stays.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_status_change_hides_its_own_system_note_but_not_a_private_one(): void {
		$this->stubs( true );
		$order = $this->order( 5, 'A-17' );
		$this->note( 11, 'Order status changed from Pending payment to Processing.', 'woocommerce@shop.hu' );
		$this->note( 12, 'Call the customer back.', 'admin@shop.hu' );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_status_changed( 5, 'pending', 'processing', $order );
		$sensor->on_note_added( 11, $order );
		$sensor->on_note_added( 12, $order );

		$survivors = $this->survive( $recorder );

		$this->assertSame( [ 9101, 9103 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 12, $survivors[1]['metadata']['note_id'] );
		$this->assertSame( 'private', $survivors[1]['metadata']['note_type'] );
		$this->assertSame( 'Note added to WC order #5: Call the customer back.', $survivors[1]['message'] );
		$this->assertSame( $survivors[0]['target'], $survivors[1]['target'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_system_note_of_another_order_is_not_hidden_by_a_status_change(): void {
		$this->stubs( true );
		$this->note( 11, 'Order status changed.', 'woocommerce@shop.hu' );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_status_changed( 5, 'pending', 'processing', $this->order( 5 ) );
		$sensor->on_note_added( 11, $this->order( 6 ) );

		$this->assertSame( [ 9101, 9103 ], array_column( $this->survive( $recorder ), 'code' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_customer_note_is_not_hidden_by_a_status_change(): void {
		$this->stubs( true );
		$order = $this->order( 5 );
		$this->note( 11, 'Your parcel is on its way.', 'woocommerce@shop.hu', true );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_status_changed( 5, 'processing', 'completed', $order );
		$sensor->on_note_added( 11, $order );

		$survivors = $this->survive( $recorder );

		$this->assertSame( [ 9101, 9103 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 'customer', $survivors[1]['metadata']['note_type'] );
	}

	/**
	 * Load the stand-ins and reset what they answer.
	 *
	 * @param bool $with_strip_tags Whether core's `wp_strip_all_tags()` exists.
	 */
	private function stubs( bool $with_strip_tags = false ): void {
		require_once __DIR__ . '/Fixtures/wordpress-order-functions.php';
		if ( $with_strip_tags ) {
			require_once __DIR__ . '/Fixtures/wordpress-strip-tags-function.php';
		}

		OrderNoteState::$comments     = [];
		OrderNoteState::$comment_meta = [];
		OrderNoteState::$orders       = [];
		OrderNoteState::$translations = [];
		OrderNoteState::$actions      = [];
	}

	/**
	 * Put a note where `get_comment()` finds it.
	 *
	 * @param int    $id               Comment id.
	 * @param string $content          Text of the note.
	 * @param string $author_email     Address the note was written under.
	 * @param bool   $is_customer_note Whether the note was meant for the customer.
	 */
	private function note( int $id, string $content, string $author_email, bool $is_customer_note = false ): void {
		OrderNoteState::$comments[ $id ] = (object) [
			'comment_ID'           => $id,
			'comment_content'      => $content,
			'comment_author_email' => $author_email,
		];
		if ( $is_customer_note ) {
			OrderNoteState::$comment_meta[ $id ]['is_customer_note'] = '1';
		}
	}

	/**
	 * A WooCommerce order stand-in.
	 *
	 * @param int         $id     Order id.
	 * @param string|null $number Order number; the id when not given.
	 * @param string|null $edit   Edit screen URL; the stand-in's default when not given.
	 */
	private function order( int $id, ?string $number = null, ?string $edit = null ): object {
		return new class( $id, $number ?? (string) $id, $edit ?? 'https://shop.test/wp-admin/order/' . $id ) {

			public function __construct( private int $id, private string $number, private string $edit ) {
			}

			public function get_id(): int {
				return $this->id;
			}

			public function get_order_number(): string {
				return $this->number;
			}

			public function get_edit_order_url(): string {
				return $this->edit;
			}
		};
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
			$this->assertIsArray( $payload );
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
