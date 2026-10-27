<?php
/**
 * Tests for what the product sensor tells about a product's or variation's
 * creation, fields and delete.
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
use HelloLog\Sensors\Integrations\WooCommerceProductSensor;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Core\Fixtures\Hooks;
use HelloLog\Tests\Sensors\Core\Fixtures\TermState;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use HelloLog\Tests\Sensors\Integrations\Fixtures\FakeProduct;
use PHPUnit\Framework\TestCase;

/**
 * A save of an existing product or variation tells which of its fields
 * changed (9001), whether the shop or WordPress wrote them; a new product is
 * a creation (9000) and its first saves tell nothing more; a product deleted
 * for good is 9002. Every event names the product as its target.
 *
 * The sensor calls WordPress, so every test that runs it runs in its own
 * process with the stand-ins of the post sensor tests.
 */
final class WooCommerceProductSensorTest extends TestCase {

	/**
	 * A price and a SKU changed in one save: one event, two changes.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_changed_price_and_sku_are_one_edit_event_with_two_changes(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->on_before_save(
			new FakeProduct(
				5,
				self::stored(),
				[
					'regular_price' => '1200',
					'sku'           => 'MUG-2',
				]
			)
		);

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9001, $code );
		$this->assertSame(
			[
				self::change( 'sku', 'MUG-1', 'MUG-2' ),
				self::change( 'regular_price', '1000', '1200' ),
			],
			$fields['changes']
		);
		$this->assertSame( self::target( '5', 'Mug' ), $fields['target'] );
		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=5&action=edit' ], $fields['links'] );

		$payloads = $this->build( $recorder );
		$this->assertSame( 'WC product "Mug" updated.', $payloads[0]['message'] );
		$this->assertSame( self::target( '5', 'Mug' ), $payloads[0]['target'] );
	}

	/**
	 * A variation is an object of its own: its id is the target, its name
	 * (the parent's with its attributes) the label, and its edit screen the
	 * parent's.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_variation_is_its_own_target_named_with_its_parent(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->on_before_save(
			new FakeProduct( 55, self::stored( [ 'name' => 'Hoodie - Blue' ] ), [ 'regular_price' => '1500' ], 50 )
		);

		$this->assertCount( 1, $recorder->calls );
		$fields = $recorder->calls[0][1];
		$this->assertSame( self::target( '55', 'Hoodie - Blue' ), $fields['target'] );
		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=50&action=edit' ], $fields['links'] );
		$this->assertSame( [ self::change( 'regular_price', '1000', '1500' ) ], $fields['changes'] );
	}

	/**
	 * A product that is being inserted has no id yet: nothing of it is an
	 * edit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_of_a_product_without_an_id_is_no_edit(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->on_before_save( new FakeProduct( 0, [], [ 'regular_price' => '1200' ] ) );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * The descriptions are texts of any length: told as changed, without
	 * their values.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_changed_description_is_told_without_its_values(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->on_before_save(
			new FakeProduct(
				5,
				self::stored(),
				[
					'description'       => 'A much longer text.',
					'short_description' => 'Short and new.',
				]
			)
		);

		$this->assertSame(
			[
				self::change( 'short_description', null, null ),
				self::change( 'description', null, null ),
			],
			$recorder->calls[0][1]['changes']
		);
	}

	/**
	 * Each field of the product is told; the price the shop computes, the
	 * attributes and the meta are not among them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_only_the_product_fields_are_told(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->on_before_save(
			new FakeProduct(
				5,
				self::stored(),
				[
					'name'               => 'Big mug',
					'status'             => 'draft',
					'slug'               => 'big-mug',
					'sale_price'         => '900',
					'date_on_sale_from'  => new \DateTimeImmutable( '2026-11-01T00:00:00+00:00' ),
					'manage_stock'       => false,
					'stock_quantity'     => 7,
					'stock_status'       => 'outofstock',
					'backorders'         => 'notify',
					'low_stock_amount'   => 2,
					'catalog_visibility' => 'hidden',
					'featured'           => true,
					'tax_status'         => 'none',
					'tax_class'          => 'reduced-rate',
					'weight'             => '0.7',
					'length'             => '10',
					'virtual'            => true,
					'downloadable'       => true,
					'purchase_note'      => 'Thanks!',
					'price'              => '900',
					'attributes'         => [ 'pa_color' => 'blue' ],
					'meta_data'          => [ (object) [ 'key' => 'x' ] ],
					'date_modified'      => new \DateTimeImmutable( '2026-11-01T00:00:00+00:00' ),
				]
			)
		);

		$this->assertSame(
			[
				self::change( 'name', 'Mug', 'Big mug' ),
				self::change( 'status', 'publish', 'draft' ),
				self::change( 'slug', 'mug', 'big-mug' ),
				self::change( 'sale_price', '', '900' ),
				self::change( 'date_on_sale_from', null, '2026-11-01T00:00:00+00:00' ),
				self::change( 'manage_stock', 'yes', 'no' ),
				self::change( 'stock_quantity', '10', '7' ),
				self::change( 'stock_status', 'instock', 'outofstock' ),
				self::change( 'backorders', 'no', 'notify' ),
				self::change( 'low_stock_amount', '', '2' ),
				self::change( 'catalog_visibility', 'visible', 'hidden' ),
				self::change( 'featured', 'no', 'yes' ),
				self::change( 'tax_status', 'taxable', 'none' ),
				self::change( 'tax_class', '', 'reduced-rate' ),
				self::change( 'weight', '0.5', '0.7' ),
				self::change( 'length', '', '10' ),
				self::change( 'virtual', 'no', 'yes' ),
				self::change( 'downloadable', 'no', 'yes' ),
				self::change( 'purchase_note', '', 'Thanks!' ),
			],
			$recorder->calls[0][1]['changes']
		);
		$this->assertSame( self::target( '5', 'Big mug' ), $recorder->calls[0][1]['target'] );
	}

	/**
	 * A value written again in another form is no change: an amount with or
	 * without its decimals, an empty value where none was, the same moment in
	 * another time zone. A save that changes nothing is not dispatched.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_same_value_in_another_form_is_no_change(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->on_before_save(
			new FakeProduct(
				5,
				self::stored(
					[
						'regular_price'   => '10.00',
						'sale_price'      => null,
						'date_on_sale_to' => new \DateTimeImmutable( '2026-11-30T23:00:00+00:00' ),
						'weight'          => '0.50',
						'category_ids'    => [ 3 ],
					]
				),
				[
					'regular_price'    => '10',
					'sale_price'       => '',
					'date_on_sale_to'  => new \DateTimeImmutable( '2026-12-01T00:00:00+01:00' ),
					'weight'           => '0.5',
					'category_ids'     => [ '3' ],
					'low_stock_amount' => null,
					'date_modified'    => new \DateTimeImmutable( '2026-11-01T00:00:00+00:00' ),
				]
			)
		);

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * Terms, the shipping class and images are named, not numbered; an id
	 * that names nothing is shown as `#<id>`.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_terms_shipping_class_and_images_are_told_by_their_names(): void {
		$this->stubs();
		self::term( 3, 'Mugs', 'product_cat' );
		self::term( 4, 'Sale', 'product_cat' );
		self::term( 7, 'Blue', 'product_tag' );
		self::term( 9, 'Bulky', 'product_shipping_class' );
		self::image( 21, 'Mug front' );
		self::image( 22, 'Mug side' );
		self::image( 23, 'Mug top' );

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->on_before_save(
			new FakeProduct(
				5,
				self::stored(
					[
						'category_ids'      => [ 3 ],
						'tag_ids'           => [],
						'shipping_class_id' => 0,
						'image_id'          => '21',
						'gallery_image_ids' => [ 22 ],
					]
				),
				[
					'category_ids'      => [ 3, 4 ],
					'tag_ids'           => [ 7, 8 ],
					'shipping_class_id' => 9,
					'image_id'          => 23,
					'gallery_image_ids' => [ 22, 99 ],
				]
			)
		);

		$this->assertSame(
			[
				self::change( 'shipping_class_id', '', 'Bulky' ),
				self::change( 'category_ids', 'Mugs', 'Mugs, Sale' ),
				self::change( 'tag_ids', '', '#8, Blue' ),
				self::change( 'image_id', 'Mug front', 'Mug top' ),
				self::change( 'gallery_image_ids', 'Mug side', '#99, Mug side' ),
			],
			$recorder->calls[0][1]['changes']
		);
	}

	/**
	 * Two different terms of the same name are told apart by their ids.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_another_term_of_the_same_name_is_a_change(): void {
		$this->stubs();
		self::term( 3, 'Sale', 'product_cat' );
		self::term( 4, 'Sale', 'product_cat' );

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->on_before_save(
			new FakeProduct( 5, self::stored( [ 'category_ids' => [ 3 ] ] ), [ 'category_ids' => [ 4 ] ] )
		);

		$this->assertSame( [ self::change( 'category_ids', 'Sale (#3)', 'Sale (#4)' ) ], $recorder->calls[0][1]['changes'] );
	}

	/**
	 * A save of a product WordPress still keeps as an editor placeholder, or
	 * of a variation of one, is part of its creation: nothing of it is an
	 * edit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_of_an_auto_draft_product_or_of_its_variation_is_no_edit(): void {
		$this->stubs();
		WpState::$posts[50] = self::post(
			[
				'ID'          => 50,
				'post_status' => 'auto-draft',
				'post_title'  => 'AUTO-DRAFT',
			]
		);

		$recorder = $this->recorder();
		$sensor   = new WooCommerceProductSensor( $recorder );
		$sensor->on_before_save(
			new FakeProduct(
				50,
				self::stored(
					[
						'status' => 'auto-draft',
						'name'   => 'AUTO-DRAFT',
					]
				),
				[ 'stock_status' => 'outofstock' ]
			)
		);
		$sensor->on_before_save( new FakeProduct( 55, self::stored( [ 'name' => 'AUTO-DRAFT - Blue' ] ), [ 'regular_price' => '1500' ], 50 ) );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * WooCommerce names a product it keeps as an editor placeholder
	 * `AUTO-DRAFT`: such a product, and one without a name, is labelled
	 * with its id.
	 */
	public function test_a_product_without_a_real_name_is_labelled_with_its_id(): void {
		$this->assertSame( self::target( '5', '#5' ), WooCommerceProductSensor::product_target( 5, 'AUTO-DRAFT' ) );
		$this->assertSame( self::target( '5', '#5' ), WooCommerceProductSensor::product_target( 5, '' ) );
		$this->assertSame( self::target( '5', 'Auto-draft mug' ), WooCommerceProductSensor::product_target( 5, 'Auto-draft mug' ) );
	}

