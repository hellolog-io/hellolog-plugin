<?php
/**
 * Tests for helloLOG's own deactivation across a network.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Plugin;

use HelloLog\Plugin;
use HelloLog\Settings\Options;
use HelloLog\Tests\Support\BootsPlugin;
use HelloLog\Tests\Support\Wp;
use HelloLog\Transport\NetworkDelivery;
use PHPUnit\Framework\TestCase;

/**
 * Deactivated for the whole network, helloLOG stops on every site at once:
 * every active site that has a key says so, in its own queue and under its
 * own key, within a time budget. Each test runs in its own process on a
 * booted plugin.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class NetworkDeactivationTest extends TestCase {

	use BootsPlugin;

	protected function setUp(): void {
		$this->load_wordpress();
		Wp::$multisite = true;
		Wp::$sites     = [ 1, 2, 3, 4 ];
		Wp::$options   = [
			2 => [ Options::KEY_TOKEN => self::key( 'site0002', 'b' ) ],
			3 => [ Options::KEY_TOKEN => '' ],
			4 => [ Options::KEY_TOKEN => self::key( 'site0004', 'd' ) ],
		];
	}

	protected function tearDown(): void {
		$this->remove_plugins_directory();
	}

	public function test_every_site_with_a_key_sends_its_own_deactivation(): void {
		$this->boot_with( self::key() );

		Plugin::instance()->deliver_own_deactivation( true );

		$this->assertSame( [ 5002 ], $this->codes_sent_with( self::key() ) );
		$this->assertSame( [ 5002 ], $this->codes_sent_with( self::key( 'site0002', 'b' ) ) );
		$this->assertSame( [ 5002 ], $this->codes_sent_with( self::key( 'site0004', 'd' ) ) );
		$this->assertCount( 3, $this->sent(), 'Nothing for the site without a key.' );
		foreach ( $this->sent() as $request ) {
			$this->assertTrue( $request['events'][0]['metadata']['network_wide'] );
		}
		$this->assertSame( 1, Wp::$blog, 'The request is back on its own site.' );
		$this->assertSame( [], Wp::$stack );
	}

	public function test_deactivating_on_one_site_sends_for_that_site_only(): void {
		$this->boot_with( self::key() );

		Plugin::instance()->deliver_own_deactivation( false );

		$this->assertSame( [ 5002 ], $this->codes_sent_with( self::key() ) );
		$this->assertCount( 1, $this->sent() );
	}

	/**
	 * The current site without a key still lets the other sites tell theirs.
	 */
	public function test_other_sites_send_even_when_this_one_has_no_key(): void {
		$this->boot_with( '', false );

		Plugin::instance()->deliver_own_deactivation( true );

		$this->assertSame( [ 5002 ], $this->codes_sent_with( self::key( 'site0002', 'b' ) ) );
		$this->assertSame( [ 5002 ], $this->codes_sent_with( self::key( 'site0004', 'd' ) ) );
		$this->assertCount( 2, $this->sent() );
	}

	/**
	 * Like network activation, the loop stops once its time is spent: the
	 * sites left wait for their own reactivation, and nothing fails.
	 */
	public function test_the_sites_stop_when_the_time_is_up(): void {
		$this->boot_with( self::key() );
		$times = [ 10.0, 10.0, 16.0 ];
		$clock = static function () use ( &$times ): float {
			return (float) array_shift( $times );
		};

		( new NetworkDelivery( Plugin::instance()->catalog(), [ Plugin::instance(), 'rules' ], 5.0, $clock ) )->deliver( 5002, [ 'name' => 'helloLOG' ] );

		$this->assertSame( [ 5002 ], $this->codes_sent_with( self::key( 'site0002', 'b' ) ) );
		$this->assertSame( [], $this->codes_sent_with( self::key( 'site0004', 'd' ) ) );
		$this->assertSame( 5.0, NetworkDelivery::BUDGET );
		$this->assertSame( 1, Wp::$blog );
	}
}
