<?php
/**
 * Tests for the sensor switches of {@see HelloLog\Cli\Command}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Cli;

use HelloLog\Cli\Command;
use HelloLog\Settings\Options;
use HelloLog\Tests\Support\BootsPlugin;
use HelloLog\Tests\Support\Wp;
use PHPUnit\Framework\TestCase;

/**
 * The sensor that logs changes to helloLOG itself can not be turned off, so
 * the command refuses instead of saying it did. Each test runs in its own
 * process on a booted plugin.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CommandSensorsTest extends TestCase {

	use BootsPlugin;

	protected function setUp(): void {
		$this->load_wordpress();
		require_once __DIR__ . '/Fixtures/wp-cli.php';
		$this->boot_with( self::key() );
	}

	protected function tearDown(): void {
		$this->remove_plugins_directory();
	}

	public function test_the_self_audit_can_not_be_turned_off(): void {
		$error = '';
		try {
			( new Command() )->disable_sensor( [ 'core-self-audit' ] );
		} catch ( \LogicException $e ) {
			$error = $e->getMessage();
		}

		$this->assertStringContainsString( 'can not be turned off', $error );
		$this->assertArrayNotHasKey( 'core-self-audit', Wp::$options[1][ Options::KEY_SENSOR_FILTERS ] );
	}

	public function test_any_other_sensor_is_turned_off(): void {
		( new Command() )->disable_sensor( [ 'core-media' ] );

		$this->assertTrue( Wp::$options[1][ Options::KEY_SENSOR_FILTERS ]['core-media'] );
		$this->assertSame( [ 'Sensor core-media disabled.' ], \WP_CLI::$successes );
	}
}
