<?php
/**
 * Tests for {@see HelloLog\Sensors\Core\ThemesSensor}.
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
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Core\ThemesSensor;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Core\Fixtures\ExtensionState;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use PHPUnit\Framework\TestCase;

/**
 * `WP_Theme::get( 'Name' )` returns `false` — not `''` — when the header is
 * unreadable. This pins the stylesheet-slug fallback for every falsy shape
 * that call can return, not just the empty string.
 *
 * Theme events name the theme as their target by its stylesheet: installs,
 * updates with the version they came from, deletions under the real name,
 * automatic-update toggles and customizer saves of the active theme. The
 * sensor calls WordPress, so every test that does runs in its own process
 * with the stand-ins of `Fixtures/wordpress-extension-functions.php`.
 */
final class ThemesSensorTest extends TestCase {

	public function test_false_name_falls_back_to_stylesheet(): void {
		$this->assertSame( 'twentytwentyfive', ThemesSensor::resolve_theme_name( false, 'twentytwentyfive' ) );
	}

	public function test_empty_string_name_falls_back_to_stylesheet(): void {
		$this->assertSame( 'twentytwentyfive', ThemesSensor::resolve_theme_name( '', 'twentytwentyfive' ) );
	}

	public function test_valid_name_is_kept_as_is(): void {
		$this->assertSame( 'Twenty Twenty-Five', ThemesSensor::resolve_theme_name( 'Twenty Twenty-Five', 'twentytwentyfive' ) );
	}

	public function test_the_theme_target_is_the_stylesheet_labelled_with_the_name(): void {
		$this->assertSame(
			[
				'type'  => 'theme',
				'id'    => 'twentytwentyfive',
				'label' => 'Twenty Twenty-Five',
			],
			ThemesSensor::theme_target( 'twentytwentyfive', 'Twenty Twenty-Five' )
		);
	}

	public function test_installed_stylesheets_takes_the_theme_the_upgrader_installed_when_the_job_names_none(): void {
		$this->assertSame(
			[ 'flavor' ],
			ThemesSensor::installed_stylesheets(
				[
					'type'   => 'theme',
					'action' => 'install',
				],
				'flavor'
			)
		);
	}

	public function test_installed_stylesheets_takes_every_theme_of_a_bulk_update(): void {
		$this->assertSame(
			[ 'flavor', 'twentytwentyfive' ],
			ThemesSensor::installed_stylesheets(
				[
					'action' => 'update',
					'type'   => 'theme',
					'bulk'   => true,
					'themes' => [ 'flavor', 'twentytwentyfive' ],
				],
				'twentytwentyfive'
			)
		);
	}

	public function test_installed_stylesheets_takes_the_theme_of_a_single_update(): void {
		$this->assertSame(
			[ 'flavor' ],
			ThemesSensor::installed_stylesheets(
				[
					'theme'  => 'flavor',
					'type'   => 'theme',
					'action' => 'update',
				],
				null
			)
		);
	}

