<?php
/**
 * Tests for {@see HelloLog\Sensors\Core\FailedLoginSensor}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Events\EventDispatcher;
use HelloLog\Sensors\Core\FailedLoginSensor;
use HelloLog\Tests\Sensors\Core\Fixtures\BuildingRecorder;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use PHPUnit\Framework\TestCase;

/**
 * A failed login is about the account somebody tried to get into: that
 * account is the target. A name that is no account has no object to name.
 * Each test runs in its own process, with the user stand-ins.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FailedLoginSensorTest extends TestCase {

	protected function setUp(): void {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';
		WpState::$users[7] = new \WP_User(
			[
				'ID'         => 7,
				'user_login' => 'bob',
				'user_email' => 'bob@example.com',
				'roles'      => [ 'editor' ],
			]
		);
	}

	public function test_a_failed_login_for_an_account_names_it_as_target(): void {
		$recorder = $this->recorder();
		( new FailedLoginSensor( $recorder ) )->on_failed_login( 'bob' );

		$this->assertSame( [ 1002 ], array_column( $recorder->calls, 0 ) );
		$fields = $recorder->calls[0][1];
		$this->assertSame(
			[
				'type'  => 'user',
				'id'    => '7',
				'label' => 'bob',
			],
			$fields['target']
		);
		$this->assertSame( 'bob', $fields['username'] );
		$this->assertSame( [ 'attempted_username' => 'bob' ], $fields['metadata'] );
	}

	public function test_the_e_mail_address_of_an_account_names_it_too(): void {
		$recorder = $this->recorder();
		( new FailedLoginSensor( $recorder ) )->on_failed_login( 'bob@example.com' );

		$fields = $recorder->calls[0][1];
		$this->assertSame( '7', $fields['target']['id'] );
		$this->assertSame( 'bob', $fields['target']['label'], 'Named by its login, whatever was typed.' );
		$this->assertSame( 'bob@example.com', $fields['username'], 'What was typed is still what the event says.' );
	}

	/**
	 * @dataProvider names_of_no_account
	 *
	 * @param string $typed What was typed as the login.
	 */
	public function test_a_name_that_is_no_account_has_no_target( string $typed ): void {
		$recorder = $this->recorder();
		( new FailedLoginSensor( $recorder ) )->on_failed_login( $typed );

		$this->assertSame( [ 1002 ], array_column( $recorder->calls, 0 ), 'The attempt is logged all the same.' );
		$this->assertArrayNotHasKey( 'target', $recorder->calls[0][1] );
		$this->assertSame( $typed, $recorder->calls[0][1]['username'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function names_of_no_account(): array {
		return [
			'an unknown login'    => [ 'admin1' ],
			'an unknown e-mail'   => [ 'nobody@example.com' ],
			'nothing typed'       => [ '' ],
			'a part of a login'   => [ 'bo' ],
		];
	}

	public function test_the_event_a_failed_login_becomes_names_the_account_as_its_target(): void {
		$recorder = new BuildingRecorder();
		( new FailedLoginSensor( $recorder ) )->on_failed_login( 'bob' );

		$payload = $recorder->payloads[0];
		$this->assertSame( 1002, $payload['code'] );
		$this->assertSame( 'Failed login attempt for bob.', $payload['message'] );
		$this->assertSame(
			[
				'type'  => 'user',
				'id'    => '7',
				'label' => 'bob',
			],
			$payload['target']
		);
	}

	public function test_the_event_for_an_unknown_name_has_no_target(): void {
		$recorder = new BuildingRecorder();
		( new FailedLoginSensor( $recorder ) )->on_failed_login( 'admin1' );

		$this->assertArrayNotHasKey( 'target', $recorder->payloads[0] );
	}

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
