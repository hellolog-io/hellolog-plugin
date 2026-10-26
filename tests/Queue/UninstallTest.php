<?php
/**
 * Tests that uninstalling removes the queue table of every site.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Queue;

use HelloLog\Tests\Queue\Fixtures\FakeWpdb;
use HelloLog\Tests\Queue\Fixtures\NetworkState;
use HelloLog\Uninstall;
use PHPUnit\Framework\TestCase;

/**
 * Each test runs in its own process: it replaces the global `$wpdb` and
 * defines the WordPress network calls.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class UninstallTest extends TestCase {

	private FakeWpdb $wpdb;

	protected function setUp(): void {
		require_once __DIR__ . '/Fixtures/FakeWpdb.php';
		require_once __DIR__ . '/Fixtures/wordpress-queue-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-network-functions.php';

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	/**
	 * The plugin's files are gone for every network, so is every queue. The
	 * tables are named by prefix; no site is switched to.
	 */
	public function test_uninstalling_on_a_network_drops_the_queue_table_of_every_site(): void {
		NetworkState::$sites        = array_fill_keys( range( 1, 150 ), 1 ) + [ 900 => 2 ];
		NetworkState::$current_blog = 2;
		$this->wpdb->prefix         = 'wp_2_';

		Uninstall::uninstall();

		$expected = [];
		foreach ( array_keys( NetworkState::$sites ) as $id ) {
			$expected[] = 'DROP TABLE IF EXISTS ' . ( 1 === $id ? 'wp_' : 'wp_' . $id . '_' ) . 'hellolog_queue';
		}
		$drops = array_values( array_filter( $this->wpdb->statements, static fn( string $q ): bool => str_starts_with( $q, 'DROP TABLE' ) ) );
		$this->assertSame( $expected, $drops );
		$this->assertSame( 0, NetworkState::$switch_count );
		$this->assertSame( 2, NetworkState::$current_blog );
	}

	public function test_uninstalling_a_single_site_drops_its_queue_table_and_options(): void {
		NetworkState::$multisite  = false;
		NetworkState::$options[1] = [
			'hellolog_token'      => 'secret',
			'hellolog_db_version' => '0.5.0',
			'blogname'            => 'Kept',
		];

		Uninstall::uninstall();

		$this->assertSame( 'DROP TABLE IF EXISTS wp_hellolog_queue', $this->wpdb->statements[0] );
		$this->assertSame( [ 'blogname' => 'Kept' ], NetworkState::$options[1] );
	}

	/**
	 * Every option helloLOG keeps on a site goes, the token included, and
	 * its transients; nothing else does. The sites are reached through their
	 * options tables, without switching to them.
	 */
	public function test_uninstalling_on_a_network_removes_the_plugins_options_on_every_site(): void {
		NetworkState::$sites        = [
			1   => 1,
			2   => 1,
			3   => 1,
			900 => 2,
		];
		NetworkState::$current_blog = 2;
		$this->wpdb->prefix         = 'wp_2_';
		foreach ( [ 'wp_options', 'wp_2_options', 'wp_3_options', 'wp_900_options' ] as $table ) {
			$this->wpdb->option_rows[ $table ] = $this->site_options();
		}

		Uninstall::uninstall();

		foreach ( [ 'wp_options', 'wp_2_options', 'wp_3_options', 'wp_900_options' ] as $table ) {
			$this->assertSame( $this->kept_options(), $this->wpdb->option_rows[ $table ], $table );
		}
		$this->assertSame( 0, NetworkState::$switch_count );
	}

	public function test_uninstalling_a_single_site_removes_every_option_of_the_plugin(): void {
		NetworkState::$multisite              = false;
		$this->wpdb->option_rows['wp_options'] = $this->site_options();

		Uninstall::uninstall();

		$this->assertSame( $this->kept_options(), $this->wpdb->option_rows['wp_options'] );
	}

	/**
	 * A site whose options table is missing (a broken or half-deleted site)
	 * is skipped instead of failing the query.
	 */
	public function test_a_site_without_an_options_table_is_skipped(): void {
		NetworkState::$sites = [
			1 => 1,
			2 => 1,
			3 => 1,
		];
		$this->wpdb->option_rows['wp_options']   = $this->site_options();
		$this->wpdb->option_rows['wp_2_options'] = $this->site_options();

		Uninstall::uninstall();

		$this->assertSame( [], $this->wpdb->errors );
		$this->assertSame( $this->kept_options(), $this->wpdb->option_rows['wp_2_options'] );
		$this->assertSame( [], preg_grep( '/^DELETE FROM wp_3_options/', $this->wpdb->statements ) );
	}

	/**
	 * The update check caches the latest release for the whole network.
	 */
	public function test_uninstalling_removes_the_release_check_cache(): void {
		Uninstall::uninstall();

		$this->assertSame( [ 'hellolog_github_release' ], NetworkState::$deleted_site_transients );
	}

	/**
	 * The options are removed with SQL, past the object cache: it is emptied
	 * last, so no removed value is served from it.
	 */
	public function test_uninstalling_empties_the_object_cache_last(): void {
		NetworkState::$sites                     = [
			1 => 1,
			2 => 1,
		];
		$this->wpdb->option_rows['wp_options']   = $this->site_options();
		$this->wpdb->option_rows['wp_2_options'] = $this->site_options();

		Uninstall::uninstall();

		$this->assertSame( [ count( $this->wpdb->statements ) ], NetworkState::$cache_flushes );
	}

	/**
	 * Options of a site: helloLOG's own (settings, state, transients) and
	 * others that only look alike.
	 *
	 * @return array<string, mixed>
	 */
	private function site_options(): array {
		return [
			'hellolog_token'                                => 'goal_live_example',
			'hellolog_token_verified'                       => '1',
			'hellolog_sensor_filters'                       => [],
			'hellolog_flush_sched_ver'                      => '3',
			'_transient_hellolog_app_login_abc'             => '1',
			'_transient_timeout_hellolog_app_login_abc'     => '1761500000',
			'_site_transient_hellolog_github_release'       => '{}',
			'_site_transient_timeout_hellolog_github_release' => '1761500000',
		] + $this->kept_options();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function kept_options(): array {
		return [
			'blogname'                 => 'Kept',
			'helloworld_setting'       => 'kept',
			'hellologger_token'        => 'kept',
			'_transient_other_plugin'  => 'kept',
			'my_hellolog_token'        => 'kept',
		];
	}
}
