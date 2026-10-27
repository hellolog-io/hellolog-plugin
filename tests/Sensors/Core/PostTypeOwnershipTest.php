<?php
/**
 * Tests for {@see HelloLog\Sensors\Core\PostTypeOwnership}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Sensors\Core\PostTypeOwnership;
use PHPUnit\Framework\TestCase;

/**
 * Pins, type by type, which post events another sensor reports in its own
 * words: exactly the ones that sensor emits, and only while its plugin is
 * active. Everything else stays with the post sensor.
 */
final class PostTypeOwnershipTest extends TestCase {

	/** Every post event code. */
	private const CODES = [ 2000, 2001, 2002, 2003, 2004, 2005, 2006, 2007, 2008, 2009, 2013 ];

	/**
	 * What each owner reports itself, by type: written out here, not read
	 * from the class, so a change to the table is a deliberate one.
	 *
	 * @return array<string, array{0: string, 1: array<int, int>}> type => [ type, owned codes ].
	 */
	public static function owned_codes(): array {
		$store  = [ 2000, 2001, 2002, 2005, 2006, 2007, 2008, 2009 ];
		$orders = [ 2000, 2001, 2002, 2006, 2007, 2008, 2009 ];

		return [
			// The store sensors report products, orders and coupons in full,
			// but for the permanent delete of an order: the classic order
			// screens delete it with WordPress's own delete, which fires no
			// hook of the shop.
			'product'              => [ 'product', $store ],
			'product_variation'    => [ 'product_variation', $store ],
			'shop_order'           => [ 'shop_order', $orders ],
			'shop_order_placehold' => [ 'shop_order_placehold', $orders ],
			'shop_order_refund'    => [ 'shop_order_refund', $store ],
			'shop_coupon'          => [ 'shop_coupon', $store ],
			// bbPress: published (8000) and deleted (8001).
			'forum'                => [ 'forum', [ 2001, 2005 ] ],
			'topic'                => [ 'topic', [ 2001, 2005 ] ],
			'reply'                => [ 'reply', [ 2001, 2005 ] ],
			// LearnDash and Easy Digital Downloads: published only (8200, 8300).
			'course'               => [ 'sfwd-courses', [ 2001 ] ],
			'lesson'               => [ 'sfwd-lessons', [ 2001 ] ],
			'learndash topic'      => [ 'sfwd-topic', [ 2001 ] ],
			'quiz'                 => [ 'sfwd-quiz', [ 2001 ] ],
			'download'             => [ 'download', [ 2001 ] ],
			// ACF: field group created or updated (5400/5401) and deleted (5402).
			'field group'          => [ 'acf-field-group', [ 2000, 2001, 2002, 2005, 2006 ] ],
			// The media sensor, and WordPress's internal records nobody edits.
			'attachment'           => [ 'attachment', $store ],
			'revision'             => [ 'revision', $store ],
			'menu item'            => [ 'nav_menu_item', $store ],
			'customizer changeset' => [ 'customize_changeset', $store ],
			'oembed cache'         => [ 'oembed_cache', $store ],
			'privacy request'      => [ 'user_request', $store ],
		];
	}

	/**
	 * With its owner active, a type's owned events are left to the owner and
	 * every other event of it stays with the post sensor.
	 *
	 * @dataProvider owned_codes
	 *
	 * @param array<int, int> $owned Codes the owner reports.
	 */
	public function test_an_active_owner_takes_exactly_the_events_it_reports( string $post_type, array $owned ): void {
		foreach ( self::CODES as $code ) {
			$this->assertSame(
				in_array( $code, $owned, true ),
				PostTypeOwnership::is_owned_elsewhere( $post_type, $code, true ),
				$post_type . ' ' . $code
			);
		}
	}

	/**
	 * The owner's own words: no owner reports a trash, a restore or a revert
	 * to `auto-draft`.
	 *
	 * @dataProvider owned_codes
	 */
	public function test_trash_restore_and_revert_are_never_owned( string $post_type ): void {
		foreach ( [ 2003, 2004, 2013 ] as $code ) {
			$this->assertFalse( PostTypeOwnership::is_owned_elsewhere( $post_type, $code, true ), $post_type . ' ' . $code );
		}
	}

	public function test_the_permanent_delete_of_an_order_stays_with_the_post_sensor(): void {
		$this->assertFalse( PostTypeOwnership::is_owned_elsewhere( 'shop_order', 2005, true ) );
		$this->assertFalse( PostTypeOwnership::is_owned_elsewhere( 'shop_order_placehold', 2005, true ) );
		// A refund is deleted with its order; products and coupons have their own delete events.
		foreach ( [ 'shop_order_refund', 'product', 'product_variation', 'shop_coupon' ] as $type ) {
			$this->assertTrue( PostTypeOwnership::is_owned_elsewhere( $type, 2005, true ), $type );
		}
	}