	/**
	 * The classic product screen's "Add new" makes a placeholder, and its
	 * first save writes the post (title, terms, status) before the shop saves
	 * the product twice: that is the creation of the product, nothing of it
	 * an edit. WooCommerce fires no creation hook of its own in this flow.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_first_save_of_a_new_product_is_its_creation(): void {
		$this->stubs();
		self::tt_term( 11, 'Mugs', 'product_cat' );

		$recorder = $this->boot_sensors();
		self::update_post(
			self::post(
				[
					'post_status' => 'auto-draft',
					'post_title'  => 'AUTO-DRAFT',
					'post_name'   => '',
				]
			),
			self::post(),
			static fn() => self::set_terms( 5, 'product_cat', [ 11 ] ),
			[
				new FakeProduct( 5, self::stored(), [ 'regular_price' => '1200' ] ),
				new FakeProduct( 5, self::stored(), [ 'gallery_image_ids' => [ 22 ] ] ),
			]
		);

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 9000 ], array_column( $survivors, 'code' ) );
		$this->assertSame( self::target( '5', 'Mug' ), $survivors[0]['target'] );
		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=5&action=edit' ], $survivors[0]['links'] );
		$this->assertSame( 'WC product "Mug" created.', $survivors[0]['message'] );
		$this->assertArrayNotHasKey( 'changes', $survivors[0] );
		$this->assertSame( 1, count( array_keys( array_column( $recorder->calls, 0 ), 9000, true ) ) );
	}

	/**
	 * A product created through the shop (the REST API, an import) is
	 * inserted by WordPress and then announced by WooCommerce: one creation.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_product_inserted_and_announced_by_the_shop_is_one_creation(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceProductSensor( $recorder );
		$sensor->on_post_status( 'publish', 'new', self::post() );
		$sensor->on_created( 5, new FakeProduct( 5, self::stored() ) );
		$sensor->on_before_save( new FakeProduct( 5, self::stored(), [ 'regular_price' => '1200' ] ) );

		$this->assertSame( [ 9000 ], array_column( $recorder->calls, 0 ) );
	}

	/**
	 * WooCommerce's own creation hook names and links the product.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_product_announced_by_the_shop_is_a_creation_with_its_target(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->on_created( 5, new FakeProduct( 5, self::stored() ) );

		$this->assertSame( [ 9000 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( self::target( '5', 'Mug' ), $recorder->calls[0][1]['target'] );
		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=5&action=edit' ], $recorder->calls[0][1]['links'] );
		$this->assertSame( 'WC product "Mug" created.', $this->build( $recorder )[0]['message'] );
	}

	/**
	 * Core gives no edit link when nobody who may edit the product is logged
	 * in (a cron job, a gateway callback): the link is built anyway, to the
	 * parent's screen for a variation.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_edit_link_is_there_without_an_editing_user(): void {
		$this->stubs();
		WpState::$can_edit = false;

		$recorder = $this->recorder();
		$sensor   = new WooCommerceProductSensor( $recorder );
		$sensor->on_created( 5, new FakeProduct( 5, self::stored() ) );
		$sensor->on_before_save( new FakeProduct( 55, self::stored( [ 'name' => 'Hoodie - Blue' ] ), [ 'regular_price' => '1500' ], 50 ) );

		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=5&action=edit' ], $recorder->calls[0][1]['links'] );
		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=50&action=edit' ], $recorder->calls[1][1]['links'] );
	}

	/**
	 * A product or variation deleted for good names its target and has
	 * nothing left to open. Other posts are not the shop's.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_product_or_variation_deleted_for_good_is_told_on_its_target(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceProductSensor( $recorder );
		$sensor->on_delete_post( 5, self::post( [ 'post_status' => 'trash' ] ) );
		$sensor->on_delete_post(
			55,
			self::post(
				[
					'ID'          => 55,
					'post_type'   => 'product_variation',
					'post_title'  => 'Hoodie - Blue',
					'post_parent' => 50,
				]
			)
		);
		$sensor->on_delete_post( 7, self::post( [ 'ID' => 7, 'post_type' => 'post' ] ) );

		$this->assertSame( [ 9002, 9002 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( self::target( '5', 'Mug' ), $recorder->calls[0][1]['target'] );
		$this->assertSame( self::target( '55', 'Hoodie - Blue' ), $recorder->calls[1][1]['target'] );
		$this->assertArrayNotHasKey( 'links', $recorder->calls[0][1] );
		$this->assertSame( 'WC product "Mug" deleted.', $this->build( $recorder )[0]['message'] );
	}

	/**
	 * WordPress's own cleanup of stale placeholders deletes nothing anybody
	 * made; any other delete of a placeholder is told.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wordpress_cleaning_up_placeholders_deletes_no_product(): void {
		$this->stubs();
		$placeholder        = self::post(
			[
				'post_status' => 'auto-draft',
				'post_title'  => 'AUTO-DRAFT',
			]
		);
		WpState::$posts[50] = self::post(
			[
				'ID'          => 50,
				'post_status' => 'auto-draft',
				'post_title'  => 'AUTO-DRAFT',
			]
		);
		$variation          = self::post(
			[
				'ID'          => 55,
				'post_type'   => 'product_variation',
				'post_title'  => 'AUTO-DRAFT - Blue',
				'post_parent' => 50,
			]
		);

		$recorder = $this->recorder();
		$sensor   = new WooCommerceProductSensor( $recorder );

		WpState::$doing_action = 'wp_scheduled_auto_draft_delete';
		$sensor->on_delete_post( 5, $placeholder );
		$sensor->on_delete_post( 55, $variation );
		$this->assertSame( [], $recorder->calls );

		WpState::$doing_action = null;
		$sensor->on_delete_post( 5, $placeholder );
		$this->assertSame( [ 9002 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( self::target( '5', '#5' ), $recorder->calls[0][1]['target'] );
	}

	/**
	 * The classic product screen writes the name, slug, descriptions and
	 * categories through WordPress before the shop saves the product, so the
	 * shop's save never sees them: what WordPress wrote and what the shop
	 * saved are one edit event.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_classic_screen_save_is_one_edit_with_what_wordpress_and_the_shop_wrote(): void {
		$this->stubs();
		self::tt_term( 11, 'Mugs', 'product_cat' );
		self::tt_term( 12, 'Sale', 'product_cat' );
		WpState::$post_terms[5]['product_cat'] = [ 11 ];

		$recorder = $this->boot_sensors();
		self::update_post(
			self::post(),
			self::post(
				[
					'post_title'   => 'Big mug',
					'post_name'    => 'big-mug',
					'post_content' => 'A much longer text.',
					'post_excerpt' => 'Short and new.',
				]
			),
			static fn() => self::set_terms( 5, 'product_cat', [ 11, 12 ] ),
			[
				new FakeProduct( 5, self::stored( [ 'name' => 'Big mug' ] ), [ 'regular_price' => '1200' ] ),
				new FakeProduct( 5, self::stored( [ 'name' => 'Big mug' ] ), [] ),
			]
		);

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 9001 ], array_column( $survivors, 'code' ) );
		$this->assertSame(
			[
				self::change( 'category_ids', 'Mugs', 'Mugs, Sale' ),
				self::change( 'name', 'Mug', 'Big mug' ),
				self::change( 'slug', 'mug', 'big-mug' ),
				self::change( 'short_description', null, null ),
				self::change( 'description', null, null ),
				self::change( 'regular_price', '1000', '1200' ),
			],
			$survivors[0]['changes']
		);
		$this->assertSame( self::target( '5', 'Big mug' ), $survivors[0]['target'] );
	}

	/**
	 * A status change WordPress writes (publish, unpublish, schedule) is told
	 * as the product's status.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_status_change_written_by_wordpress_is_an_edit_of_the_status(): void {
		$this->stubs();

		$recorder = $this->boot_sensors();
		self::update_post( self::post(), self::post( [ 'post_status' => 'draft' ] ), null, [ new FakeProduct( 5, self::stored( [ 'status' => 'draft' ] ), [] ) ] );
		// A scheduled product going live: WordPress fires no update of the post.
		Hooks::fire( 'transition_post_status', 'publish', 'future', self::post( [ 'ID' => 6 ] ) );

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 9001, 9001 ], array_column( $survivors, 'code' ) );
		$this->assertSame( [ self::change( 'status', 'publish', 'draft' ) ], $survivors[0]['changes'] );
		$this->assertSame( [ self::change( 'status', 'future', 'publish' ) ], $survivors[1]['changes'] );
		$this->assertSame( '6', $survivors[1]['target']['id'] );
	}

	/**
	 * Moving a product to the trash is the trash event of the post sensor on
	 * the product's target: the status and WordPress's trash suffix on the
	 * slug are no edit next to it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_trashed_product_is_one_trash_event(): void {
		$this->stubs();

		$recorder = $this->boot_sensors();
		self::update_post(
			self::post(),
			self::post(
				[
					'post_status' => 'trash',
					'post_name'   => 'mug__trashed',
				]
			)
		);

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 2003 ], array_column( $survivors, 'code' ) );
		$this->assertSame( self::target( '5', 'Mug' ), $survivors[0]['target'] );
		$this->assertArrayNotHasKey( 'changes', $survivors[0] );
	}

	/**
	 * A save through the shop that changes the price and moves the product to
	 * the trash: the trash event carries the price, not the status.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_edit_in_the_save_that_trashes_or_restores_the_product_goes_into_that_event(): void {
		$this->stubs();

		$recorder = $this->boot_sensors();
		Hooks::fire(
			'woocommerce_before_product_object_save',
			new FakeProduct(
				5,
				self::stored(),
				[
					'regular_price' => '1200',
					'status'        => 'trash',
				]
			)
		);
		self::update_post( self::post(), self::post( [ 'post_status' => 'trash' ] ) );

		Hooks::fire(
			'woocommerce_before_product_object_save',
			new FakeProduct(
				6,
				self::stored( [ 'status' => 'trash' ] ),
				[
					'sku'    => 'MUG-6',
					'status' => 'draft',
				]
			)
		);
		self::update_post( self::post( [ 'ID' => 6, 'post_status' => 'trash' ] ), self::post( [ 'ID' => 6, 'post_status' => 'draft' ] ) );

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 2003, 2004 ], array_column( $survivors, 'code' ) );
		$this->assertSame( [ self::change( 'regular_price', '1000', '1200' ) ], $survivors[0]['changes'] );
		$this->assertSame( [ self::change( 'sku', 'MUG-1', 'MUG-6' ) ], $survivors[1]['changes'] );
	}

	/**
	 * WooCommerce renames a product sent back to `auto-draft` to its
	 * placeholder: the revert is told under the name the product had, and the
	 * placeholder is no rename.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_product_reverted_to_auto_draft_is_one_revert_under_its_real_name(): void {
		$this->stubs();

		$recorder = $this->boot_sensors();
		self::update_post(
			self::post( [ 'post_title' => 'Hoodie' ] ),
			self::post(
				[
					'post_status' => 'auto-draft',
					'post_title'  => 'AUTO-DRAFT',
				]
			)
		);

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 2013 ], array_column( $survivors, 'code' ) );
		$this->assertSame( self::target( '5', 'Hoodie' ), $survivors[0]['target'] );
		$this->assertSame( 'Product "Hoodie" reverted to auto-draft.', $survivors[0]['message'] );
		$this->assertArrayNotHasKey( 'changes', $survivors[0] );
	}

	/**
	 * WordPress's write of a variation tells its name and slug: the shop
	 * keeps a variation's description in a field of its own (its save tells
	 * it) and writes the list of its attributes into the excerpt. A variation
	 * inserted is no product created.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_variation_written_by_wordpress_tells_its_name_and_slug(): void {
		$this->stubs();
		$variation = static fn( array $over ): \WP_Post => self::post(
			array_merge(
				[
					'ID'          => 55,
					'post_type'   => 'product_variation',
					'post_title'  => 'Hoodie - S',
					'post_name'   => 'hoodie-s',
					'post_parent' => 50,
				],
				$over
			)
		);

		$recorder = $this->recorder();
		$sensor   = new WooCommerceProductSensor( $recorder );
		$sensor->on_post_status( 'publish', 'new', $variation( [] ) );
		$sensor->on_post_updated(
			55,
			$variation(
				[
					'post_title'   => 'Hoodie - M',
					'post_excerpt' => 'Size: M',
					'post_content' => 'Changed.',
				]
			),
			$variation( [ 'post_excerpt' => 'Size: S' ] )
		);

		$this->assertSame( [ 9001 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ self::change( 'name', 'Hoodie - S', 'Hoodie - M' ) ], $recorder->calls[0][1]['changes'] );
		$this->assertSame( self::target( '55', 'Hoodie - M' ), $recorder->calls[0][1]['target'] );
		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=50&action=edit' ], $recorder->calls[0][1]['links'] );
	}

	/**
	 * Appending terms: core passes no old terms, so they are the product's
	 * terms less those the call added.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_appending_a_category_tells_the_categories_before_and_after(): void {
		$this->stubs();
		self::tt_term( 11, 'Mugs', 'product_cat' );
		self::tt_term( 12, 'Sale', 'product_cat' );
		self::tt_term( 21, 'Blue', 'product_tag' );
		WpState::$posts[5]                     = self::post();
		WpState::$post_terms[5]['product_cat'] = [ 11 ];

		$recorder = $this->boot_sensors();
		self::set_terms( 5, 'product_cat', [ 12 ], true );
		self::set_terms( 5, 'product_cat', [ 11 ], true );
		self::set_terms( 5, 'product_tag', [ 21 ] );
		self::set_terms( 5, 'product_type', [ 31 ] );

		$this->assertSame( [ 9001, 9001 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ self::change( 'category_ids', 'Mugs', 'Mugs, Sale' ) ], $recorder->calls[0][1]['changes'] );
		$this->assertSame( [ self::change( 'tag_ids', '', 'Blue' ) ], $recorder->calls[1][1]['changes'] );
	}

	/**
	 * A bulk edit of 40 variations: one edit event per variation, each on the
	 * variation, labelled with its parent's name. The parent's saves that
	 * change nothing are not dispatched.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_bulk_edit_of_forty_variations_is_one_edit_per_variation(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceProductSensor( $recorder );
		for ( $i = 1; $i <= 40; $i++ ) {
			$sensor->on_before_save( new FakeProduct( 100 + $i, self::stored( [ 'name' => 'Hoodie - Size ' . $i ] ), [ 'regular_price' => '1500' ], 50 ) );
			$sensor->on_before_save( new FakeProduct( 50, self::stored( [ 'name' => 'Hoodie' ] ), [] ) );
		}

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertCount( 40, $survivors );
		$this->assertSame( array_fill( 0, 40, 9001 ), array_column( $survivors, 'code' ) );
		$this->assertSame( array_map( 'strval', range( 101, 140 ) ), array_column( array_column( $survivors, 'target' ), 'id' ) );
		foreach ( $survivors as $i => $payload ) {
			$this->assertSame( 'Hoodie - Size ' . ( $i + 1 ), $payload['target']['label'] );
			$this->assertSame( [ self::change( 'regular_price', '1000', '1500' ) ], $payload['changes'] );
		}
	}

	/**
	 * A product created on one site of a network is not created on another.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_creation_is_kept_per_site(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceProductSensor( $recorder );
		$sensor->on_created( 5, new FakeProduct( 5, self::stored() ) );

		WpState::$blog_id = 2;
		$sensor->on_before_save( new FakeProduct( 5, self::stored(), [ 'regular_price' => '1200' ] ) );

		$this->assertSame( [ 9000, 9001 ], array_column( $recorder->calls, 0 ) );
	}

	/**
	 * The shop's save of a product runs before it writes it: whatever fails
	 * while reading the product is reported, never thrown.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_product_that_fails_to_answer_never_breaks_its_save(): void {
		$this->stubs();
		$product = new class( 5 ) extends FakeProduct {
			public function get_changes(): array {
				throw new \TypeError( 'A filter broke.' );
			}
		};

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->on_before_save( $product );

		$this->assertSame( [], $recorder->calls );
		$this->assertCount( 1, WpCalls::$actions );
		[ $hook, $args ] = WpCalls::$actions[0];
		$this->assertSame( 'hellolog_dispatch_failed', $hook );
		$this->assertInstanceOf( \TypeError::class, $args[0] );
		$this->assertSame( 9001, $args[1] );
		$this->assertSame( [ 'product_id' => 5 ], $args[2] );
	}

	/**
	 * @return array<string, array{0: int, 1: string}> Code the handler works on, handler.
	 */
	public static function product_hooks(): array {
		return [
			'created'      => [ 9000, 'on_created' ],
			'status'       => [ 9001, 'on_post_status' ],
			'post updated' => [ 9001, 'on_post_updated' ],
			'terms set'    => [ 9001, 'on_terms_set' ],
			'post deleted' => [ 9002, 'on_delete_post' ],
		];
	}

