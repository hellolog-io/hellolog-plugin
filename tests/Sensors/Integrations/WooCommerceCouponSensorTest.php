<?php
/**
 * Tests for what the coupon sensor tells about a coupon's creation, fields
 * and delete.
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
use HelloLog\Sensors\Core\ContentSensor;
use HelloLog\Sensors\Integrations\WooCommerceCouponSensor;
use HelloLog\Sensors\StoreTargets;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Core\Fixtures\Hooks;
use HelloLog\Tests\Sensors\Core\Fixtures\TermState;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use HelloLog\Tests\Sensors\Integrations\Fixtures\FakeProduct;
use PHPUnit\Framework\TestCase;

/**
 * A save of an existing coupon tells which of its fields changed (9201),
 * whether the shop or WordPress wrote them; a new coupon is a creation
 * (9200) and its first saves tell nothing more; a coupon deleted for good is
 * 9202. Every event names the coupon as its target.
 *
 * The sensor calls WordPress, so every test that runs it runs in its own
 * process with the stand-ins of the post sensor tests.
 */
final class WooCommerceCouponSensorTest extends TestCase {

	/**
	 * An amount and an expiry date changed in one save: one event, two
	 * changes. The coupon is told by what it is, never asked for its type
	 * (a coupon has none).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_changed_amount_and_expiry_are_one_edit_event_with_two_changes(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceCouponSensor( $recorder ) )->on_before_save(
			new \WC_Coupon(
				8,
				self::stored(),
				[
					'amount'       => '25',
					'date_expires' => new \DateTimeImmutable( '2026-12-31T00:00:00+01:00' ),
				]
			)
		);

		$this->assertSame( [], WpCalls::$actions, 'nothing failed' );
		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9201, $code );
		$this->assertSame(
			[
				self::change( 'amount', '10', '25' ),
				self::change( 'date_expires', null, '2026-12-30T23:00:00+00:00' ),
			],
			$fields['changes']
		);
		$this->assertSame( self::target( '8', 'summer10' ), $fields['target'] );
		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=8&action=edit' ], $fields['links'] );

		$payloads = $this->build( $recorder );
		$this->assertSame( 'WC coupon "summer10" updated.', $payloads[0]['message'] );
		$this->assertSame( self::target( '8', 'summer10' ), $payloads[0]['target'] );
		$this->assertSame( 8, $payloads[0]['metadata']['coupon_id'] );
	}

	/**
	 * The shop saves other objects through the same hook (webhooks, for
	 * one): only a coupon is a coupon.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_object_that_is_no_coupon_is_ignored(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceCouponSensor( $recorder ) )->on_before_save(
			new FakeProduct( 8, self::stored(), [ 'amount' => '25' ] )
		);

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( [], WpCalls::$actions );
	}

	/**
	 * How often a coupon was used, and by whom, is the shop's bookkeeping,
	 * not an edit (and who used it is the customers' business): a save that
	 * changes only that is no edit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_that_changes_only_the_usage_is_no_edit(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceCouponSensor( $recorder ) )->on_before_save(
			new \WC_Coupon(
				8,
				self::stored(),
				[
					'usage_count'   => 4,
					'used_by'       => [ 'customer@example.com' ],
					'date_modified' => new \DateTimeImmutable( '2026-11-01T00:00:00+00:00' ),
				]
			)
		);

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( [], $this->survive_calls( $recorder->calls ) );
	}

	/**
	 * A coupon that is being inserted has no id yet: nothing of it is an
	 * edit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_of_a_coupon_without_an_id_is_no_edit(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceCouponSensor( $recorder ) )->on_before_save( new \WC_Coupon( 0, [], [ 'amount' => '25' ] ) );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * Each field of the coupon is told, in a fixed order; the description and
	 * the customers' e-mail addresses it is limited to are told as changed,
	 * without their values. The usage bookkeeping and the meta are not
	 * among them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_only_the_coupon_fields_are_told(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceCouponSensor( $recorder ) )->on_before_save(
			new \WC_Coupon(
				8,
				self::stored(),
				[
					'description'            => 'Winter sale.',
					'email_restrictions'     => [ 'vip@example.com' ],
					'exclude_sale_items'     => true,
					'maximum_amount'         => '500',
					'minimum_amount'         => '50',
					'free_shipping'          => true,
					'limit_usage_to_x_items' => 2,
					'usage_limit_per_user'   => 1,
					'usage_limit'            => 100,
					'individual_use'         => true,
					'date_expires'           => new \DateTimeImmutable( '2026-12-31T00:00:00+00:00' ),
					'discount_type'          => 'fixed_cart',
					'amount'                 => '25',
					'status'                 => 'draft',
					'code'                   => 'summer25',
					'usage_count'            => 4,
					'used_by'                => [ '7' ],
					'virtual'                => true,
					'meta_data'              => [
						(object) [
							'key'   => '_secret_key',
							'value' => 'sk_live_1',
						],
					],
					'date_modified'          => new \DateTimeImmutable( '2026-11-01T00:00:00+00:00' ),
				]
			)
		);

		$this->assertSame(
			[
				self::change( 'code', 'summer10', 'summer25' ),
				self::change( 'status', 'publish', 'draft' ),
				self::change( 'amount', '10', '25' ),
				self::change( 'discount_type', 'percent', 'fixed_cart' ),
				self::change( 'date_expires', null, '2026-12-31T00:00:00+00:00' ),
				self::change( 'individual_use', 'no', 'yes' ),
				self::change( 'usage_limit', null, '100' ),
				self::change( 'usage_limit_per_user', null, '1' ),
				self::change( 'limit_usage_to_x_items', null, '2' ),
				self::change( 'free_shipping', 'no', 'yes' ),
				self::change( 'minimum_amount', '', '50' ),
				self::change( 'maximum_amount', '', '500' ),
				self::change( 'email_restrictions', null, null ),
				self::change( 'exclude_sale_items', 'no', 'yes' ),
				self::change( 'description', null, null ),
			],
			$recorder->calls[0][1]['changes']
		);
		$this->assertSame( self::target( '8', 'summer25' ), $recorder->calls[0][1]['target'] );
		$this->assertStringNotContainsString( 'sk_live_1', (string) wp_json_encode( $this->build( $recorder ) ) );
		$this->assertStringNotContainsString( 'example.com', (string) wp_json_encode( $this->build( $recorder ) ) );
	}

	/**
	 * A value written again in another form is no change: an amount with or
	 * without its decimals, an empty value where none was, the same moment in
	 * another time zone, and a limit of 0 where there was none (the classic
	 * coupon screen writes `0` for every empty limit). A save that changes
	 * nothing is not dispatched.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_same_value_in_another_form_is_no_change(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceCouponSensor( $recorder ) )->on_before_save(
			new \WC_Coupon(
				8,
				self::stored(
					[
						'amount'               => '10.00',
						'minimum_amount'       => null,
						'maximum_amount'       => '100',
						'date_expires'         => new \DateTimeImmutable( '2026-12-30T23:00:00+00:00' ),
						'usage_limit'          => '',
						'usage_limit_per_user' => 0,
						'product_ids'          => [ 5 ],
					]
				),
				[
					'amount'                 => '10',
					'minimum_amount'         => '',
					'maximum_amount'         => '100.00',
					'date_expires'           => new \DateTimeImmutable( '2026-12-31T00:00:00+01:00' ),
					'usage_limit'            => 0,
					'usage_limit_per_user'   => null,
					'limit_usage_to_x_items' => 0,
					'product_ids'            => [ '5' ],
					'email_restrictions'     => [],
				]
			)
		);

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * The products and categories a coupon is limited to (or excludes) are
	 * named, not numbered; an id that names nothing is shown as `#<id>`.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_products_and_categories_are_told_by_their_names(): void {
		$this->stubs();
		self::product( 5, 'Mug', 'product' );
		self::product( 55, 'Hoodie - Blue', 'product_variation' );
		self::term( 3, 'Mugs', 'product_cat' );
		self::term( 4, 'Sale', 'product_cat' );

		$recorder = $this->recorder();
		( new WooCommerceCouponSensor( $recorder ) )->on_before_save(
			new \WC_Coupon(
				8,
				self::stored(
					[
						'product_ids'                 => [ 5 ],
						'excluded_product_categories' => [ 4 ],
					]
				),
				[
					'product_ids'                 => [ 5, 55 ],
					'excluded_product_ids'        => [ 99 ],
					'product_categories'          => [ 3 ],
					'excluded_product_categories' => [],
				]
			)
		);

		$this->assertSame(
			[
				self::change( 'product_ids', 'Mug', 'Hoodie - Blue, Mug' ),
				self::change( 'excluded_product_ids', '', '#99' ),
				self::change( 'product_categories', '', 'Mugs' ),
				self::change( 'excluded_product_categories', 'Sale', '' ),
			],
			$recorder->calls[0][1]['changes']
		);
	}

	/**
	 * The same products and categories, in another order or with their ids
	 * written as text, are no change, and they are compared by their ids
	 * before anything is read to name them: a product or category that can
	 * not be read does not stand in the way of the other changes.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_products_and_categories_are_named_only_when_they_changed(): void {
		$this->stubs();
		WpState::$posts[5]      = self::broken_post();
		WpState::$posts[6]      = self::broken_post();
		TermState::$reads_throw = true;

		$recorder = $this->recorder();
		( new WooCommerceCouponSensor( $recorder ) )->on_before_save(
			new \WC_Coupon(
				8,
				self::stored(
					[
						'product_ids'        => [ 5, 6 ],
						'product_categories' => [ 3, 4 ],
					]
				),
				[
					'product_ids'        => [ '6', 5 ],
					'product_categories' => [ 4, 3 ],
					'amount'             => '25',
				]
			)
		);

		$this->assertSame( [], WpCalls::$actions, 'nothing was read to name them' );
		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( [ self::change( 'amount', '10', '25' ) ], $recorder->calls[0][1]['changes'] );
	}

	/**
	 * The e-mail addresses a coupon is limited to are the customers'
	 * personal data: a change of them is told, the addresses never.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_customer_addresses_are_told_without_their_values(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceCouponSensor( $recorder ) )->on_before_save(
			new \WC_Coupon(
				8,
				self::stored( [ 'email_restrictions' => [ 'anna@example.com' ] ] ),
				[ 'email_restrictions' => [ 'anna@example.com', 'bela@example.com' ] ]
			)
		);

		$this->assertSame( [ self::change( 'email_restrictions', null, null ) ], $recorder->calls[0][1]['changes'] );
		$this->assertStringNotContainsString( 'example.com', (string) wp_json_encode( $this->build( $recorder ) ) );
	}

	/**
	 * The shop filters empty entries out of the address list, which can leave
	 * gaps in its keys: the same addresses in another order or under other
	 * keys are no change.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_same_addresses_under_other_keys_are_no_change(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceCouponSensor( $recorder ) )->on_before_save(
			new \WC_Coupon(
				8,
				self::stored(
					[
						'email_restrictions' => [
							1 => 'bela@example.com',
							3 => 'anna@example.com',
						],
					]
				),
				[ 'email_restrictions' => [ 'anna@example.com', 'bela@example.com' ] ]
			)
		);

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * A save of a coupon WordPress still keeps as an editor placeholder is
	 * part of its creation: nothing of it is an edit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_of_an_auto_draft_coupon_is_no_edit(): void {
		$this->stubs();
		$placeholder = self::post(
			[
				'post_status'  => 'auto-draft',
				'post_title'   => 'Auto Draft',
				'post_excerpt' => '',
			]
		);

		$recorder = $this->boot_sensors();
		Hooks::fire(
			'woocommerce_before_data_object_save',
			new \WC_Coupon(
				8,
				self::stored(
					[
						'status' => 'auto-draft',
						'code'   => 'Auto Draft',
					]
				),
				[ 'amount' => '25' ]
			),
			null
		);
		// WordPress writes the placeholder and keeps it one.
		self::update_post( $placeholder, self::post( [ 'post_status' => 'auto-draft' ] ) );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * WordPress titles a coupon it keeps as an editor placeholder
	 * `Auto Draft`: such a coupon, and one without a code, is labelled with
	 * its id.
	 */
	public function test_a_coupon_without_a_real_code_is_labelled_with_its_id(): void {
		$this->assertSame( self::target( '8', '#8' ), StoreTargets::coupon( 8, StoreTargets::COUPON_PLACEHOLDER ) );
		$this->assertSame( self::target( '8', '#8' ), StoreTargets::coupon( 8, '' ) );
		$this->assertSame( self::target( '8', 'auto draft' ), StoreTargets::coupon( 8, 'auto draft' ) );
	}

