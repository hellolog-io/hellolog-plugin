<?php
/**
 * Tests for how helloLOG's own critical events leave the site: the API key
 * replaced, cleared or no longer verified, and its own deactivation.
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
use HelloLog\Transport\FinalDelivery;
use PHPUnit\Framework\TestCase;

/**
 * Whoever changes the API key could otherwise silence the log: a new key
 * stops the sensors until it is verified, and the events queued until then
 * would be sent under the new key, or never. So these events are sent at
 * once, under the key the request started with, the one the site's account
 * verified. Each test runs in its own process on a booted plugin.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SelfAuditDeliveryTest extends TestCase {

	use BootsPlugin;

	protected function tearDown(): void {
		$this->remove_plugins_directory();
	}

	public function test_saving_the_key_it_already_has_keeps_it_verified(): void {
		$this->load_wordpress();
		$this->boot_with( self::key() );

		( new SettingsController() )->save_settings( new \WP_REST_Request( [ 'token' => self::key() ] ) );

		$this->assertSame( '1', Wp::$options[1][ Options::KEY_TOKEN_VERIFIED ] );
		$this->assertSame( [], $this->sent() );
	}

	public function test_setting_the_key_it_already_has_from_the_command_line_keeps_it_verified(): void {
		$this->load_wordpress();
		require_once __DIR__ . '/../Cli/Fixtures/wp-cli.php';
		$this->boot_with( self::key() );

		( new Command() )->set_token( [ self::key() ] );

		$this->assertSame( '1', Wp::$options[1][ Options::KEY_TOKEN_VERIFIED ] );
		$this->assertSame( [], $this->sent() );
		$this->assertStringContainsString( 'it is verified', \WP_CLI::$successes[0] );
	}

	/**
	 * The command says what is true: the key it already has may not be
	 * verified at all.
	 */
	public function test_setting_an_unverified_key_again_says_it_is_not_verified(): void {
		$this->load_wordpress();
		require_once __DIR__ . '/../Cli/Fixtures/wp-cli.php';
		$this->boot_with( self::key(), false );

		( new Command() )->set_token( [ self::key() ] );

		$this->assertSame( '0', Wp::$options[1][ Options::KEY_TOKEN_VERIFIED ] );
		$this->assertStringContainsString( 'it is not verified', \WP_CLI::$successes[0] );
		$this->assertStringNotContainsString( 'it is verified', \WP_CLI::$successes[0] );
	}

	public function test_a_replaced_key_is_reported_under_the_key_it_replaced(): void {
		$this->load_wordpress();
		$this->boot_with( self::key() );

		( new SettingsController() )->save_settings( new \WP_REST_Request( [ 'token' => self::key( 'wxyz9876', 'b' ) ] ) );

		$this->assertSame( [ 6600, 6600 ], $this->codes_sent_with( self::key() ), 'The key change, then the key no longer verified.' );
		$this->assertSame( [], $this->codes_sent_with( self::key( 'wxyz9876', 'b' ) ) );
		$changes = array_merge( ...array_column( array_merge( $this->sent()[0]['events'], $this->sent()[1]['events'] ), 'changes' ) );
		$this->assertContains(
			[
				'field' => 'token',
				'old'   => '[redacted]',
				'new'   => '[redacted]',
			],
			$changes
		);
		$this->assertContains(
			[
				'field' => 'key_verified',
				'old'   => 'yes',
				'new'   => 'no',
			],
			$changes
		);
		$this->assertStringNotContainsString( self::key( 'wxyz9876', 'b' ), (string) json_encode( $this->sent() ) );
	}

	public function test_a_key_replaced_from_the_command_line_is_reported_under_the_key_it_replaced(): void {
		$this->load_wordpress();
		require_once __DIR__ . '/../Cli/Fixtures/wp-cli.php';
		$this->boot_with( self::key() );

		( new Command() )->set_token( [ self::key( 'wxyz9876', 'b' ) ] );

		$this->assertSame( [ 6600, 6600 ], $this->codes_sent_with( self::key() ) );
		$this->assertCount( 2, $this->sent() );
	}

	public function test_a_cleared_key_is_reported_under_the_key_that_was_cleared(): void {
		$this->load_wordpress();
		$this->boot_with( self::key() );

		( new SettingsController() )->save_settings( new \WP_REST_Request( [ 'clearToken' => true ] ) );

		$this->assertSame( [ 6600, 6600 ], $this->codes_sent_with( self::key() ) );
		$this->assertSame(
			[
				'field' => 'token',
				'old'   => '[redacted]',
				'new'   => 'removed',
			],
			$this->sent()[0]['events'][0]['changes'][0]
		);
		$this->assertArrayNotHasKey( Options::KEY_TOKEN, Wp::$options[1] );
	}

	/**
	 * A failed connection test, a script, anything but the daily recheck:
	 * the sensors stop from the next request on, so it is told now.
	 */
	public function test_a_key_no_longer_verified_is_reported_at_once(): void {
		$this->load_wordpress();
		$this->boot_with( self::key() );

		( new Options() )->mark_active( false );

		$this->assertSame( [ 6600 ], $this->codes_sent_with( self::key() ) );
		$this->assertSame(
			[
				[
					'field' => 'key_verified',
					'old'   => 'yes',
					'new'   => 'no',
				],
			],
			$this->sent()[0]['events'][0]['changes']
		);
		$this->assertSame( 'hellolog_token_verified', $this->sent()[0]['events'][0]['target']['id'] );
	}

	/**
	 * The daily recheck asks the backend itself; its answer is not a change
	 * somebody made.
	 */
	public function test_the_daily_recheck_turning_the_key_off_is_not_reported(): void {
		$this->load_wordpress();
		$this->boot_with( self::key() );

		// Inside the recheck's action, as when its job runs.
		Wp::$doing[] = 'hellolog_verify_token';
		( new Options() )->mark_active( false );
		array_pop( Wp::$doing );

		$this->assertSame( [], $this->sent() );
	}

	/**
	 * With the sensors running (the request started verified), a flag that
	 * is off being turned on is not reported, while turning it off again is.
	 */
	public function test_turning_the_key_on_is_not_reported(): void {
		$this->load_wordpress();
		$plugin = $this->boot_with( self::key() );
		$this->assertArrayHasKey( 'core-self-audit', $plugin->sensors()->sensors() );
		Wp::$options[1][ Options::KEY_TOKEN_VERIFIED ] = '0';

		( new Options() )->mark_active( true );
		$this->assertSame( [], $this->sent() );

		( new Options() )->mark_active( false );
		$this->assertSame( [ 6600 ], $this->codes_sent_with( self::key() ) );
	}

	/**
	 * Deleting the flag stops the sensors as turning it off does.
	 */
	public function test_a_deleted_verified_flag_is_reported_at_once(): void {
		$this->load_wordpress();
		$this->boot_with( self::key() );

		delete_option( Options::KEY_TOKEN_VERIFIED );

		$this->assertSame( [ 6600 ], $this->codes_sent_with( self::key() ) );
		$this->assertSame(
			[
				[
					'field' => 'key_verified',
					'old'   => 'yes',
					'new'   => 'no',
				],
			],
			$this->sent()[0]['events'][0]['changes']
		);
	}

	public function test_deleting_a_flag_that_was_already_off_is_not_reported(): void {
		$this->load_wordpress();
		$this->boot_with( self::key() );
		Wp::$options[1][ Options::KEY_TOKEN_VERIFIED ] = '0';

		delete_option( Options::KEY_TOKEN_VERIFIED );

		$this->assertSame( [], $this->sent() );
	}

	/**
	 * A key that is stored but not verified (a re-saved key, a failed test)
	 * still says the plugin was deactivated.
	 */
	public function test_deactivation_is_sent_with_a_key_that_is_not_verified(): void {
		$this->load_wordpress();
		$this->boot_with( self::key(), false );

		Plugin::instance()->deliver_own_deactivation( false );

		$this->assertSame( [ 5002 ], $this->codes_sent_with( self::key() ) );
	}

	public function test_without_a_key_the_deactivation_is_not_sent(): void {
		$this->load_wordpress();
		$this->boot_with( '', false );

		Plugin::instance()->deliver_own_deactivation( false );

		$this->assertSame( [], $this->sent() );
		$this->assertSame( [], $this->wpdb->rows['wp_hellolog_queue'] ?? [] );
	}

	public function test_before_boot_the_deactivation_does_nothing(): void {
		$this->load_wordpress();
		Plugin::instance()->deliver_own_deactivation( false );

		$this->assertSame( [], $this->sent() );
		$this->assertSame( [], $this->wpdb->inserts );
	}

	/**
	 * The plugin is named by the directory it is installed in, which need
	 * not be `hellolog`, and the deactivation leaves under the key the
	 * request started with, waiting a moment for the lock, and once more at
	 * the end of the request.
	 */
	public function test_the_deactivation_is_wired_to_the_plugin_file_the_key_and_the_lock(): void {
		$this->load_wordpress( 'hellolog-main' );
		$this->boot_with( self::key() );
		Wp::$options[1][ Options::KEY_TOKEN ] = self::key( 'wxyz9876', 'b' );

		Plugin::instance()->deliver_own_deactivation( true );

		$this->assertSame( [ 5002 ], $this->codes_sent_with( self::key() ) );
		$event = $this->sent()[0]['events'][0];
		$this->assertSame( 'hellolog-main', $event['target']['id'] );
		$this->assertSame( 'helloLOG', $event['target']['label'] );
		$this->assertSame(
			[
				'plugin_file'  => 'hellolog-main/hellolog.php',
				'network_wide' => true,
			],
			$event['metadata']
		);
		$this->assertContains( "SELECT GET_LOCK('wp_hellolog_flush', 1)", $this->wpdb->statements );
		$ends = array_values(
			array_filter(
				Wp::$hooks['shutdown'],
				static fn( array $hook ): bool => is_array( $hook['callback'] ) && $hook['callback'][0] instanceof FinalDelivery
			)
		);
		$this->assertCount( 1, $ends );
		$this->assertSame( PHP_INT_MAX, $ends[0]['priority'] );
	}
}