	/**
	 * The other handlers are just as safe.
	 *
	 * @dataProvider product_hooks
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_every_product_hook_reports_instead_of_throwing( int $code, string $handler ): void {
		$this->stubs();
		// Product 5: its id can be read, nothing else.
		$broken                    = new class( [] ) extends \WP_Post {
			public function __get( string $name ) {
				if ( 'ID' === $name ) {
					return 5;
				}
				throw new \RuntimeException( 'A filter broke.' );
			}
		};
		WpState::$posts[5]         = $broken;
		TermState::$reads_throw    = true;
		WpState::$term_reads_throw = true;
		$product                   = new class( 5 ) extends FakeProduct {
			public function get_name() {
				throw new \RuntimeException( 'A filter broke.' );
			}
		};

		$recorder = $this->recorder();
		$sensor   = new WooCommerceProductSensor( $recorder );
		switch ( $handler ) {
			case 'on_created':
				$sensor->on_created( 5, $product );
				break;
			case 'on_post_status':
				$sensor->on_post_status( 'draft', 'publish', $broken );
				break;
			case 'on_post_updated':
				$sensor->on_post_updated( 5, $broken, $broken );
				break;
			case 'on_terms_set':
				$sensor->on_terms_set( 5, [ 11 ], [ 11 ], 'product_cat', false, [ 12 ] );
				break;
			default:
				$sensor->on_delete_post( 5, $broken );
		}

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 'hellolog_dispatch_failed', WpCalls::$actions[0][0] ?? null );
		$this->assertSame( $code, WpCalls::$actions[0][1][1] ?? null );
		$this->assertSame( [ 'product_id' => 5 ], WpCalls::$actions[0][1][2] ?? null );
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
		$sensor   = new WooCommerceProductSensor( $recorder );
		$sensor->on_created( '5', new FakeProduct( 5, self::stored() ) );
		$sensor->on_delete_post( '6', self::post( [ 'ID' => 6 ] ) );

		$sensor->on_created( [], null );
		$sensor->on_before_save( null );
		$sensor->on_before_save( 'product' );
		$sensor->on_before_save(
			new class() {
				public function get_id(): int {
					return 5;
				}
			}
		);
		$sensor->on_post_status( [], null, 'post' );
		$sensor->on_post_updated( 'x', null, null );
		$sensor->on_term_added( null, [], null );
		$sensor->on_terms_set( null, null, null, null, null, null );
		$sensor->on_delete_post( [], null );

		$this->assertSame( [ 9000, 9002 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( '5', $recorder->calls[0][1]['target']['id'] );
		$this->assertSame( '6', $recorder->calls[1][1]['target']['id'] );
		$this->assertSame( [], WpCalls::$actions );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_sensor_listens_to_the_product_hooks_with_their_arguments(): void {
		$this->stubs();

		$sensor = new WooCommerceProductSensor( $this->recorder() );
		$sensor->boot();

		$expected = [
			'woocommerce_new_product'                => [ 'on_created', 2 ],
			'woocommerce_before_product_object_save' => [ 'on_before_save', 1 ],
			'transition_post_status'                 => [ 'on_post_status', 3 ],
			'post_updated'                           => [ 'on_post_updated', 3 ],
			'added_term_relationship'                => [ 'on_term_added', 3 ],
			'set_object_terms'                       => [ 'on_terms_set', 6 ],
			'before_delete_post'                     => [ 'on_delete_post', 2 ],
		];
		$this->assertSame( array_keys( $expected ), array_keys( WpState::$callbacks ) );
		foreach ( $expected as $hook => [ $method, $args ] ) {
			$this->assertCount( 1, WpState::$callbacks[ $hook ], $hook );
			$registered = WpState::$callbacks[ $hook ][0];
			$this->assertSame( [ $sensor, $method ], $registered['callback'], $hook );
			$this->assertSame( $args, $registered['accepted_args'], $hook );
			$this->assertSame( 10, $registered['priority'], $hook );
		}
	}

	/**
	 * Load the stand-ins of the post sensor tests, the term lookup, the shop
	 * and the failure report.
	 */
	private function stubs(): void {
		require_once __DIR__ . '/../Core/Fixtures/wordpress-user-functions.php';
		require_once __DIR__ . '/../Core/Fixtures/wordpress-content-functions.php';
		require_once __DIR__ . '/../Core/Fixtures/wordpress-term-functions.php';
		require_once __DIR__ . '/../Core/Fixtures/woocommerce-class.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';

		WpState::$post_types['product_variation'] = false;
		WpState::$taxonomies['product']           = [ 'product_cat', 'product_tag', 'product_type' ];
	}