	/**
	 * The classic coupon screen's "Add coupon" makes a placeholder, and its
	 * first save writes the code, description and status through WordPress
	 * before the shop saves the coupon: that is the creation of the coupon,
	 * nothing of it an edit. WooCommerce fires no creation hook of its own in
	 * this flow.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_first_save_of_a_new_coupon_is_its_creation(): void {
		$this->stubs();

		$recorder = $this->boot_sensors();
		self::update_post(
			self::post(
				[
					'post_status'  => 'auto-draft',
					'post_title'   => 'Auto Draft',
					'post_excerpt' => '',
				]
			),
			self::post(),
			[
				new \WC_Coupon(
					8,
					self::stored(),
					[
						'discount_type'          => 'fixed_cart',
						'amount'                 => '25',
						'limit_usage_to_x_items' => 0,
					]
				),
			]
		);

		$this->assertSame( [ 9200 ], array_column( $recorder->calls, 0 ) );
		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 9200 ], array_column( $survivors, 'code' ) );
		$this->assertSame( self::target( '8', 'summer10' ), $survivors[0]['target'] );
		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=8&action=edit' ], $survivors[0]['links'] );
		$this->assertSame( 'WC coupon "summer10" created.', $survivors[0]['message'] );
		$this->assertArrayNotHasKey( 'changes', $survivors[0] );
	}

	/**
	 * A coupon created through the shop (the REST API, an import) is
	 * inserted by WordPress and then announced by WooCommerce: one creation.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_coupon_inserted_and_announced_by_the_shop_is_one_creation(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceCouponSensor( $recorder );
		$sensor->on_post_status( 'publish', 'new', self::post() );
		$sensor->on_created( 8, new \WC_Coupon( 8, self::stored() ) );
		$sensor->on_before_save( new \WC_Coupon( 8, self::stored(), [ 'amount' => '25' ] ) );

		$this->assertSame( [ 9200 ], array_column( $recorder->calls, 0 ) );
	}

	/**
	 * WooCommerce's own creation hook names and links the coupon.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_coupon_announced_by_the_shop_is_a_creation_with_its_target(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceCouponSensor( $recorder ) )->on_created( 8, new \WC_Coupon( 8, self::stored() ) );

		$this->assertSame( [ 9200 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( self::target( '8', 'summer10' ), $recorder->calls[0][1]['target'] );
		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=8&action=edit' ], $recorder->calls[0][1]['links'] );
		$this->assertSame( 'WC coupon "summer10" created.', $this->build( $recorder )[0]['message'] );
	}

	/**
	 * A creation hook that passes no coupon names it by its post.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_creation_without_the_coupon_is_named_by_its_post(): void {
		$this->stubs();
		WpState::$posts[8] = self::post();

		$recorder = $this->recorder();
		( new WooCommerceCouponSensor( $recorder ) )->on_created( 8 );

		$this->assertSame( self::target( '8', 'summer10' ), $recorder->calls[0][1]['target'] );
	}

	/**
	 * Core gives no edit link when nobody who may edit the coupon is logged
	 * in (a cron job, an import): the link is built anyway.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_edit_link_is_there_without_an_editing_user(): void {
		$this->stubs();
		WpState::$can_edit = false;

		$recorder = $this->recorder();
		$sensor   = new WooCommerceCouponSensor( $recorder );
		$sensor->on_created( 8, new \WC_Coupon( 8, self::stored() ) );
		$sensor->on_before_save( new \WC_Coupon( 9, self::stored( [ 'id' => 9 ] ), [ 'amount' => '25' ] ) );

		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=8&action=edit' ], $recorder->calls[0][1]['links'] );
		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=9&action=edit' ], $recorder->calls[1][1]['links'] );
	}

	/**
	 * A coupon deleted for good names its target and has nothing left to
	 * open. Other posts are not the shop's coupons.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_coupon_deleted_for_good_is_told_on_its_target(): void {
		$this->stubs();
		WpState::$posts[9] = self::post(
			[
				'ID'         => 9,
				'post_title' => 'winter5',
			]
		);

		$recorder = $this->recorder();
		$sensor   = new WooCommerceCouponSensor( $recorder );
		$sensor->on_delete_post( 8, self::post( [ 'post_status' => 'trash' ] ) );
		$sensor->on_delete_post( 9 );
		$sensor->on_delete_post(
			7,
			self::post(
				[
					'ID'        => 7,
					'post_type' => 'post',
				]
			)
		);
		$sensor->on_delete_post(
			5,
			self::post(
				[
					'ID'        => 5,
					'post_type' => 'product',
				]
			)
		);

		$this->assertSame( [ 9202, 9202 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( self::target( '8', 'summer10' ), $recorder->calls[0][1]['target'] );
		$this->assertSame( self::target( '9', 'winter5' ), $recorder->calls[1][1]['target'] );
		$this->assertArrayNotHasKey( 'links', $recorder->calls[0][1] );
		$payloads = $this->build( $recorder );
		$this->assertSame( 'WC coupon "summer10" deleted.', $payloads[0]['message'] );
		$this->assertSame( 8, $payloads[0]['metadata']['coupon_id'] );
	}

	/**
	 * WordPress's own cleanup of stale placeholders deletes nothing anybody
	 * made; any other delete of a placeholder is told, labelled with its id
	 * (its title is WordPress's placeholder, in the site's language).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wordpress_cleaning_up_placeholders_deletes_no_coupon(): void {
		$this->stubs();
		$placeholder = self::post(
			[
				'post_status' => 'auto-draft',
				'post_title'  => 'Automatischer Entwurf',
			]
		);

		$recorder = $this->recorder();
		$sensor   = new WooCommerceCouponSensor( $recorder );

		WpState::$doing_action = 'wp_scheduled_auto_draft_delete';
		$sensor->on_delete_post( 8, $placeholder );
		$this->assertSame( [], $recorder->calls );

		WpState::$doing_action = null;
		$sensor->on_delete_post( 8, $placeholder );
		$this->assertSame( [ 9202 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( self::target( '8', '#8' ), $recorder->calls[0][1]['target'] );
	}

	/**
	 * The classic coupon screen writes the code, description and status
	 * through WordPress before the shop saves the coupon, so the shop's save
	 * never sees them; it does see an empty item limit written as `0`. What
	 * WordPress wrote and what the shop saved are one edit event, named by
	 * the new code.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_classic_screen_save_is_one_edit_with_what_wordpress_and_the_shop_wrote(): void {
		$this->stubs();

		$recorder = $this->boot_sensors();
		self::update_post(
			self::post(),
			self::post(
				[
					'post_title'   => 'summer25',
					'post_name'    => 'summer25',
					'post_excerpt' => 'Bigger summer sale.',
					'post_status'  => 'draft',
				]
			),
			[
				new \WC_Coupon(
					8,
					self::stored(
						[
							'code'        => 'summer25',
							'description' => 'Bigger summer sale.',
							'status'      => 'draft',
						]
					),
					[
						'amount'                 => '25',
						'limit_usage_to_x_items' => 0,
					]
				),
			]
		);

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 9201 ], array_column( $survivors, 'code' ) );
		// One change per field, in the order the writes told them: the status
		// change, the post, the shop's save.
		$this->assertSame(
			[
				self::change( 'status', 'publish', 'draft' ),
				self::change( 'code', 'summer10', 'summer25' ),
				self::change( 'description', null, null ),
				self::change( 'amount', '10', '25' ),
			],
			$survivors[0]['changes']
		);
		$this->assertSame( self::target( '8', 'summer25' ), $survivors[0]['target'] );
		$this->assertSame( 'WC coupon "summer25" updated.', $survivors[0]['message'] );
		foreach ( $recorder->calls as [ $code, $fields ] ) {
			$this->assertSame( 'summer25', $fields['target']['label'], "code $code" );
		}
	}

	/**
	 * A rename through the shop (its REST API, a CRUD save) is told by the
	 * shop's save and again by WordPress's write of the post: one edit,
	 * named by the new code.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_rename_through_the_shop_is_one_edit_under_the_new_code(): void {
		$this->stubs();

		$recorder = $this->boot_sensors();
		Hooks::fire(
			'woocommerce_before_data_object_save',
			new \WC_Coupon(
				8,
				self::stored(),
				[
					'code'        => 'summer25',
					'status'      => 'draft',
					'description' => 'Bigger summer sale.',
					'amount'      => '25',
				]
			),
			null
		);
		self::update_post(
			self::post(),
			self::post(
				[
					'post_title'   => 'summer25',
					'post_excerpt' => 'Bigger summer sale.',
					'post_status'  => 'draft',
				]
			)
		);

		$this->assertSame( [ 9201, 9201, 9201 ], array_column( $recorder->calls, 0 ) );
		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 9201 ], array_column( $survivors, 'code' ) );
		$this->assertSame(
			[
				self::change( 'code', 'summer10', 'summer25' ),
				self::change( 'status', 'publish', 'draft' ),
				self::change( 'amount', '10', '25' ),
				self::change( 'description', null, null ),
			],
			$survivors[0]['changes']
		);
		$this->assertSame( self::target( '8', 'summer25' ), $survivors[0]['target'] );
	}

	/**
	 * The classic screen keeps the code as typed in the post title, while the
	 * shop reads and stores every code in lower case: a coupon typed
	 * `SUMMER10` is `summer10` in every event of the request, whichever write
	 * tells it, and in the same request's creation.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_code_typed_in_capitals_is_named_the_way_the_shop_stores_it(): void {
		$this->stubs();
		$typed = static fn( array $over = [] ): \WP_Post => self::post( array_merge( [ 'post_title' => 'SUMMER10' ], $over ) );

		$recorder = $this->boot_sensors();
		// An edit of the amount, then one of the status.
		self::update_post( $typed(), $typed(), [ new \WC_Coupon( 8, self::stored(), [ 'amount' => '25' ] ) ] );
		self::update_post( $typed(), $typed( [ 'post_status' => 'draft' ] ), [ new \WC_Coupon( 8, self::stored( [ 'status' => 'draft' ] ), [] ) ] );
		// A new coupon typed in capitals, then deleted.
		self::update_post(
			$typed(
				[
					'ID'          => 9,
					'post_status' => 'auto-draft',
					'post_title'  => 'Auto Draft',
				]
			),
			$typed(
				[
					'ID'         => 9,
					'post_title' => 'WINTER5',
				]
			)
		);
		Hooks::fire( 'before_delete_post', 8, $typed( [ 'post_status' => 'trash' ] ) );

		$this->assertSame( [ 9201, 9201, 9200, 9202 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ 'summer10', 'summer10', 'winter5', 'summer10' ], array_map( static fn( array $call ): string => $call[1]['target']['label'], $recorder->calls ) );
		$this->assertSame( [ self::change( 'amount', '10', '25' ) ], $recorder->calls[0][1]['changes'] );
		$this->assertSame( [ self::change( 'status', 'publish', 'draft' ) ], $recorder->calls[1][1]['changes'] );
		$this->assertSame( 'WC coupon "summer10" updated.', $this->build( $recorder )[0]['message'] );
	}

	/**
	 * A save through the shop (its REST API, a CRUD save) of a coupon typed
	 * in capitals writes the code back to the post in lower case: no change
	 * of the code, only what the save changed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_shop_writing_the_code_back_in_lower_case_is_no_change(): void {
		$this->stubs();

		$recorder = $this->boot_sensors();
		Hooks::fire( 'woocommerce_before_data_object_save', new \WC_Coupon( 8, self::stored(), [ 'description' => 'Bigger summer sale.' ] ), null );
		self::update_post(
			self::post( [ 'post_title' => 'SUMMER10' ] ),
			self::post( [ 'post_excerpt' => 'Bigger summer sale.' ] )
		);

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 9201 ], array_column( $survivors, 'code' ) );
		$this->assertSame( [ self::change( 'description', null, null ) ], $survivors[0]['changes'] );
		$this->assertSame( self::target( '8', 'summer10' ), $survivors[0]['target'] );
	}

	/**
	 * A code typed again in other capitals is the same code to the shop: no
	 * change.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_rename_in_other_capitals_is_no_change_of_the_code(): void {
		$this->stubs();

		$recorder = $this->boot_sensors();
		self::update_post( self::post(), self::post( [ 'post_title' => 'Summer10' ] ), [ new \WC_Coupon( 8, self::stored(), [] ) ] );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * A coupon sent back to `auto-draft` is the post sensor's revert, on the
	 * coupon's target and named by its code; an edit in the same save goes
	 * into it, without the status.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_coupon_reverted_to_auto_draft_is_one_revert_with_its_edits(): void {
		$this->stubs();

		$recorder = $this->boot_sensors();
		Hooks::fire(
			'woocommerce_before_data_object_save',
			new \WC_Coupon(
				8,
				self::stored(),
				[
					'status' => 'auto-draft',
					'amount' => '25',
				]
			),
			null
		);
		self::update_post( self::post(), self::post( [ 'post_status' => 'auto-draft' ] ) );

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 2013 ], array_column( $survivors, 'code' ) );
		$this->assertSame( self::target( '8', 'summer10' ), $survivors[0]['target'] );
		$this->assertSame( 'Shop coupon "summer10" reverted to auto-draft.', $survivors[0]['message'] );
		$this->assertSame( [ self::change( 'amount', '10', '25' ) ], $survivors[0]['changes'] );
	}

	/**
	 * A status change WordPress writes (publish, unpublish, schedule) is told
	 * as the coupon's status.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_status_change_written_by_wordpress_is_an_edit_of_the_status(): void {
		$this->stubs();

		$recorder = $this->boot_sensors();
		self::update_post( self::post(), self::post( [ 'post_status' => 'draft' ] ), [ new \WC_Coupon( 8, self::stored( [ 'status' => 'draft' ] ), [] ) ] );
		// A scheduled coupon going live: WordPress fires no update of the post.
		Hooks::fire( 'transition_post_status', 'publish', 'future', self::post( [ 'ID' => 9 ] ) );

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 9201, 9201 ], array_column( $survivors, 'code' ) );
		$this->assertSame( [ self::change( 'status', 'publish', 'draft' ) ], $survivors[0]['changes'] );
		$this->assertSame( [ self::change( 'status', 'future', 'publish' ) ], $survivors[1]['changes'] );
		$this->assertSame( '9', $survivors[1]['target']['id'] );
	}

	/**
	 * Moving a coupon to the trash is the trash event of the post sensor on
	 * the coupon's target: the status is no edit next to it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_trashed_coupon_is_one_trash_event(): void {
		$this->stubs();

		$recorder = $this->boot_sensors();
		self::update_post(
			self::post(),
			self::post(
				[
					'post_status' => 'trash',
					'post_name'   => 'summer10__trashed',
				]
			)
		);

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 2003 ], array_column( $survivors, 'code' ) );
		$this->assertSame( self::target( '8', 'summer10' ), $survivors[0]['target'] );
		$this->assertArrayNotHasKey( 'changes', $survivors[0] );
	}

	/**
	 * A save through the shop that changes the amount and moves the coupon
	 * to the trash: the trash event carries the amount, not the status; the
	 * same for a restore.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_edit_in_the_save_that_trashes_or_restores_the_coupon_goes_into_that_event(): void {
		$this->stubs();

		$recorder = $this->boot_sensors();
		Hooks::fire(
			'woocommerce_before_data_object_save',
			new \WC_Coupon(
				8,
				self::stored(),
				[
					'amount' => '25',
					'status' => 'trash',
				]
			),
			null
		);
		self::update_post( self::post(), self::post( [ 'post_status' => 'trash' ] ) );

		Hooks::fire(
			'woocommerce_before_data_object_save',
			new \WC_Coupon(
				9,
				self::stored(
					[
						'id'     => 9,
						'status' => 'trash',
					]
				),
				[
					'discount_type' => 'fixed_cart',
					'status'        => 'draft',
				]
			),
			null
		);
		self::update_post(
			self::post(
				[
					'ID'          => 9,
					'post_status' => 'trash',
				]
			),
			self::post(
				[
					'ID'          => 9,
					'post_status' => 'draft',
				]
			)
		);

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 2003, 2004 ], array_column( $survivors, 'code' ) );
		$this->assertSame( [ self::change( 'amount', '10', '25' ) ], $survivors[0]['changes'] );
		$this->assertSame( [ self::change( 'discount_type', 'percent', 'fixed_cart' ) ], $survivors[1]['changes'] );
	}

	/**
	 * Edits of a coupon say nothing of their own next to its creation or its
	 * permanent delete in the same request.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_edit_next_to_a_delete_is_dropped(): void {
		$this->stubs();

		$recorder = $this->boot_sensors();
		Hooks::fire( 'woocommerce_before_data_object_save', new \WC_Coupon( 8, self::stored(), [ 'amount' => '25' ] ), null );
		Hooks::fire( 'before_delete_post', 8, self::post() );

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 9202 ], array_column( $survivors, 'code' ) );
		$this->assertArrayNotHasKey( 'changes', $survivors[0] );
	}

	/**
	 * A coupon created on one site of a network is not created on another.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_creation_is_kept_per_site(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceCouponSensor( $recorder );
		$sensor->on_created( 8, new \WC_Coupon( 8, self::stored() ) );

		WpState::$blog_id = 2;
		$sensor->on_before_save( new \WC_Coupon( 8, self::stored(), [ 'amount' => '25' ] ) );

		$this->assertSame( [ 9200, 9201 ], array_column( $recorder->calls, 0 ) );
	}

	/**
	 * A coupon an Action Scheduler job created is made when the job ends: a
	 * later job of the same run (the command line runs many in one process)
	 * that edits it logs the edit. One the request created before a job ran
	 * inside it is still being made after that job.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_coupon_created_by_a_job_is_edited_by_a_later_job_of_the_same_run(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceCouponSensor( $recorder );
		$sensor->on_created( 9, new \WC_Coupon( 9, self::stored( [ 'code' => 'winter5' ] ) ) );

		ActionSchedulerContext::begin( 41 );
		$sensor->on_created( 8, new \WC_Coupon( 8, self::stored() ) );
		$sensor->on_before_save( new \WC_Coupon( 8, self::stored(), [ 'amount' => '15' ] ) );
		$sensor->flush_job_state();
		ActionSchedulerContext::end( 41 );

		ActionSchedulerContext::begin( 42 );
		$sensor->on_before_save( new \WC_Coupon( 8, self::stored(), [ 'amount' => '25' ] ) );
		$sensor->on_before_save( new \WC_Coupon( 9, self::stored( [ 'code' => 'winter5' ] ), [ 'amount' => '25' ] ) );
		$sensor->flush_job_state();
		ActionSchedulerContext::end( 42 );
		$sensor->flush_job_state();

		$this->assertSame( [ 9200, 9200, 9201 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( '8', $recorder->calls[2][1]['target']['id'] );
	}

	/**
	 * The shop's save of a coupon runs before it writes it, with no guard
	 * around its hooks: whatever fails while reading the coupon is reported,
	 * never thrown.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_coupon_that_fails_to_answer_never_breaks_its_save(): void {
		$this->stubs();
		$coupon = new class( 8 ) extends \WC_Coupon {
			public function get_changes(): array {
				throw new \TypeError( 'A filter broke.' );
			}
		};

		$recorder = $this->recorder();
		( new WooCommerceCouponSensor( $recorder ) )->on_before_save( $coupon );

		$this->assertSame( [], $recorder->calls );
		$this->assertCount( 1, WpCalls::$actions );
		[ $hook, $args ] = WpCalls::$actions[0];
		$this->assertSame( 'hellolog_dispatch_failed', $hook );
		$this->assertInstanceOf( \TypeError::class, $args[0] );
		$this->assertSame( 9201, $args[1] );
		$this->assertSame( [ 'coupon_id' => 8 ], $args[2] );
	}

	/**
	 * @return array<string, array{0: int, 1: string}> Code the handler works on, handler.
	 */
	public static function coupon_hooks(): array {
		return [
			'created'      => [ 9200, 'on_created' ],
			'saved'        => [ 9201, 'on_before_save' ],
			'status'       => [ 9201, 'on_post_status' ],
			'post updated' => [ 9201, 'on_post_updated' ],
			'post deleted' => [ 9202, 'on_delete_post' ],
		];
	}

