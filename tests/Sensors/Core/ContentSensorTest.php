<?php
/**
 * Tests for the pure parts of {@see HelloLog\Sensors\Core\ContentSensor}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Events\EventDispatcher;
use HelloLog\Sensors\Core\ContentSensor;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use PHPUnit\Framework\TestCase;

/**
 * Pins which status change is which event, which post types the generic post
 * sensor leaves to someone else, and the shape of the target, links and
 * changes every post event carries.
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
			'auto-draft to pending'     => [ 'pending', 'auto-draft', 2000 ],
			'auto-draft to future'      => [ 'future', 'auto-draft', 2000 ],
			'auto-draft to private'     => [ 'private', 'auto-draft', 2000 ],
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
			'draft to pending'          => [ 'pending', 'draft', 0 ],
			'pending to draft'          => [ 'draft', 'pending', 0 ],
			'new to draft'              => [ 'draft', 'new', 2000 ],
			'new to pending'            => [ 'pending', 'new', 2000 ],
			'new to future'             => [ 'future', 'new', 2000 ],
			'new to private'            => [ 'private', 'new', 2000 ],
			'new to trash'              => [ 'trash', 'new', 2003 ],
			'new to inherit'            => [ 'inherit', 'new', 0 ],
			'new to auto-draft'         => [ 'auto-draft', 'new', 0 ],
			'trash to publish'          => [ 'publish', 'trash', 2001 ],
			'private to publish'        => [ 'publish', 'private', 2001 ],
			'trash to draft'            => [ 'draft', 'trash', 0 ],
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
			],
			ContentSensor::OWNED_ELSEWHERE
		);
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
	 * @dataProvider tracked_types
	 */
	public function test_is_tracked_type( string $post_type, bool $show_ui, bool $expected ): void {
		$this->assertSame( $expected, ContentSensor::is_tracked_type( $post_type, $show_ui ) );
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

	public function test_field_changes_reports_changed_fields_in_list_order(): void {
		$before = $this->post_values();
		$after  = array_merge(
			$before,
			[
				'post_title'     => 'New title',
				'comment_status' => 'closed',
			]
		);

		$this->assertSame(
			[
				[
					'field' => 'post_title',
					'old'   => 'Old title',
					'new'   => 'New title',
				],
				[
					'field' => 'comment_status',
					'old'   => 'open',
					'new'   => 'closed',
				],
			],
			ContentSensor::field_changes( $before, $after )
		);
	}

	public function test_field_changes_hides_the_content_values(): void {
		$before = $this->post_values();
		$after  = array_merge( $before, [ 'post_content' => 'Something else entirely' ] );

		$this->assertSame(
			[
				[
					'field' => 'post_content',
					'old'   => null,
					'new'   => null,
				],
			],
			ContentSensor::field_changes( $before, $after )
		);
	}

	public function test_field_changes_shows_the_author_change_as_it_is(): void {
		$before = $this->post_values();
		$after  = array_merge( $before, [ 'post_author' => '3' ] );

		$this->assertSame(
			[
				[
					'field' => 'post_author',
					'old'   => '1',
					'new'   => '3',
				],
			],
			ContentSensor::field_changes( $before, $after )
		);
	}

	public function test_field_changes_is_empty_when_nothing_listed_changed(): void {
		$before = $this->post_values();
		$after  = array_merge( $before, [ 'post_modified' => '2026-10-26 12:00:00' ] );

		$this->assertSame( [], ContentSensor::field_changes( $before, $after ) );
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
		$calls = $this->run_hook(
			static function ( ContentSensor $sensor, callable $post ): void {
				$sensor->on_status( 'publish', 'draft', $post( [ 'post_type' => 'product' ] ) );
				$sensor->on_status( 'publish', 'draft', $post( [ 'post_type' => 'custom_css' ] ) );
				$sensor->on_status( 'publish', 'draft', $post( [ 'post_type' => 'not_registered' ] ) );
				$sensor->on_delete( 7, $post( [ 'post_type' => 'product' ] ) );
			}
		);

		$this->assertSame( [], $calls );
	}

	/**
	 * Run hook calls against a recording dispatcher, with the WordPress
	 * stand-ins loaded. Only call this from a test of its own process.
	 *
	 * @param callable(ContentSensor, callable(array<string, mixed>): \WP_Post): void $hooks What to call.
	 * @return array<int, array{0: int, 1: array<string, mixed>}> Every dispatch as `[ code, fields ]`.
	 */
	private function run_hook( callable $hooks ): array {
		require_once __DIR__ . '/Fixtures/wordpress-content-functions.php';

		$dispatcher = new class() implements EventDispatcher {

			/** @var array<int, array{0: int, 1: array<string, mixed>}> */
			public array $calls = [];

			public function dispatch( int $code, array $fields = [] ): void {
				$this->calls[] = [ $code, $fields ];
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

		$hooks( new ContentSensor( $dispatcher ), $post );

		return $dispatcher->calls;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function post_values(): array {
		return [
			'post_title'     => 'Old title',
			'post_content'   => 'Old body',
			'post_excerpt'   => '',
			'post_name'      => 'old-title',
			'post_parent'    => 0,
			'post_author'    => '1',
			'comment_status' => 'open',
			'ping_status'    => 'open',
			'post_modified'  => '2026-10-26 11:00:00',
		];
	}
}