	/**
	 * Boot the post sensor and the product sensor on one recording
	 * dispatcher, the way a request with the shop active runs them.
	 */
	private function boot_sensors(): EventDispatcher {
		$recorder = $this->recorder();
		( new ContentSensor( $recorder ) )->boot();
		( new WooCommerceProductSensor( $recorder ) )->boot();

		return $recorder;
	}

	/**
	 * The hooks `wp_update_post()` fires when a product that was `$before` is
	 * saved as `$after`, in core's order (`wp_insert_post()`): the post is
	 * still the old one on `pre_post_update`; the terms given with the save
	 * are written (`$in_save`) before the status change; `post_updated`
	 * follows, then `save_post`, where the classic screen's boxes save the
	 * product through the shop (`$saves`), and `wp_after_insert_post`.
	 *
	 * @param \WP_Post                 $before  The post before the save.
	 * @param \WP_Post                 $after   The post as saved.
	 * @param callable|null            $in_save Writes made inside the save.
	 * @param array<int, FakeProduct>  $saves   The shop's saves of the product in `save_post`.
	 */
	private static function update_post( \WP_Post $before, \WP_Post $after, ?callable $in_save = null, array $saves = [] ): void {
		$id = (int) $after->ID;

		WpState::$posts[ $id ] = $before;
		Hooks::fire( 'pre_post_update', $id, [] );

		WpState::$posts[ $id ] = $after;
		if ( null !== $in_save ) {
			$in_save();
		}
		Hooks::fire( 'transition_post_status', (string) $after->post_status, (string) $before->post_status, $after );
		Hooks::fire( 'post_updated', $id, $after, $before );
		foreach ( $saves as $product ) {
			Hooks::fire( 'woocommerce_before_product_object_save', $product );
		}
		Hooks::fire( 'wp_after_insert_post', $id, $after, true, $before );
	}