	/**
	 * The other handlers are just as safe.
	 *
	 * @dataProvider coupon_hooks
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_every_coupon_hook_reports_instead_of_throwing( int $code, string $handler ): void {
		$this->stubs();
		$broken                 = self::broken_post();
		WpState::$posts[8]      = $broken;
		TermState::$reads_throw = true;
		$coupon                 = new class( 8, self::stored(), [ 'product_categories' => [ 3 ] ] ) extends \WC_Coupon {
			/**
			 * @param string $context Not used.
			 * @return mixed
			 * @throws \RuntimeException Always: a filter on the code broke.
			 */
			public function get_code( string $context = 'view' ) {
				throw new \RuntimeException( 'A filter broke.' );
			}
		};

		$recorder = $this->recorder();
		$sensor   = new WooCommerceCouponSensor( $recorder );
		switch ( $handler ) {
			case 'on_created':
				$sensor->on_created( 8, $coupon );
				break;
			case 'on_before_save':
				$sensor->on_before_save( $coupon );
				break;
			case 'on_post_status':
				$sensor->on_post_status( 'draft', 'publish', $broken );
				break;
			case 'on_post_updated':
				$sensor->on_post_updated( 8, $broken, $broken );
				break;
			default:
				$sensor->on_delete_post( 8, $broken );
		}

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 'hellolog_dispatch_failed', WpCalls::$actions[0][0] ?? null );
		$this->assertSame( $code, WpCalls::$actions[0][1][1] ?? null );
		$this->assertSame( [ 'coupon_id' => 8 ], WpCalls::$actions[0][1][2] ?? null );
	}

	/**
	 * Hooks may pass ids as text; anything else they pass is ignored, never a
	 * failure.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_hooks_take_ids_as_text_and_never_fail_on_an_argument(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceCouponSensor( $recorder );
		$sensor->on_created( '8', new \WC_Coupon( 8, self::stored() ) );
		$sensor->on_delete_post( '9', self::post( [ 'ID' => 9 ] ) );

		$sensor->on_created( [], null );
		$sensor->on_created( 0, null );
		$sensor->on_before_save( null );
		$sensor->on_before_save( 'coupon' );
		$sensor->on_post_status( [], null, 'post' );
		$sensor->on_post_updated( 'x', null, null );
		$sensor->on_post_updated( 8, self::post(), null );
		$sensor->on_delete_post( [], null );
		$sensor->on_delete_post( 0, self::post() );

		$this->assertSame( [ 9200, 9202 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( '8', $recorder->calls[0][1]['target']['id'] );
		$this->assertSame( '9', $recorder->calls[1][1]['target']['id'] );
		$this->assertSame( [], WpCalls::$actions );
	}

	/**
	 * Coupons have no save hook of their own: the shop saves them through
	 * the hook of every data object. The hook WooCommerce fires after the
	 * save (which tells nothing of what changed) is not used.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_sensor_listens_to_the_coupon_hooks_with_their_arguments(): void {
		$this->stubs();

		$sensor = new WooCommerceCouponSensor( $this->recorder() );
		$sensor->boot();

		// Hook => method, arguments, priority.
		$expected = [
			'woocommerce_new_coupon'              => [ 'on_created', 2, 10 ],
			'woocommerce_before_data_object_save' => [ 'on_before_save', 1, 10 ],
			'transition_post_status'              => [ 'on_post_status', 3, 10 ],
			'post_updated'                        => [ 'on_post_updated', 3, 10 ],
			'before_delete_post'                  => [ 'on_delete_post', 2, 10 ],
			// When a job ends, before its context closes at PHP_INT_MAX.
			'action_scheduler_after_execute'      => [ 'flush_job_state', 0, PHP_INT_MAX - 1 ],
			'action_scheduler_failed_execution'   => [ 'flush_job_state', 0, PHP_INT_MAX - 1 ],
		];
		$this->assertSame( array_keys( $expected ), array_keys( WpState::$callbacks ) );
		foreach ( $expected as $hook => [ $method, $args, $priority ] ) {
			$this->assertCount( 1, WpState::$callbacks[ $hook ], $hook );
			$registered = WpState::$callbacks[ $hook ][0];
			$this->assertSame( [ $sensor, $method ], $registered['callback'], $hook );
			$this->assertSame( $args, $registered['accepted_args'], $hook );
			$this->assertSame( $priority, $registered['priority'], $hook );
		}
	}

	/**
	 * Load the stand-ins of the post sensor tests, the term lookup, the shop,
	 * its coupon and the failure report.
	 */
	private function stubs(): void {
		require_once __DIR__ . '/../Core/Fixtures/wordpress-user-functions.php';
		require_once __DIR__ . '/../Core/Fixtures/wordpress-content-functions.php';
		require_once __DIR__ . '/../Core/Fixtures/wordpress-term-functions.php';
		require_once __DIR__ . '/../Core/Fixtures/woocommerce-class.php';
		require_once __DIR__ . '/Fixtures/woocommerce-coupon-class.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';
	}

	/**
	 * Boot the post sensor and the coupon sensor on one recording
	 * dispatcher, the way a request with the shop active runs them.
	 */
	private function boot_sensors(): EventDispatcher {
		$recorder = $this->recorder();
		( new ContentSensor( $recorder ) )->boot();
		( new WooCommerceCouponSensor( $recorder ) )->boot();

		return $recorder;
	}

	/**
	 * The hooks `wp_update_post()` fires when a coupon that was `$before` is
	 * saved as `$after`, in core's order (`wp_insert_post()`):
	 * `pre_post_update`, the status change and `post_updated` with the new
	 * post, then `save_post`, where the classic screen's box saves the coupon
	 * through the shop (`$saves`), and `wp_after_insert_post`.
	 *
	 * @param \WP_Post               $before The post before the save.
	 * @param \WP_Post               $after  The post as saved.
	 * @param array<int, \WC_Coupon> $saves  The shop's saves of the coupon in `save_post`.
	 */
	private static function update_post( \WP_Post $before, \WP_Post $after, array $saves = [] ): void {
		$id = (int) $after->ID;

		WpState::$posts[ $id ] = $before;
		Hooks::fire(
			'pre_post_update',
			$id,
			[
				'post_type'   => (string) $after->post_type,
				'post_title'  => (string) $after->post_title,
				'post_status' => (string) $after->post_status,
			]
		);

		WpState::$posts[ $id ] = $after;
		Hooks::fire( 'transition_post_status', (string) $after->post_status, (string) $before->post_status, $after );
		Hooks::fire( 'post_updated', $id, $after, $before );
		foreach ( $saves as $coupon ) {
			Hooks::fire( 'woocommerce_before_data_object_save', $coupon, null );
		}
		Hooks::fire( 'wp_after_insert_post', $id, $after, true, $before );
	}

	/**
	 * Post 8, whose id can be read and nothing else (a filter on it throws).
	 */
	private static function broken_post(): \WP_Post {
		return new class( [] ) extends \WP_Post {
			public function __get( string $name ) {
				if ( 'ID' === $name ) {
					return 8;
				}
				throw new \RuntimeException( 'A filter broke.' );
			}
		};
	}

	/**
	 * A product or variation `get_post()` finds.
	 *
	 * @param int    $id    Post id.
	 * @param string $title Its title.
	 * @param string $type  `product` or `product_variation`.
	 */
	private static function product( int $id, string $title, string $type ): void {
		WpState::$posts[ $id ] = new \WP_Post(
			[
				'ID'          => $id,
				'post_title'  => $title,
				'post_type'   => $type,
				'post_status' => 'publish',
			]
		);
	}

	/**
	 * A term `get_term()` finds by its id.
	 *
	 * @param int    $id       Term id.
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy name.
	 */
	private static function term( int $id, string $name, string $taxonomy ): void {
		TermState::$terms[ $id ] = new \WP_Term(
			[
				'term_id'  => $id,
				'name'     => $name,
				'taxonomy' => $taxonomy,
			]
		);
	}

	/**
	 * Coupon 8, "summer10", published, as a post.
	 *
	 * @param array<string, mixed> $over Fields to replace.
	 */
	private static function post( array $over = [] ): \WP_Post {
		return new \WP_Post(
			array_merge(
				[
					'ID'           => 8,
					'post_title'   => 'summer10',
					'post_type'    => 'shop_coupon',
					'post_status'  => 'publish',
					'post_name'    => 'summer10',
					'post_content' => '',
					'post_excerpt' => 'Summer sale.',
					'post_author'  => '1',
					'post_parent'  => 0,
				],
				$over
			)
		);
	}

	/**
	 * What `get_data()` of a published coupon holds, with some values
	 * replaced.
	 *
	 * @param array<string, mixed> $over Values to replace.
	 * @return array<string, mixed>
	 */
	private static function stored( array $over = [] ): array {
		return array_merge(
			[
				'id'                          => 8,
				'code'                        => 'summer10',
				'amount'                      => '10',
				'status'                      => 'publish',
				'date_created'                => new \DateTimeImmutable( '2026-10-20T08:00:00+00:00' ),
				'date_modified'               => new \DateTimeImmutable( '2026-10-20T08:00:00+00:00' ),
				'date_expires'                => null,
				'discount_type'               => 'percent',
				'description'                 => 'Summer sale.',
				'usage_count'                 => 3,
				'individual_use'              => false,
				'product_ids'                 => [],
				'excluded_product_ids'        => [],
				'usage_limit'                 => 0,
				'usage_limit_per_user'        => 0,
				'limit_usage_to_x_items'      => null,
				'free_shipping'               => false,
				'product_categories'          => [],
				'excluded_product_categories' => [],
				'exclude_sale_items'          => false,
				'minimum_amount'              => '',
				'maximum_amount'              => '',
				'email_restrictions'          => [],
				'used_by'                     => [ '5' ],
				'virtual'                     => false,
				'meta_data'                   => [],
			],
			$over
		);
	}

	/**
	 * @return array{type: string, id: string, label: string}
	 */
	private static function target( string $id, string $label ): array {
		return [
			'type'  => 'coupon',
			'id'    => $id,
			'label' => $label,
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
	 * Build what the recorder saw, as an admin on the dashboard.
	 *
	 * @param EventDispatcher $recorder The recording dispatcher.
	 * @return array<int, array<string, mixed>> The payloads, before any rule.
	 */
	private function build( EventDispatcher $recorder ): array {
		return $this->build_calls( $recorder->calls );
	}

	/**
	 * Build dispatches as an admin on the dashboard would.
	 *
	 * @param array<int, array{0: int, 1: array<string, mixed>}> $calls Dispatches as `[ code, fields ]`.
	 * @return array<int, array<string, mixed>> The payloads, before any rule.
	 */
	private function build_calls( array $calls ): array {
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
		foreach ( $calls as [ $code, $fields ] ) {
			$payload = $builder->build( $code, $fields );
			$this->assertIsArray( $payload, "code $code is in the catalog" );
			$payloads[] = $payload;
		}

		return $payloads;
	}

	/**
	 * Build the dispatches of one request and run the default per-request
	 * rules over them.
	 *
	 * @param array<int, array{0: int, 1: array<string, mixed>}> $calls Dispatches as `[ code, fields ]`.
	 * @return array<int, array<string, mixed>> The payloads that would be written.
	 */
	private function survive_calls( array $calls ): array {
		return SuppressionRules::defaults()->apply( $this->build_calls( $calls ) );
	}
}
