<?php
/**
 * Tests for {@see HelloLog\Sensors\Core\PluginsSensor}.
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
use HelloLog\Sensors\Core\PluginsSensor;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Core\Fixtures\ExtensionState;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use PHPUnit\Framework\TestCase;

/**
 * An install says which plugin arrived, an update says which version it came
 * from and went to, a deletion keeps the plugin's real name, and turning
 * automatic updates on or off is logged per plugin. Every plugin event names
 * the plugin as its target by its slug, with the full plugin file in the
 * metadata.
 *
 * The sensor calls WordPress, so every test that does runs in its own process
 * with the stand-ins of `Fixtures/wordpress-extension-functions.php`.
 */
final class PluginsSensorTest extends TestCase {

	protected function tearDown(): void {
		if ( defined( 'WP_PLUGIN_DIR' ) && str_contains( (string) WP_PLUGIN_DIR, 'hellolog-test-plugins-' ) ) {
			$this->remove_tree( (string) WP_PLUGIN_DIR );
		}
	}

	public function test_installed_files_takes_the_plugin_the_upgrader_installed_when_the_job_names_none(): void {
		$this->assertSame(
			[ 'akismet/akismet.php' ],
			PluginsSensor::installed_files(
				[
					'type'   => 'plugin',
					'action' => 'install',
				],
				'akismet/akismet.php'
			)
		);
	}

	public function test_installed_files_takes_every_plugin_of_a_bulk_update(): void {
		$this->assertSame(
			[ 'akismet/akismet.php', 'hello.php' ],
			PluginsSensor::installed_files(
				[
					'action'  => 'update',
					'type'    => 'plugin',
					'bulk'    => true,
					'plugins' => [ 'akismet/akismet.php', 'hello.php' ],
				],
				'hello.php'
			)
		);
	}

	public function test_installed_files_takes_the_plugin_of_a_single_update(): void {
		$this->assertSame(
			[ 'akismet/akismet.php' ],
			PluginsSensor::installed_files(
				[
					'plugin' => 'akismet/akismet.php',
					'type'   => 'plugin',
					'action' => 'update',
				],
				null
			)
		);
	}

	public function test_installed_files_is_empty_when_nothing_names_a_plugin(): void {
		$install = [
			'type'   => 'plugin',
			'action' => 'install',
		];

		$this->assertSame( [], PluginsSensor::installed_files( $install, null ) );
		$this->assertSame( [], PluginsSensor::installed_files( $install, '' ) );
		$this->assertSame( [], PluginsSensor::installed_files( $install + [ 'plugins' => [] ], null ) );
	}

	public function test_version_changes_reports_the_plugins_whose_version_moved(): void {
		$before = [
			'akismet/akismet.php' => [
				'Name'    => 'Akismet Anti-spam',
				'Version' => '1.0',
			],
			'hello.php'           => [
				'Name'    => 'Hello Dolly',
				'Version' => '1.7.2',
			],
		];
		$after  = [
			'akismet/akismet.php' => [
				'Name'    => 'Akismet Anti-spam',
				'Version' => '1.1',
			],
			'hello.php'           => [
				'Name'    => 'Hello Dolly',
				'Version' => '1.7.2',
			],
		];

		$this->assertSame(
			[
				'akismet/akismet.php' => [
					'field' => 'version',
					'old'   => '1.0',
					'new'   => '1.1',
				],
			],
			PluginsSensor::version_changes( $before, $after )
		);
	}