	public function test_the_events_the_plugin_sensors_do_not_report_stay_with_the_post_sensor(): void {
		// Easy Digital Downloads reports a publish, not a delete.
		$this->assertFalse( PostTypeOwnership::is_owned_elsewhere( 'download', 2005, true ) );
		$this->assertTrue( PostTypeOwnership::is_owned_elsewhere( 'download', 2001, true ) );
		// LearnDash reports a publish, not an edit or an unpublish.
		$this->assertFalse( PostTypeOwnership::is_owned_elsewhere( 'sfwd-lessons', 2006, true ) );
		$this->assertFalse( PostTypeOwnership::is_owned_elsewhere( 'sfwd-lessons', 2002, true ) );
		// bbPress reports a publish and a delete, not an edit.
		$this->assertTrue( PostTypeOwnership::is_owned_elsewhere( 'topic', 2001, true ) );
		$this->assertTrue( PostTypeOwnership::is_owned_elsewhere( 'topic', 2005, true ) );
		$this->assertFalse( PostTypeOwnership::is_owned_elsewhere( 'topic', 2006, true ) );
		// ACF reports every save of a field group, not its trash.
		$this->assertTrue( PostTypeOwnership::is_owned_elsewhere( 'acf-field-group', 2006, true ) );
		$this->assertFalse( PostTypeOwnership::is_owned_elsewhere( 'acf-field-group', 2007, true ) );
	}

	/**
	 * Without its plugin a type of that name belongs to some other plugin
	 * (a forum plugin is not the only one with topics) and nobody else
	 * reports it.
	 *
	 * @dataProvider owned_codes
	 */
	public function test_a_plugins_type_is_owned_only_while_its_plugin_is_active( string $post_type, array $owned ): void {
		$always = in_array( $post_type, [ 'attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request' ], true );

		foreach ( self::CODES as $code ) {
			$this->assertSame(
				$always && in_array( $code, $owned, true ),
				PostTypeOwnership::is_owned_elsewhere( $post_type, $code, false ),
				$post_type . ' ' . $code
			);
		}
	}

	public function test_other_types_are_never_owned(): void {
		foreach ( [ 'post', 'page', 'wc_booking', 'wp_template', 'Product', 'forum ' ] as $type ) {
			foreach ( self::CODES as $code ) {
				$this->assertFalse( PostTypeOwnership::is_owned_elsewhere( $type, $code, true ), $type . ' ' . $code );
			}
		}
	}

	public function test_the_owners_are_exactly_these(): void {
		$this->assertSame(
			[
				'product'              => 'woocommerce',
				'product_variation'    => 'woocommerce',
				'shop_order'           => 'woocommerce',
				'shop_order_placehold' => 'woocommerce',
				'shop_order_refund'    => 'woocommerce',
				'shop_coupon'          => 'woocommerce',
				'forum'                => 'bbpress',
				'topic'                => 'bbpress',
				'reply'                => 'bbpress',
				'sfwd-courses'         => 'learndash',
				'sfwd-lessons'         => 'learndash',
				'sfwd-topic'           => 'learndash',
				'sfwd-quiz'            => 'learndash',
				'download'             => 'edd',
				'acf-field-group'      => 'acf',
				'attachment'           => 'media',
				'revision'             => 'internal',
				'nav_menu_item'        => 'internal',
				'customize_changeset'  => 'internal',
				'oembed_cache'         => 'internal',
				'user_request'         => 'internal',
			],
			PostTypeOwnership::OWNERS
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_plugin_owner_is_active_without_its_plugin(): void {
		foreach ( [ 'product', 'shop_order', 'forum', 'sfwd-lessons', 'download', 'acf-field-group' ] as $type ) {
			$this->assertFalse( PostTypeOwnership::owner_active( $type ), $type );
		}
		foreach ( [ 'attachment', 'revision', 'post', 'wc_booking' ] as $type ) {
			$this->assertTrue( PostTypeOwnership::owner_active( $type ), $type );
		}
	}

	/**
	 * Each plugin is told active the way its sensor decides to load.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_each_plugin_owner_is_active_with_its_plugin(): void {
		require_once __DIR__ . '/Fixtures/woocommerce-class.php';
		require_once __DIR__ . '/Fixtures/bbpress-class.php';
		require_once __DIR__ . '/Fixtures/acf-class.php';
		define( 'LEARNDASH_VERSION', '4.0.0' );
		define( 'EDD_VERSION', '3.0.0' );

		foreach ( [ 'product', 'shop_coupon', 'topic', 'sfwd-quiz', 'download', 'acf-field-group' ] as $type ) {
			$this->assertTrue( PostTypeOwnership::owner_active( $type ), $type );
		}
	}

	/**
	 * bbPress is also told active by its version function, as its sensor does.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bbpress_is_active_by_its_version_function_too(): void {
		require_once __DIR__ . '/Fixtures/bbpress-functions.php';

		$this->assertTrue( PostTypeOwnership::owner_active( 'reply' ) );
		$this->assertFalse( PostTypeOwnership::owner_active( 'download' ) );
	}
}
