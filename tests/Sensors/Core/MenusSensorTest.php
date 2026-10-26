<?php
/**
 * Tests for {@see HelloLog\Sensors\Core\MenusSensor}.
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
use HelloLog\Sensors\Core\MenusSensor;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Core\Fixtures\TermState;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use PHPUnit\Framework\TestCase;

/**
 * Every menu event names the menu as its target. A save says when the menu
 * was renamed: the sensor reads the menu just before core writes it
 * (`edit_terms` for the `nav_menu` taxonomy) on its own, since the taxonomy
 * sensor leaves menus alone and either sensor can be turned off. A deletion
 * keeps the menu's name.
 *
 * The sensor calls WordPress, so every test that does runs in its own process
 * with the stand-ins of `Fixtures/wordpress-term-functions.php`.
 */
final class MenusSensorTest extends TestCase {

	public function test_the_menu_target_is_the_id_labelled_with_the_name(): void {
		$this->assertSame(
			[
				'type'  => 'menu',
				'id'    => '9',
				'label' => 'Main',
			],
			MenusSensor::menu_target( 9, 'Main' )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_hooks_are_registered_with_the_arguments_they_read(): void {
		$this->wordpress();

		( new MenusSensor( $this->recorder() ) )->boot();

		$this->assertSame( 2, WpState::$actions['edit_terms']['accepted_args'] );
		$this->assertSame( 4, WpState::$actions['delete_term']['accepted_args'] );
		$this->assertSame( 2, WpState::$actions['wp_create_nav_menu']['accepted_args'] );
		$this->assertSame( 2, WpState::$actions['wp_update_nav_menu']['accepted_args'] );
		$this->assertSame( 1, WpState::$actions['wp_delete_nav_menu']['accepted_args'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_renamed_menu_reports_its_old_and_new_name(): void {
		$this->put_menu( 9, 'Main' );
		$recorder = $this->recorder();
		$sensor   = new MenusSensor( $recorder );

		$sensor->on_before_edit( 9, 'nav_menu' );
		$this->put_menu( 9, 'Primary' );
		$sensor->on_update( 9, [ 'menu-name' => 'Primary' ] );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 2401, $code );
		$this->assertSame( MenusSensor::menu_target( 9, 'Primary' ), $fields['target'] );
		$this->assertSame( 'Primary', $fields['name'] );
		$this->assertSame(
			[
				[
					'field' => 'name',
					'old'   => 'Main',
					'new'   => 'Primary',
				],
			],
			$fields['changes']
		);
		$this->assertSame( [ 'menu_id' => 9 ], $fields['metadata'] );
	}

	/**
	 * The name is read from the stored menu, not from the input of the save,
	 * which core passes on slashed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_name_is_the_stored_one(): void {
		$this->put_menu( 9, "Bob's menu" );
		$recorder = $this->recorder();
		$sensor   = new MenusSensor( $recorder );

		$sensor->on_create( 9, [ 'menu-name' => "Bob\\'s menu" ] );
		$sensor->on_update( 9, [ 'menu-name' => "Bob\\'s menu" ] );

		$this->assertSame( "Bob's menu", $recorder->calls[0][1]['name'] );
		$this->assertSame( "Bob's menu", $recorder->calls[1][1]['name'] );
	}

	/**
	 * A save of the menu's items (core fires the update hook with the menu id
	 * alone) names the menu, and is logged though nothing about the menu
	 * itself changed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_of_the_items_names_the_menu_and_is_logged(): void {
		$this->put_menu( 9, 'Main' );
		$recorder = $this->recorder();

		( new MenusSensor( $recorder ) )->on_update( 9 );

		$this->assertSame( MenusSensor::menu_target( 9, 'Main' ), $recorder->calls[0][1]['target'] );
		$this->assertSame( 'Main', $recorder->calls[0][1]['name'] );
		$this->assertSame( [], $recorder->calls[0][1]['changes'] );

		$payloads = $this->payloads( $recorder );
		$this->assertCount( 1, $payloads );
		$this->assertSame( 'Menu "Main" updated.', $payloads[0]['message'] );
		$this->assertArrayNotHasKey( 'changes', $payloads[0] );
	}

	/**
	 * The menu screen saves the menu, then its items: one event, with the
	 * rename.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_menu_screen_save_is_one_event_with_the_rename(): void {
		$this->put_menu( 9, 'Main' );
		$recorder = $this->recorder();
		$sensor   = new MenusSensor( $recorder );

		$sensor->on_before_edit( 9, 'nav_menu' );
		$this->put_menu( 9, 'Primary' );
		$sensor->on_update( 9, [ 'menu-name' => 'Primary' ] );
		$sensor->on_update( 9 );

		$payloads = $this->payloads( $recorder );
		$this->assertCount( 1, $payloads );
		$this->assertSame( 2401, $payloads[0]['code'] );
		$this->assertSame( 'Menu "Primary" updated.', $payloads[0]['message'] );
		$this->assertSame(
			[
				'type'  => 'menu',
				'id'    => '9',
				'label' => 'Primary',
			],
			$payloads[0]['target']
		);
		$this->assertSame(
			[
				[
					'field' => 'name',
					'old'   => 'Main',
					'new'   => 'Primary',
				],
			],
			$payloads[0]['changes']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_terms_of_other_taxonomies_are_not_noted(): void {
		$this->put_menu( 9, 'Main' );
		$recorder = $this->recorder();
		$sensor   = new MenusSensor( $recorder );

		$sensor->on_before_edit( 9, 'category' );
		$this->put_menu( 9, 'Primary' );
		$sensor->on_update( 9, [ 'menu-name' => 'Primary' ] );

		$this->assertSame( [], $recorder->calls[0][1]['changes'] );
	}

	/**
	 * A menu id is only unique on its own site.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_snapshot_taken_on_another_site_is_not_used(): void {
		$this->put_menu( 9, 'Main' );
		$recorder = $this->recorder();
		$sensor   = new MenusSensor( $recorder );

		switch_to_blog( 2 );
		$sensor->on_before_edit( 9, 'nav_menu' );
		restore_current_blog();
		$this->put_menu( 9, 'Primary' );
		$sensor->on_update( 9, [ 'menu-name' => 'Primary' ] );

		$this->assertSame( [], $recorder->calls[0][1]['changes'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_snapshot_is_compared_once(): void {
		$this->put_menu( 9, 'Main' );
		$recorder = $this->recorder();
		$sensor   = new MenusSensor( $recorder );

		$sensor->on_before_edit( 9, 'nav_menu' );
		$this->put_menu( 9, 'Primary' );
		$sensor->on_update( 9, [ 'menu-name' => 'Primary' ] );
		$sensor->on_update( 9 );

		$this->assertCount( 1, $recorder->calls[0][1]['changes'] );
		$this->assertSame( [], $recorder->calls[1][1]['changes'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_menu_that_cannot_be_read_before_the_save_is_reported_and_never_thrown(): void {
		$this->put_menu( 9, 'Main' );
		$this->failure_listener();
		$recorder               = $this->recorder();
		$sensor                 = new MenusSensor( $recorder );
		TermState::$reads_throw = true;

		$sensor->on_before_edit( 9, 'nav_menu' );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 'hellolog_dispatch_failed', WpCalls::$actions[0][0] );
		$this->assertSame( 2401, WpCalls::$actions[0][1][1] );
		$this->assertSame( [ 'menu_id' => 9 ], WpCalls::$actions[0][1][2] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failed_snapshot_drops_the_one_before_it(): void {
		$this->put_menu( 9, 'Main' );
		$this->failure_listener();
		$recorder = $this->recorder();
		$sensor   = new MenusSensor( $recorder );

		$sensor->on_before_edit( 9, 'nav_menu' );
		TermState::$reads_throw = true;
		$sensor->on_before_edit( 9, 'nav_menu' );
		TermState::$reads_throw = false;
		$this->put_menu( 9, 'Primary' );
		$sensor->on_update( 9, [ 'menu-name' => 'Primary' ] );

		$this->assertSame( [], $recorder->calls[0][1]['changes'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_menu_that_cannot_be_read_after_the_save_is_reported_and_never_thrown(): void {
		$this->put_menu( 9, 'Main' );
		$this->failure_listener();
		$recorder = $this->recorder();
		$sensor   = new MenusSensor( $recorder );

		$sensor->on_before_edit( 9, 'nav_menu' );
		TermState::$reads_throw = true;
		$sensor->on_update( 9, [ 'menu-name' => 'Primary' ] );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 2401, WpCalls::$actions[0][1][1] );
		$this->assertSame( [ 'menu_id' => 9 ], WpCalls::$actions[0][1][2] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_new_menu_that_cannot_be_read_is_reported_and_never_thrown(): void {
		$this->put_menu( 9, 'Main' );
		$this->failure_listener();
		$recorder               = $this->recorder();
		TermState::$reads_throw = true;

		( new MenusSensor( $recorder ) )->on_create( 9, [ 'menu-name' => 'Main' ] );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 2400, WpCalls::$actions[0][1][1] );
		$this->assertSame( [ 'menu_id' => 9 ], WpCalls::$actions[0][1][2] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_new_menu_is_named_as_target(): void {
		$this->put_menu( 9, 'Main' );
		$recorder = $this->recorder();

		( new MenusSensor( $recorder ) )->on_create( 9, [ 'menu-name' => 'Main' ] );

		$this->assertSame( 2400, $recorder->calls[0][0] );
		$this->assertSame( MenusSensor::menu_target( 9, 'Main' ), $recorder->calls[0][1]['target'] );
		$this->assertSame( 'Main', $recorder->calls[0][1]['name'] );
		$this->assertSame( [ 'menu_id' => 9 ], $recorder->calls[0][1]['metadata'] );
	}

	/**
	 * A menu not found is named by the input of the save.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_menu_that_is_not_found_is_named_by_the_save(): void {
		$this->wordpress();
		$recorder = $this->recorder();

		( new MenusSensor( $recorder ) )->on_create( 9, [ 'menu-name' => 'Main' ] );

		$this->assertSame( 'Main', $recorder->calls[0][1]['name'] );
	}

	/**
	 * Core tells the deletion by the menu id once the menu is gone; its name
	 * comes from the term core deleted.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deleted_menu_keeps_its_name(): void {
		$this->put_menu( 9, 'Main' );
		$recorder = $this->recorder();
		$sensor   = new MenusSensor( $recorder );
		$deleted  = TermState::$terms[9];
		unset( TermState::$terms[9] );

		$sensor->on_term_deleted( 9, 90, 'nav_menu', $deleted );
		$sensor->on_delete( 9 );

		$this->assertSame( 2402, $recorder->calls[0][0] );
		$this->assertSame( MenusSensor::menu_target( 9, 'Main' ), $recorder->calls[0][1]['target'] );
		$this->assertSame( 'Main', $recorder->calls[0][1]['name'] );
		$this->assertSame( [ 'menu_id' => 9 ], $recorder->calls[0][1]['metadata'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deleted_term_of_another_taxonomy_names_no_menu(): void {
		$this->put_menu( 9, 'Main' );
		$recorder = $this->recorder();
		$sensor   = new MenusSensor( $recorder );
		$deleted  = TermState::$terms[9];

		$sensor->on_term_deleted( 9, 90, 'category', $deleted );
		$sensor->on_delete( 9 );

		$this->assertSame( '#9', $recorder->calls[0][1]['name'] );
		$this->assertSame( MenusSensor::menu_target( 9, '#9' ), $recorder->calls[0][1]['target'] );
	}

	/**
	 * The name noted on one site is not the name of a menu of another.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_name_noted_on_another_site_is_not_used(): void {
		$this->put_menu( 9, 'Main' );
		$recorder = $this->recorder();
		$sensor   = new MenusSensor( $recorder );

		switch_to_blog( 2 );
		$sensor->on_term_deleted( 9, 90, 'nav_menu', TermState::$terms[9] );
		restore_current_blog();
		$sensor->on_delete( 9 );

		$this->assertSame( '#9', $recorder->calls[0][1]['name'] );
	}

	/**
	 * Code that fires the hook itself may pass the deleted term instead of
	 * its id.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deletion_told_with_the_term_takes_its_name(): void {
		$this->put_menu( 9, 'Main' );
		$recorder = $this->recorder();

		( new MenusSensor( $recorder ) )->on_delete( TermState::$terms[9] );

		$this->assertSame( 'Main', $recorder->calls[0][1]['name'] );
		$this->assertSame( MenusSensor::menu_target( 9, 'Main' ), $recorder->calls[0][1]['target'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deleted_term_that_cannot_be_read_is_reported_and_never_thrown(): void {
		$this->wordpress();
		$this->failure_listener();
		$recorder = $this->recorder();
		$broken   = new class() {

			public function __isset( string $name ): bool {
				return true;
			}

			public function __get( string $name ) {
				throw new \RuntimeException( 'unreadable' );
			}
		};

		( new MenusSensor( $recorder ) )->on_term_deleted( 9, 90, 'nav_menu', $broken );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 2402, WpCalls::$actions[0][1][1] );
		$this->assertSame( [ 'menu_id' => 9 ], WpCalls::$actions[0][1][2] );
	}

	/**
	 * Load the WordPress stand-ins. Only call this from a test of its own process.
	 */
	private function wordpress(): void {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-term-functions.php';
	}

	/**
	 * Store a menu with this name, or rename the one stored. Only call this
	 * from a test of its own process.
	 *
	 * @param int    $id   Menu (term) id.
	 * @param string $name Name.
	 */
	private function put_menu( int $id, string $name ): void {
		$this->wordpress();

		TermState::$terms[ $id ] = new \WP_Term(
			[
				'term_id'  => $id,
				'name'     => $name,
				'slug'     => strtolower( $name ),
				'taxonomy' => 'nav_menu',
			]
		);
	}

	/**
	 * Load the `do_action()` stand-in, which records the failure reports.
	 * Only call this from a test of its own process.
	 */
	private function failure_listener(): void {
		require_once __DIR__ . '/../../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';
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
	 * Build what the recorder saw as an admin (user 1) would on the dashboard
	 * and run the default per-request rules over it.
	 *
	 * @param EventDispatcher $recorder The recording dispatcher.
	 * @return array<int, array<string, mixed>> The payloads that would be written.
	 */
	private function payloads( EventDispatcher $recorder ): array {
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

		return SuppressionRules::defaults()->apply( $payloads );
	}
}
