<?php
/**
 * Tests for what the product sensor tells about a product's or variation's
 * creation, fields and delete.
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
use HelloLog\Sensors\Integrations\WooCommerceProductSensor;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Core\Fixtures\BuildingRecorder;
use HelloLog\Tests\Sensors\Core\Fixtures\Hooks;
use HelloLog\Tests\Sensors\Core\Fixtures\TermState;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use HelloLog\Tests\Sensors\Integrations\Fixtures\FakeOrder;
use HelloLog\Tests\Sensors\Integrations\Fixtures\FakeProduct;
use PHPUnit\Framework\TestCase;

/**
 * A save of an existing product or variation tells which of its fields
 * changed (9001), whether the shop or WordPress wrote them; a new product is
 * a creation (9000) and its first saves tell nothing more; a product deleted
 * for good is 9002. An order that reduces a product's stock is 9005; any
 * other write of a stock level without a save is an edit (9001). Every event
 * names the product as its target.
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
		self::image( 21, 'Mug front' );

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
			static function (): void {
				self::set_terms( 5, 'product_cat', [ 11 ] );
				self::set_thumbnail( 5, 21 );
			},
			[
				new FakeProduct( 5, self::stored(), [ 'regular_price' => '1200' ] ),
				new FakeProduct( 5, self::stored(), [ 'gallery_image_ids' => [ 22 ] ] ),
			]
		);

		$this->assertSame( [ 9000 ], array_column( $recorder->calls, 0 ) );
		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 9000 ], array_column( $survivors, 'code' ) );
		$this->assertSame( self::target( '5', 'Mug' ), $survivors[0]['target'] );
		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=5&action=edit' ], $survivors[0]['links'] );
		$this->assertSame( 'WC product "Mug" created.', $survivors[0]['message'] );
		$this->assertArrayNotHasKey( 'changes', $survivors[0] );
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
	 * nothing left to open; a variation's delete is told when the request
	 * ends (its product may still be deleted after it). Other posts are not
	 * the shop's.
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
		$this->assertSame( [ 9002 ], array_column( $recorder->calls, 0 ) );
		$sensor->flush_open_saves();

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
				self::change( 'name', 'Mug', 'Big mug' ),
				self::change( 'slug', 'mug', 'big-mug' ),
				self::change( 'category_ids', 'Mugs', 'Mugs, Sale' ),
				self::change( 'short_description', null, null ),
				self::change( 'description', null, null ),
				self::change( 'regular_price', '1000', '1200' ),
			],
			$survivors[0]['changes']
		);
		$this->assertSame( self::target( '5', 'Big mug' ), $survivors[0]['target'] );
		$this->assertSame( 'WC product "Big mug" updated.', $survivors[0]['message'] );
		// WordPress still holds the post as it was while it writes the terms:
		// no event of the save is named by the old name.
		foreach ( $recorder->calls as [ $code, $fields ] ) {
			$this->assertSame( 'Big mug', $fields['target']['label'], "code $code" );
		}
	}

	/**
	 * The classic product screen sets the product image through WordPress
	 * (the shop's save never sees it): a new image is one edit naming the
	 * images, and so is a removed one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_classic_screen_image_change_is_one_edit_of_the_image(): void {
		$this->stubs();
		self::image( 21, 'Mug front' );
		self::image( 23, 'Mug top' );
		WpState::$post_meta[5]['_thumbnail_id'] = '21';

		$recorder = $this->boot_sensors();
		self::update_post(
			self::post(),
			self::post(),
			static fn() => self::set_thumbnail( 5, 23 ),
			[
				new FakeProduct( 5, self::stored( [ 'image_id' => '23' ] ), [] ),
				new FakeProduct( 5, self::stored( [ 'image_id' => '23' ] ), [] ),
			]
		);
		self::update_post( self::post( [ 'ID' => 5 ] ), self::post( [ 'ID' => 5 ] ), static fn() => self::set_thumbnail( 5, 0 ) );

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 9001 ], array_column( $survivors, 'code' ) );
		$this->assertSame( [ self::change( 'image_id', 'Mug front', '' ) ], $survivors[0]['changes'] );

		$first = $this->survive_calls( array_slice( $recorder->calls, 0, 1 ) );
		$this->assertSame( [ self::change( 'image_id', 'Mug front', 'Mug top' ) ], $first[0]['changes'] );
		$this->assertSame( self::target( '5', 'Mug' ), $first[0]['target'] );
	}

	/**
	 * An image set or removed outside a save of the post is an edit of its
	 * own; other meta, and the image of a post that is no product, are not.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_image_set_or_removed_outside_a_save_is_an_edit(): void {
		$this->stubs();
		self::image( 21, 'Mug front' );
		WpState::$posts[5] = self::post();
		WpState::$posts[7] = self::post(
			[
				'ID'        => 7,
				'post_type' => 'post',
			]
		);

		$recorder = $this->boot_sensors();
		self::set_thumbnail( 5, 21 );
		self::set_thumbnail( 5, 0 );
		self::set_thumbnail( 5, 99 );
		self::set_thumbnail( 7, 21 );
		Hooks::fire( 'updated_post_meta', 1, 5, '_price', '12' );

		$this->assertSame( [ 9001, 9001, 9001 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ self::change( 'image_id', '', 'Mug front' ) ], $recorder->calls[0][1]['changes'] );
		$this->assertSame( [ self::change( 'image_id', 'Mug front', '' ) ], $recorder->calls[1][1]['changes'] );
		$this->assertSame( [ self::change( 'image_id', '', '#99' ) ], $recorder->calls[2][1]['changes'] );
		$this->assertSame( self::target( '5', 'Mug' ), $recorder->calls[0][1]['target'] );
	}

	/**
	 * A rename and unpublish through the shop (REST API, code): the shop's
	 * save, WordPress's status change and its write of the post are one edit,
	 * named by the new name.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_rename_through_the_shop_is_one_edit_under_the_new_name(): void {
		$this->stubs();

		$recorder = $this->boot_sensors();
		Hooks::fire(
			'woocommerce_before_product_object_save',
			new FakeProduct(
				5,
				self::stored(),
				[
					'name'   => 'Big mug',
					'status' => 'draft',
				]
			)
		);
		self::update_post(
			self::post(),
			self::post(
				[
					'post_title'  => 'Big mug',
					'post_status' => 'draft',
				]
			)
		);

		$this->assertSame( [ 9001, 9001, 9001 ], array_column( $recorder->calls, 0 ) );
		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 9001 ], array_column( $survivors, 'code' ) );
		$this->assertSame(
			[
				self::change( 'name', 'Mug', 'Big mug' ),
				self::change( 'status', 'publish', 'draft' ),
			],
			$survivors[0]['changes']
		);
		$this->assertSame( self::target( '5', 'Big mug' ), $survivors[0]['target'] );
	}

	/**
	 * What WordPress wrote while a save of the post was open is told when the
	 * save ends; a save that never got there is told when the request ends.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_that_never_ends_is_told_when_the_request_ends(): void {
		$this->stubs();
		self::tt_term( 11, 'Mugs', 'product_cat' );
		WpState::$posts[5] = self::post();

		$recorder = $this->boot_sensors();
		Hooks::fire( 'pre_post_update', 5, [ 'post_type' => 'product' ] );
		self::set_terms( 5, 'product_cat', [ 11 ] );
		$this->assertSame( [], $recorder->calls );

		Hooks::fire( 'shutdown' );

		$this->assertSame( [ 9001 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ self::change( 'category_ids', '', 'Mugs' ) ], $recorder->calls[0][1]['changes'] );
		$this->assertSame( self::target( '5', 'Mug' ), $recorder->calls[0][1]['target'] );
	}

	/**
	 * Reading the product to tell a save that never ended can fail too: it is
	 * reported, never thrown.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_told_when_the_request_ends_never_fails_the_request(): void {
		$this->stubs();
		self::tt_term( 11, 'Mugs', 'product_cat' );
		WpState::$posts[5] = self::post();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceProductSensor( $recorder );
		$sensor->on_before_post_update( 5, [ 'post_type' => 'product' ] );
		$sensor->on_terms_set( 5, [ 11 ], [ 11 ], 'product_cat', false, [] );
		WpState::$posts[5] = self::broken_post();
		$sensor->flush_open_saves();

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 'hellolog_dispatch_failed', WpCalls::$actions[0][0] ?? null );
		$this->assertSame( 9001, WpCalls::$actions[0][1][1] ?? null );
		$this->assertSame( [ 'product_id' => 5 ], WpCalls::$actions[0][1][2] ?? null );
	}

	/**
	 * Moving a variable product to the trash moves every variation with it:
	 * the product's trash says it all.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_trashing_a_variable_product_is_one_trash_for_all_its_variations(): void {
		$this->stubs();
		$parent             = self::variable_product();
		WpState::$posts[50] = $parent;

		$recorder = $this->boot_sensors();
		Hooks::fire( 'wp_trash_post', 50, 'publish' );
		self::update_post(
			$parent,
			self::variable_product(
				[
					'post_status' => 'trash',
					'post_name'   => 'hoodie__trashed',
				]
			)
		);
		for ( $i = 1; $i <= 20; $i++ ) {
			self::update_post(
				self::variation( $i ),
				self::variation(
					$i,
					[
						'post_status' => 'trash',
						'post_name'   => 'hoodie-size-' . $i . '__trashed',
					]
				)
			);
		}

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 2003 ], array_column( $survivors, 'code' ) );
		$this->assertSame( self::target( '50', 'Hoodie' ), $survivors[0]['target'] );
		$this->assertArrayNotHasKey( 'changes', $survivors[0] );
	}

	/**
	 * Restoring a variable product restores every variation with it: the
	 * product's restore says it all.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_restoring_a_variable_product_is_one_restore_for_all_its_variations(): void {
		$this->stubs();
		$trashed            = self::variable_product(
			[
				'post_status' => 'trash',
				'post_name'   => 'hoodie__trashed',
			]
		);
		WpState::$posts[50] = $trashed;

		$recorder = $this->boot_sensors();
		Hooks::fire( 'untrash_post', 50, 'publish' );
		self::update_post( $trashed, self::variable_product( [ 'post_status' => 'draft' ] ) );
		for ( $i = 1; $i <= 20; $i++ ) {
			self::update_post(
				self::variation(
					$i,
					[
						'post_status' => 'trash',
						'post_name'   => 'hoodie-size-' . $i . '__trashed',
					]
				),
				self::variation( $i )
			);
		}

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 2004 ], array_column( $survivors, 'code' ) );
		$this->assertSame( self::target( '50', 'Hoodie' ), $survivors[0]['target'] );
		$this->assertArrayNotHasKey( 'changes', $survivors[0] );
	}

	/**
	 * Deleting a variable product for good deletes every variation with it:
	 * the product's delete says it all. A variation deleted on its own is
	 * still told.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_a_variable_product_is_one_delete_for_all_its_variations(): void {
		$this->stubs();
		$parent             = self::variable_product( [ 'post_status' => 'trash' ] );
		WpState::$posts[50] = $parent;
		WpState::$posts[60] = self::variable_product(
			[
				'ID'         => 60,
				'post_title' => 'Scarf',
			]
		);

		$recorder = $this->boot_sensors();
		self::delete_post( $parent, '21' );
		for ( $i = 1; $i <= 20; $i++ ) {
			self::delete_post( self::variation( $i ), '23' );
		}
		self::delete_post(
			self::variation(
				1,
				[
					'ID'          => 201,
					'post_parent' => 60,
					'post_title'  => 'Scarf - Red',
				]
			),
			'23'
		);
		Hooks::fire( 'shutdown' );

		$this->assertNotContains( 9001, array_column( $recorder->calls, 0 ), 'deleting the meta of a deleted post is no edit' );
		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 9002, 9002 ], array_column( $survivors, 'code' ) );
		$this->assertSame( self::target( '50', 'Hoodie' ), $survivors[0]['target'] );
		$this->assertSame( self::target( '201', 'Scarf - Red' ), $survivors[1]['target'] );
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
	 * @return array<string, array{0: string}> Status of the product and its variations when they are deleted.
	 */
	public static function deletes_children_first(): array {
		return [
			'shop REST API'  => [ 'publish' ],
			'trash emptying' => [ 'trash' ],
		];
	}

	/**
	 * The shop's REST API deletes the variations of a variable product before
	 * the product, and WordPress's emptying of the trash takes them in any
	 * order: still one delete, the product's.
	 *
	 * @dataProvider deletes_children_first
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $status Status of the product and its variations.
	 */
	public function test_variations_deleted_before_their_product_are_one_delete( string $status ): void {
		$this->stubs();
		$parent             = self::variable_product( [ 'post_status' => $status ] );
		WpState::$posts[50] = $parent;

		$recorder = $this->boot_sensors();
		for ( $i = 1; $i <= 20; $i++ ) {
			self::delete_post( self::variation( $i, [ 'post_status' => $status ] ), '23' );
		}
		self::delete_post( $parent, '21' );
		Hooks::fire( 'shutdown' );

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 9002 ], array_column( $survivors, 'code' ) );
		$this->assertSame( self::target( '50', 'Hoodie' ), $survivors[0]['target'] );
		$this->assertNotContains( 9001, array_column( $recorder->calls, 0 ) );
	}

	/**
	 * A variation deleted on its own is told, when the request ends; the
	 * removal of its image with it is no edit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_variation_deleted_on_its_own_is_told_when_the_request_ends(): void {
		$this->stubs();
		WpState::$posts[50] = self::variable_product();

		$recorder = $this->boot_sensors();
		self::delete_post( self::variation( 3 ), '23' );
		$this->assertSame( [], $recorder->calls );

		Hooks::fire( 'shutdown' );

		$this->assertSame( [ 9002 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( self::target( '103', 'Hoodie - Size 3' ), $recorder->calls[0][1]['target'] );
		$this->assertArrayNotHasKey( 'links', $recorder->calls[0][1] );
	}

	/**
	 * A variation's status change is told when the request ends, unless its
	 * product is trashed or restored in the same request, whichever comes
	 * first.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_variations_status_change_is_told_unless_its_product_takes_it_along(): void {
		$this->stubs();
		WpState::$posts[50] = self::variable_product();
		WpState::$posts[60] = self::variable_product(
			[
				'ID'         => 60,
				'post_title' => 'Scarf',
			]
		);

		$recorder = $this->boot_sensors();
		self::update_post( self::variation( 1 ), self::variation( 1, [ 'post_status' => 'trash' ] ) );
		self::update_post(
			self::variation(
				2,
				[
					'post_parent' => 60,
					'post_status' => 'private',
				]
			),
			self::variation( 2, [ 'post_parent' => 60 ] )
		);
		Hooks::fire( 'wp_trash_post', 50, 'publish' );
		Hooks::fire( 'shutdown' );

		$this->assertSame( [ 9001 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( '102', $recorder->calls[0][1]['target']['id'] );
		$this->assertSame( [ self::change( 'status', 'private', 'publish' ) ], $recorder->calls[0][1]['changes'] );
	}

	/**
	 * A variation deleted by an Action Scheduler job is told when the job
	 * ends, while the job is still the context, and not again at shutdown.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_variation_deleted_in_a_job_is_told_when_the_job_ends(): void {
		$this->stubs();
		WpState::$posts[50] = self::variable_product();
		$recorder           = new BuildingRecorder();
		ActionSchedulerContext::register();
		( new WooCommerceProductSensor( $recorder ) )->boot();

		Hooks::fire( 'action_scheduler_begin_execute', 41, 'WP Cron' );
		self::delete_post( self::variation( 3 ) );
		Hooks::fire( 'action_scheduler_after_execute', 41, null, 'WP Cron' );

		$this->assertSame( [ 9002 ], array_column( $recorder->payloads, 'code' ) );
		$this->assertSame( 'action-scheduler', $recorder->payloads[0]['source'] );

		Hooks::fire( 'shutdown' );
		$this->assertCount( 1, $recorder->payloads, 'Not again at shutdown.' );
	}

	/**
	 * An order that reduces a product's stock writes the level straight to
	 * the database, then says so: one stock event on the product, naming the
	 * order, and no edit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_stock_reduced_by_an_order_is_one_stock_event_on_the_product(): void {
		$this->stubs();
		WpState::$posts[5] = self::post();

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->boot();
		self::write_stock( self::stocked( 5, 10 ), self::stocked( 5, 8 ) );
		self::reduce( self::stocked( 5, 8 ), 10, 8 );
		Hooks::fire( 'shutdown' );

		$this->assertSame( [ 9005 ], array_column( $recorder->calls, 0 ) );
		$fields = $recorder->calls[0][1];
		$this->assertSame( self::target( '5', 'Mug' ), $fields['target'] );
		$this->assertSame( [ self::change( 'stock_quantity', '10', '8' ) ], $fields['changes'] );
		$this->assertSame(
			[
				'name' => 'Mug',
				'old'  => '10',
				'new'  => '8',
			],
			$fields['vars']
		);
		$this->assertSame(
			[
				'product_id' => 5,
				'order_id'   => 77,
			],
			$fields['metadata']
		);
		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=5&action=edit' ], $fields['links'] );

		$this->assertSame( 'WC product "Mug" stock reduced by an order: 10 → 8.', $this->build( $recorder )[0]['message'] );
	}

	/**
	 * A cancelled order's restock, a refund's restock, an item edited on the
	 * order screen and a bulk edit write the level the same way, without a
	 * save that would show it: an edit of the product's stock, told once it
	 * is known that no order reduced it (here when the request ends).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_stock_written_without_a_save_is_an_edit_of_the_stock(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->boot();
		self::write_stock( self::stocked( 5, 8 ), self::stocked( 5, 10 ) );
		$this->assertSame( [], $recorder->calls, 'Held until it is known whether an order made it.' );

		Hooks::fire( 'shutdown' );

		$this->assertSame( [ 9001 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( self::target( '5', 'Mug' ), $recorder->calls[0][1]['target'] );
		$this->assertSame( [ self::change( 'stock_quantity', '8', '10' ) ], $recorder->calls[0][1]['changes'] );
	}

	/**
	 * The next write of a stock level tells the one before it: an order
	 * says it reduced the stock right after the write.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_stock_write_is_told_when_the_next_one_begins(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->boot();
		self::write_stock( self::stocked( 5, 8 ), self::stocked( 5, 10 ) );
		Hooks::fire( 'woocommerce_product_before_set_stock', self::stocked( 6, 4, 'Plate' ) );
		$this->assertSame( [ 9001 ], array_column( $recorder->calls, 0 ) );

		Hooks::fire( 'woocommerce_updated_product_stock', 6 );
		Hooks::fire( 'woocommerce_product_set_stock', self::stocked( 6, 3, 'Plate' ) );
		self::reduce( self::stocked( 6, 3, 'Plate' ), 4, 3 );
		Hooks::fire( 'shutdown' );

		$this->assertSame( [ 9001, 9005 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( self::target( '6', 'Plate' ), $recorder->calls[1][1]['target'] );
	}

	/**
	 * A bulk edit writes the stock level, then saves the product with the
	 * other fields it changed: one edit with all of them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_bulk_stock_edit_is_one_edit_with_the_products_other_changes(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->boot();
		self::write_stock( self::stocked( 5, 8 ), self::stocked( 5, 6 ) );
		Hooks::fire( 'woocommerce_before_product_object_save', new FakeProduct( 5, self::stored( [ 'stock_quantity' => 6 ] ), [ 'backorders' => 'notify' ] ) );
		Hooks::fire( 'shutdown' );

		$survivors = $this->survive_calls( $recorder->calls );
		$this->assertSame( [ 9001 ], array_column( $survivors, 'code' ) );
		$this->assertSame(
			[
				self::change( 'backorders', 'no', 'notify' ),
				self::change( 'stock_quantity', '8', '6' ),
			],
			$survivors[0]['changes']
		);
	}

	/**
	 * A stock level the shop saves with the product (the product screen, the
	 * REST API) is in the save's changes; WooCommerce's stock hooks inside
	 * that save tell it no second time.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_stock_level_saved_by_the_shop_is_told_once(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->boot();
		$product = new FakeProduct( 5, self::stored(), [ 'stock_quantity' => 12 ] );
		Hooks::fire( 'woocommerce_before_product_object_save', $product );
		Hooks::fire( 'woocommerce_product_before_set_stock', $product );
		Hooks::fire( 'woocommerce_product_set_stock', self::stocked( 5, 12 ) );
		Hooks::fire( 'shutdown' );

		$this->assertSame( [ 9001 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ self::change( 'stock_quantity', '10', '12' ) ], $recorder->calls[0][1]['changes'] );
	}

	/**
	 * A variation whose stock its parent manages takes the parent's stock:
	 * the reduction is told on the product whose level moved.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_order_reducing_a_variation_stocked_by_its_parent_is_told_on_the_parent(): void {
		$this->stubs();
		WpState::$posts[50] = self::variable_product();
		$variation          = new class( 103, self::stored( [ 'name' => 'Hoodie - Size 3' ] ), [], 50 ) extends FakeProduct {
			public function get_stock_managed_by_id(): int {
				return 50;
			}
		};

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->boot();
		self::write_stock( self::stocked( 50, 10, 'Hoodie' ), self::stocked( 50, 8, 'Hoodie' ) );
		self::reduce( $variation, 10, 8 );
		Hooks::fire( 'shutdown' );

		$this->assertSame( [ 9005 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( self::target( '50', 'Hoodie' ), $recorder->calls[0][1]['target'] );
		$this->assertSame( 50, $recorder->calls[0][1]['metadata']['product_id'] );
	}

	/**
	 * When the write itself went unseen, the order's own word tells the
	 * reduction, on the product it names; a reduction by nothing is none.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_order_reduction_without_a_write_seen_is_told_from_the_order(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceProductSensor( $recorder ) )->boot();
		self::reduce( self::stocked( 5, 8 ), 10, 8 );
		self::reduce( self::stocked( 6, 3, 'Plate' ), 3, 3 );

		$this->assertSame( [ 9005 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( self::target( '5', 'Mug' ), $recorder->calls[0][1]['target'] );
		$this->assertSame( [ self::change( 'stock_quantity', '10', '8' ) ], $recorder->calls[0][1]['changes'] );
	}

	/**
	 * A stock level written by an Action Scheduler job is told when the job
	 * ends, while the job is still the context, and not again at shutdown.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_stock_write_in_a_job_is_told_when_the_job_ends(): void {
		$this->stubs();
		$recorder = new BuildingRecorder();
		ActionSchedulerContext::register();
		( new WooCommerceProductSensor( $recorder ) )->boot();

		Hooks::fire( 'action_scheduler_begin_execute', 41, 'WP Cron' );
		self::write_stock( self::stocked( 5, 8 ), self::stocked( 5, 10 ) );
		Hooks::fire( 'action_scheduler_after_execute', 41, null, 'WP Cron' );

		$this->assertSame( [ 9001 ], array_column( $recorder->payloads, 'code' ) );
		$this->assertSame( 'action-scheduler', $recorder->payloads[0]['source'] );

		Hooks::fire( 'shutdown' );
		$this->assertCount( 1, $recorder->payloads, 'Not again at shutdown.' );
	}

	/**
	 * Product 5 of one site is not product 5 of another: a write is matched
	 * with its order, and told, on the site it happened on.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_stock_write_is_told_on_the_site_it_happened_on(): void {
		$this->stubs();
		$recorder = new class() implements EventDispatcher {

			/** @var array<int, array{0: int, 1: array<string, mixed>, 2: int}> */
			public array $calls = [];

			public function dispatch( int $code, array $fields = [] ): void {
				$this->calls[] = [ $code, $fields, get_current_blog_id() ];
			}
		};
		( new WooCommerceProductSensor( $recorder ) )->boot();

		self::write_stock( self::stocked( 5, 8 ), self::stocked( 5, 10 ) );
		WpState::$blog_id = 2;
		self::reduce( self::stocked( 5, 19 ), 20, 19 );
		Hooks::fire( 'shutdown' );

		$this->assertSame( [ 9005, 9001 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ self::change( 'stock_quantity', '20', '19' ) ], $recorder->calls[0][1]['changes'] );
		$this->assertSame( 2, $recorder->calls[0][2] );
		$this->assertSame( [ self::change( 'stock_quantity', '8', '10' ) ], $recorder->calls[1][1]['changes'] );
		$this->assertSame( 1, $recorder->calls[1][2] );
		$this->assertSame( 2, WpState::$blog_id );
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
			'term added'   => [ 9001, 'on_term_added' ],
			'save opened'  => [ 9001, 'on_before_post_update' ],
			'image read'   => [ 9001, 'on_meta_changing' ],
			'image added'  => [ 9001, 'on_meta_added' ],
			'image set'    => [ 9001, 'on_meta_updated' ],
			'image gone'   => [ 9001, 'on_meta_deleted' ],
			'trashing'      => [ 9001, 'on_trashing' ],
			'restoring'     => [ 9001, 'on_untrashing' ],
			'post deleted'  => [ 9002, 'on_delete_post' ],
			'stock read'    => [ 9001, 'on_before_set_stock' ],
			'stock updated' => [ 9001, 'on_stock_updated' ],
			'stock written' => [ 9001, 'on_set_stock' ],
			'stock reduced' => [ 9005, 'on_order_reduced_stock' ],
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
		$broken                    = self::broken_post();
		WpState::$posts[5]         = $broken;
		TermState::$reads_throw    = true;
		WpState::$term_reads_throw = true;
		WpState::$post_meta_throws = true;
		// The handlers that only note an id read nothing but the site.
		WpState::$blog_id_throws = in_array( $handler, [ 'on_term_added', 'on_trashing', 'on_untrashing', 'on_stock_updated', 'on_set_stock' ], true );
		$product                 = new class( 5 ) extends FakeProduct {
			public function get_name() {
				throw new \RuntimeException( 'A filter broke.' );
			}

			public function get_stock_quantity( string $context = 'view' ) {
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
			case 'on_term_added':
				$sensor->on_term_added( 5, 11, 'product_cat' );
				break;
			case 'on_before_post_update':
				$sensor->on_before_post_update( 5, [] );
				break;
			case 'on_meta_changing':
			case 'on_meta_added':
			case 'on_meta_updated':
			case 'on_meta_deleted':
				$sensor->$handler( 1, 5, '_thumbnail_id', '23' );
				break;
			case 'on_trashing':
			case 'on_untrashing':
			case 'on_stock_updated':
				$sensor->$handler( 5 );
				break;
			case 'on_before_set_stock':
			case 'on_set_stock':
				$sensor->$handler( $product );
				break;
			case 'on_order_reduced_stock':
				$sensor->on_order_reduced_stock(
					new \stdClass(),
					[
						'product' => $product,
						'from'    => 10,
						'to'      => 8,
					],
					null
				);
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
		$sensor->on_before_post_update( [], null );
		$sensor->on_meta_changing( null, [], null, null );
		$sensor->on_meta_added( null, 'x', '_thumbnail_id', [] );
		$sensor->on_meta_updated( null, null, null, null );
		$sensor->on_meta_deleted( [], 0, '_thumbnail_id', null );
		$sensor->on_trashing( [] );
		$sensor->on_untrashing( null );
		$sensor->on_delete_post( [], null );
		$sensor->on_before_set_stock( null );
		$sensor->on_before_set_stock( 'product' );
		$sensor->on_stock_updated( [] );
		$sensor->on_set_stock( new \stdClass() );
		$sensor->on_order_reduced_stock( null, null, null );
		$sensor->on_order_reduced_stock(
			null,
			[
				'product' => 'product',
				'from'    => 10,
				'to'      => 8,
			],
			5
		);
		$sensor->on_order_reduced_stock(
			null,
			[
				'product' => new FakeProduct( 5, self::stored() ),
				'from'    => [],
				'to'      => null,
			],
			5
		);
		$sensor->flush_open_saves();
		$sensor->flush_job_saves();

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

		// Hook => method, arguments, priority. The trash and restore of a
		// product are noted before the shop's own callbacks trash or restore
		// its variations; open saves are told before the request's events
		// are written (priority 1).
		$expected = [
			'woocommerce_new_product'                => [ 'on_created', 2, 10 ],
			'woocommerce_before_product_object_save' => [ 'on_before_save', 1, 10 ],
			'pre_post_update'                        => [ 'on_before_post_update', 2, 10 ],
			'transition_post_status'                 => [ 'on_post_status', 3, 10 ],
			'post_updated'                           => [ 'on_post_updated', 3, 10 ],
			'added_term_relationship'                => [ 'on_term_added', 3, 10 ],
			'set_object_terms'                       => [ 'on_terms_set', 6, 10 ],
			'update_post_meta'                       => [ 'on_meta_changing', 4, 10 ],
			'delete_post_meta'                       => [ 'on_meta_changing', 4, 10 ],
			'added_post_meta'                        => [ 'on_meta_added', 4, 10 ],
			'updated_post_meta'                      => [ 'on_meta_updated', 4, 10 ],
			'deleted_post_meta'                      => [ 'on_meta_deleted', 4, 10 ],
			'wp_trash_post'                          => [ 'on_trashing', 1, 0 ],
			'untrash_post'                           => [ 'on_untrashing', 1, 0 ],
			'before_delete_post'                     => [ 'on_delete_post', 2, 10 ],
			// Stock levels the shop writes without a save.
			'woocommerce_product_before_set_stock'   => [ 'on_before_set_stock', 1, 10 ],
			'woocommerce_variation_before_set_stock' => [ 'on_before_set_stock', 1, 10 ],
			'woocommerce_updated_product_stock'      => [ 'on_stock_updated', 1, 10 ],
			'woocommerce_product_set_stock'          => [ 'on_set_stock', 1, 10 ],
			'woocommerce_variation_set_stock'        => [ 'on_set_stock', 1, 10 ],
			'woocommerce_reduce_order_item_stock'    => [ 'on_order_reduced_stock', 3, 10 ],
			'shutdown'                               => [ 'flush_open_saves', 1, 0 ],
			// When a job ends, before its context closes at PHP_INT_MAX.
			'action_scheduler_after_execute'         => [ 'flush_job_saves', 0, PHP_INT_MAX - 1 ],
			'action_scheduler_failed_execution'      => [ 'flush_job_saves', 0, PHP_INT_MAX - 1 ],
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
	 * saved as `$after`, in core's order (`wp_insert_post()`): `pre_post_update`;
	 * the terms and the product image given with the save are written
	 * (`$in_save`) while WordPress still holds the old post in its cache (it
	 * clears it only after them); then the status change and `post_updated`
	 * with the new post, then `save_post`, where the classic screen's boxes
	 * save the product through the shop (`$saves`), and `wp_after_insert_post`.
	 *
	 * @param \WP_Post                 $before  The post before the save.
	 * @param \WP_Post                 $after   The post as saved.
	 * @param callable|null            $in_save Writes made inside the save.
	 * @param array<int, FakeProduct>  $saves   The shop's saves of the product in `save_post`.
	 */
	private static function update_post( \WP_Post $before, \WP_Post $after, ?callable $in_save = null, array $saves = [] ): void {
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
		if ( null !== $in_save ) {
			$in_save();
		}

		WpState::$posts[ $id ] = $after;
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
	 * Set or remove the product image of a post the way `set_post_thumbnail()`
	 * and `delete_post_thumbnail()` do through the meta functions: an image
	 * where there was none is added, another one updated (with the hook
	 * before the write), none deletes it (likewise); the same image writes
	 * nothing.
	 *
	 * @param int $post_id       Post id.
	 * @param int $attachment_id Image id; 0 to remove the image.
	 */
	private static function set_thumbnail( int $post_id, int $attachment_id ): void {
		$old = (string) ( WpState::$post_meta[ $post_id ]['_thumbnail_id'] ?? '' );
		if ( $attachment_id <= 0 ) {
			if ( '' !== $old ) {
				Hooks::fire( 'delete_post_meta', [ 1 ], $post_id, '_thumbnail_id', '' );
				unset( WpState::$post_meta[ $post_id ]['_thumbnail_id'] );
				Hooks::fire( 'deleted_post_meta', [ 1 ], $post_id, '_thumbnail_id', '' );
			}
			return;
		}
		if ( '' === $old ) {
			WpState::$post_meta[ $post_id ]['_thumbnail_id'] = (string) $attachment_id;
			Hooks::fire( 'added_post_meta', 1, $post_id, '_thumbnail_id', $attachment_id );
			return;
		}
		if ( (string) $attachment_id !== $old ) {
			Hooks::fire( 'update_post_meta', 1, $post_id, '_thumbnail_id', $attachment_id );
			WpState::$post_meta[ $post_id ]['_thumbnail_id'] = (string) $attachment_id;
			Hooks::fire( 'updated_post_meta', 1, $post_id, '_thumbnail_id', $attachment_id );
		}
	}

	/**
	 * Delete a post for good the way `wp_delete_post()` does: the post is
	 * still there on `before_delete_post`, then each of its meta rows is
	 * deleted by its id (the product image among them, `$image`), then
	 * `delete_post`.
	 *
	 * @param \WP_Post $post  The post.
	 * @param string   $image Id of its product image; empty for none.
	 */
	private static function delete_post( \WP_Post $post, string $image = '' ): void {
		$id                    = (int) $post->ID;
		WpState::$posts[ $id ] = $post;
		if ( '' !== $image ) {
			WpState::$post_meta[ $id ]['_thumbnail_id'] = $image;
		}

		Hooks::fire( 'before_delete_post', $id, $post );
		if ( '' !== $image ) {
			Hooks::fire( 'delete_post_meta', [ 900 + $id ], $id, '_thumbnail_id', $image );
			unset( WpState::$post_meta[ $id ]['_thumbnail_id'] );
			Hooks::fire( 'deleted_post_meta', [ 900 + $id ], $id, '_thumbnail_id', $image );
		}
		Hooks::fire( 'delete_post', $id, $post );
	}

	/**
	 * The hooks `wc_update_product_stock()` fires when it writes a stock level
	 * straight to the database (an order, a restock, a bulk edit): the
	 * product as it was, the direct write, the product holding the new level.
	 *
	 * @param FakeProduct $before The product before the write.
	 * @param FakeProduct $after  The product after it.
	 */
	private static function write_stock( FakeProduct $before, FakeProduct $after ): void {
		$kind = $before->get_parent_id() > 0 ? 'variation' : 'product';
		Hooks::fire( 'woocommerce_' . $kind . '_before_set_stock', $before );
		Hooks::fire( 'woocommerce_updated_product_stock', $before->get_id() );
		Hooks::fire( 'woocommerce_' . $kind . '_set_stock', $after );
	}

	/**
	 * What `wc_reduce_stock_levels()` says after it reduced the stock of the
	 * product of a line of order 77.
	 *
	 * @param FakeProduct $product The product of the line.
	 * @param int         $from    Stock level before.
	 * @param int         $to      Stock level after.
	 */
	private static function reduce( FakeProduct $product, int $from, int $to ): void {
		Hooks::fire(
			'woocommerce_reduce_order_item_stock',
			new \stdClass(),
			[
				'product' => $product,
				'from'    => $from,
				'to'      => $to,
			],
			new FakeOrder( 77 )
		);
	}

	/**
	 * A product with a stock level.
	 *
	 * @param int    $id    Product id.
	 * @param int    $stock Its stock level.
	 * @param string $name  Its name.
	 */
	private static function stocked( int $id, int $stock, string $name = 'Mug' ): FakeProduct {
		return new FakeProduct(
			$id,
			self::stored(
				[
					'id'             => $id,
					'name'           => $name,
					'stock_quantity' => $stock,
				]
			)
		);
	}

	/**
	 * Variable product 50, "Hoodie", published, as a post.
	 *
	 * @param array<string, mixed> $over Fields to replace.
	 */
	private static function variable_product( array $over = [] ): \WP_Post {
		return self::post(
			array_merge(
				[
					'ID'         => 50,
					'post_title' => 'Hoodie',
					'post_name'  => 'hoodie',
				],
				$over
			)
		);
	}

	/**
	 * Variation `100 + $n` of product 50, "Hoodie - Size <n>", published.
	 *
	 * @param int                  $n    Number of the variation.
	 * @param array<string, mixed> $over Fields to replace.
	 */
	private static function variation( int $n, array $over = [] ): \WP_Post {
		return self::post(
			array_merge(
				[
					'ID'          => 100 + $n,
					'post_type'   => 'product_variation',
					'post_title'  => 'Hoodie - Size ' . $n,
					'post_name'   => 'hoodie-size-' . $n,
					'post_parent' => 50,
				],
				$over
			)
		);
	}

	/**
	 * Post 5, whose id can be read and nothing else (a filter on it throws).
	 */
	private static function broken_post(): \WP_Post {
		return new class( [] ) extends \WP_Post {
			public function __get( string $name ) {
				if ( 'ID' === $name ) {
					return 5;
				}
				throw new \RuntimeException( 'A filter broke.' );
			}
		};
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
