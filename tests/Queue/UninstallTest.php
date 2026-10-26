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
		$this->assertSame( $expected, $this->wpdb->statements );
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

		$this->assertSame( [ 'DROP TABLE IF EXISTS wp_hellolog_queue' ], $this->wpdb->statements );
		$this->assertSame( [ 'blogname' => 'Kept' ], NetworkState::$options[1] );
	}
}
