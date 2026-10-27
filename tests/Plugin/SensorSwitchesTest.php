<?php
/**
 * Tests for the sensor switches of the settings screen and the command line
 * on a booted plugin.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Plugin;

use HelloLog\Cli\Command;
use HelloLog\Plugin;
use HelloLog\Rest\SettingsController;
use HelloLog\Settings\Options;
use HelloLog\Tests\Support\BootsPlugin;
use HelloLog\Tests\Support\Wp;
use PHPUnit\Framework\TestCase;

/**
 * Only the sensors the plugin has can be switched: made-up keys saved with
 * real ones could otherwise push the real changes out of the settings
 * event, which keeps at most 50. Each test runs in its own process on a
 * booted plugin.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SensorSwitchesTest extends TestCase {

	use BootsPlugin;

	protected function setUp(): void {
		$this->load_wordpress();
		require_once __DIR__ . '/../Cli/Fixtures/wp-cli.php';
	}

	protected function tearDown(): void {
		$this->remove_plugins_directory();
	}

	public function test_made_up_keys_saved_with_real_ones_are_dropped_and_the_real_changes_logged(): void {
		$this->boot_with( self::key() );
		$filters = [];
		for ( $i = 1; $i <= 60; $i++ ) {
			$filters[ 'junk-' . $i ] = true;
		}
		$filters['core-auth']          = true;
		$filters['core-app-passwords'] = true;

		( new SettingsController() )->save_settings( new \WP_REST_Request( [ 'sensorFilters' => $filters ] ) );
		Plugin::instance()->dispatcher()->flush();

		$stored = Wp::$options[1][ Options::KEY_SENSOR_FILTERS ];
		$this->assertSame(
			[
				'core-auth'          => true,
				'core-app-passwords' => true,
			],
			$stored
		);
		$changes = $this->queued_changes( 6600 );
		$this->assertContains( 'sensor:core-auth', $changes );
		$this->assertContains( 'sensor:core-app-passwords', $changes );
		$this->assertNotContains( 'sensor:junk-1', $changes );
	}

	/**
	 * Written around the settings screen (a script, a database tool), the
	 * made-up keys are stored but still never reported.
	 */
	public function test_made_up_keys_written_directly_are_not_reported(): void {
		$this->boot_with( self::key() );
		$filters = Wp::$options[1][ Options::KEY_SENSOR_FILTERS ];
		for ( $i = 1; $i <= 60; $i++ ) {
			$filters[ 'junk-' . $i ] = true;
		}
		$filters['core-auth'] = true;

		update_option( Options::KEY_SENSOR_FILTERS, $filters );
		Plugin::instance()->dispatcher()->flush();

		$this->assertSame( [ 'sensor:core-auth' ], $this->queued_changes( 6600 ) );
	}

	/**
	 * Without a verified key no sensor is set up, so there is nothing to
	 * switch: the stored switches are left as they are.
	 */
	public function test_switches_are_left_alone_while_no_sensor_is_set_up(): void {
		$this->boot_with( self::key(), false, [ Options::KEY_SENSOR_FILTERS => [ 'core-media' => true ] ] );

		( new SettingsController() )->save_settings( new \WP_REST_Request( [ 'sensorFilters' => [ 'core-auth' => true ] ] ) );

		$this->assertSame( [ 'core-media' => true ], Wp::$options[1][ Options::KEY_SENSOR_FILTERS ] );
	}

	public function test_the_command_line_refuses_a_sensor_the_plugin_does_not_have(): void {
		$this->boot_with( self::key() );

		$error = '';
		try {
			( new Command() )->disable_sensor( [ 'junk-1' ] );
		} catch ( \LogicException $e ) {
			$error = $e->getMessage();
		}

		$this->assertStringContainsString( 'Unknown sensor junk-1', $error );
		$this->assertArrayNotHasKey( 'junk-1', Wp::$options[1][ Options::KEY_SENSOR_FILTERS ] );
	}

	/**
	 * The list shows what runs in this request: the self-audit, which can
	 * not be switched, is not listed, and a noisy sensor the settings do not
	 * mention reads as off.
	 */
	public function test_the_command_line_list_shows_the_switchable_sensors_as_they_run(): void {
		$this->boot_with( self::key(), true, [ Options::KEY_SENSOR_FILTERS => [ 'core-media' => true ] ] );

		( new Command() )->sensors();

		$rows = array_column( \WP_CLI::$tables[0], 'enabled', 'key' );
		$this->assertArrayNotHasKey( 'core-self-audit', $rows );
		$this->assertSame( 'NO', $rows['core-media'] );
		$this->assertSame( 'NO', $rows['core-request'] );
		$this->assertSame( 'yes', $rows['core-auth'] );
	}

	/**
	 * The fields changed by the queued events of a code.
	 *
	 * @param int $code Event code.
	 * @return array<int, string>
	 */
	private function queued_changes( int $code ): array {
		$fields = [];
		foreach ( $this->wpdb->rows['wp_hellolog_queue'] ?? [] as $payload ) {
			$event = json_decode( $payload, true );
			if ( $code === $event['code'] ) {
				$fields = array_merge( $fields, array_column( $event['changes'] ?? [], 'field' ) );
			}
		}

		return $fields;
	}
}