	public function test_version_changes_leaves_out_a_plugin_that_was_not_installed_before(): void {
		$after = [
			'akismet/akismet.php' => [
				'Name'    => 'Akismet Anti-spam',
				'Version' => '5.3',
			],
		];

		$this->assertSame( [], PluginsSensor::version_changes( [], $after ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}> Plugin file, slug.
	 */
	public static function plugin_slugs(): array {
		return [
			'plugin in a directory'  => [ 'akismet/akismet.php', 'akismet' ],
			'single-file plugin'     => [ 'hello.php', 'hello' ],
			'file longer than an id' => [ 'woocommerce-pdf-invoices-packing-slips/woocommerce-pdf-invoices-packingslips.php', 'woocommerce-pdf-invoices-packing-slips' ],
		];
	}

	/**
	 * @dataProvider plugin_slugs
	 */
	public function test_a_plugin_is_known_by_its_slug( string $file, string $slug ): void {
		$this->assertSame( $slug, PluginsSensor::plugin_slug( $file ) );
	}

	public function test_the_plugin_target_is_the_slug_labelled_with_the_name(): void {
		$this->assertSame(
			[
				'type'  => 'plugin',
				'id'    => 'akismet',
				'label' => 'Akismet Anti-spam',
			],
			PluginsSensor::plugin_target( 'akismet/akismet.php', 'Akismet Anti-spam' )
		);
	}

	public function test_auto_update_toggles_lists_what_was_turned_on_then_what_was_turned_off(): void {
		$this->assertSame(
			[
				[
					'entry' => 'hello.php',
					'state' => 'enabled',
				],
				[
					'entry' => 'akismet/akismet.php',
					'state' => 'disabled',
				],
			],
			PluginsSensor::auto_update_toggles(
				[ 'akismet/akismet.php', 'jetpack/jetpack.php' ],
				[ 'jetpack/jetpack.php', 'hello.php' ]
			)
		);
	}

	public function test_auto_update_toggles_of_the_same_list_in_another_order_is_empty(): void {
		$this->assertSame( [], PluginsSensor::auto_update_toggles( [ 'a/a.php', 'b/b.php' ], [ 'b/b.php', 'a/a.php' ] ) );
	}

	public function test_auto_update_toggles_reads_anything_but_a_list_of_names_as_nothing(): void {
		$this->assertSame( [], PluginsSensor::auto_update_toggles( false, 'a/a.php' ) );
		$this->assertSame(
			[
				[
					'entry' => 'a/a.php',
					'state' => 'enabled',
				],
			],
			PluginsSensor::auto_update_toggles( null, [ 'a/a.php', '', 7, null ] )
		);
	}

	/**
	 * A numeric stylesheet or file name stays a string.
	 */
	public function test_auto_update_toggles_keeps_a_numeric_name_a_string(): void {
		$this->assertSame(
			[
				[
					'entry' => '2024',
					'state' => 'enabled',
				],
			],
			PluginsSensor::auto_update_toggles( [], [ '2024' ] )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_hooks_are_registered_with_the_arguments_they_read(): void {
		$this->wordpress();

		( new PluginsSensor( $this->recorder() ) )->boot();

		$this->assertSame(
			[
				'priority'      => 10,
				'accepted_args' => 2,
			],
			ExtensionState::$filters['upgrader_pre_install']
		);
		$this->assertSame( 2, WpState::$actions['upgrader_process_complete']['accepted_args'] );
		$this->assertSame( 1, WpState::$actions['delete_plugin']['accepted_args'] );
		$this->assertSame( 2, WpState::$actions['deleted_plugin']['accepted_args'] );
	}

	/**
	 * The automatic-updates list is written with `update_site_option()`. On a
	 * single site that ends in `update_option()`, which fires `updated_option`
	 * (or `added_option` the first time) AND the site-option hooks; only the
	 * option hooks are used, so a toggle is logged once.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_single_site_listens_to_the_option_hooks_only(): void {
		$this->wordpress();

		( new PluginsSensor( $this->recorder() ) )->boot();

		$this->assertSame( 3, WpState::$actions['updated_option']['accepted_args'] );
		$this->assertSame( 2, WpState::$actions['added_option']['accepted_args'] );
		$this->assertArrayNotHasKey( 'update_site_option_auto_update_plugins', WpState::$actions );
		$this->assertArrayNotHasKey( 'add_site_option_auto_update_plugins', WpState::$actions );
	}

	/**
	 * On a network the list is a network option: only the site-option hooks fire.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_network_listens_to_the_site_option_hooks_only(): void {
		$this->wordpress();
		ExtensionState::$multisite = true;

		( new PluginsSensor( $this->recorder() ) )->boot();

		$this->assertSame( 3, WpState::$actions['update_site_option_auto_update_plugins']['accepted_args'] );
		$this->assertSame( 2, WpState::$actions['add_site_option_auto_update_plugins']['accepted_args'] );
		$this->assertArrayNotHasKey( 'updated_option', WpState::$actions );
		$this->assertArrayNotHasKey( 'added_option', WpState::$actions );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_install_names_the_new_plugin_as_target(): void {
		$this->put_plugin( 'hello.php', 'Hello Dolly', '1.7.2' );
		$recorder = $this->recorder();
		$sensor   = new PluginsSensor( $recorder );
		$job      = [
			'type'   => 'plugin',
			'action' => 'install',
		];

		$this->assertTrue( $sensor->on_pre_install( true, $job ) );
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.3' );
		$sensor->on_upgrader( $this->upgrader( 'akismet/akismet.php' ), $job );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 5000, $code );
		$this->assertSame( PluginsSensor::plugin_target( 'akismet/akismet.php', 'Akismet Anti-spam' ), $fields['target'] );
		$this->assertSame( 'Akismet Anti-spam', $fields['name'] );
		$this->assertSame( 'akismet/akismet.php', $fields['metadata']['plugin_file'] );
		$this->assertSame( 'install', $fields['metadata']['action'] );
		$this->assertSame( '5.3', $fields['metadata']['version'] );
		$this->assertArrayNotHasKey( 'changes', $fields );
	}

	/**
	 * An automatic update does not clear the plugin list core keeps for the
	 * request, so the list still has the old version when the update is
	 * complete: the new one is read from the file.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_update_reports_the_old_and_the_new_version(): void {
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.0' );
		$recorder = $this->recorder();
		$sensor   = new PluginsSensor( $recorder );
		$job      = [
			'plugin' => 'akismet/akismet.php',
			'type'   => 'plugin',
			'action' => 'update',
		];

		$sensor->on_pre_install( true, $job );
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.1' );
		$sensor->on_upgrader( $this->upgrader( false ), $job );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 5003, $code );
		$this->assertSame( '5.1', $fields['version'] );
		$this->assertSame(
			[
				[
					'field' => 'version',
					'old'   => '5.0',
					'new'   => '5.1',
				],
			],
			$fields['changes']
		);
		$this->assertSame( PluginsSensor::plugin_target( 'akismet/akismet.php', 'Akismet Anti-spam' ), $fields['target'] );
		$this->assertSame( 'update', $fields['metadata']['action'] );
	}

	/**
	 * Core runs the install step once per plugin of a bulk update, so the
	 * list is read again after the first plugin was replaced; the version a
	 * plugin had when it was first seen is the one it came from.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_bulk_update_of_two_plugins_logs_each_with_its_own_versions(): void {
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.0' );
		$this->put_plugin( 'hello.php', 'Hello Dolly', '1.7.1' );
		$recorder = $this->recorder();
		$sensor   = new PluginsSensor( $recorder );

		$sensor->on_pre_install( true, [ 'plugin' => 'akismet/akismet.php' ] );
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.1' );
		ExtensionState::$plugin_cache = null;
		$sensor->on_pre_install( true, [ 'plugin' => 'hello.php' ] );
		$this->put_plugin( 'hello.php', 'Hello Dolly', '1.7.2' );
		$sensor->on_upgrader(
			$this->upgrader( 'hello.php' ),
			[
				'action'  => 'update',
				'type'    => 'plugin',
				'bulk'    => true,
				'plugins' => [ 'akismet/akismet.php', 'hello.php' ],
			]
		);

		$this->assertSame( [ 5003, 5003 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 'akismet', $recorder->calls[0][1]['target']['id'] );
		$this->assertSame(
			[
				[
					'field' => 'version',
					'old'   => '5.0',
					'new'   => '5.1',
				],
			],
			$recorder->calls[0][1]['changes']
		);
		$this->assertSame( 'hello', $recorder->calls[1][1]['target']['id'] );
		$this->assertSame(
			[
				[
					'field' => 'version',
					'old'   => '1.7.1',
					'new'   => '1.7.2',
				],
			],
			$recorder->calls[1][1]['changes']
		);
	}

	/**
	 * "Replace current with uploaded" runs the install, not the update.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uploading_over_an_installed_plugin_is_an_update_from_its_old_version(): void {
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.0' );
		$recorder = $this->recorder();
		$sensor   = new PluginsSensor( $recorder );
		$job      = [
			'type'   => 'plugin',
			'action' => 'install',
		];

		$sensor->on_pre_install( true, $job );
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '4.9' );
		$sensor->on_upgrader( $this->upgrader( 'akismet/akismet.php' ), $job );

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( 5003, $recorder->calls[0][0] );
		$this->assertSame(
			[
				[
					'field' => 'version',
					'old'   => '5.0',
					'new'   => '4.9',
				],
			],
			$recorder->calls[0][1]['changes']
		);
		$this->assertSame( 'install', $recorder->calls[0][1]['metadata']['action'] );
	}

	/**
	 * Plugins are network-wide, but a snapshot belongs to the site it was
	 * taken on, like every snapshot of the request.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_snapshot_taken_on_another_site_is_not_used(): void {
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.0' );
		$recorder = $this->recorder();
		$sensor   = new PluginsSensor( $recorder );
		$job      = [
			'type'   => 'plugin',
			'action' => 'install',
		];

		switch_to_blog( 2 );
		$sensor->on_pre_install( true, $job );
		restore_current_blog();
		$sensor->on_upgrader( $this->upgrader( 'akismet/akismet.php' ), $job );

		$this->assertSame( 5000, $recorder->calls[0][0] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_install_step_hands_back_what_it_was_given(): void {
		$this->wordpress();
		$sensor   = new PluginsSensor( $this->recorder() );
		$response = new \stdClass();

		$this->assertSame( $response, $sensor->on_pre_install( $response, [ 'plugin' => 'hello.php' ] ) );
		$this->assertFalse( $sensor->on_pre_install( false, 'not a job' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_snapshot_that_cannot_be_read_is_reported_and_the_install_goes_on(): void {
		$this->wordpress();
		$this->failure_listener();
		ExtensionState::$reads_throw = true;
		$recorder                    = $this->recorder();

		$result = ( new PluginsSensor( $recorder ) )->on_pre_install(
			true,
			[
				'plugin' => 'hello.php',
				'type'   => 'plugin',
				'action' => 'update',
			]
		);

		$this->assertTrue( $result );
		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 'hellolog_dispatch_failed', WpCalls::$actions[0][0] );
		$this->assertSame( 5003, WpCalls::$actions[0][1][1] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_theme_and_translation_jobs_take_no_plugin_snapshot(): void {
		$this->wordpress();
		$sensor = new PluginsSensor( $this->recorder() );

		$sensor->on_pre_install(
			true,
			[
				'type'   => 'theme',
				'action' => 'install',
			]
		);
		$sensor->on_pre_install( true, [ 'theme' => 'twentytwentyfive' ] );
		$sensor->on_pre_install( true, [ 'language_update_type' => 'plugin' ] );

		$this->assertSame( 0, ExtensionState::$plugin_lists );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_upgrader_without_plugin_info_logs_nothing_and_does_not_fail(): void {
		$this->wordpress();
		$recorder = $this->recorder();

		( new PluginsSensor( $recorder ) )->on_upgrader(
			new \stdClass(),
			[
				'type'   => 'plugin',
				'action' => 'install',
			]
		);

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_other_upgrades_are_not_plugin_events(): void {
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.0' );
		$recorder = $this->recorder();

		( new PluginsSensor( $recorder ) )->on_upgrader(
			$this->upgrader( 'akismet/akismet.php' ),
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
	public function test_an_upgrader_that_fails_to_answer_is_reported_and_never_thrown(): void {
		$this->wordpress();
		$this->failure_listener();
		$recorder = $this->recorder();
		$upgrader = new class() {
			public function plugin_info(): string {
				throw new \RuntimeException( 'unreadable' );
			}
		};

		( new PluginsSensor( $recorder ) )->on_upgrader(
			$upgrader,
			[
				'type'   => 'plugin',
				'action' => 'install',
			]
		);

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 5000, WpCalls::$actions[0][1][1] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deleted_plugin_keeps_its_real_name(): void {
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.3' );
		$recorder = $this->recorder();
		$sensor   = new PluginsSensor( $recorder );

		$sensor->on_delete( 'akismet/akismet.php' );
		$this->remove_plugin( 'akismet/akismet.php' );
		$sensor->on_deleted( 'akismet/akismet.php', true );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 5004, $code );
		$this->assertSame( 'Akismet Anti-spam', $fields['name'] );
		$this->assertSame( PluginsSensor::plugin_target( 'akismet/akismet.php', 'Akismet Anti-spam' ), $fields['target'] );
		$this->assertSame(
			[
				'plugin_file' => 'akismet/akismet.php',
				'version'     => '5.3',
			],
			$fields['metadata']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failed_deletion_logs_nothing(): void {
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.3' );
		$recorder = $this->recorder();
		$sensor   = new PluginsSensor( $recorder );

		$sensor->on_delete( 'akismet/akismet.php' );
		$sensor->on_deleted( 'akismet/akismet.php', false );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deletion_nobody_saw_coming_is_named_by_its_file(): void {
		$this->wordpress();
		$recorder = $this->recorder();

		( new PluginsSensor( $recorder ) )->on_deleted( 'akismet/akismet.php', true );

		$fields = $recorder->calls[0][1];
		$this->assertSame( 'akismet/akismet.php', $fields['name'] );
		$this->assertSame( 'akismet', $fields['target']['id'] );
		$this->assertSame( [ 'plugin_file' => 'akismet/akismet.php' ], $fields['metadata'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_plugin_about_to_be_deleted_that_cannot_be_read_is_reported_and_never_thrown(): void {
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.3' );
		$this->failure_listener();
		ExtensionState::$reads_throw = true;

		( new PluginsSensor( $this->recorder() ) )->on_delete( 'akismet/akismet.php' );

		$this->assertSame( 5004, WpCalls::$actions[0][1][1] );
		$this->assertSame( [ 'plugin_file' => 'akismet/akismet.php' ], WpCalls::$actions[0][1][2] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_activation_and_deactivation_name_the_plugin_as_target(): void {
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.3' );
		$recorder = $this->recorder();
		$sensor   = new PluginsSensor( $recorder );

		$sensor->on_activated( 'akismet/akismet.php', false );
		$sensor->on_deactivated( 'akismet/akismet.php', true );

		$this->assertSame( [ 5001, 5002 ], array_column( $recorder->calls, 0 ) );
		foreach ( $recorder->calls as [ , $fields ] ) {
			$this->assertSame( PluginsSensor::plugin_target( 'akismet/akismet.php', 'Akismet Anti-spam' ), $fields['target'] );
			$this->assertSame( 'akismet/akismet.php', $fields['metadata']['plugin_file'] );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_turning_automatic_updates_on_and_off_logs_one_event_per_plugin(): void {
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.3' );
		$this->put_plugin( 'hello.php', 'Hello Dolly', '1.7.2' );
		$this->put_plugin( 'jetpack/jetpack.php', 'Jetpack', '14.0' );
		$recorder = $this->recorder();

		( new PluginsSensor( $recorder ) )->on_option_updated(
			'auto_update_plugins',
			[ 'akismet/akismet.php', 'jetpack/jetpack.php' ],
			[ 'jetpack/jetpack.php', 'hello.php' ]
		);

		$this->assertSame( [ 5005, 5005 ], array_column( $recorder->calls, 0 ) );
		$enabled  = $recorder->calls[0][1];
		$disabled = $recorder->calls[1][1];
		$this->assertSame( 'enabled', $enabled['state'] );
		$this->assertSame( 'Hello Dolly', $enabled['name'] );
		$this->assertSame( PluginsSensor::plugin_target( 'hello.php', 'Hello Dolly' ), $enabled['target'] );
		$this->assertSame( [ 'plugin_file' => 'hello.php' ], $enabled['metadata'] );
		$this->assertSame( 'disabled', $disabled['state'] );
		$this->assertSame( 'Akismet Anti-spam', $disabled['name'] );
		$this->assertSame( PluginsSensor::plugin_target( 'akismet/akismet.php', 'Akismet Anti-spam' ), $disabled['target'] );
	}

	/**
	 * The first toggle on a site creates the option.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_first_toggle_on_a_site_is_logged(): void {
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.3' );
		$recorder = $this->recorder();

		( new PluginsSensor( $recorder ) )->on_option_added( 'auto_update_plugins', [ 'akismet/akismet.php' ] );

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( 5005, $recorder->calls[0][0] );
		$this->assertSame( 'enabled', $recorder->calls[0][1]['state'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_toggle_on_a_network_is_logged(): void {
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.3' );
		$this->put_plugin( 'hello.php', 'Hello Dolly', '1.7.2' );
		ExtensionState::$multisite = true;
		$recorder                  = $this->recorder();
		$sensor                    = new PluginsSensor( $recorder );

		$sensor->on_site_option_added( 'auto_update_plugins', [ 'hello.php' ], 1 );
		$sensor->on_site_option_updated( 'auto_update_plugins', [ 'hello.php', 'akismet/akismet.php' ], [ 'hello.php' ], 1 );

		$this->assertSame( [ 5005, 5005 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 'hello', $recorder->calls[0][1]['target']['id'] );
		$this->assertSame( 'akismet', $recorder->calls[1][1]['target']['id'] );
		$this->assertSame( [ 'enabled', 'enabled' ], array_column( array_column( $recorder->calls, 1 ), 'state' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_other_options_are_not_automatic_update_toggles(): void {
		$this->put_plugin( 'akismet/akismet.php', 'Akismet Anti-spam', '5.3' );
		$recorder = $this->recorder();
		$sensor   = new PluginsSensor( $recorder );

		$sensor->on_option_updated( 'auto_update_themes', [], [ 'akismet/akismet.php' ] );
		$sensor->on_option_added( 'active_plugins', [ 'akismet/akismet.php' ] );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * Saving the list drops plugins that were deleted since; nobody turned
	 * their automatic updates off.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_plugin_dropped_from_the_list_because_it_is_gone_is_not_logged(): void {
		$this->put_plugin( 'hello.php', 'Hello Dolly', '1.7.2' );
		$recorder = $this->recorder();

		( new PluginsSensor( $recorder ) )->on_option_updated(
			'auto_update_plugins',
			[ 'deleted-long-ago/deleted-long-ago.php' ],
			[ 'hello.php' ]
		);

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( 'hello.php', $recorder->calls[0][1]['metadata']['plugin_file'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_toggle_that_cannot_be_read_is_reported_and_never_thrown(): void {
		$this->wordpress();
		$this->failure_listener();
		ExtensionState::$reads_throw = true;
		$recorder                    = $this->recorder();

		( new PluginsSensor( $recorder ) )->on_option_updated( 'auto_update_plugins', [], [ 'hello.php' ] );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 5005, WpCalls::$actions[0][1][1] );
		$this->assertSame( [ 'option' => 'auto_update_plugins' ], WpCalls::$actions[0][1][2] );
	}

	/**
	 * What reaches the log: the slug target survives the wire limit even for
	 * a plugin file that does not, and the messages read naturally.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_payloads_carry_the_target_the_changes_and_the_state(): void {
		$long = 'woocommerce-pdf-invoices-packing-slips/woocommerce-pdf-invoices-packingslips.php';
		$this->put_plugin( $long, 'PDF Invoices', '3.0' );
		$recorder = $this->recorder();
		$sensor   = new PluginsSensor( $recorder );
		$job      = [
			'plugin' => $long,
			'type'   => 'plugin',
			'action' => 'update',
		];

		$sensor->on_pre_install( true, $job );
		$this->put_plugin( $long, 'PDF Invoices', '3.1' );
		$sensor->on_upgrader( $this->upgrader( false ), $job );
		$sensor->on_option_updated( 'auto_update_plugins', [], [ $long ] );

		$payloads = $this->payloads( $recorder );
		$this->assertCount( 2, $payloads );
		$this->assertSame(
			[
				'type'  => 'plugin',
				'id'    => 'woocommerce-pdf-invoices-packing-slips',
				'label' => 'PDF Invoices',
			],
			$payloads[0]['target']
		);
		$this->assertSame( 'Plugin PDF Invoices updated to 3.1.', $payloads[0]['message'] );
		$this->assertSame(
			[
				[
					'field' => 'version',
					'old'   => '3.0',
					'new'   => '3.1',
				],
			],
			$payloads[0]['changes']
		);
		$this->assertSame( $long, $payloads[0]['metadata']['plugin_file'] );
		$this->assertSame( 'Automatic updates enabled for plugin PDF Invoices.', $payloads[1]['message'] );
		$this->assertSame( 'enabled', $payloads[1]['vars']['state'] );
		$this->assertSame( 'woocommerce-pdf-invoices-packing-slips', $payloads[1]['target']['id'] );
	}

	/**
	 * Load the WordPress stand-ins. Only call this from a test of its own process.
	 */
	private function wordpress(): void {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-extension-functions.php';
	}

	/**
	 * Put a plugin on disk with these headers, or change the ones it has.
	 * Only call this from a test of its own process.
	 *
	 * @param string $file    Plugin file.
	 * @param string $name    Plugin Name header.
	 * @param string $version Version header.
	 */
	private function put_plugin( string $file, string $name, string $version ): void {
		$this->wordpress();

		ExtensionState::$plugins[ $file ] = [
			'Name'    => $name,
			'Version' => $version,
		];

		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		touch( $path );
	}

	/**
	 * Take a plugin off the disk.
	 *
	 * @param string $file Plugin file.
	 */
	private function remove_plugin( string $file ): void {
		unset( ExtensionState::$plugins[ $file ] );
		unlink( WP_PLUGIN_DIR . '/' . $file );
	}

	/**
	 * Remove a directory of this test and everything in it.
	 *
	 * @param string $dir Directory.
	 */
	private function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	/**
	 * An upgrader whose `plugin_info()` answers this.
	 *
	 * @param string|false $info Plugin file of the package it installed, or false.
	 */
	private function upgrader( $info ): object {
		return new class( $info ) {

			/** @param string|false $info */
			public function __construct( private $info ) {
			}

			/** @return string|false */
			public function plugin_info() {
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