	/**
	 * Set the terms of a post in one taxonomy the way `wp_set_object_terms()`
	 * does: `added_term_relationship` for each term the post did not have
	 * yet, then `set_object_terms` with the term taxonomy ids of every term
	 * given and the ones the post had before, which core leaves empty when
	 * appending.
	 *
	 * @param int             $post_id  Post id.
	 * @param string          $taxonomy Taxonomy name.
	 * @param array<int, int> $tt_ids   Term taxonomy ids given.
	 * @param bool            $append   Whether they are added to the post's terms.
	 */
	private static function set_terms( int $post_id, string $taxonomy, array $tt_ids, bool $append = false ): void {
		$old = WpState::$post_terms[ $post_id ][ $taxonomy ] ?? [];
		$new = $append ? array_values( array_unique( array_merge( $old, $tt_ids ) ) ) : $tt_ids;

		WpState::$post_terms[ $post_id ][ $taxonomy ] = $new;
		foreach ( array_diff( $tt_ids, $old ) as $tt_id ) {
			Hooks::fire( 'added_term_relationship', $post_id, $tt_id, $taxonomy );
		}
		Hooks::fire( 'set_object_terms', $post_id, $tt_ids, $tt_ids, $taxonomy, $append, $append ? [] : $old );
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
	 * A term `get_term_by( 'term_taxonomy_id' )` and `get_the_terms()` find by
	 * its term taxonomy id.
	 *
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy name.
	 */
	private static function tt_term( int $tt_id, string $name, string $taxonomy ): void {
		WpState::$terms[ $tt_id ] = (object) [
			'term_id'          => $tt_id + 100,
			'term_taxonomy_id' => $tt_id,
			'name'             => $name,
			'taxonomy'         => $taxonomy,
		];
	}

	/**
	 * A file of the media library `get_post()` finds.
	 *
	 * @param int    $id    Attachment id.
	 * @param string $title Its title.
	 */
	private static function image( int $id, string $title ): void {
		WpState::$posts[ $id ] = new \WP_Post(
			[
				'ID'          => $id,
				'post_title'  => $title,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);
	}

	/**
	 * Product 5, "Mug", published, as a post.
	 *
	 * @param array<string, mixed> $over Fields to replace.
	 */
	private static function post( array $over = [] ): \WP_Post {
		return new \WP_Post(
			array_merge(
				[
					'ID'           => 5,
					'post_title'   => 'Mug',
					'post_type'    => 'product',
					'post_status'  => 'publish',
					'post_name'    => 'mug',
					'post_content' => 'Long text.',
					'post_excerpt' => 'Short.',
					'post_author'  => '1',
					'post_parent'  => 0,
				],
				$over
			)
		);
	}

	/**
	 * What `get_data()` of a published simple product holds, with some
	 * values replaced.
	 *
	 * @param array<string, mixed> $over Values to replace.
	 * @return array<string, mixed>
	 */
	private static function stored( array $over = [] ): array {
		return array_merge(
			[
				'id'                 => 5,
				'name'               => 'Mug',
				'slug'               => 'mug',
				'status'             => 'publish',
				'featured'           => false,
				'catalog_visibility' => 'visible',
				'description'        => 'Long text.',
				'short_description'  => 'Short.',
				'sku'                => 'MUG-1',
				'price'              => '1000',
				'regular_price'      => '1000',
				'sale_price'         => '',
				'date_on_sale_from'  => null,
				'date_on_sale_to'    => null,
				'tax_status'         => 'taxable',
				'tax_class'          => '',
				'manage_stock'       => true,
				'stock_quantity'     => 10,
				'stock_status'       => 'instock',
				'backorders'         => 'no',
				'low_stock_amount'   => '',
				'weight'             => '0.5',
				'length'             => '',
				'width'              => '',
				'height'             => '',
				'shipping_class_id'  => 0,
				'category_ids'       => [],
				'tag_ids'            => [],
				'image_id'           => '',
				'gallery_image_ids'  => [],
				'virtual'            => false,
				'downloadable'       => false,
				'purchase_note'      => '',
				'attributes'         => [],
				'meta_data'          => [],
				'date_created'       => new \DateTimeImmutable( '2026-10-20T08:00:00+00:00' ),
			],
			$over
		);
	}

	/**
	 * @return array{type: string, id: string, label: string}
	 */
	private static function target( string $id, string $label ): array {
		return [
			'type'  => 'product',
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