	public function test_installed_stylesheets_is_empty_when_nothing_names_a_theme(): void {
		$install = [
			'type'   => 'theme',
			'action' => 'install',
		];

		$this->assertSame( [], ThemesSensor::installed_stylesheets( $install, null ) );
		$this->assertSame( [], ThemesSensor::installed_stylesheets( $install, '' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_hooks_are_registered_with_the_arguments_they_read(): void {
		$this->wordpress();

		( new ThemesSensor( $this->recorder() ) )->boot();

		$this->assertSame(
			[
				'priority'      => 10,
				'accepted_args' => 2,
			],
			ExtensionState::$filters['upgrader_pre_install']
		);
		$this->assertSame( 2, WpState::$actions['upgrader_process_complete']['accepted_args'] );
		$this->assertSame( 1, WpState::$actions['delete_theme']['accepted_args'] );
		$this->assertSame( 2, WpState::$actions['deleted_theme']['accepted_args'] );
		$this->assertSame( 0, WpState::$actions['customize_save_after']['accepted_args'] );
		$this->assertSame( 3, WpState::$actions['updated_option']['accepted_args'] );
		$this->assertSame( 2, WpState::$actions['added_option']['accepted_args'] );
		$this->assertArrayNotHasKey( 'update_site_option_auto_update_themes', WpState::$actions );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_network_listens_to_the_site_option_hooks_only(): void {
		$this->wordpress();
		ExtensionState::$multisite = true;

		( new ThemesSensor( $this->recorder() ) )->boot();

		$this->assertSame( 3, WpState::$actions['update_site_option_auto_update_themes']['accepted_args'] );
		$this->assertSame( 2, WpState::$actions['add_site_option_auto_update_themes']['accepted_args'] );
		$this->assertArrayNotHasKey( 'updated_option', WpState::$actions );
		$this->assertArrayNotHasKey( 'added_option', WpState::$actions );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_install_names_the_theme_the_upgrader_installed_as_target(): void {
		$this->put_theme( 'twentytwentyfive', 'Twenty Twenty-Five', '1.2' );
		$recorder = $this->recorder();
		$sensor   = new ThemesSensor( $recorder );
		$job      = [
			'type'   => 'theme',
			'action' => 'install',
		];

		$this->assertTrue( $sensor->on_pre_install( true, $job ) );
		$this->put_theme( 'flavor', 'Flavor', '2.0' );
		$sensor->on_upgrader( $this->upgrader( new \WP_Theme( 'flavor', [ 'Name' => 'Flavor' ], true ) ), $job );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 5101, $code );
		$this->assertSame( ThemesSensor::theme_target( 'flavor', 'Flavor' ), $fields['target'] );
		$this->assertSame( 'Flavor', $fields['name'] );
		$this->assertSame(
			[
				'stylesheet' => 'flavor',
				'action'     => 'install',
				'version'    => '2.0',
			],
			$fields['metadata']
		);
		$this->assertArrayNotHasKey( 'changes', $fields );
	}

	/**
	 * A single update does not clear the themes core read earlier in the
	 * request unless it clears the update cache, which an automatic update
	 * does not: the new version is read afresh.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_update_reports_the_old_and_the_new_version(): void {
		$this->put_theme( 'twentytwentyfive', 'Twenty Twenty-Five', '1.2' );
		$recorder = $this->recorder();
		$sensor   = new ThemesSensor( $recorder );
		$job      = [
			'theme'  => 'twentytwentyfive',
			'type'   => 'theme',
			'action' => 'update',
		];

		$sensor->on_pre_install( true, $job );
		$this->put_theme( 'twentytwentyfive', 'Twenty Twenty-Five', '1.3' );
		$sensor->on_upgrader( $this->upgrader( false ), $job );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 5102, $code );
		$this->assertSame( '1.3', $fields['version'] );
		$this->assertSame(
			[
				[
					'field' => 'version',
					'old'   => '1.2',
					'new'   => '1.3',
				],
			],
			$fields['changes']
		);
		$this->assertSame( ThemesSensor::theme_target( 'twentytwentyfive', 'Twenty Twenty-Five' ), $fields['target'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_bulk_update_of_two_themes_logs_each_with_its_own_versions(): void {
		$this->put_theme( 'flavor', 'Flavor', '2.0' );
		$this->put_theme( 'twentytwentyfive', 'Twenty Twenty-Five', '1.2' );
		$recorder = $this->recorder();
		$sensor   = new ThemesSensor( $recorder );

		$sensor->on_pre_install( true, [ 'theme' => 'flavor' ] );
		$this->put_theme( 'flavor', 'Flavor', '2.1' );
		ExtensionState::$theme_cache = [];
		$sensor->on_pre_install( true, [ 'theme' => 'twentytwentyfive' ] );
		$this->put_theme( 'twentytwentyfive', 'Twenty Twenty-Five', '1.3' );
		$sensor->on_upgrader(
			$this->upgrader( false ),
			[
				'action' => 'update',
				'type'   => 'theme',
				'bulk'   => true,
				'themes' => [ 'flavor', 'twentytwentyfive' ],
			]
		);

		$this->assertSame( [ 5102, 5102 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 'flavor', $recorder->calls[0][1]['target']['id'] );
		$this->assertSame( '2.0', $recorder->calls[0][1]['changes'][0]['old'] );
		$this->assertSame( '2.1', $recorder->calls[0][1]['changes'][0]['new'] );
		$this->assertSame( 'twentytwentyfive', $recorder->calls[1][1]['target']['id'] );
		$this->assertSame( '1.2', $recorder->calls[1][1]['changes'][0]['old'] );
		$this->assertSame( '1.3', $recorder->calls[1][1]['changes'][0]['new'] );
	}

	/**
	 * "Replace active with uploaded" runs the install, not the update.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uploading_over_an_installed_theme_is_an_update_from_its_old_version(): void {
		$this->put_theme( 'flavor', 'Flavor', '2.0' );
		$recorder = $this->recorder();
		$sensor   = new ThemesSensor( $recorder );
		$job      = [
			'type'   => 'theme',
			'action' => 'install',
		];

		$sensor->on_pre_install( true, $job );
		$this->put_theme( 'flavor', 'Flavor', '2.2' );
		$sensor->on_upgrader( $this->upgrader( new \WP_Theme( 'flavor', [ 'Version' => '2.0' ], true ) ), $job );

		$this->assertSame( 5102, $recorder->calls[0][0] );
		$this->assertSame(
			[
				[
					'field' => 'version',
					'old'   => '2.0',
					'new'   => '2.2',
				],
			],
			$recorder->calls[0][1]['changes']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_snapshot_taken_on_another_site_is_not_used(): void {
		$this->put_theme( 'flavor', 'Flavor', '2.0' );
		$recorder = $this->recorder();
		$sensor   = new ThemesSensor( $recorder );
		$job      = [
			'type'   => 'theme',
			'action' => 'install',
		];

		switch_to_blog( 3 );
		$sensor->on_pre_install( true, $job );
		restore_current_blog();
		$sensor->on_upgrader( $this->upgrader( new \WP_Theme( 'flavor', [], true ) ), $job );

		$this->assertSame( 5101, $recorder->calls[0][0] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_plugin_and_translation_jobs_take_no_theme_snapshot(): void {
		$this->wordpress();
		$sensor = new ThemesSensor( $this->recorder() );

		$sensor->on_pre_install(
			true,
			[
				'type'   => 'plugin',
				'action' => 'install',
			]
		);
		$sensor->on_pre_install( true, [ 'plugin' => 'hello.php' ] );
		$sensor->on_pre_install( true, [ 'language_update_type' => 'theme' ] );

		$this->assertSame( 0, ExtensionState::$theme_lists );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_snapshot_that_cannot_be_read_is_reported_and_the_install_goes_on(): void {
		$this->put_theme( 'flavor', 'Flavor', '2.0' );
		$this->failure_listener();
		ExtensionState::$reads_throw = true;
		$response                    = new \stdClass();

		$result = ( new ThemesSensor( $this->recorder() ) )->on_pre_install(
			$response,
			[
				'type'   => 'theme',
				'action' => 'install',
			]
		);

		$this->assertSame( $response, $result );
		$this->assertSame( 'hellolog_dispatch_failed', WpCalls::$actions[0][0] );
		$this->assertSame( 5101, WpCalls::$actions[0][1][1] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_upgrader_without_theme_info_logs_nothing_and_does_not_fail(): void {
		$this->wordpress();
		$recorder = $this->recorder();

		( new ThemesSensor( $recorder ) )->on_upgrader(
			new \stdClass(),
			[
				'type'   => 'theme',
				'action' => 'install',
			]
		);

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deleted_theme_keeps_its_real_name(): void {
		$this->put_theme( 'flavor', 'Flavor', '2.0' );
		$recorder = $this->recorder();
		$sensor   = new ThemesSensor( $recorder );

		$sensor->on_delete( 'flavor' );
		unset( ExtensionState::$themes['flavor'], ExtensionState::$theme_cache['flavor'] );
		$sensor->on_deleted( 'flavor', true );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 5103, $code );
		$this->assertSame( 'Flavor', $fields['name'] );
		$this->assertSame( ThemesSensor::theme_target( 'flavor', 'Flavor' ), $fields['target'] );
		$this->assertSame(
			[
				'stylesheet' => 'flavor',
				'version'    => '2.0',
			],
			$fields['metadata']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failed_theme_deletion_logs_nothing(): void {
		$this->put_theme( 'flavor', 'Flavor', '2.0' );
		$recorder = $this->recorder();
		$sensor   = new ThemesSensor( $recorder );

		$sensor->on_delete( 'flavor' );
		$sensor->on_deleted( 'flavor', false );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_theme_deletion_nobody_saw_coming_is_named_by_its_stylesheet(): void {
		$this->wordpress();
		$recorder = $this->recorder();

		( new ThemesSensor( $recorder ) )->on_deleted( 'flavor', true );

		$this->assertSame( 'flavor', $recorder->calls[0][1]['name'] );
		$this->assertSame( ThemesSensor::theme_target( 'flavor', 'flavor' ), $recorder->calls[0][1]['target'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_theme_about_to_be_deleted_that_cannot_be_read_is_reported_and_never_thrown(): void {
		$this->wordpress();
		$this->failure_listener();
		ExtensionState::$reads_throw = true;

		( new ThemesSensor( $this->recorder() ) )->on_delete( 'flavor' );

		$this->assertSame( 5103, WpCalls::$actions[0][1][1] );
		$this->assertSame( [ 'stylesheet' => 'flavor' ], WpCalls::$actions[0][1][2] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_turning_automatic_updates_on_and_off_logs_one_event_per_theme(): void {
		$this->put_theme( 'flavor', 'Flavor', '2.0' );
		$this->put_theme( 'twentytwentyfive', 'Twenty Twenty-Five', '1.2' );
		$recorder = $this->recorder();

		( new ThemesSensor( $recorder ) )->on_option_updated( 'auto_update_themes', [ 'twentytwentyfive' ], [ 'flavor' ] );

		$this->assertSame( [ 5105, 5105 ], array_column( $recorder->calls, 0 ) );
		$enabled  = $recorder->calls[0][1];
		$disabled = $recorder->calls[1][1];
		$this->assertSame( 'enabled', $enabled['state'] );
		$this->assertSame( 'Flavor', $enabled['name'] );
		$this->assertSame( ThemesSensor::theme_target( 'flavor', 'Flavor' ), $enabled['target'] );
		$this->assertSame( [ 'stylesheet' => 'flavor' ], $enabled['metadata'] );
		$this->assertSame( 'disabled', $disabled['state'] );
		$this->assertSame( ThemesSensor::theme_target( 'twentytwentyfive', 'Twenty Twenty-Five' ), $disabled['target'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_first_theme_toggle_on_a_site_is_logged(): void {
		$this->put_theme( 'flavor', 'Flavor', '2.0' );
		$recorder = $this->recorder();

		( new ThemesSensor( $recorder ) )->on_option_added( 'auto_update_themes', [ 'flavor' ] );

		$this->assertSame( 5105, $recorder->calls[0][0] );
		$this->assertSame( 'enabled', $recorder->calls[0][1]['state'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_theme_toggle_on_a_network_is_logged(): void {
		$this->put_theme( 'flavor', 'Flavor', '2.0' );
		$this->put_theme( 'twentytwentyfive', 'Twenty Twenty-Five', '1.2' );
		ExtensionState::$multisite = true;
		$recorder                  = $this->recorder();
		$sensor                    = new ThemesSensor( $recorder );

		$sensor->on_site_option_added( 'auto_update_themes', [ 'flavor' ], 1 );
		$sensor->on_site_option_updated( 'auto_update_themes', [], [ 'flavor' ], 1 );

		$this->assertSame( [ 5105, 5105 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ 'enabled', 'disabled' ], array_column( array_column( $recorder->calls, 1 ), 'state' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_theme_dropped_from_the_list_because_it_is_gone_is_not_logged(): void {
		$this->put_theme( 'flavor', 'Flavor', '2.0' );
		$recorder = $this->recorder();
		$sensor   = new ThemesSensor( $recorder );

		$sensor->on_option_updated( 'auto_update_themes', [ 'deleted-long-ago', 'flavor' ], [ 'flavor' ] );
		$sensor->on_option_updated( 'auto_update_plugins', [], [ 'flavor' ] );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_theme_toggle_that_cannot_be_read_is_reported_and_never_thrown(): void {
		$this->wordpress();
		$this->failure_listener();
		ExtensionState::$reads_throw = true;
		$recorder                    = $this->recorder();

		( new ThemesSensor( $recorder ) )->on_option_added( 'auto_update_themes', [ 'flavor' ] );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 5105, WpCalls::$actions[0][1][1] );
		$this->assertSame( [ 'option' => 'auto_update_themes' ], WpCalls::$actions[0][1][2] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_customizer_save_names_the_active_theme_as_target(): void {
		$this->put_theme( 'flavor', 'Flavor', '2.0' );
		ExtensionState::$active_theme = 'flavor';
		$recorder                     = $this->recorder();

		( new ThemesSensor( $recorder ) )->on_customizer_save();

		$this->assertSame( 5104, $recorder->calls[0][0] );
		$this->assertSame( ThemesSensor::theme_target( 'flavor', 'Flavor' ), $recorder->calls[0][1]['target'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_customizer_save_that_cannot_read_the_theme_is_reported_and_never_thrown(): void {
		$this->wordpress();
		$this->failure_listener();
		ExtensionState::$reads_throw = true;
		$recorder                    = $this->recorder();

		( new ThemesSensor( $recorder ) )->on_customizer_save();

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 5104, WpCalls::$actions[0][1][1] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_theme_switch_names_the_new_theme_as_target(): void {
		$this->wordpress();
		$recorder = $this->recorder();

		( new ThemesSensor( $recorder ) )->on_switch(
			'Flavor',
			new \WP_Theme( 'flavor', [ 'Name' => 'Flavor' ], true ),
			new \WP_Theme( 'twentytwentyfive', [ 'Name' => 'Twenty Twenty-Five' ], true )
		);

		$this->assertSame( 5100, $recorder->calls[0][0] );
		$this->assertSame( ThemesSensor::theme_target( 'flavor', 'Flavor' ), $recorder->calls[0][1]['target'] );
		$this->assertSame( 'Twenty Twenty-Five', $recorder->calls[0][1]['metadata']['old_theme'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_toggle_payload_reads_naturally(): void {
		$this->put_theme( 'twentytwentyfive', 'Twenty Twenty-Five', '1.2' );
		$recorder = $this->recorder();

		( new ThemesSensor( $recorder ) )->on_option_updated( 'auto_update_themes', [ 'twentytwentyfive' ], [] );

		$payload = $this->builder()->build( $recorder->calls[0][0], $recorder->calls[0][1] );
		$this->assertIsArray( $payload );
		$this->assertSame( 'Automatic updates disabled for theme Twenty Twenty-Five.', $payload['message'] );
		$this->assertSame(
			[
				'state' => 'disabled',
				'name'  => 'Twenty Twenty-Five',
			],
			$payload['vars']
		);
		$this->assertSame( 'twentytwentyfive', $payload['target']['id'] );
	}

	/**
	 * Load the WordPress stand-ins. Only call this from a test of its own process.
	 */
	private function wordpress(): void {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-extension-functions.php';
	}

	/**
	 * Put a theme on disk with these headers, or change the ones it has.
	 * Only call this from a test of its own process.
	 *
	 * @param string $stylesheet Theme directory.
	 * @param string $name       Theme Name header.
	 * @param string $version    Version header.
	 */
	private function put_theme( string $stylesheet, string $name, string $version ): void {
		$this->wordpress();

		ExtensionState::$themes[ $stylesheet ] = [
			'Name'    => $name,
			'Version' => $version,
		];
	}

	/**
	 * An upgrader whose `theme_info()` answers this.
	 *
	 * @param \WP_Theme|false $info The theme of the package it installed, or false.
	 */
	private function upgrader( $info ): object {
		return new class( $info ) {

			/** @param \WP_Theme|false $info */
			public function __construct( private $info ) {
			}

			/** @return \WP_Theme|false */
			public function theme_info() {
				return $this->info;
			}
		};
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
	 * A builder that sees an admin (user 1) on the dashboard.
	 */
	private function builder(): EventBuilder {
		$catalog = new EventCatalog();
		CatalogSeeder::seed( $catalog );

		return new class( $catalog ) extends EventBuilder {

			public function __construct( EventCatalog $catalog ) {
				parent::__construct( $catalog, false, static fn(): string => RequestSource::ADMIN );
			}

			protected function current_context(): RequestContext {
				return new RequestContext( 1, 'admin', [ 'administrator' ], null, null, null );
			}
		};
	}
}
