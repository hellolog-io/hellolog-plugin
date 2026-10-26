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

	/** The sentences WooCommerce writes into a status-transition note. */
	private const STATUS_FORMATS = [
		'Order status changed from %1$s to %2$s.',
		'Order status set to %s.',
	];

	public function test_a_customer_note_is_a_customer_note_whatever_its_text_and_author(): void {
		$this->assertSame( 'customer', WooCommerceOrderSensor::note_type( true, true, true ) );
		$this->assertSame( 'customer', WooCommerceOrderSensor::note_type( true, false, true ) );
		$this->assertSame( 'customer', WooCommerceOrderSensor::note_type( true, false, false ) );
	}

	public function test_a_status_sentence_is_a_status_note_even_from_the_shop(): void {
		$this->assertSame( 'status', WooCommerceOrderSensor::note_type( false, true, true ) );
		$this->assertSame( 'status', WooCommerceOrderSensor::note_type( false, true, false ) );
	}

	public function test_a_note_from_the_shop_that_is_no_status_sentence_is_a_system_note(): void {
		$this->assertSame( 'system', WooCommerceOrderSensor::note_type( false, false, true ) );
	}

	public function test_every_other_note_is_private(): void {
		$this->assertSame( 'private', WooCommerceOrderSensor::note_type( false, false, false ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function status_sentences(): array {
		return [
			'status changed'            => [ 'Order status changed from Pending payment to Processing.' ],
			'status set'                => [ 'Order status set to Completed.' ],
			'surrounding whitespace'    => [ "  Order status changed from On hold to Cancelled.\n" ],
			'status name with a "to"'   => [ 'Order status changed from Ready to ship to Shipped.' ],
			'status name with a dot'    => [ 'Order status changed from Awaiting pay. to Paid.' ],
		];
	}

	/**
	 * @dataProvider status_sentences
	 */
	public function test_a_note_that_is_exactly_a_status_sentence_is_a_status_note( string $content ): void {
		$this->assertTrue( WooCommerceOrderSensor::is_status_note( $content, self::STATUS_FORMATS ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function not_status_sentences(): array {
		return [
			'custom text before it'      => [ 'Called customer. Order status changed from Pending payment to Processing.' ],
			'a second line'              => [ "Order status changed from Pending payment to Processing.\nCall back." ],
			'another sentence'           => [ 'Payment via Stripe (ch_123).' ],
			'a status name only'         => [ 'Order status changed.' ],
			'an empty note'              => [ '' ],
			'only whitespace'            => [ "  \n" ],
			'markup around it'           => [ '<b>Order status changed from Pending payment to Processing.</b>' ],
			'not valid UTF-8'            => [ "Order status set to \xC3." ],
		];
	}

	/**
	 * @dataProvider not_status_sentences
	 */
	public function test_a_note_with_anything_else_in_it_is_no_status_note( string $content ): void {
		$this->assertFalse( WooCommerceOrderSensor::is_status_note( $content, self::STATUS_FORMATS ) );
	}

	public function test_a_status_sentence_is_recognised_in_the_translated_form(): void {
		$formats = [ 'Rendelés állapota: %1$s → %2$s.', 'Rendelés állapota beállítva: %s.' ];

		$this->assertTrue( WooCommerceOrderSensor::is_status_note( 'Rendelés állapota: Fizetésre vár → Feldolgozás alatt.', $formats ) );
		$this->assertTrue( WooCommerceOrderSensor::is_status_note( 'Rendelés állapota beállítva: Teljesítve.', $formats ) );
		$this->assertFalse( WooCommerceOrderSensor::is_status_note( 'Order status set to Completed.', $formats ) );
	}

	public function test_a_format_that_says_nothing_but_placeholders_matches_no_note(): void {
		$this->assertFalse( WooCommerceOrderSensor::is_status_note( 'Anything at all.', [ '%s', '', ' %1$s %2$s ' ] ) );
	}

	public function test_an_overlong_note_is_no_status_note(): void {
		$this->assertFalse( WooCommerceOrderSensor::is_status_note( 'Order status set to ' . str_repeat( 'a', 1100 ) . '.', self::STATUS_FORMATS ) );
	}

	public function test_only_the_shops_own_name_and_exact_address_make_a_system_note(): void {
		$this->assertTrue( WooCommerceOrderSensor::is_system_note( 'WooCommerce', 'woocommerce@shop.hu', 'WooCommerce', 'woocommerce@shop.hu' ) );
	}

	public function test_the_shops_address_under_another_name_is_no_system_note(): void {
		$this->assertFalse( WooCommerceOrderSensor::is_system_note( 'Mallory', 'woocommerce@shop.hu', 'WooCommerce', 'woocommerce@shop.hu' ) );
	}

	public function test_the_shops_name_under_another_address_is_no_system_note(): void {
		$this->assertFalse( WooCommerceOrderSensor::is_system_note( 'WooCommerce', 'mallory@shop.hu', 'WooCommerce', 'woocommerce@shop.hu' ) );
		$this->assertFalse( WooCommerceOrderSensor::is_system_note( 'WooCommerce', 'woocommerce@evil.test', 'WooCommerce', 'woocommerce@shop.hu' ) );
		$this->assertFalse( WooCommerceOrderSensor::is_system_note( 'WooCommerce', 'woocommerce@shop.hu.evil.test', 'WooCommerce', 'woocommerce@shop.hu' ) );
	}

	public function test_no_note_is_a_system_note_when_the_shop_has_no_address(): void {
		$this->assertFalse( WooCommerceOrderSensor::is_system_note( 'WooCommerce', '', 'WooCommerce', '' ) );
		$this->assertFalse( WooCommerceOrderSensor::is_system_note( '', '', '', '' ) );
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
	public function test_note_text_has_its_character_entities_decoded(): void {
		$this->stubs( true );

		$this->assertSame( 'Pending → Processing', WooCommerceOrderSensor::note_text( 'Pending &rarr; Processing' ) );
		$this->assertSame( 'Tom & Jerry\'s "kit"', WooCommerceOrderSensor::note_text( 'Tom &amp; Jerry&#039;s &quot;kit&quot;' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_note_text_has_a_secret_masked_before_it_is_cut(): void {
		$this->stubs( true );

		// Cut first, the 499 characters would end inside the password, leaving a
		// piece of it with no URL around it to be recognised by.
		$content = str_repeat( 'x', 470 ) . ' https://admin:' . str_repeat( 'hunter2-', 5 ) . '@h/x';
		$this->assertGreaterThan( 500, mb_strlen( $content, 'UTF-8' ) );

		$text = WooCommerceOrderSensor::note_text( $content );

		$this->assertStringNotContainsString( 'hunt', $text );
		$this->assertStringNotContainsString( 'admin', $text );
		$this->assertStringEndsWith( 'https://[redacted]@h/x', $text );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_note_text_masks_a_url_password_that_holds_an_at_sign_and_is_then_cut(): void {
		$this->stubs( true );

		$text = WooCommerceOrderSensor::note_text( str_repeat( 'x', 480 ) . ' https://u:p@ss@h/x ' . str_repeat( 'y', 100 ) );

		$this->assertStringNotContainsString( 'p@ss', $text );
		$this->assertStringNotContainsString( 'u:p', $text );
		$this->assertSame( 500, mb_strlen( $text, 'UTF-8' ) );
		$this->assertStringEndsWith( '…', $text );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_note_text_with_nothing_in_it_reads_empty_note(): void {
		$this->stubs( true );

		$this->assertSame( '(empty note)', WooCommerceOrderSensor::note_text( '' ) );
		$this->assertSame( '(empty note)', WooCommerceOrderSensor::note_text( "  \n " ) );
		$this->assertSame( '(empty note)', WooCommerceOrderSensor::note_text( '<p></p><script>x()</script>' ) );
		$this->assertSame( '(empty note)', WooCommerceOrderSensor::note_text( '&nbsp;&#160;' ) );
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
	public function test_a_note_the_shop_wrote_about_a_payment_is_a_system_note(): void {
		$this->stubs( true );
		$this->shop_note( 11, 'Payment via Stripe (ch_123).' );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_note_added( 11, $this->order( 5 ) );

		$this->assertSame( 'system', $recorder->calls[0][1]['metadata']['note_type'] );
		$this->assertSame( 'Payment via Stripe (ch_123).', $recorder->calls[0][1]['vars']['note'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_status_sentence_is_a_status_note_whoever_wrote_it(): void {
		$this->stubs( true );
		$this->note( 11, 'Order status changed from Pending payment to Processing.', 'admin@shop.hu' );
		$this->shop_note( 12, 'Order status changed from Pending payment to Processing.' );
		$this->note( 13, 'Order status set to Completed.', 'admin@shop.hu' );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		foreach ( [ 11, 12, 13 ] as $id ) {
			$sensor->on_note_added( $id, $this->order( 5 ) );
		}

		$this->assertSame( [ 'status', 'status', 'status' ], array_column( array_column( array_column( $recorder->calls, 1 ), 'metadata' ), 'note_type' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_status_sentence_with_text_of_a_person_in_front_of_it_is_a_private_note(): void {
		$this->stubs( true );
		$this->note( 11, 'Called customer. Order status changed from Pending payment to Processing.', 'admin@shop.hu' );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_note_added( 11, $this->order( 5 ) );

		$this->assertSame( 'private', $recorder->calls[0][1]['metadata']['note_type'] );
		$this->assertSame( 'Called customer. Order status changed from Pending payment to Processing.', $recorder->calls[0][1]['vars']['note'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_note_flagged_for_the_customer_is_a_customer_note(): void {
		$this->stubs( true );
		$this->shop_note( 11, 'Your parcel is on its way.', true );
		$this->shop_note( 12, 'Order status changed from Processing to Completed.', true );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_note_added( 11, $this->order( 5 ) );
		$sensor->on_note_added( 12, $this->order( 5 ) );

		$this->assertSame( 'customer', $recorder->calls[0][1]['metadata']['note_type'] );
		$this->assertSame( 'customer', $recorder->calls[1][1]['metadata']['note_type'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_person_using_the_shops_address_does_not_write_system_notes(): void {
		$this->stubs( true );
		$this->note( 11, 'Payment via Stripe (ch_123).', 'woocommerce@shop.hu', false, 'Mallory' );
		$this->note( 12, 'Payment via Stripe (ch_123).', 'mallory@shop.hu', false, 'WooCommerce' );
		$this->note( 13, 'Payment via Stripe (ch_123).', 'woocommerce@evil.test', false, 'WooCommerce' );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		foreach ( [ 11, 12, 13 ] as $id ) {
			$sensor->on_note_added( $id, $this->order( 5 ) );
		}

		$this->assertSame( [ 'private', 'private', 'private' ], array_column( array_column( array_column( $recorder->calls, 1 ), 'metadata' ), 'note_type' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_shops_address_is_built_from_the_host_the_way_woocommerce_builds_it(): void {
		$this->stubs( true );
		$_SERVER['HTTP_HOST'] = 'www.shop.hu';
		$this->note( 11, 'Stock reduced.', 'woocommerce@shop.hu', false, 'WooCommerce' );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_note_added( 11, $this->order( 5 ) );

		$this->assertSame( 'system', $recorder->calls[0][1]['metadata']['note_type'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_shops_address_without_a_host_uses_the_placeholder_domain(): void {
		$this->stubs( true );
		unset( $_SERVER['HTTP_HOST'] );
		$this->note( 11, 'Stock reduced.', 'woocommerce@noreply.com', false, 'WooCommerce' );
		$this->note( 12, 'Stock reduced.', 'woocommerce@shop.hu', false, 'WooCommerce' );

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
	public function test_the_shop_name_and_the_status_sentences_follow_the_translation(): void {
		$this->stubs( true );
		OrderNoteState::$translations = [
			'WooCommerce'                              => 'WooKereskedelem',
			'Order status changed from %1$s to %2$s.' => 'Rendelés állapota: %1$s → %2$s.',
		];
		$this->note( 11, 'Fizetve.', 'wookereskedelem@shop.hu', false, 'WooKereskedelem' );
		$this->note( 12, 'Fizetve.', 'woocommerce@shop.hu', false, 'WooCommerce' );
		$this->note( 13, 'Rendelés állapota: Fizetésre vár → Feldolgozás alatt.', 'admin@shop.hu' );
		$this->note( 14, 'Order status changed from Pending payment to Processing.', 'admin@shop.hu' );
		$this->note( 15, 'Order status set to Completed.', 'admin@shop.hu' );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		foreach ( [ 11, 12, 13, 14, 15 ] as $id ) {
			$sensor->on_note_added( $id, $this->order( 5 ) );
		}

		$this->assertSame(
			[ 'system', 'private', 'status', 'private', 'status' ],
			array_column( array_column( array_column( $recorder->calls, 1 ), 'metadata' ), 'note_type' )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_shop_name_that_cannot_make_an_address_makes_no_system_note(): void {
		$this->stubs( true );
		OrderNoteState::$translations['WooCommerce'] = 'Бутик';
		$this->note( 11, 'Fizetve.', '', false, 'Бутик' );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_note_added( 11, $this->order( 5 ) );

		$this->assertSame( 'private', $recorder->calls[0][1]['metadata']['note_type'] );
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
		$this->assertSame( '(empty note)', $recorder->calls[0][1]['vars']['note'] );
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
	 * WooCommerce writes "Order status changed from X to Y." for every status
	 * change. When an admin makes the change by hand, the note is filed under
	 * the admin's own name: it says nothing the status change does not.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_status_change_hides_the_transition_note_written_under_the_admins_name(): void {
		$this->stubs( true );
		$order = $this->order( 5, 'A-17' );
		$this->note( 11, 'Order status changed from Pending payment to Processing.', 'admin@shop.hu' );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_status_changed( 5, 'pending', 'processing', $order );
		$sensor->on_note_added( 11, $order );

		$this->assertSame( [ 9101 ], array_column( $this->survive( $recorder ), 'code' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_status_change_hides_the_transition_note_written_by_the_shop(): void {
		$this->stubs( true );
		$order = $this->order( 5, 'A-17' );
		$this->shop_note( 11, 'Order status changed from Pending payment to Processing.' );
		$this->shop_note( 12, 'Order status set to Processing.' );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_status_changed( 5, 'pending', 'processing', $order );
		$sensor->on_note_added( 11, $order );
		$sensor->on_note_added( 12, $order );

		$this->assertSame( [ 9101 ], array_column( $this->survive( $recorder ), 'code' ) );
	}

	/**
	 * The admin's own words in front of the sentence are the news.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_transition_note_with_text_of_the_admin_in_front_of_it_stays(): void {
		$this->stubs( true );
		$order = $this->order( 5, 'A-17' );
		$this->note( 11, 'Called customer. Order status changed from Pending payment to Processing.', 'admin@shop.hu' );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_status_changed( 5, 'pending', 'processing', $order );
		$sensor->on_note_added( 11, $order );

		$survivors = $this->survive( $recorder );

		$this->assertSame( [ 9101, 9103 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 'private', $survivors[1]['metadata']['note_type'] );
		$this->assertSame( 'Called customer. Order status changed from Pending payment to Processing.', $survivors[1]['vars']['note'] );
		$this->assertSame(
			'Note added to WC order #5: Called customer. Order status changed from Pending payment to Processing.',
			$survivors[1]['message']
		);
	}

	/**
	 * What WooCommerce itself notes about payments and stock is information
	 * of its own, not a repeat of the status change.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_payment_note_of_the_shop_stays_next_to_a_status_change(): void {
		$this->stubs( true );
		$order = $this->order( 5, 'A-17' );
		$this->shop_note( 11, 'Payment via Stripe (ch_123).' );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_status_changed( 5, 'pending', 'processing', $order );
		$sensor->on_note_added( 11, $order );

		$survivors = $this->survive( $recorder );

		$this->assertSame( [ 9101, 9103 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 'system', $survivors[1]['metadata']['note_type'] );
		$this->assertSame( 'Note added to WC order #5: Payment via Stripe (ch_123).', $survivors[1]['message'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_status_change_hides_its_transition_note_but_not_the_notes_around_it(): void {
		$this->stubs( true );
		$order = $this->order( 5, 'A-17' );
		$this->shop_note( 11, 'Order status changed from Pending payment to Processing.' );
		$this->note( 12, 'Call the customer back.', 'admin@shop.hu' );
		$this->shop_note( 13, 'Stock levels reduced: Mug (#7) 4→3' );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_status_changed( 5, 'pending', 'processing', $order );
		$sensor->on_note_added( 11, $order );
		$sensor->on_note_added( 12, $order );
		$sensor->on_note_added( 13, $order );

		$survivors = $this->survive( $recorder );

		$this->assertSame( [ 9101, 9103, 9103 ], array_column( $survivors, 'code' ) );
		$this->assertSame( [ 12, 13 ], array_column( array_slice( array_column( $survivors, 'metadata' ), 1 ), 'note_id' ) );
		$this->assertSame( [ 'private', 'system' ], array_column( array_slice( array_column( $survivors, 'metadata' ), 1 ), 'note_type' ) );
		$this->assertSame( 'Note added to WC order #5: Call the customer back.', $survivors[1]['message'] );
		$this->assertSame( $survivors[0]['target'], $survivors[1]['target'] );
	}

	/**
	 * A person who gives themselves the shop's address is still a person.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_note_under_the_shops_address_but_another_name_is_logged_as_private(): void {
		$this->stubs( true );
		$order = $this->order( 5 );
		$this->note( 11, 'Payment via Stripe (ch_123).', 'woocommerce@shop.hu', false, 'Mallory' );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_status_changed( 5, 'pending', 'processing', $order );
		$sensor->on_note_added( 11, $order );

		$survivors = $this->survive( $recorder );

		$this->assertSame( [ 9101, 9103 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 'private', $survivors[1]['metadata']['note_type'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_note_of_another_order_is_not_hidden_by_a_status_change(): void {
		$this->stubs( true );
		$this->shop_note( 11, 'Order status changed from Pending payment to Processing.' );

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
		$this->shop_note( 11, 'Order status changed from Processing to Completed.', true );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceOrderSensor( $recorder );
		$sensor->on_status_changed( 5, 'processing', 'completed', $order );
		$sensor->on_note_added( 11, $order );

		$survivors = $this->survive( $recorder );

		$this->assertSame( [ 9101, 9103 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 'customer', $survivors[1]['metadata']['note_type'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_transition_note_without_a_status_change_stays(): void {
		$this->stubs( true );
		$this->shop_note( 11, 'Order status changed from Processing to Completed.' );

		$recorder = $this->recorder();
		( new WooCommerceOrderSensor( $recorder ) )->on_note_added( 11, $this->order( 5 ) );

		$survivors = $this->survive( $recorder );

		$this->assertSame( [ 9103 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 'status', $survivors[0]['metadata']['note_type'] );
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

		$_SERVER['HTTP_HOST'] = 'www.shop.hu';
	}

	/**
	 * Put a note where `get_comment()` finds it.
	 *
	 * @param int    $id               Comment id.
	 * @param string $content          Text of the note.
	 * @param string $author_email     Address the note was written under.
	 * @param bool   $is_customer_note Whether the note was meant for the customer.
	 * @param string $author           Name the note was written under.
	 */
	private function note( int $id, string $content, string $author_email, bool $is_customer_note = false, string $author = 'Admin' ): void {
		OrderNoteState::$comments[ $id ] = (object) [
			'comment_ID'           => $id,
			'comment_content'      => $content,
			'comment_author'       => $author,
			'comment_author_email' => $author_email,
		];
		if ( $is_customer_note ) {
			OrderNoteState::$comment_meta[ $id ]['is_customer_note'] = '1';
		}
	}

	/**
	 * Put a note written by WooCommerce itself where `get_comment()` finds it:
	 * its name and the address it builds from the host of the request.
	 *
	 * @param int    $id               Comment id.
	 * @param string $content          Text of the note.
	 * @param bool   $is_customer_note Whether the note was meant for the customer.
	 */
	private function shop_note( int $id, string $content, bool $is_customer_note = false ): void {
		$this->note( $id, $content, 'woocommerce@shop.hu', $is_customer_note, 'WooCommerce' );
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
