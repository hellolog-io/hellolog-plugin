<?php
/**
 * Tests for {@see HelloLog\Sensors\Core\SystemSensor}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Events\EventDispatcher;
use HelloLog\Sensors\Core\SystemSensor;
use HelloLog\Tests\Sensors\Core\Fixtures\ExtensionState;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use PHPUnit\Framework\TestCase;

/**
 * A core update says which version it came from. The update loads the new
 * version before it fires its hook, so the old one is the version the
 * request started with. The sensor calls WordPress, so every test runs in its
 * own process with the stand-ins of `Fixtures/wordpress-extension-functions.php`.
 */
final class SystemSensorTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_core_update_reports_the_old_and_the_new_version(): void {
		$this->wordpress();
		ExtensionState::$wp_version = '6.8.3';
		$recorder                   = $this->recorder();
		$sensor                     = new SystemSensor( $recorder );

		$sensor->boot();
		ExtensionState::$wp_version = '6.9';
		$sensor->on_core_updated( '6.9' );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 6100, $code );
		$this->assertSame( '6.9', $fields['version'] );
		$this->assertSame(
			[
				[
					'field' => 'version',
					'old'   => '6.8.3',
					'new'   => '6.9',
				],
			],
			$fields['changes']
		);
		$this->assertSame( [ 'wp_version' => '6.9' ], $fields['metadata'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_core_update_from_an_unknown_version_has_no_changes(): void {
		$this->wordpress();
		$recorder = $this->recorder();
		$sensor   = new SystemSensor( $recorder );

		$sensor->boot();
		$sensor->on_core_updated( '6.9' );

		$this->assertSame( 6100, $recorder->calls[0][0] );
		$this->assertArrayNotHasKey( 'changes', $recorder->calls[0][1] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reinstalling_the_same_version_has_no_changes(): void {
		$this->wordpress();
		ExtensionState::$wp_version = '6.9';
		$recorder                   = $this->recorder();
		$sensor                     = new SystemSensor( $recorder );

		$sensor->boot();
		$sensor->on_core_updated( '6.9' );

		$this->assertArrayNotHasKey( 'changes', $recorder->calls[0][1] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_hook_takes_the_new_version(): void {
		$this->wordpress();

		( new SystemSensor( $this->recorder() ) )->boot();

		$this->assertSame( 1, WpState::$actions['_core_updated_successfully']['accepted_args'] );
	}

	/**
	 * Load the WordPress stand-ins. Only call this from a test of its own process.
	 */
	private function wordpress(): void {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-extension-functions.php';
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
}
