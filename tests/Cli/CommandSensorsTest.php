<?php
/**
 * Tests for the sensor switches of {@see HelloLog\Cli\Command}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Cli;

use HelloLog\Cli\Command;
use PHPUnit\Framework\TestCase;

/**
 * The sensor that logs changes to helloLOG itself can not be turned off, so
 * the command refuses instead of saying it did. Each test runs in its own
 * process: it defines `WP_CLI` and the option calls.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CommandSensorsTest extends TestCase {

	protected function setUp(): void {
		require_once __DIR__ . '/Fixtures/wp-cli.php';
	}

	public function test_the_self_audit_can_not_be_turned_off(): void {
		$error = '';
		try {
			( new Command() )->disable_sensor( [ 'core-self-audit' ] );
		} catch ( \LogicException $e ) {
			$error = $e->getMessage();
		}

		$this->assertStringContainsString( 'can not be turned off', $error );
		$this->assertSame( [], $GLOBALS['hellolog_test_options'] );
	}

	public function test_any_other_sensor_is_turned_off(): void {
		( new Command() )->disable_sensor( [ 'core-media' ] );

		$this->assertSame( [ 'core-media' => true ], $GLOBALS['hellolog_test_options']['hellolog_sensor_filters'] );
		$this->assertSame( [ 'Sensor core-media disabled.' ], \WP_CLI::$successes );
	}
}
