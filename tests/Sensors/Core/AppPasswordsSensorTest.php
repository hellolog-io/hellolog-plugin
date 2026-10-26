<?php
/**
 * Tests that an application-password sign-in is logged without re-entering
 * the sign-in itself.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Events\BufferedDispatcher;
use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\RequestSource;
use HelloLog\Events\SuppressionRules;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Core\AppPasswordsSensor;
use HelloLog\Tests\Events\Fixtures\AuthState;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use PHPUnit\Framework\TestCase;

/**
 * WordPress checks an application password inside `determine_current_user`,
 * and reports the sign-in from there, before the user is the current one.
 * The event built for it must not read the current user: that would apply
 * `determine_current_user` again, check the password again, report again,
 * and so on until the request runs out of memory.
 *
 * The real builder and dispatcher run here, with the current-user stand-ins
 * of `Events/Fixtures/wordpress-auth-functions.php`.
 *
 * A client that polls the REST API signs in on every request, so a sign-in
 * is logged at most once an hour per user, application password and IP.
 */
final class AppPasswordsSensorTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_sign_in_is_logged_once_without_determining_the_user_again(): void {
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-auth-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-transient-functions.php';

		$written    = [];
		$catalog    = new EventCatalog();
		$dispatcher = new BufferedDispatcher(
			new EventBuilder( $catalog, false, static fn(): string => RequestSource::REST ),
			static function ( string $json ) use ( &$written ): void {
				$written[] = json_decode( $json, true );
			},
			SuppressionRules::defaults()
		);
		CatalogSeeder::seed( $catalog );
		$sensor = new AppPasswordsSensor( $dispatcher );

		$user = new \WP_User(
			[
				'ID'         => 5,
				'user_login' => 'integration',
				'roles'      => [ 'editor' ],
			]
		);

		// What core's application-password check does from inside the filter.
		AuthState::$determined   = $user;
		AuthState::$on_determine = static function () use ( $sensor, $user ): void {
			$sensor->on_authenticate( $user, [ 'name' => 'Sync client', 'uuid' => 'u-1', 'app_id' => '' ] );
		};

		// The first read of the current user in the request, as a REST route would do.
		$this->assertSame( 5, wp_get_current_user()->ID );
		$dispatcher->flush();

		$this->assertSame( 1, AuthState::$max_nesting, 'determine_current_user must run once, not recurse.' );
		$this->assertCount( 1, $written );
		$this->assertSame( 4502, $written[0]['code'] );
		$this->assertSame(
			[
				'id'       => 5,
				'username' => 'integration',
				'roles'    => [ 'editor' ],
			],
			$written[0]['user']
		);
		$this->assertSame( 'user', $written[0]['target']['type'] );
		$this->assertSame( '5', $written[0]['target']['id'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_second_sign_in_within_the_hour_is_not_logged_again(): void {
		$recorder = $this->load_sign_in_stand_ins();
		$sensor   = new AppPasswordsSensor( $recorder );

		$sensor->on_authenticate( $this->user( 5 ), $this->app_password( 'u-1' ) );
		$sensor->on_authenticate( $this->user( 5 ), $this->app_password( 'u-1' ) );
		$sensor->on_authenticate( $this->user( 5 ), $this->app_password( 'u-1' ) );

		$this->assertSame( [ 4502 ], array_column( $recorder->calls, 0 ) );
		$this->assertCount( 1, WpState::$transients );
		$this->assertSame( 3600, array_values( WpState::$transients )[0][1], 'Remembered for an hour.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_another_ip_application_password_or_user_is_logged(): void {
		$recorder = $this->load_sign_in_stand_ins();
		$sensor   = new AppPasswordsSensor( $recorder );

		$sensor->on_authenticate( $this->user( 5 ), $this->app_password( 'u-1' ) );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$sensor->on_authenticate( $this->user( 5 ), $this->app_password( 'u-1' ) );
		$sensor->on_authenticate( $this->user( 5 ), $this->app_password( 'u-2' ) );
		$sensor->on_authenticate( $this->user( 6 ), $this->app_password( 'u-2' ) );

		$this->assertSame( [ 4502, 4502, 4502, 4502 ], array_column( $recorder->calls, 0 ) );
	}

	/**
	 * The check runs inside `determine_current_user`, like the event itself:
	 * it must not read the current user. Nor does the IP reach the stored
	 * name in the clear.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_hourly_check_never_reads_the_current_user(): void {
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-auth-functions.php';
		$recorder           = $this->load_sign_in_stand_ins();
		AuthState::$filters = [ 'determine_current_user' ];

		$sensor = new AppPasswordsSensor( $recorder );
		$sensor->on_authenticate( $this->user( 5 ), $this->app_password( 'u-1' ) );
		$sensor->on_authenticate( $this->user( 5 ), $this->app_password( 'u-1' ) );

		$this->assertSame( 0, AuthState::$current_user_calls );
		$this->assertCount( 1, $recorder->calls );
		$name = (string) array_key_first( WpState::$transients );
		$this->assertStringStartsWith( 'hellolog_', $name );
		$this->assertStringNotContainsString( '203.0.113.7', $name );
	}

	/**
	 * Loads the stand-ins a sign-in needs and returns a recording dispatcher.
	 */
	private function load_sign_in_stand_ins(): EventDispatcher {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-transient-functions.php';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		return new class() implements EventDispatcher {

			/** @var array<int, array{0: int, 1: array<string, mixed>}> */
			public array $calls = [];

			public function dispatch( int $code, array $fields = [] ): void {
				$this->calls[] = [ $code, $fields ];
			}
		};
	}

	private function user( int $id ): \WP_User {
		return new \WP_User(
			[
				'ID'         => $id,
				'user_login' => 'user' . $id,
				'roles'      => [ 'editor' ],
			]
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function app_password( string $uuid ): array {
		return [
			'name'   => 'Sync client',
			'uuid'   => $uuid,
			'app_id' => '',
		];
	}
}
