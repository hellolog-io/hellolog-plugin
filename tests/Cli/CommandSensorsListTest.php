<?php
/**
 * Tests for `wp hellolog sensors` of {@see HelloLog\Cli\Command}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Cli;

use HelloLog\Cli\Command;
use HelloLog\Settings\Options;
use HelloLog\Tests\Support\BootsPlugin;
use PHPUnit\Framework\TestCase;

/**
 * No sensor is set up until the site key is verified, but the switches work
 * and the listing must say why nothing runs. Each test boots the plugin as
 * it wants it, in its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class CommandSensorsListTest extends TestCase {

	use BootsPlugin;

	protected function setUp(): void {
		$this->load_wordpress();
		require_once __DIR__ . '/Fixtures/wp-cli.php';
	}

	protected function tearDown(): void {
		$this->remove_plugins_directory();
	}

	/**
	 * The rows of the table the command printed, by sensor key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function printed_rows(): array {
		$this->assertCount( 1, \WP_CLI::$tables );

		$rows = [];
		foreach ( \WP_CLI::$tables[0] as $row ) {
			$rows[ (string) $row['key'] ] = $row;
		}

		return $rows;
	}

	public function test_a_stored_key_that_is_not_verified_says_when_sensors_start(): void {
		$this->boot_with( self::key(), false );

		( new Command() )->sensors();

		$this->assertCount( 1, \WP_CLI::$warnings );
		$this->assertStringContainsString( 'once the site key is verified', \WP_CLI::$warnings[0] );
		$this->assertStringContainsString( '`wp hellolog test`', \WP_CLI::$warnings[0] );
		$this->assertStringNotContainsString( 'set-token', \WP_CLI::$warnings[0] );
		$this->assertStringNotContainsString( 'No sensors registered', \WP_CLI::$warnings[0] );
	}

	public function test_without_a_key_the_message_names_the_command_that_sets_it(): void {
		$this->boot_with( '', false );

		( new Command() )->sensors();

		$this->assertCount( 1, \WP_CLI::$warnings );
		$this->assertStringContainsString( 'once the site key is verified', \WP_CLI::$warnings[0] );
		$this->assertStringContainsString( '`wp hellolog set-token <token>`', \WP_CLI::$warnings[0] );
		$this->assertStringContainsString( '`wp hellolog test`', \WP_CLI::$warnings[0] );
	}

	public function test_the_switchable_sensors_are_listed_before_the_key_is_verified(): void {
		$plugin = $this->boot_with( self::key(), false );
		$this->assertSame( [], $plugin->sensors()->sensors(), 'Nothing is registered before the key is verified.' );

		( new Command() )->sensors();

		$this->assertSame( $plugin->sensors()->switchable(), array_keys( $this->printed_rows() ) );
		$this->assertNotContains( 'core-self-audit', array_keys( $this->printed_rows() ) );
	}

	public function test_the_listing_before_verification_shows_the_switches_as_stored(): void {
		$this->boot_with(
			self::key(),
			false,
			[
				Options::KEY_SENSOR_FILTERS => [
					'core-media'        => true,
					'core-failed-login' => false,
				],
			]
		);

		( new Command() )->sensors();

		$rows = $this->printed_rows();
		$this->assertSame( 'NO', $rows['core-media']['enabled'] );
		$this->assertSame( 'yes', $rows['core-failed-login']['enabled'], 'Turned on although it is off by default.' );
		$this->assertSame( 'NO', $rows['core-request']['enabled'], 'Off by default, and the settings do not mention it.' );
		$this->assertSame( 'yes', $rows['core-content']['enabled'] );
	}

	public function test_a_listed_sensor_before_verification_can_be_switched(): void {
		$this->boot_with( self::key(), false );

		( new Command() )->sensors();
		$listed = array_keys( $this->printed_rows() );
		( new Command() )->disable_sensor( [ 'core-media' ] );

		$this->assertContains( 'core-media', $listed );
		$this->assertSame( [ 'Sensor core-media disabled.' ], \WP_CLI::$successes );
	}

	public function test_a_verified_key_lists_the_running_sensors_without_a_warning(): void {
		$plugin = $this->boot_with( self::key() );

		( new Command() )->sensors();

		$this->assertSame( [], \WP_CLI::$warnings );
		$rows = $this->printed_rows();
		$this->assertSame( array_keys( $plugin->sensors()->toggleable() ), array_keys( $rows ) );
		$this->assertSame( 'LoginLogoutSensor', $rows['core-auth']['class'] );
		$this->assertSame( 'NO', $rows['core-request']['enabled'] );
	}
}
