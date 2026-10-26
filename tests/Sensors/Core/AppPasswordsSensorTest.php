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
use HelloLog\Events\RequestSource;
use HelloLog\Events\SuppressionRules;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Core\AppPasswordsSensor;
use HelloLog\Tests\Events\Fixtures\AuthState;
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
 */
final class AppPasswordsSensorTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_sign_in_is_logged_once_without_determining_the_user_again(): void {
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-auth-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';

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
}
