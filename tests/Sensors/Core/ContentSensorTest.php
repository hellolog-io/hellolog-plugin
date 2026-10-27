<?php
/**
 * Tests for {@see HelloLog\Sensors\Core\ContentSensor}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\RequestContext;
use HelloLog\Events\RequestSource;
use HelloLog\Events\SuppressionRules;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Core\ContentSensor;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use PHPUnit\Framework\TestCase;

/**
 * Pins which status change is which event, which post types the generic post
 * sensor leaves to someone else, and the shape of the target, links and
 * changes every post event carries. Saves are replayed hook by hook in the
 * order WordPress fires them, from the classic editor and the REST API.
 */
final class ContentSensorTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string, 2: int}> new status, old status, expected code.
	 */
	public static function status_transitions(): array {
		return [
			'auto-draft to publish'     => [ 'publish', 'auto-draft', 2001 ],
			'new to publish'            => [ 'publish', 'new', 2001 ],
			'draft to publish'          => [ 'publish', 'draft', 2001 ],
			'pending to publish'        => [ 'publish', 'pending', 2001 ],
			'future to publish'         => [ 'publish', 'future', 2001 ],
			'auto-draft to draft'       => [ 'draft', 'auto-draft', 2000 ],
			'auto-draft to pending'     => [ 'pending', 'auto-draft', 2008 ],
			'auto-draft to future'      => [ 'future', 'auto-draft', 2007 ],
			'auto-draft to private'     => [ 'private', 'auto-draft', 2009 ],
			'publish to draft'          => [ 'draft', 'publish', 2002 ],
			'publish to pending'        => [ 'pending', 'publish', 2002 ],
			'publish to private'        => [ 'private', 'publish', 2002 ],
			'publish to future'         => [ 'future', 'publish', 2002 ],
			'publish to trash'          => [ 'trash', 'publish', 2003 ],
			'draft to trash'            => [ 'trash', 'draft', 2003 ],
			'auto-draft to trash'       => [ 'trash', 'auto-draft', 2003 ],
			'private to trash'          => [ 'trash', 'private', 2003 ],
			'publish to publish'        => [ 'publish', 'publish', 0 ],
			'draft to draft'            => [ 'draft', 'draft', 0 ],
			'auto-draft to auto-draft'  => [ 'auto-draft', 'auto-draft', 0 ],
			'trash to trash'            => [ 'trash', 'trash', 0 ],
			'draft to pending'          => [ 'pending', 'draft', 2008 ],
			'pending to draft'          => [ 'draft', 'pending', 0 ],
			'future to draft'           => [ 'draft', 'future', 0 ],
			'private to draft'          => [ 'draft', 'private', 0 ],
			'draft to future'           => [ 'future', 'draft', 2007 ],
			'pending to future'         => [ 'future', 'pending', 2007 ],
			'private to future'         => [ 'future', 'private', 2007 ],
			'future to pending'         => [ 'pending', 'future', 2008 ],
			'private to pending'        => [ 'pending', 'private', 2008 ],
			'draft to private'          => [ 'private', 'draft', 2009 ],
			'pending to private'        => [ 'private', 'pending', 2009 ],
			'future to private'         => [ 'private', 'future', 2009 ],
			'custom to private'         => [ 'private', 'acf-disabled', 2009 ],
			'new to draft'              => [ 'draft', 'new', 2000 ],
			'new to pending'            => [ 'pending', 'new', 2008 ],
			'new to future'             => [ 'future', 'new', 2007 ],
			'new to private'            => [ 'private', 'new', 2009 ],
			'new to trash'              => [ 'trash', 'new', 2003 ],
			'new to inherit'            => [ 'inherit', 'new', 0 ],
			'new to auto-draft'         => [ 'auto-draft', 'new', 0 ],
			'draft to auto-draft'       => [ 'auto-draft', 'draft', 2013 ],
			'pending to auto-draft'     => [ 'auto-draft', 'pending', 2013 ],
			'private to auto-draft'     => [ 'auto-draft', 'private', 2013 ],
			'future to auto-draft'      => [ 'auto-draft', 'future', 2013 ],
			// Out of the trash into the placeholder state WordPress deletes on its
			// own: that is the silent deletion "reverted" exists to flag.
			'trash to auto-draft'       => [ 'auto-draft', 'trash', 2013 ],
			'custom to auto-draft'      => [ 'auto-draft', 'acf-disabled', 2013 ],
			'publish to auto-draft'     => [ 'auto-draft', 'publish', 2013 ],
			'trash to publish'          => [ 'publish', 'trash', 2004 ],
			'private to publish'        => [ 'publish', 'private', 2001 ],
			'trash to draft'            => [ 'draft', 'trash', 2004 ],
			'trash to pending'          => [ 'pending', 'trash', 2004 ],
			'trash to private'          => [ 'private', 'trash', 2004 ],
			'trash to future'           => [ 'future', 'trash', 2004 ],
			'trash to inherit'          => [ 'inherit', 'trash', 2004 ],
			'trash to an order status'  => [ 'wc-processing', 'trash', 2004 ],
			'auto-draft to inherit'     => [ 'inherit', 'auto-draft', 0 ],
		];
	}

	/**
	 * @dataProvider status_transitions
	 */
	public function test_status_event_code( string $new, string $old, int $expected ): void {
		$this->assertSame( $expected, ContentSensor::status_event_code( $new, $old ) );
	}

	public function test_owned_elsewhere_lists_exactly_the_agreed_types(): void {
		$this->assertSame(
			[
				'product',
				'product_variation',
				'shop_order',
				'shop_order_placehold',
				'shop_order_refund',
				'shop_coupon',
				'revision',
				'nav_menu_item',
				'customize_changeset',
				'oembed_cache',
				'user_request',
				'attachment',
				'forum',
				'topic',
				'reply',
				'sfwd-courses',
				'sfwd-lessons',
				'sfwd-topic',
				'sfwd-quiz',
				'download',
				'acf-field-group',
			],
			ContentSensor::OWNED_ELSEWHERE
		);
	}

	public function test_site_editor_types_are_named_by_the_editors_labels(): void {
		$this->assertSame(
			[
				'wp_template'      => 'Template',
				'wp_template_part' => 'Template part',
				'wp_global_styles' => 'Global styles',
				'wp_navigation'    => 'Navigation menu',
				'wp_block'         => 'Pattern',
			],
			ContentSensor::SITE_EDITOR_TYPES
		);

		foreach ( ContentSensor::SITE_EDITOR_TYPES as $type => $label ) {
			$this->assertSame( $label, ContentSensor::type_label( $type ), $type );
		}
	}

	/**
	 * @return array<string, array{0: string, 1: string}> post type, label.
	 */
	public static function type_labels(): array {
		return [
			'post'            => [ 'post', 'Post' ],
			'page'            => [ 'page', 'Page' ],
			'underscored'     => [ 'wc_booking', 'Wc booking' ],
			'hyphenated'      => [ 'sfwd-courses', 'Sfwd courses' ],
			'attachment'      => [ 'attachment', 'Attachment' ],
			'wrong case'      => [ 'WP_Template', 'WP Template' ],
			'site editor-ish' => [ 'wp_template_parts', 'Wp template parts' ],
		];
	}

	/**
	 * Every other type is named by its own name, made readable.
	 *
	 * @dataProvider type_labels
	 */
	public function test_other_types_are_named_by_their_name( string $post_type, string $label ): void {
		$this->assertSame( $label, ContentSensor::type_label( $post_type ) );
	}

	/**
	 * Templates, template parts and global styles have no admin screen of
	 * their own (they are edited in the site editor), and every event of a
	 * site-editor type is logged.
	 */
	public function test_site_editor_types_are_tracked_with_or_without_an_admin_screen(): void {
		foreach ( array_keys( ContentSensor::SITE_EDITOR_TYPES ) as $type ) {
			foreach ( [ 2000, 2001, 2002, 2003, 2004, 2005, 2006, 2007, 2008, 2009, 2013 ] as $code ) {
				$this->assertTrue( ContentSensor::is_tracked_type( $type, false, $code, true ), $type . ' ' . $code );
				$this->assertTrue( ContentSensor::is_tracked_type( $type, true, $code, false ), $type . ' ' . $code );
			}
		}
	}

	/**
	 * Forums, topics, replies and downloads are common names: they are the
	 * forum's or the download shop's only while that plugin is active, the
	 * way a `product` is the shop's only with WooCommerce. The course and
	 * field group types carry their plugin's prefix and are always theirs.
	 */
	public function test_shared_type_names_are_owned_elsewhere_only_while_their_plugin_is_active(): void {
		foreach ( [ 'forum', 'topic', 'reply', 'download' ] as $type ) {
			$this->assertFalse( ContentSensor::is_tracked_type( $type, true, 2006, true ), $type );
			$this->assertTrue( ContentSensor::is_tracked_type( $type, true, 2006, false ), $type );
			$this->assertTrue( ContentSensor::is_tracked_type( $type, true, 2003, true ), $type );
			$this->assertFalse( ContentSensor::is_tracked_type( $type, false, 2006, false ), $type );
		}
		foreach ( [ 'sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'acf-field-group' ] as $type ) {
			$this->assertFalse( ContentSensor::is_tracked_type( $type, true, 2006, false ), $type );
			$this->assertTrue( ContentSensor::is_tracked_type( $type, true, 2003, false ), $type );
		}
	}

	/**
	 * @return array<string, array{0: string, 1: bool, 2: bool}> post type, show_ui, expected.
	 */
	public static function tracked_types(): array {
		return [
			'post with ui'                  => [ 'post', true, true ],
			'page with ui'                  => [ 'page', true, true ],
			'custom type with ui'           => [ 'wc_booking', true, true ],
			'custom type without ui'        => [ 'wc_booking', false, false ],
			'order note is not a post type' => [ 'order_note', false, false ],
			'page without ui'               => [ 'page', false, false ],
			'internal css post'             => [ 'custom_css', false, false ],
			'product'                       => [ 'product', true, false ],
			'product variation'             => [ 'product_variation', false, false ],
			'order'                         => [ 'shop_order', true, false ],
			'order placeholder'             => [ 'shop_order_placehold', true, false ],
			'order placeholder no ui'       => [ 'shop_order_placehold', false, false ],
			'refund'                        => [ 'shop_order_refund', true, false ],
			'coupon'                        => [ 'shop_coupon', true, false ],
			'revision'                      => [ 'revision', true, false ],
			'menu item'                     => [ 'nav_menu_item', true, false ],
			'customizer changeset'          => [ 'customize_changeset', true, false ],
			'oembed cache'                  => [ 'oembed_cache', true, false ],
			'privacy request'               => [ 'user_request', true, false ],
			'attachment'                    => [ 'attachment', true, false ],
		];
	}

	/**
	 * Which types an ordinary event (an update) is logged for, with the shop
	 * active.
	 *
	 * @dataProvider tracked_types
	 */
	public function test_is_tracked_type( string $post_type, bool $show_ui, bool $expected ): void {
		$this->assertSame( $expected, ContentSensor::is_tracked_type( $post_type, $show_ui, 2006, true ) );
	}

	/**
	 * @return array<string, array{0: string, 1: int, 2: bool}> post type, event code, expected.
	 */
	public static function owned_type_codes(): array {
		$cases = [];
		foreach ( ContentSensor::OWNED_ELSEWHERE as $type ) {
			foreach ( [ 2003, 2004, 2013 ] as $code ) {
				$cases[ $type . ' ' . $code ] = [ $type, $code, true ];
			}
			foreach ( [ 2000, 2001, 2002, 2005, 2006, 2007, 2008, 2009 ] as $code ) {
				$cases[ $type . ' ' . $code ] = [ $type, $code, false ];
			}
		}

		return $cases;
	}

	/**
	 * The store sensors report products, coupons and orders in their own
	 * words, but not their trash, restore or revert to auto-draft: those
	 * stay with this sensor.
	 *
	 * @dataProvider owned_type_codes
	 */
	public function test_types_owned_elsewhere_keep_only_trash_restore_and_revert( string $post_type, int $code, bool $expected ): void {
		$this->assertSame( $expected, ContentSensor::is_tracked_type( $post_type, true, $code, true ) );
	}

	public function test_trash_and_restore_of_a_type_without_ui_are_not_logged(): void {
		$this->assertFalse( ContentSensor::is_tracked_type( 'product_variation', false, 2003, true ) );
		$this->assertFalse( ContentSensor::is_tracked_type( 'revision', false, 2004, true ) );
	}

	/**
	 * Without WooCommerce a `product` type belongs to some other plugin, and
	 * nobody else reports it.
	 */
	public function test_without_woocommerce_a_product_type_is_tracked_like_any_other(): void {
		foreach ( [ 2000, 2001, 2002, 2003, 2004, 2005, 2006, 2007, 2008, 2009, 2013 ] as $code ) {
			$this->assertTrue( ContentSensor::is_tracked_type( 'product', true, $code, false ), (string) $code );
		}
		$this->assertFalse( ContentSensor::is_tracked_type( 'product', false, 2006, false ) );
		$this->assertFalse( ContentSensor::is_tracked_type( 'shop_coupon', true, 2006, false ) );
	}

	public function test_post_target_has_a_string_id_and_the_title_as_label(): void {
		$this->assertSame(
			[
				'type'  => 'post',
				'id'    => '42',
				'label' => 'Hello world',
			],
			ContentSensor::post_target( 42, 'Hello world' )
		);
	}

	public function test_post_links_has_edit_and_view_for_a_published_post(): void {
		$this->assertSame(
			[
				'edit' => 'https://example.test/wp-admin/post.php?post=7&action=edit',
				'view' => 'https://example.test/hello/',
			],
			ContentSensor::post_links( 'https://example.test/wp-admin/post.php?post=7&action=edit', 'https://example.test/hello/', 'publish' )
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unpublished_statuses(): array {
		return [
			'draft'   => [ 'draft' ],
			'pending' => [ 'pending' ],
			'future'  => [ 'future' ],
			'private' => [ 'private' ],
			'trash'   => [ 'trash' ],
		];
	}

	/**
	 * @dataProvider unpublished_statuses
	 */
	public function test_post_links_has_no_view_unless_published( string $status ): void {
		$this->assertSame(
			[ 'edit' => 'https://example.test/wp-admin/post.php?post=7&action=edit' ],
			ContentSensor::post_links( 'https://example.test/wp-admin/post.php?post=7&action=edit', 'https://example.test/?p=7', $status )
		);
	}

	public function test_post_links_leaves_out_links_core_could_not_give(): void {
		$this->assertSame( [], ContentSensor::post_links( null, null, 'publish' ) );
		$this->assertSame( [], ContentSensor::post_links( '', '', 'publish' ) );
		$this->assertSame(
			[ 'view' => 'https://example.test/hello/' ],
			ContentSensor::post_links( null, 'https://example.test/hello/', 'publish' )
		);
	}

	/**
	 * @return array<string, array{0: string, 1: bool, 2: bool}> post status, in WP's own auto-draft cleanup, expected.
	 */
	public static function delete_cases(): array {
		$cases = [
			'auto-draft in the cleanup'      => [ 'auto-draft', true, false ],
			'auto-draft outside the cleanup' => [ 'auto-draft', false, true ],
		];
		foreach ( [ 'draft', 'publish', 'pending', 'future', 'private', 'trash', 'inherit' ] as $status ) {
			$cases[ $status . ' in the cleanup' ]      = [ $status, true, true ];
			$cases[ $status . ' outside the cleanup' ] = [ $status, false, true ];
		}

		return $cases;
	}

	/**
	 * @dataProvider delete_cases
	 */
	public function test_is_loggable_delete( string $status, bool $in_auto_draft_cleanup, bool $expected ): void {
		$this->assertSame( $expected, ContentSensor::is_loggable_delete( $status, $in_auto_draft_cleanup ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_permanent_delete_carries_no_links(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->on_delete( 7, $post( [ 'post_status' => 'publish' ] ) );
			}
		);

		$this->assertCount( 1, $calls );
		$this->assertSame( 2005, $calls[0][0] );
		$this->assertArrayNotHasKey( 'links', $calls[0][1] );
		$this->assertSame(
			[
				'type'  => 'post',
				'id'    => '7',
				'label' => 'Hello',
			],
			$calls[0][1]['target']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_an_auto_draft_in_wps_cleanup_emits_nothing(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				WpState::$doing_action = 'wp_scheduled_auto_draft_delete';
				$sensor->on_delete( 7, $post( [ 'post_status' => 'auto-draft' ] ) );
			}
		);

		$this->assertSame( [], $calls );
	}

	/**
	 * Setting a post to `auto-draft` and deleting it must not be a way to hide
	 * the deletion: only the cleanup WordPress runs itself is skipped.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_an_auto_draft_outside_the_cleanup_is_logged(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->on_delete( 7, $post( [ 'post_status' => 'auto-draft' ] ) );

				WpState::$doing_action = 'wp_ajax_delete-post';
				$sensor->on_delete( 8, $post( [ 'ID' => 8, 'post_status' => 'auto-draft' ] ) );
			}
		);

		$this->assertSame( [ 2005, 2005 ], array_column( $calls, 0 ) );
		$this->assertSame( '7', $calls[0][1]['target']['id'] );
		$this->assertSame( '8', $calls[1][1]['target']['id'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_a_real_post_is_logged_even_inside_the_cleanup(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				WpState::$doing_action = 'wp_scheduled_auto_draft_delete';
				$sensor->on_delete( 7, $post( [ 'post_status' => 'publish' ] ) );
			}
		);

		$this->assertSame( [ 2005 ], array_column( $calls, 0 ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_other_post_events_still_carry_links(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->on_status( 'publish', 'draft', $post( [ 'post_status' => 'publish' ] ) );
				$sensor->on_status( 'trash', 'publish', $post( [ 'post_status' => 'trash' ] ) );
			}
		);

		$this->assertSame( [ 2001, 2003 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				'edit' => 'https://example.test/wp-admin/post.php?post=7&action=edit',
				'view' => 'https://example.test/p/7/',
			],
			$calls[0][1]['links']
		);
		$this->assertSame(
			[ 'edit' => 'https://example.test/wp-admin/post.php?post=7&action=edit' ],
			$calls[1][1]['links']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_direct_insert_as_draft_is_a_creation(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->on_status( 'draft', 'new', $post( [ 'post_status' => 'draft' ] ) );
			}
		);

		$this->assertSame( [ 2000 ], array_column( $calls, 0 ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_owned_elsewhere_and_ui_less_types_emit_nothing(): void {
		require_once __DIR__ . '/Fixtures/woocommerce-class.php';

		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->on_status( 'publish', 'draft', $post( [ 'post_type' => 'product' ] ) );
				$sensor->on_status( 'draft', 'auto-draft', $post( [ 'post_type' => 'shop_coupon' ] ) );
				$sensor->on_status( 'publish', 'draft', $post( [ 'post_type' => 'custom_css' ] ) );
				$sensor->on_status( 'publish', 'draft', $post( [ 'post_type' => 'not_registered' ] ) );
				$sensor->on_status( 'trash', 'publish', $post( [ 'post_type' => 'custom_css' ] ) );
				$sensor->on_delete( 7, $post( [ 'post_type' => 'product' ] ) );

				$sensor->boot();
				self::update_post( $post( [ 'post_type' => 'product' ] ), $post( [ 'post_type' => 'product', 'post_title' => 'New' ] ) );
				self::update_post( $post( [ 'post_type' => 'custom_css' ] ), $post( [ 'post_type' => 'custom_css', 'post_title' => 'New' ] ) );
			}
		);

		$this->assertSame( [], $calls );
	}

	/**
	 * No store sensor reports a product, coupon or order (stored as a post)
	 * going to the trash or coming back from it, so this one does.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_trash_and_restore_of_products_coupons_and_orders_are_logged(): void {
		require_once __DIR__ . '/Fixtures/woocommerce-class.php';

		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->on_status( 'trash', 'publish', $post( [ 'post_type' => 'product', 'post_status' => 'trash' ] ) );
				$sensor->on_status( 'trash', 'publish', $post( [ 'ID' => 8, 'post_type' => 'shop_coupon', 'post_status' => 'trash' ] ) );
				$sensor->on_status( 'trash', 'wc-processing', $post( [ 'ID' => 9, 'post_type' => 'shop_order', 'post_status' => 'trash' ] ) );

				WpState::$posts[7] = $post( [ 'post_type' => 'product', 'post_status' => 'draft' ] );
				WpState::$posts[8] = $post( [ 'ID' => 8, 'post_type' => 'shop_coupon', 'post_status' => 'draft' ] );
				$sensor->on_untrashed( 7, 'publish' );
				$sensor->on_untrashed( 8, 'publish' );
			}
		);

		$this->assertSame( [ 2003, 2003, 2003, 2004, 2004 ], array_column( $calls, 0 ) );
		$this->assertSame( [ '7', '8', '9', '7', '8' ], array_map( static fn( array $call ): string => $call[1]['target']['id'], $calls ) );
		$this->assertSame( 'product', $calls[0][1]['post']['type'] );
		$this->assertSame( 'shop_coupon', $calls[4][1]['post']['type'] );
	}

	/**
	 * The REST API has no restore route: the block editor's Restore sends a
	 * plain status update (`{"status":"draft"}`), so the status change is
	 * the only sign of the restore.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_restore_through_a_status_update_is_logged_once(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->boot();
				self::rest_update(
					$post(
						[
							'post_status' => 'trash',
							'post_name'   => 'hello__trashed',
						]
					),
					$post( [ 'post_status' => 'draft' ] ),
					static function (): void {
					}
				);
			}
		);

		$this->assertSame( [ 2004 ], array_column( $calls, 0 ) );
		// The slug only loses the suffix WordPress gave it in the trash.
		$this->assertArrayNotHasKey( 'changes', $calls[0][1] );
		$this->assertSame(
			[
				'type'  => 'post',
				'id'    => '7',
				'label' => 'Hello',
			],
			$calls[0][1]['target']
		);
		$this->assertSame( 'draft', $calls[0][1]['post']['status'] );
		$this->assertSame(
			[ 'edit' => 'https://example.test/wp-admin/post.php?post=7&action=edit' ],
			$calls[0][1]['links']
		);
	}

	/**
	 * `wp_untrash_post()` fires `untrash_post`, changes the status (which
	 * fires the status change) and then fires `untrashed_post`: one restore,
	 * one event, still telling the status the post had before the trash.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_untrash_post_logs_one_restore_with_the_previous_status(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->boot();
				self::fire( 'untrash_post', 7, 'publish' );
				self::update_post(
					$post(
						[
							'post_status' => 'trash',
							'post_name'   => 'hello__trashed',
						]
					),
					$post( [ 'post_status' => 'draft' ] )
				);
				self::fire( 'untrashed_post', 7, 'publish' );
			}
		);

		$this->assertSame( [ 2004 ], array_column( $calls, 0 ) );
		$this->assertSame( 'publish', $calls[0][1]['metadata']['previous_status'] );
		$this->assertSame( '7', $calls[0][1]['target']['id'] );
		$this->assertArrayNotHasKey( 'changes', $calls[0][1] );
	}

	/**
	 * Trashed and restored twice in one request: two restores, each once.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_each_restore_of_a_request_is_logged_once(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$draft             = $post( [ 'post_status' => 'draft' ] );
				WpState::$posts[7] = $draft;

				$sensor->on_untrash( 7, 'publish' );
				$sensor->on_status( 'draft', 'trash', $draft );
				$sensor->on_untrashed( 7, 'publish' );

				$sensor->on_status( 'trash', 'draft', $post( [ 'post_status' => 'trash' ] ) );
				$sensor->on_status( 'draft', 'trash', $draft );
			}
		);

		$this->assertSame( [ 2004, 2003, 2004 ], array_column( $calls, 0 ) );
		$this->assertSame( 'publish', $calls[0][1]['metadata']['previous_status'] );
		$this->assertArrayNotHasKey( 'previous_status', $calls[2][1]['metadata'] );
	}

	/**
	 * A restore through a status update leaves nothing behind: a later
	 * `untrashed_post` of its own (fired by some plugin) is still logged.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_status_update_restore_does_not_swallow_a_later_untrashed_post(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$draft             = $post( [ 'post_status' => 'draft' ] );
				WpState::$posts[7] = $draft;

				$sensor->on_status( 'draft', 'trash', $draft );
				$sensor->on_untrashed( 7, 'publish' );
			}
		);

		$this->assertSame( [ 2004, 2004 ], array_column( $calls, 0 ) );
		$this->assertSame( 'publish', $calls[1][1]['metadata']['previous_status'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_both_restore_hooks_are_registered_with_the_previous_status(): void {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';
		$this->run_hook( static fn( ContentSensor $sensor ) => $sensor->boot() );

		foreach ( [ 'untrash_post', 'untrashed_post' ] as $hook ) {
			$this->assertSame( 2, WpState::$actions[ $hook ]['accepted_args'] ?? null, $hook );
		}
	}

	/**
	 * A trashed post sent to `auto-draft` is cleaned up by WordPress later
	 * without a trace: it is logged as reverted, not as restored.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_trashed_post_sent_to_auto_draft_is_reverted_not_restored(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->on_status( 'auto-draft', 'trash', $post( [ 'post_status' => 'auto-draft' ] ) );
			}
		);

		$this->assertSame( [ 2013 ], array_column( $calls, 0 ) );
	}

	/**
	 * No store sensor reports a product or an order sent to `auto-draft`,
	 * where WordPress's cleanup deletes it unseen, so this one does.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_product_or_order_sent_to_auto_draft_is_logged_once_as_reverted(): void {
		require_once __DIR__ . '/Fixtures/woocommerce-class.php';

		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				foreach ( [ 'product' => 7, 'shop_order' => 9 ] as $type => $id ) {
					foreach ( [ 'trash', 'publish' ] as $old ) {
						$sensor->on_status( 'auto-draft', $old, $post( [ 'ID' => $id, 'post_type' => $type, 'post_status' => 'auto-draft' ] ) );
					}
				}
			}
		);

		$this->assertSame( [ 2013, 2013, 2013, 2013 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[ 'post:7', 'post:7', 'post:9', 'post:9' ],
			array_map( static fn( array $call ): string => $call[1]['target']['type'] . ':' . $call[1]['target']['id'], $calls )
		);
		$this->assertSame( [ 'product', 'product', 'shop_order', 'shop_order' ], array_map( static fn( array $call ): string => $call[1]['post']['type'], $calls ) );
	}

	/**
	 * A product or coupon restored through the shop's REST API is a status
	 * update too.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_product_or_coupon_restored_through_a_status_update_is_logged(): void {
		require_once __DIR__ . '/Fixtures/woocommerce-class.php';

		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->on_status( 'draft', 'trash', $post( [ 'post_type' => 'product', 'post_status' => 'draft' ] ) );
				$sensor->on_status( 'publish', 'trash', $post( [ 'ID' => 8, 'post_type' => 'shop_coupon', 'post_status' => 'publish' ] ) );
			}
		);

		$this->assertSame( [ 2004, 2004 ], array_column( $calls, 0 ) );
		$this->assertSame( [ 'product', 'shop_coupon' ], array_map( static fn( array $call ): string => $call[1]['post']['type'], $calls ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_woocommerce_a_product_post_type_is_logged_like_any_other(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->on_status( 'publish', 'draft', $post( [ 'post_type' => 'product' ] ) );
				$sensor->on_delete( 7, $post( [ 'post_type' => 'product' ] ) );
			}
		);

		$this->assertFalse( class_exists( 'WooCommerce', false ) );
		$this->assertSame( [ 2001, 2005 ], array_column( $calls, 0 ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_password_protecting_a_published_post_is_an_update_that_never_carries_the_password(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->boot();
				self::update_post( $post( [ 'post_password' => '' ] ), $post( [ 'post_password' => 'hunter2' ] ) );
			}
		);

		$this->assertSame( [ 2006 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				[
					'field' => 'password_protected',
					'old'   => 'no',
					'new'   => 'yes',
				],
			],
			$calls[0][1]['changes']
		);
		$this->assertSame( [ 'password_protected' ], $calls[0][1]['metadata']['changed'] );
		$this->assertStringNotContainsString( 'hunter2', serialize( $calls ) );
	}

	/**
	 * A new password on a post that already had one is not reported: the
	 * password itself never is.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_changed_password_is_never_reported(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->boot();
				self::update_post( $post( [ 'post_password' => 'OLDSECRET' ] ), $post( [ 'post_password' => 'NEWSECRET' ] ) );
			}
		);

		$this->assertSame( [ 2006 ], array_column( $calls, 0 ) );
		$this->assertSame( [], $calls[0][1]['changes'] );
		$this->assertStringNotContainsString( 'SECRET', serialize( $calls ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_backdating_or_reordering_a_post_is_an_update(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->boot();
				self::update_post(
					$post( [ 'post_date' => '2026-10-26 10:00:00', 'post_date_gmt' => '2026-10-26 09:00:00' ] ),
					$post( [ 'post_date' => '2020-01-01 09:00:00', 'post_date_gmt' => '2020-01-01 08:00:00' ] )
				);
				self::update_post( $post( [ 'menu_order' => 0 ] ), $post( [ 'menu_order' => 5 ] ) );
			}
		);

		$this->assertSame( [ 2006, 2006 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				[
					'field' => 'post_date',
					'old'   => '2026-10-26 10:00:00',
					'new'   => '2020-01-01 09:00:00',
				],
			],
			$calls[0][1]['changes']
		);
		$this->assertSame(
			[
				[
					'field' => 'menu_order',
					'old'   => '0',
					'new'   => '5',
				],
			],
			$calls[1][1]['changes']
		);
	}

	/**
	 * A draft without a date of its own takes the time of every save. That is
	 * no date anybody chose; a date set on it is.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_floating_date_of_a_draft_is_not_a_change_but_setting_one_is(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$floating = [
					'post_status'   => 'draft',
					'post_date_gmt' => '0000-00-00 00:00:00',
				];
				$sensor->boot();
				self::update_post(
					$post( $floating + [ 'post_date' => '2026-10-26 10:00:00' ] ),
					$post( $floating + [ 'post_date' => '2026-10-26 10:05:00' ] )
				);
				self::update_post(
					$post( $floating + [ 'post_date' => '2026-10-26 10:05:00' ] ),
					$post( [ 'post_status' => 'draft', 'post_date' => '2026-12-24 18:00:00', 'post_date_gmt' => '2026-12-24 17:00:00' ] )
				);
			}
		);

		$this->assertSame( [], $calls[0][1]['changes'] );
		$this->assertSame(
			[
				[
					'field' => 'post_date',
					'old'   => null,
					'new'   => '2026-12-24 18:00:00',
				],
			],
			$calls[1][1]['changes']
		);
	}

	/**
	 * A post moved to `auto-draft` is picked up by WordPress's cleanup later,
	 * so the move itself is what has to be on record.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_post_reverted_to_auto_draft_is_logged(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->on_status( 'auto-draft', 'draft', $post( [ 'post_status' => 'auto-draft' ] ) );
				$sensor->on_status( 'auto-draft', 'new', $post( [ 'post_status' => 'auto-draft' ] ) );
				$sensor->on_status( 'auto-draft', 'publish', $post( [ 'post_status' => 'auto-draft' ] ) );
			}
		);

		$this->assertSame( [ 2013, 2013 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				'type'  => 'post',
				'id'    => '7',
				'label' => 'Hello',
			],
			$calls[0][1]['target']
		);
		$this->assertSame(
			[ 'edit' => 'https://example.test/wp-admin/post.php?post=7&action=edit' ],
			$calls[0][1]['links']
		);
	}

	/**
	 * Publishing a draft and changing its title in one save is one event,
	 * the publish, and it carries the title change but not the status: the
	 * event says that already. Core's hooks are replayed in their order.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_publish_with_a_title_change_is_one_published_event_carrying_the_change(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->boot();
				self::update_post(
					$post(
						[
							'post_status' => 'draft',
							'post_title'  => 'Draft title',
						]
					),
					$post( [ 'post_title' => 'Final title' ] )
				);
			}
		);

		$this->assertSame( [ 2001 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				[
					'field' => 'post_title',
					'old'   => 'Draft title',
					'new'   => 'Final title',
				],
			],
			$calls[0][1]['changes']
		);
		$this->assertSame( 'Final title', $calls[0][1]['title'] );
	}

	/**
	 * The block editor saves through the REST API, which writes the featured
	 * image, the sticky flag, the template and the terms after the post
	 * itself and fires `wp_after_insert_post` last: the publish carries all
	 * of them, and nothing else is left for the per-request rules to drop.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_block_editor_publish_carries_what_the_rest_api_writes_after_the_save(): void {
		$recorder = null;
		$calls    = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post, EventDispatcher $dispatcher ) use ( &$recorder ): void {
				$recorder = $dispatcher;
				self::term( 15, 5, 'News', 'category' );
				self::term( 16, 6, 'Reviews', 'category' );
				WpState::$post_terms[7]['category'] = [ 15 ];
				WpState::$posts[30]                 = new \WP_Post(
					[
						'ID'         => 30,
						'post_title' => 'Sunset',
						'post_type'  => 'attachment',
					]
				);

				$sensor->boot();
				self::rest_update(
					$post( [ 'post_status' => 'draft' ] ),
					$post(),
					static function (): void {
						WpState::$thumbnails[7] = 30;
						self::stick( 7, true );
						WpState::$templates[7] = 'templates/wide.php';
						self::set_terms( 7, 'category', [ 16 ] );
					}
				);
			}
		);

		$this->assertSame( [ 2001 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				[
					'field' => 'template',
					'old'   => '',
					'new'   => 'templates/wide.php',
				],
				[
					'field' => 'featured_image',
					'old'   => null,
					'new'   => 'Sunset',
				],
				[
					'field' => 'sticky',
					'old'   => 'no',
					'new'   => 'yes',
				],
				[
					'field' => 'category',
					'old'   => 'News',
					'new'   => 'Reviews',
				],
			],
			$calls[0][1]['changes']
		);

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 2001 ], array_column( $survivors, 'code' ) );
		$this->assertSame( [ 'template', 'featured_image', 'sticky', 'category' ], array_column( $survivors[0]['changes'], 'field' ) );
	}

	/**
	 * With meta boxes on its screen, the block editor saves through the REST
	 * API and then posts the meta boxes to `post.php` in a second request,
	 * which runs `edit_post()` with the same data (the sticky flag included).
	 * That second save changes nothing, and nothing of it survives: the save
	 * is one event.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_block_editor_save_and_its_meta_box_request_leave_one_event(): void {
		$requests = [];
		$this->run_hook(
			static function ( ContentSensor $sensor, callable $post, EventDispatcher $dispatcher ) use ( &$requests ): void {
				self::term( 15, 5, 'News', 'category' );
				self::term( 16, 6, 'Reviews', 'category' );
				WpState::$post_terms[7]['category'] = [ 15 ];
				$saved                              = $post( [ 'post_title' => 'Hello again' ] );

				// The REST save.
				$sensor->boot();
				self::rest_update(
					$post(),
					$saved,
					static function (): void {
						self::stick( 7, true );
						self::set_terms( 7, 'category', [ 16 ] );
					}
				);
				self::fire( 'shutdown' );
				$requests[] = $dispatcher->calls;

				// The meta box request: a new request, a new sensor.
				$dispatcher->calls  = [];
				WpState::$callbacks = [];
				WpState::$actions   = [];
				( new ContentSensor( $dispatcher ) )->boot();
				self::update_post(
					$saved,
					$saved,
					static function (): void {
						self::set_terms( 7, 'category', [ 16 ] );
					}
				);
				self::stick( 7, true );
				self::fire( 'shutdown' );
				$requests[] = $dispatcher->calls;
			}
		);

		$first = $this->survive_calls( $requests[0] );
		$this->assertSame( [ 2006 ], array_column( $first, 'code' ) );
		$this->assertSame( [ 'post_title', 'sticky', 'category' ], array_column( $first[0]['changes'], 'field' ) );

		$this->assertSame( [ 2006 ], array_column( $requests[1], 0 ) );
		$this->assertSame( [], $requests[1][0][1]['changes'] );
		$this->assertSame( [], $this->survive_calls( $requests[1] ) );
	}

	/**
	 * Terms given with a save (the classic editor's `tax_input`) are written
	 * inside `wp_insert_post()`, before the status change; the update
	 * compares them with the rest.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_terms_given_with_a_save_are_compared_by_its_update(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				self::term( 15, 5, 'News', 'category' );
				self::term( 16, 6, 'Reviews', 'category' );
				WpState::$post_terms[7]['category'] = [ 15 ];

				$sensor->boot();
				self::update_post(
					$post(),
					$post( [ 'post_title' => 'Hello again' ] ),
					static function (): void {
						self::set_terms( 7, 'category', [ 16 ] );
					}
				);
			}
		);

		$this->assertSame( [ 2006 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				[
					'field' => 'post_title',
					'old'   => 'Hello',
					'new'   => 'Hello again',
				],
				[
					'field' => 'category',
					'old'   => 'News',
					'new'   => 'Reviews',
				],
			],
			$calls[0][1]['changes']
		);
		$this->assertSame( [ 'post_title', 'category' ], $calls[0][1]['metadata']['changed'] );
	}

	/**
	 * The classic editor makes a post sticky after `wp_update_post()` has
	 * returned: that is an update of its own, and the per-request rules fold
	 * it into the save's update.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_classic_editor_save_and_its_sticky_flag_end_up_as_one_update(): void {
		$recorder = null;
		$calls    = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post, EventDispatcher $dispatcher ) use ( &$recorder ): void {
				$recorder = $dispatcher;
				$sensor->boot();
				self::update_post( $post(), $post( [ 'post_title' => 'Hello again' ] ) );
				self::stick( 7, true );
			}
		);

		$this->assertSame( [ 2006, 2006 ], array_column( $calls, 0 ) );

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 2006 ], array_column( $survivors, 'code' ) );
		$this->assertSame( [ 'post_title', 'sticky' ], array_column( $survivors[0]['changes'], 'field' ) );
		$this->assertSame( [ 'post_title', 'sticky' ], $survivors[0]['metadata']['changed'] );
	}

	/**
	 * `edit_post()` (the classic editor, quick edit) saves the post with
	 * `wp_update_post()` and only then sticks or unsticks it: publishing a
	 * post and sticking it to the front page in one go is one publish event
	 * that carries the sticky change.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_classic_editor_publish_that_sticks_the_post_is_one_published_event_with_the_sticky_change(): void {
		$recorder = null;
		$this->run_hook(
			static function ( ContentSensor $sensor, callable $post, EventDispatcher $dispatcher ) use ( &$recorder ): void {
				$recorder = $dispatcher;
				$sensor->boot();
				self::update_post(
					$post(
						[
							'post_status' => 'draft',
							'post_title'  => 'Draft title',
						]
					),
					$post( [ 'post_title' => 'Final title' ] )
				);
				self::stick( 7, true );
			}
		);

		$this->assertSame( [ 2001, 2006 ], array_column( $recorder->calls, 0 ) );

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 2001 ], array_column( $survivors, 'code' ) );
		$this->assertSame(
			[
				[
					'field' => 'post_title',
					'old'   => 'Draft title',
					'new'   => 'Final title',
				],
				[
					'field' => 'sticky',
					'old'   => 'no',
					'new'   => 'yes',
				],
			],
			$survivors[0]['changes']
		);
	}

	/**
	 * Making a post private in the classic editor drops its sticky flag
	 * after the save (`_wp_translate_postdata()` unsets it): the status event
	 * carries the unstick. From a draft that is "made private" (2009), from a
	 * published post it is "unpublished" (2002).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_making_a_sticky_post_private_carries_the_unstick(): void {
		$recorder = null;
		$this->run_hook(
			static function ( ContentSensor $sensor, callable $post, EventDispatcher $dispatcher ) use ( &$recorder ): void {
				$recorder        = $dispatcher;
				WpState::$sticky = [ 7, 8 ];

				$sensor->boot();
				self::update_post( $post( [ 'post_status' => 'draft' ] ), $post( [ 'post_status' => 'private' ] ) );
				self::stick( 7, false );
				self::update_post( $post( [ 'ID' => 8 ] ), $post( [ 'ID' => 8, 'post_status' => 'private' ] ) );
				self::stick( 8, false );
			}
		);

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 2009, 2002 ], array_column( $survivors, 'code' ) );
		foreach ( $survivors as $survivor ) {
			$this->assertSame(
				[
					[
						'field' => 'sticky',
						'old'   => 'yes',
						'new'   => 'no',
					],
				],
				$survivor['changes']
			);
		}
	}

	/**
	 * `bulk_edit_posts()` saves each post, then sticks it: every post's
	 * publish carries its own sticky change.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_bulk_publish_that_sticks_the_posts_carries_each_sticky_change(): void {
		$recorder = null;
		$this->run_hook(
			static function ( ContentSensor $sensor, callable $post, EventDispatcher $dispatcher ) use ( &$recorder ): void {
				$recorder = $dispatcher;
				$sensor->boot();
				foreach ( [ 7, 8 ] as $id ) {
					self::update_post(
						$post(
							[
								'ID'          => $id,
								'post_status' => 'pending',
							]
						),
						$post( [ 'ID' => $id ] )
					);
					self::stick( $id, true );
				}
			}
		);

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 2001, 2001 ], array_column( $survivors, 'code' ) );
		$this->assertSame( [ '7', '8' ], array_map( static fn( array $p ): string => $p['target']['id'], $survivors ) );
		$this->assertSame( [ 'sticky' ], array_column( $survivors[0]['changes'], 'field' ) );
		$this->assertSame( [ 'sticky' ], array_column( $survivors[1]['changes'], 'field' ) );
	}

	/**
	 * A post created through the REST API is inserted (its default category
	 * set inside the insert), its status change logged at once, and its terms
	 * and sticky flag written after: those updates fold into the creation,
	 * with the terms it ends up with.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_rest_creation_carries_the_terms_and_sticky_flag_it_ends_up_with(): void {
		$recorder = null;
		$this->run_hook(
			static function ( ContentSensor $sensor, callable $post, EventDispatcher $dispatcher ) use ( &$recorder ): void {
				$recorder = $dispatcher;
				self::term( 15, 5, 'Uncategorized', 'category' );
				self::term( 16, 6, 'News', 'category' );

				$sensor->boot();
				$created           = $post();
				WpState::$posts[7] = $created;
				self::set_terms( 7, 'category', [ 15 ] );
				self::fire( 'transition_post_status', 'publish', 'new', $created );
				self::stick( 7, true );
				self::set_terms( 7, 'category', [ 16 ] );
				self::fire( 'wp_after_insert_post', 7, $created, false, null );
			}
		);

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 2001 ], array_column( $survivors, 'code' ) );
		$this->assertSame(
			[
				[
					'field' => 'category',
					'old'   => '',
					'new'   => 'News',
				],
				[
					'field' => 'sticky',
					'old'   => 'no',
					'new'   => 'yes',
				],
			],
			$survivors[0]['changes']
		);
	}

	/**
	 * A status change that is no event of its own (back to draft from
	 * review, from scheduled or from private) is an update that names the
	 * status among its changes.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_status_change_that_is_no_event_of_its_own_is_an_update_naming_the_status(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->boot();
				self::update_post(
					$post(
						[
							'post_status' => 'pending',
							'post_title'  => 'Under review',
						]
					),
					$post(
						[
							'post_status' => 'draft',
							'post_title'  => 'Back to work',
						]
					)
				);
				self::update_post(
					$post(
						[
							'ID'          => 8,
							'post_status' => 'future',
						]
					),
					$post(
						[
							'ID'          => 8,
							'post_status' => 'draft',
						]
					)
				);
			}
		);

		$this->assertSame( [ 2006, 2006 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				[
					'field' => 'post_title',
					'old'   => 'Under review',
					'new'   => 'Back to work',
				],
				[
					'field' => 'post_status',
					'old'   => 'pending',
					'new'   => 'draft',
				],
			],
			$calls[0][1]['changes']
		);
		$this->assertSame( [ 'post_title', 'post_status' ], $calls[0][1]['metadata']['changed'] );
		$this->assertSame(
			[
				[
					'field' => 'post_status',
					'old'   => 'future',
					'new'   => 'draft',
				],
			],
			$calls[1][1]['changes']
		);
	}

	/**
	 * The first save of an editor placeholder fills in every field: that is
	 * a creation, not a list of changes.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_first_save_of_an_auto_draft_carries_no_changes(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$placeholder = static fn( int $id ): \WP_Post => $post(
					[
						'ID'          => $id,
						'post_status' => 'auto-draft',
						'post_title'  => '',
						'post_name'   => '',
					]
				);

				$sensor->boot();
				self::update_post( $placeholder( 7 ), $post() );
				self::update_post( $placeholder( 8 ), $post( [ 'ID' => 8, 'post_status' => 'draft' ] ) );
				// The editor's autosave of a placeholder by its own author.
				self::update_post( $placeholder( 9 ), $post( [ 'ID' => 9, 'post_status' => 'auto-draft' ] ) );
			}
		);

		$this->assertSame( [ 2001, 2000, 2006 ], array_column( $calls, 0 ) );
		$this->assertArrayNotHasKey( 'changes', $calls[0][1] );
		$this->assertArrayNotHasKey( 'changes', $calls[1][1] );
		$this->assertSame( [], $calls[2][1]['changes'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_scheduling_a_post_names_its_date(): void {
		$recorder = null;
		$calls    = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post, EventDispatcher $dispatcher ) use ( &$recorder ): void {
				$recorder = $dispatcher;
				$sensor->boot();
				self::update_post(
					$post(
						[
							'post_status'   => 'draft',
							'post_date'     => '2026-10-27 09:00:00',
							'post_date_gmt' => '0000-00-00 00:00:00',
						]
					),
					$post(
						[
							'post_status'   => 'future',
							'post_date'     => '2026-12-24 18:00:00',
							'post_date_gmt' => '2026-12-24 17:00:00',
						]
					)
				);
				$sensor->on_status( 'future', 'new', $post( [ 'ID' => 8, 'post_status' => 'future', 'post_date' => '2027-01-01 08:00:00' ] ) );
			}
		);

		$this->assertSame( [ 2007, 2007 ], array_column( $calls, 0 ) );
		$this->assertSame( '2026-12-24 18:00:00', $calls[0][1]['date'] );
		// The draft had no date of its own: the date is the one the event names.
		$this->assertArrayNotHasKey( 'changes', $calls[0][1] );
		$this->assertSame( '2027-01-01 08:00:00', $calls[1][1]['date'] );

		$survivors = $this->survive( $recorder );
		$this->assertSame( 'Post "Hello" scheduled for 2026-12-24 18:00:00.', $survivors[0]['message'] );
		$this->assertSame( '2026-12-24 18:00:00', $survivors[0]['vars']['date'] );
	}

	/**
	 * A draft that never had a date of its own gets one when it is published,
	 * scheduled or made private: that is part of the status change, not a
	 * change of its own. A date somebody set on the draft is.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_status_event_reports_no_date_for_a_draft_that_had_none(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$floating = static fn( int $id ): \WP_Post => $post(
					[
						'ID'            => $id,
						'post_status'   => 'draft',
						'post_title'    => 'Draft title',
						'post_date'     => '2026-10-27 09:00:00',
						'post_date_gmt' => '0000-00-00 00:00:00',
					]
				);
				$dated    = static fn( int $id, string $status ): \WP_Post => $post(
					[
						'ID'            => $id,
						'post_status'   => $status,
						'post_title'    => 'Final title',
						'post_date'     => '2026-10-27 12:00:00',
						'post_date_gmt' => '2026-10-27 11:00:00',
					]
				);

				$sensor->boot();
				self::update_post( $floating( 7 ), $dated( 7, 'publish' ) );
				self::update_post( $floating( 8 ), $dated( 8, 'private' ) );
				self::update_post(
					$post(
						[
							'ID'            => 9,
							'post_status'   => 'draft',
							'post_title'    => 'Final title',
							'post_date'     => '2026-10-20 09:00:00',
							'post_date_gmt' => '2026-10-20 08:00:00',
						]
					),
					$dated( 9, 'publish' )
				);
			}
		);

		$this->assertSame( [ 2001, 2009, 2001 ], array_column( $calls, 0 ) );
		$this->assertSame( [ 'post_title' ], array_column( $calls[0][1]['changes'], 'field' ) );
		$this->assertSame( [ 'post_title' ], array_column( $calls[1][1]['changes'], 'field' ) );
		$this->assertSame(
			[
				[
					'field' => 'post_date',
					'old'   => '2026-10-20 09:00:00',
					'new'   => '2026-10-27 12:00:00',
				],
			],
			$calls[2][1]['changes']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_submitting_for_review_and_making_private_are_events_of_their_own(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->boot();
				self::update_post( $post( [ 'post_status' => 'draft' ] ), $post( [ 'post_status' => 'pending' ] ) );
				self::update_post( $post( [ 'post_status' => 'pending' ] ), $post( [ 'post_status' => 'private' ] ) );
				self::update_post( $post( [ 'post_status' => 'private' ] ), $post( [ 'post_status' => 'publish' ] ) );
				self::update_post( $post( [ 'post_status' => 'publish' ] ), $post( [ 'post_status' => 'private' ] ) );
			}
		);

		$this->assertSame( [ 2008, 2009, 2001, 2002 ], array_column( $calls, 0 ) );
		foreach ( $calls as $call ) {
			$this->assertArrayNotHasKey( 'changes', $call[1] );
			$this->assertArrayNotHasKey( 'date', $call[1] );
		}
	}

	/**
	 * A changed text links to the revision core saved for it, just before
	 * the post event is built.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_content_change_links_the_revision(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				WpState::$revisions[7] = 99;

				$sensor->boot();
				self::update_post( $post( [ 'post_content' => 'Old body' ] ), $post( [ 'post_content' => 'New body' ] ) );
				self::update_post( $post( [ 'post_title' => 'Hello' ] ), $post( [ 'post_title' => 'Hi' ] ) );
				self::update_post(
					$post(
						[
							'post_status'  => 'draft',
							'post_content' => 'Old body',
						]
					),
					$post( [ 'post_content' => 'Newest body' ] )
				);
				self::update_post( $post( [ 'ID' => 8, 'post_content' => 'Old body' ] ), $post( [ 'ID' => 8, 'post_content' => 'New body' ] ) );
			}
		);

		$this->assertSame( [ 2006, 2006, 2001, 2006 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				'edit'     => 'https://example.test/wp-admin/post.php?post=7&action=edit',
				'view'     => 'https://example.test/p/7/',
				'revision' => 'https://example.test/wp-admin/revision.php?revision=99',
			],
			$calls[0][1]['links']
		);
		$this->assertArrayNotHasKey( 'revision', $calls[1][1]['links'] );
		$this->assertSame( 'https://example.test/wp-admin/revision.php?revision=99', $calls[2][1]['links']['revision'] );
		// No revision was saved for the other post.
		$this->assertArrayNotHasKey( 'revision', $calls[3][1]['links'] );
	}

	/**
	 * Terms set on their own are an update named by the taxonomy, with the
	 * term names before and after. Core passes term taxonomy ids, which are
	 * not the term ids.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_term_change_outside_a_save_is_an_update_named_by_the_taxonomy(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				self::term( 15, 5, 'News', 'category' );
				self::term( 16, 6, 'Reviews', 'category' );
				self::term( 21, 11, 'aside', 'post_format' );
				self::term( 31, 3, 'Gold', 'product_tag' );
				WpState::$posts[7]                  = $post();
				WpState::$post_terms[7]['category'] = [ 15 ];

				$sensor->boot();
				self::set_terms( 7, 'category', [ '16' ], true );
				// A term the post already has, appended again: nothing changed.
				self::set_terms( 7, 'category', [ 15 ], true );
				// The same terms again, in another order: nothing changed.
				self::set_terms( 7, 'category', [ 16, 15 ] );
				// Post formats are not tracked, product tags are not on posts.
				self::set_terms( 7, 'post_format', [ 21 ] );
				self::set_terms( 7, 'product_tag', [ 31 ] );
				self::set_terms( 7, 'category', [] );
			}
		);

		$this->assertSame( [ 2006, 2006 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				[
					'field' => 'category',
					'old'   => 'News',
					'new'   => 'News, Reviews',
				],
			],
			$calls[0][1]['changes']
		);
		$this->assertSame( [ 'category' ], $calls[0][1]['metadata']['changed'] );
		$this->assertSame(
			[
				'type'  => 'post',
				'id'    => '7',
				'label' => 'Hello',
			],
			$calls[0][1]['target']
		);
		$this->assertSame(
			[
				[
					'field' => 'category',
					'old'   => 'News, Reviews',
					'new'   => '',
				],
			],
			$calls[1][1]['changes']
		);
	}

	/**
	 * Core passes no old terms when it appends: the old ones are the terms
	 * the post has now, less the ones the call added.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_appending_terms_reports_the_terms_the_post_had(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				self::term( 25, 9, 'a', 'post_tag' );
				self::term( 26, 10, 'b', 'post_tag' );
				self::term( 27, 11, 'c', 'post_tag' );
				WpState::$posts[7]                  = $post();
				WpState::$post_terms[7]['post_tag'] = [ 25 ];

				$sensor->boot();
				// wp_add_object_terms(): one new term and one the post has.
				self::set_terms( 7, 'post_tag', [ 26, 25 ], true );
				self::set_terms( 7, 'post_tag', [ 27 ], true );
				self::set_terms( 7, 'post_tag', [ 26, 27 ], true );
			}
		);

		$this->assertSame( [ 2006, 2006 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				[
					'field' => 'post_tag',
					'old'   => 'a',
					'new'   => 'a, b',
				],
			],
			$calls[0][1]['changes']
		);
		$this->assertSame(
			[
				[
					'field' => 'post_tag',
					'old'   => 'a, b',
					'new'   => 'a, b, c',
				],
			],
			$calls[1][1]['changes']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sticking_and_unsticking_a_post_outside_a_save_is_an_update(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				WpState::$posts[7] = $post();

				$sensor->boot();
				self::stick( 7, true );
				self::stick( 7, false );
			}
		);

		$this->assertSame( [ 2006, 2006 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				[
					'field' => 'sticky',
					'old'   => 'no',
					'new'   => 'yes',
				],
			],
			$calls[0][1]['changes']
		);
		$this->assertSame(
			[
				[
					'field' => 'sticky',
					'old'   => 'yes',
					'new'   => 'no',
				],
			],
			$calls[1][1]['changes']
		);
	}

	/**
	 * Terms or the sticky flag of a post this sensor does not log, or of an
	 * editor placeholder, are no event.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_term_and_sticky_changes_of_untracked_posts_and_placeholders_emit_nothing(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				self::term( 15, 5, 'News', 'category' );
				WpState::$taxonomies['attachment'] = [ 'category' ];
				WpState::$taxonomies['custom_css'] = [ 'category' ];
				WpState::$posts[7]                 = $post( [ 'post_type' => 'attachment' ] );
				WpState::$posts[8]                 = $post(
					[
						'ID'          => 8,
						'post_status' => 'auto-draft',
					]
				);
				WpState::$posts[9]                 = $post(
					[
						'ID'        => 9,
						'post_type' => 'custom_css',
					]
				);

				$sensor->boot();
				foreach ( [ 7, 8, 9 ] as $id ) {
					self::set_terms( $id, 'category', [ 15 ] );
					self::stick( $id, true );
				}
			}
		);

		$this->assertSame( [], $calls );
	}

	/**
	 * The same post id is another post on every site of a network: a save on
	 * one site is compared with what that site's post was.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_saves_are_compared_per_site(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->boot();

				WpState::$posts[7] = $post( [ 'post_title' => 'One' ] );
				self::fire( 'pre_post_update', 7, [] );

				switch_to_blog( 2 );
				self::update_post( $post( [ 'post_title' => 'Two' ] ), $post( [ 'post_title' => 'Two edited' ] ) );
				restore_current_blog();

				$after             = $post( [ 'post_title' => 'One edited' ] );
				WpState::$posts[7] = $after;
				self::fire( 'transition_post_status', 'publish', 'publish', $after );
				self::fire( 'post_updated', 7, $after, $post( [ 'post_title' => 'One' ] ) );
				self::fire( 'wp_after_insert_post', 7, $after, true, $post( [ 'post_title' => 'One' ] ) );
			}
		);

		$this->assertSame( [ 2006, 2006 ], array_column( $calls, 0 ) );
		$this->assertSame( [ 2, 1 ], array_column( $calls, 2 ) );
		$this->assertSame( [ 'Two', 'Two edited' ], [ $calls[0][1]['changes'][0]['old'], $calls[0][1]['changes'][0]['new'] ] );
		$this->assertSame( [ 'One', 'One edited' ], [ $calls[1][1]['changes'][0]['old'], $calls[1][1]['changes'][0]['new'] ] );
	}

	/**
	 * A REST save stops before `wp_after_insert_post` when writing its terms
	 * fails, after the post itself was saved: the save is still logged, at
	 * the end of the request and on the site it happened on.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_that_never_finishes_is_logged_at_shutdown(): void {
		$seen  = [];
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post, EventDispatcher $dispatcher ) use ( &$seen ): void {
				$sensor->boot();
				self::update_post(
					$post(
						[
							'post_status' => 'draft',
							'post_title'  => 'Draft title',
						]
					),
					$post( [ 'post_title' => 'Final title' ] ),
					null,
					false
				);

				switch_to_blog( 3 );
				self::update_post(
					$post(
						[
							'ID'         => 8,
							'post_title' => 'Elsewhere',
						]
					),
					$post(
						[
							'ID'         => 8,
							'post_title' => 'Elsewhere too',
						]
					),
					null,
					false
				);
				restore_current_blog();

				$seen[] = count( $dispatcher->calls );
				self::fire( 'shutdown' );
				$seen[] = get_current_blog_id();
			}
		);

		$this->assertSame( [ 0, 1 ], $seen );
		$this->assertSame( [ 2001, 2006 ], array_column( $calls, 0 ) );
		$this->assertSame( [ 1, 3 ], array_column( $calls, 2 ) );
		$this->assertSame( 'Final title', $calls[0][1]['changes'][0]['new'] );
		$this->assertSame( 'Elsewhere too', $calls[1][1]['changes'][0]['new'] );
	}

	/**
	 * `wp_publish_post()` (a scheduled post going live) changes the status
	 * without `pre_post_update`: there is nothing to compare, so the status
	 * change is logged as it happens, once.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_status_change_outside_an_open_save_is_logged_at_once(): void {
		$seen  = [];
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post, EventDispatcher $dispatcher ) use ( &$seen ): void {
				$sensor->boot();

				$before            = $post( [ 'post_status' => 'future' ] );
				$after             = $post();
				WpState::$posts[7] = $after;
				self::fire( 'transition_post_status', 'publish', 'future', $after );
				$seen[] = count( $dispatcher->calls );
				self::fire( 'wp_after_insert_post', 7, $after, true, $before );
				self::fire( 'shutdown' );
			}
		);

		$this->assertSame( [ 1 ], $seen );
		$this->assertSame( [ 2001 ], array_column( $calls, 0 ) );
		$this->assertArrayNotHasKey( 'changes', $calls[0][1] );
	}

	/**
	 * A plugin that saves the post again from its own `save_post` callback
	 * opens a save inside the save: one event tells both.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_inside_a_save_is_one_event(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$draft     = $post(
					[
						'post_status'  => 'draft',
						'post_title'   => 'A',
						'post_excerpt' => '',
					]
				);
				$published = $post(
					[
						'post_title'   => 'B',
						'post_excerpt' => '',
					]
				);
				$final     = $post(
					[
						'post_title'   => 'B',
						'post_excerpt' => 'C',
					]
				);

				$sensor->boot();
				WpState::$posts[7] = $draft;
				self::fire( 'pre_post_update', 7, [] );
				WpState::$posts[7] = $published;
				self::fire( 'transition_post_status', 'publish', 'draft', $published );
				self::fire( 'post_updated', 7, $published, $draft );
				self::update_post( $published, $final );
				self::fire( 'wp_after_insert_post', 7, $final, true, $draft );
				self::fire( 'shutdown' );
			}
		);

		$this->assertSame( [ 2001 ], array_column( $calls, 0 ) );
		$this->assertSame( [ 'post_title', 'post_excerpt' ], array_column( $calls[0][1]['changes'], 'field' ) );
	}

	/**
	 * `wp_trash_post()` saves the post with the trash status and a
	 * `__trashed` slug: one trash event, with no slug change.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_trashing_a_post_is_one_event_without_the_trash_slug(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->boot();
				self::update_post(
					$post(),
					$post(
						[
							'post_status' => 'trash',
							'post_name'   => 'hello__trashed',
						]
					)
				);
			}
		);

		$this->assertSame( [ 2003 ], array_column( $calls, 0 ) );
		$this->assertArrayNotHasKey( 'changes', $calls[0][1] );
	}

	/**
	 * Core unsticks a post on `wp_trash_post`, before the trash save starts:
	 * that update says nothing the trash does not, and only the trash stays.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_trashing_a_sticky_post_leaves_only_the_trash(): void {
		$recorder = null;
		$this->run_hook(
			static function ( ContentSensor $sensor, callable $post, EventDispatcher $dispatcher ) use ( &$recorder ): void {
				$recorder          = $dispatcher;
				WpState::$sticky   = [ 7 ];
				WpState::$posts[7] = $post();

				$sensor->boot();
				self::stick( 7, false );
				self::update_post(
					$post(),
					$post(
						[
							'post_status' => 'trash',
							'post_name'   => 'hello__trashed',
						]
					)
				);
			}
		);

		$this->assertSame( [ 2006, 2003 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ 2003 ], array_column( $this->survive( $recorder ), 'code' ) );
	}

	/**
	 * Reading the post before its save can fail (a filter on the terms that
	 * throws): the failure is reported, never thrown at the save, and the
	 * status change is still logged.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failing_read_before_a_save_is_reported_and_the_publish_still_logged(): void {
		require_once __DIR__ . '/../../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';

		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->boot();
				WpState::$term_reads_throw = true;
				self::update_post( $post( [ 'post_status' => 'draft' ] ), $post() );
			}
		);

		$this->assertSame( [ 2001 ], array_column( $calls, 0 ) );
		$this->assertArrayNotHasKey( 'changes', $calls[0][1] );
		$this->assertSame( [ 'hellolog_dispatch_failed' ], array_column( WpCalls::$actions, 0 ) );
		$this->assertSame( [ 'post_id' => 7 ], WpCalls::$actions[0][1][2] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failing_read_after_a_save_is_reported_and_the_event_still_logged(): void {
		require_once __DIR__ . '/../../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';

		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->boot();
				self::rest_update(
					$post( [ 'post_status' => 'draft' ] ),
					$post(),
					static function (): void {
						WpState::$term_reads_throw = true;
					}
				);
			}
		);

		$this->assertSame( [ 2001 ], array_column( $calls, 0 ) );
		$this->assertArrayNotHasKey( 'changes', $calls[0][1] );
		$this->assertSame( [ 'hellolog_dispatch_failed' ], array_column( WpCalls::$actions, 0 ) );
		$this->assertSame( 2001, WpCalls::$actions[0][1][1] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failing_term_read_outside_a_save_is_reported(): void {
		require_once __DIR__ . '/../../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';

		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				WpState::$posts[7] = $post();

				$sensor->boot();
				WpState::$term_reads_throw = true;
				self::set_terms( 7, 'category', [ 15 ] );
				self::set_terms( 7, 'post_tag', [ 25 ], true );
			}
		);

		$this->assertSame( [], $calls );
		$this->assertSame( [ 'hellolog_dispatch_failed', 'hellolog_dispatch_failed' ], array_column( WpCalls::$actions, 0 ) );
		$this->assertSame(
			[
				'post_id'  => 7,
				'taxonomy' => 'category',
			],
			WpCalls::$actions[0][1][2]
		);
	}

	/**
	 * A template has no admin screen, yet its save is logged, and the
	 * message names it the way the site editor does.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_site_editor_save_is_logged_under_the_editors_label(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				WpState::$post_types += [
					'wp_template'      => false,
					'wp_template_part' => false,
					'wp_global_styles' => false,
					'wp_navigation'    => true,
					'wp_block'         => true,
				];

				$sensor->boot();
				self::update_post(
					$post(
						[
							'post_type'  => 'wp_template',
							'post_title' => 'Single Posts',
						]
					),
					$post(
						[
							'post_type'    => 'wp_template',
							'post_title'   => 'Single Posts',
							'post_content' => '<!-- wp:post-title /-->',
						]
					)
				);
				$sensor->on_status( 'publish', 'new', $post( [ 'ID' => 8, 'post_type' => 'wp_template_part', 'post_title' => 'Header' ] ) );
				$sensor->on_status( 'publish', 'new', $post( [ 'ID' => 9, 'post_type' => 'wp_global_styles', 'post_title' => 'Custom Styles' ] ) );
				$sensor->on_status( 'publish', 'draft', $post( [ 'ID' => 10, 'post_type' => 'wp_navigation', 'post_title' => 'Main' ] ) );
				$sensor->on_delete( 11, $post( [ 'ID' => 11, 'post_type' => 'wp_block', 'post_title' => 'Call to action' ] ) );
			}
		);

		$this->assertSame( [ 2006, 2001, 2001, 2001, 2005 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[ 'Template', 'Template part', 'Global styles', 'Navigation menu', 'Pattern' ],
			array_map( static fn( array $call ): string => $call[1]['post_type'], $calls )
		);
		$this->assertSame(
			[
				[
					'field' => 'post_content',
					'old'   => null,
					'new'   => null,
				],
			],
			$calls[0][1]['changes']
		);
		$this->assertSame( 'wp_template', $calls[0][1]['post']['type'] );

		$payloads = $this->survive_calls( $calls );
		$this->assertSame(
			[
				'Template "Single Posts" updated.',
				'Template part "Header" published.',
				'Global styles "Custom Styles" published.',
				'Navigation menu "Main" published.',
				'Pattern "Call to action" permanently deleted.',
			],
			array_column( $payloads, 'message' )
		);
		$this->assertSame( 'Template', $payloads[0]['vars']['post_type'] );
	}

	/**
	 * With bbPress and Easy Digital Downloads active their own sensors report
	 * forums and downloads, so this one only logs their trash; without those
	 * plugins a type of that name is somebody else's and logged in full.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_forums_and_downloads_are_left_to_their_plugins_while_those_are_active(): void {
		require_once __DIR__ . '/Fixtures/bbpress-class.php';
		define( 'EDD_VERSION', '3.0.0' );

		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				WpState::$post_types += [
					'forum'        => true,
					'download'     => true,
					'sfwd-courses' => true,
				];

				foreach ( [ 'forum' => 7, 'download' => 8, 'sfwd-courses' => 9 ] as $type => $id ) {
					$sensor->on_status( 'publish', 'draft', $post( [ 'ID' => $id, 'post_type' => $type ] ) );
					$sensor->on_status( 'trash', 'publish', $post( [ 'ID' => $id, 'post_type' => $type, 'post_status' => 'trash' ] ) );
				}
			}
		);

		$this->assertSame( [ 2003, 2003, 2003 ], array_column( $calls, 0 ) );
		$this->assertSame( [ 'forum', 'download', 'sfwd-courses' ], array_map( static fn( array $call ): string => $call[1]['post']['type'], $calls ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_their_plugins_forums_and_downloads_are_logged_like_any_other_type(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				WpState::$post_types += [
					'forum'    => true,
					'download' => true,
				];

				$sensor->on_status( 'publish', 'draft', $post( [ 'post_type' => 'forum' ] ) );
				$sensor->on_status( 'publish', 'draft', $post( [ 'ID' => 8, 'post_type' => 'download' ] ) );
			}
		);

		$this->assertFalse( class_exists( 'bbPress', false ) );
		$this->assertFalse( defined( 'EDD_VERSION' ) );
		$this->assertSame( [ 2001, 2001 ], array_column( $calls, 0 ) );
	}

	/**
	 * Core moves an attachment to the trash (only possible with `MEDIA_TRASH`)
	 * and back without a status change hook: `wp_trash_post()` fires
	 * `trashed_post` once the attachment is in the trash, `wp_untrash_post()`
	 * fires `untrash_post` and `untrashed_post`. Both events name the file as
	 * the media sensor does, so one file's history stays on one target.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_attachments_trash_and_restore_are_logged_on_its_media_target(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->boot();

				WpState::$posts[7] = $post(
					[
						'post_type'   => 'attachment',
						'post_title'  => 'Logo',
						'post_status' => 'trash',
					]
				);
				self::fire( 'trashed_post', 7, 'inherit' );

				WpState::$posts[7] = $post(
					[
						'post_type'   => 'attachment',
						'post_title'  => 'Logo',
						'post_status' => 'inherit',
					]
				);
				self::fire( 'untrash_post', 7, 'inherit' );
				self::fire( 'untrashed_post', 7, 'inherit' );
			}
		);

		$this->assertSame( [ 2003, 2004 ], array_column( $calls, 0 ) );
		foreach ( $calls as $call ) {
			$this->assertSame(
				[
					'type'  => 'media',
					'id'    => '7',
					'label' => 'Logo',
				],
				$call[1]['target']
			);
			$this->assertSame( 'attachment', $call[1]['post']['type'] );
			$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=7&action=edit' ], $call[1]['links'] );
		}
		$this->assertSame( 'inherit', $calls[1][1]['metadata']['previous_status'] );
	}

	/**
	 * `trashed_post` follows the status change of every other post, which
	 * logged the trash already.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_trash_of_a_post_is_logged_once(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->boot();
				self::update_post( $post(), $post( [ 'post_status' => 'trash' ] ) );
				self::fire( 'trashed_post', 7, 'publish' );
			}
		);

		$this->assertSame( [ 2003 ], array_column( $calls, 0 ) );
		$this->assertSame( 'post', $calls[0][1]['target']['type'] );
	}

	/**
	 * A plugin or theme zip uploaded to be installed is kept as an attachment
	 * until the install is done; with `MEDIA_TRASH` its clean-up moves it to
	 * the trash. It is no file of the media library.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_installers_upload_is_not_logged_when_trashed_or_restored(): void {
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->boot();

				WpState::$post_meta[7]['_wp_attachment_context'] = 'upgrader';

				WpState::$posts[7] = $post(
					[
						'post_type'   => 'attachment',
						'post_title'  => 'plugin.zip',
						'post_status' => 'trash',
					]
				);
				self::fire( 'trashed_post', 7, 'private' );
				self::fire( 'untrash_post', 7, 'private' );
				self::fire( 'untrashed_post', 7, 'private' );
			}
		);

		$this->assertSame( [], $calls );
	}

	/**
	 * When whether an attachment is an installer's upload can not be read,
	 * it is taken for a file of the library: the failure is reported and the
	 * trash still logged.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failing_read_of_an_attachments_context_is_reported_and_the_trash_still_logged(): void {
		require_once __DIR__ . '/../../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';

		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->boot();

				WpState::$post_meta_throws = true;

				WpState::$posts[7] = $post(
					[
						'post_type'   => 'attachment',
						'post_status' => 'trash',
					]
				);
				self::fire( 'trashed_post', 7, 'inherit' );
			}
		);

		$this->assertSame( [ 2003 ], array_column( $calls, 0 ) );
		$this->assertSame( [ 'hellolog_dispatch_failed' ], array_column( WpCalls::$actions, 0 ) );
		$this->assertSame( 2003, WpCalls::$actions[0][1][1] );
		$this->assertSame( [ 'post_id' => 7 ], WpCalls::$actions[0][1][2] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_post_hooks_are_registered_with_their_arguments(): void {
		$this->run_hook( static fn( ContentSensor $sensor ) => $sensor->boot() );

		$expected = [
			'pre_post_update'         => [ 10, 1 ],
			'transition_post_status'  => [ 10, 3 ],
			'set_object_terms'        => [ 10, 6 ],
			'added_term_relationship' => [ 10, 3 ],
			'post_stuck'              => [ 10, 1 ],
			'post_unstuck'            => [ 10, 1 ],
			'before_delete_post'      => [ 10, 2 ],
			'trashed_post'            => [ 10, 1 ],
			'shutdown'                => [ 0, 1 ],
		];
		foreach ( $expected as $hook => [ $priority, $args ] ) {
			$this->assertSame(
				[
					'priority'      => $priority,
					'accepted_args' => $args,
				],
				WpState::$actions[ $hook ] ?? null,
				$hook
			);
		}

		// After core saves the revision (priority 9).
		$this->assertGreaterThanOrEqual( 10, WpState::$actions['wp_after_insert_post']['priority'] ?? 0 );
		$this->assertSame( 1, WpState::$actions['wp_after_insert_post']['accepted_args'] ?? null );
		$this->assertArrayNotHasKey( 'post_updated', WpState::$actions );
	}

	/**
	 * Run hook calls against a recording dispatcher, with the WordPress
	 * stand-ins loaded. Only call this from a test of its own process.
	 *
	 * @param callable(ContentSensor, callable(array<string, mixed>): \WP_Post, EventDispatcher): void $hooks What to call.
	 * @return array<int, array{0: int, 1: array<string, mixed>, 2: int}> Every dispatch as `[ code, fields, site ]`.
	 */
	private function run_hook( callable $hooks ): array {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-content-functions.php';

		$dispatcher = new class() implements EventDispatcher {

			/** @var array<int, array{0: int, 1: array<string, mixed>, 2: int}> */
			public array $calls = [];

			public function dispatch( int $code, array $fields = [] ): void {
				$this->calls[] = [ $code, $fields, get_current_blog_id() ];
			}
		};

		$post = static fn( array $over = [] ): \WP_Post => new \WP_Post(
			array_merge(
				[
					'ID'          => 7,
					'post_title'  => 'Hello',
					'post_type'   => 'post',
					'post_status' => 'publish',
					'post_name'   => 'hello',
					'post_author' => '1',
				],
				$over
			)
		);

		$hooks( new ContentSensor( $dispatcher ), $post, $dispatcher );

		return $dispatcher->calls;
	}

	/**
	 * Fire a hook the way `do_action()` does: every callback registered on
	 * it, by priority, with as many arguments as it accepts.
	 *
	 * @param string $hook    Hook name.
	 * @param mixed  ...$args Arguments of the hook.
	 */
	private static function fire( string $hook, ...$args ): void {
		$callbacks = WpState::$callbacks[ $hook ] ?? [];
		usort( $callbacks, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );
		foreach ( $callbacks as $registered ) {
			( $registered['callback'] )( ...array_slice( $args, 0, $registered['accepted_args'] ) );
		}
	}

	/**
	 * The hooks `wp_update_post()` fires when a post that was `$before` is
	 * saved as `$after`, in core's order (`wp_insert_post()`): the post is
	 * still the old one on `pre_post_update`; the terms, featured image and
	 * template given with the save are written (`$in_save`) before the
	 * status change; `post_updated` follows, and `wp_after_insert_post` comes
	 * last, unless the caller fires it itself later, as the REST API does.
	 *
	 * @param \WP_Post      $before      The post before the save.
	 * @param \WP_Post      $after       The post as saved.
	 * @param callable|null $in_save     Writes made inside the save.
	 * @param bool          $after_hooks Whether `wp_after_insert_post` fires.
	 */
	private static function update_post( \WP_Post $before, \WP_Post $after, ?callable $in_save = null, bool $after_hooks = true ): void {
		$id = (int) $after->ID;

		WpState::$posts[ $id ] = $before;
		self::fire( 'pre_post_update', $id, [] );

		WpState::$posts[ $id ] = $after;
		if ( null !== $in_save ) {
			$in_save();
		}
		self::fire( 'transition_post_status', (string) $after->post_status, (string) $before->post_status, $after );
		self::fire( 'post_updated', $id, $after, $before );

		if ( $after_hooks ) {
			self::fire( 'wp_after_insert_post', $id, $after, true, $before );
		}
	}

	/**
	 * A save through the REST API (the block editor), in the order of its
	 * posts controller: `wp_update_post()` without its closing hook, then the
	 * featured image, sticky flag, template and terms (`$after_save`), then
	 * `wp_after_insert_post`.
	 *
	 * @param \WP_Post $before     The post before the save.
	 * @param \WP_Post $after      The post as saved.
	 * @param callable $after_save Writes made after the post itself.
	 */
	private static function rest_update( \WP_Post $before, \WP_Post $after, callable $after_save ): void {
		self::update_post( $before, $after, null, false );
		$after_save();
		self::fire( 'wp_after_insert_post', (int) $after->ID, $after, true, $before );
	}

	/**
	 * Set the terms of a post in one taxonomy the way `wp_set_object_terms()`
	 * does: `added_term_relationship` for each term the post did not have
	 * yet, then `set_object_terms` with the term taxonomy ids of every term
	 * given and the ones the post had before, which core leaves empty when
	 * appending.
	 *
	 * @param int                    $post_id  Post id.
	 * @param string                 $taxonomy Taxonomy name.
	 * @param array<int, int|string> $tt_ids   Term taxonomy ids given.
	 * @param bool                   $append   Whether they are added to the post's terms.
	 */
	private static function set_terms( int $post_id, string $taxonomy, array $tt_ids, bool $append = false ): void {
		$old = WpState::$post_terms[ $post_id ][ $taxonomy ] ?? [];
		$ids = array_map( 'intval', $tt_ids );
		$new = $append ? array_values( array_unique( array_merge( $old, $ids ) ) ) : $ids;

		WpState::$post_terms[ $post_id ][ $taxonomy ] = $new;
		foreach ( array_diff( $ids, $old ) as $tt_id ) {
			self::fire( 'added_term_relationship', $post_id, $tt_id, $taxonomy );
		}
		self::fire( 'set_object_terms', $post_id, $tt_ids, $tt_ids, $taxonomy, $append, $append ? [] : $old );
	}

	/**
	 * `stick_post()` / `unstick_post()`: the sticky list changes, then the
	 * hook fires, only on a real change.
	 *
	 * @param int  $post_id Post id.
	 * @param bool $sticky  Whether the post is made sticky.
	 */
	private static function stick( int $post_id, bool $sticky ): void {
		$is = in_array( $post_id, WpState::$sticky, true );
		if ( $is === $sticky ) {
			return;
		}

		WpState::$sticky = $sticky
			? array_merge( WpState::$sticky, [ $post_id ] )
			: array_values( array_diff( WpState::$sticky, [ $post_id ] ) );
		self::fire( $sticky ? 'post_stuck' : 'post_unstuck', $post_id );
	}

	/**
	 * A term `get_term_by( 'term_taxonomy_id' )` and `get_the_terms()` find.
	 *
	 * @param int    $tt_id    Term taxonomy id.
	 * @param int    $term_id  Term id.
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy name.
	 */
	private static function term( int $tt_id, int $term_id, string $name, string $taxonomy ): void {
		WpState::$terms[ $tt_id ] = (object) [
			'term_id'          => $term_id,
			'term_taxonomy_id' => $tt_id,
			'name'             => $name,
			'taxonomy'         => $taxonomy,
		];
	}

	/**
	 * Build what the recorder saw as an admin would on the dashboard and run
	 * the default per-request rules over it.
	 *
	 * @param EventDispatcher|null $recorder The recording dispatcher.
	 * @return array<int, array<string, mixed>> The payloads that would be written.
	 */
	private function survive( ?EventDispatcher $recorder ): array {
		$this->assertNotNull( $recorder );

		return $this->survive_calls( $recorder->calls );
	}

	/**
	 * Build the dispatches of one request as an admin would on the dashboard
	 * and run the default per-request rules over them.
	 *
	 * @param array<int, array{0: int, 1: array<string, mixed>}> $calls Dispatches as `[ code, fields ]`.
	 * @return array<int, array<string, mixed>> The payloads that would be written.
	 */
	private function survive_calls( array $calls ): array {
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
			$this->assertIsArray( $payload );
			$payloads[] = $payload;
		}

		return SuppressionRules::defaults()->apply( $payloads );
	}
}
