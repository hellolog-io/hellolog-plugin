<?php
/**
 * Tests that every site of a network gets its queue table.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Queue;

use HelloLog\Activator;
use HelloLog\Tests\Queue\Fixtures\FakeWpdb;
use HelloLog\Tests\Queue\Fixtures\NetworkState;
use PHPUnit\Framework\TestCase;

/**
 * Each test runs in its own process: it replaces the global `$wpdb` and
 * defines the WordPress network calls.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ActivatorTest extends TestCase {

	private const PLUGIN = 'hellolog/hellolog.php';

	private FakeWpdb $wpdb;

	protected function setUp(): void {
		require_once __DIR__ . '/Fixtures/FakeWpdb.php';
		require_once __DIR__ . '/Fixtures/wordpress-queue-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-network-functions.php';
		defined( 'HELLOLOG_FILE' ) || define( 'HELLOLOG_FILE', '/tmp/wordpress/wp-content/plugins/hellolog/hellolog.php' );

		$this->wpdb         = new FakeWpdb();
		$this->wpdb->tables = [];
		$GLOBALS['wpdb']    = $this->wpdb;

		// More sites than one page of get_sites(), and one on another network.
		NetworkState::$sites = array_fill_keys( range( 1, 150 ), 1 ) + [ 900 => 2 ];
	}

	public function test_network_activation_creates_the_queue_table_on_every_site_of_the_network(): void {
		Activator::activate( true );

		$expected = array_map( static fn( int $id ): string => ( 1 === $id ? 'wp_' : 'wp_' . $id . '_' ) . 'hellolog_queue', range( 1, 150 ) );
		$this->assertSame( $expected, $this->wpdb->tables );
		$this->assertSame( 1, NetworkState::$current_blog, 'The request is back on its own site.' );
		$this->assertSame( 'wp_', $this->wpdb->prefix );
		$this->assertSame( [], NetworkState::$switched );
	}

	public function test_network_activation_sets_up_each_site_like_a_single_activation(): void {
		Activator::activate( true );

		foreach ( [ 1, 2, 150 ] as $blog_id ) {
			$this->assertSame( HELLOLOG_VERSION, NetworkState::$options[ $blog_id ]['hellolog_db_version'] ?? null, (string) $blog_id );
			$this->assertArrayHasKey( 'hellolog_sensor_filters', NetworkState::$options[ $blog_id ] );
		}
	}

	public function test_activation_on_one_site_creates_only_its_table(): void {
		NetworkState::$current_blog = 2;
		$this->wpdb->prefix         = 'wp_2_';

		Activator::activate( false );
		Activator::activate();

		$this->assertSame( [ 'wp_2_hellolog_queue' ], $this->wpdb->tables );
	}

	public function test_a_single_site_install_creates_its_table(): void {
		NetworkState::$multisite = false;

		Activator::activate( true );

		$this->assertSame( [ 'wp_hellolog_queue' ], $this->wpdb->tables );
	}

	public function test_a_site_added_while_the_plugin_is_network_active_gets_its_table(): void {
		NetworkState::$network_options[1]['active_sitewide_plugins'] = [ self::PLUGIN => 1761480000 ];

		Activator::initialize_site( new \WP_Site( 151, 1 ) );

		$this->assertSame( [ 'wp_151_hellolog_queue' ], $this->wpdb->tables );
		$this->assertSame( HELLOLOG_VERSION, NetworkState::$options[151]['hellolog_db_version'] ?? null );
		$this->assertSame( 1, NetworkState::$current_blog );
		$this->assertSame( 'wp_', $this->wpdb->prefix );
	}

	public function test_a_site_added_while_the_plugin_is_not_network_active_gets_nothing(): void {
		NetworkState::$network_options[1]['active_sitewide_plugins'] = [ 'another/plugin.php' => 1761480000 ];

		Activator::initialize_site( new \WP_Site( 151, 1 ) );

		$this->assertSame( [], $this->wpdb->tables );
		$this->assertSame( [], NetworkState::$options );
	}

	/**
	 * Network activation is per network: a site follows its own.
	 */
	public function test_a_site_added_to_another_network_follows_that_network(): void {
		NetworkState::$network_options[2]['active_sitewide_plugins'] = [ self::PLUGIN => 1761480000 ];

		Activator::initialize_site( new \WP_Site( 151, 1 ) );
		Activator::initialize_site( new \WP_Site( 901, 2 ) );

		$this->assertSame( [ 'wp_901_hellolog_queue' ], $this->wpdb->tables );
	}
}
