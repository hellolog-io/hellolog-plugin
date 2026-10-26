<?php
/**
 * Tests for who the user sensors name as actor and who as target.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\RequestContext;
use HelloLog\Events\RequestSource;
use HelloLog\Events\SuppressionRules;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Core\AppPasswordsSensor;
use HelloLog\Sensors\Core\LoginLogoutSensor;
use HelloLog\Sensors\Core\TwoFactorSensor;
use HelloLog\Sensors\Core\UserProfileSensor;
use HelloLog\Sensors\Integrations\WooCommerceCustomerSensor;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use PHPUnit\Framework\TestCase;

/**
 * An event about a user names that user as its `target`. The `actor` is left
 * to the request's current user, except where the user is not (yet, or any
 * longer) the current one: login, logout and application-password sign-in.
 *
 * The sensors call WordPress, so every test that does runs in its own process
 * with the user stand-ins of `Fixtures/wordpress-user-functions.php`.
 */
final class UserTargetTest extends TestCase {

	public function test_user_target_has_a_string_id_and_the_login_as_label(): void {
		$this->assertSame(
			[
				'type'  => 'user',
				'id'    => '7',
				'label' => 'bob',
			],
			UserProfileSensor::user_target( 7, 'bob' )
		);
	}

	public function test_user_actor_is_id_username_and_a_list_of_roles(): void {
		$this->assertSame(
			[
				'id'       => 7,
				'username' => 'bob',
				'roles'    => [ 'editor', 'author' ],
			],
			UserProfileSensor::user_actor( 7, 'bob', [ 3 => 'editor', 5 => 'author' ] )
		);
	}

	public function test_profile_changes_reports_the_fields_that_differ_in_order(): void {
		$old = $this->profile(
			[
				'user_email'   => 'bob@example.com',
				'display_name' => 'Bob',
				'user_url'     => '',
			]
		);
		$new = $this->profile(
			[
				'user_email'   => 'robert@example.com',
				'display_name' => 'Robert',
				'user_url'     => 'https://bob.example.com',
			]
		);

		$this->assertSame(
			[
				[
					'field' => 'user_email',
					'old'   => 'bob@example.com',
					'new'   => 'robert@example.com',
				],
				[
					'field' => 'display_name',
					'old'   => 'Bob',
					'new'   => 'Robert',
				],
				[
					'field' => 'user_url',
					'old'   => '',
					'new'   => 'https://bob.example.com',
				],
			],
			UserProfileSensor::profile_changes( $old, $new )
		);
	}

	public function test_profile_changes_is_empty_when_nothing_differs(): void {
		$this->assertSame( [], UserProfileSensor::profile_changes( $this->profile(), $this->profile() ) );
	}

	public function test_a_new_password_hash_is_a_valueless_password_change(): void {
		$changes = UserProfileSensor::profile_changes(
			$this->profile( [ 'user_pass' => '$P$Bold-hash-value' ] ),
			$this->profile( [ 'user_pass' => '$P$Bnew-hash-value' ] )
		);

		$this->assertSame(
			[
				[
					'field' => 'password',
					'old'   => null,
					'new'   => null,
				],
			],
			$changes
		);
	}

	public function test_a_password_hash_never_appears_in_the_changes(): void {
		$changes = UserProfileSensor::profile_changes(
			$this->profile(
				[
					'user_pass'  => '$P$Bold-hash-value',
					'user_email' => 'bob@example.com',
				]
			),
			$this->profile(
				[
					'user_pass'  => '$P$Bnew-hash-value',
					'user_email' => 'robert@example.com',
				]
			)
		);

		$json = (string) json_encode( $changes );
		$this->assertStringNotContainsString( 'hash-value', $json );
		$this->assertStringNotContainsString( 'user_pass', $json );
		$this->assertSame( [ 'user_email', 'password' ], array_column( $changes, 'field' ) );
	}

	public function test_an_unchanged_password_hash_is_not_a_change(): void {
		$this->assertSame(
			[],
			UserProfileSensor::profile_changes(
				$this->profile( [ 'user_pass' => '$P$Bsame' ] ),
				$this->profile( [ 'user_pass' => '$P$Bsame' ] )
			)
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_change_names_the_user_as_target_and_passes_no_actor(): void {
		$this->wp_user( 7, 'bob', [ 'customer' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_role_change( 7, 'customer', [] );

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( 4002, $recorder->calls[0][0] );
		$fields = $recorder->calls[0][1];
		$this->assertSame( '7', $fields['target']['id'] );
		$this->assertSame( 'user', $fields['target']['type'] );
		$this->assertSame( 'bob', $fields['target']['label'] );
		$this->assertArrayNotHasKey( 'user', $fields );
		$this->assertArrayNotHasKey( 'actor', $fields );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_change_reports_the_old_and_the_new_role(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_role_change( 7, 'editor', [ 'subscriber', 'author' ] );

		$fields = $recorder->calls[0][1];
		$this->assertSame(
			[
				[
					'field' => 'role',
					'old'   => 'subscriber, author',
					'new'   => 'editor',
				],
			],
			$fields['changes']
		);
		$this->assertSame( 'bob', $fields['username'] );
		$this->assertSame( 'subscriber', $fields['old_role'] );
		$this->assertSame( 'editor', $fields['new_role'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_change_of_an_unknown_user_emits_nothing(): void {
		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_role_change( 99, 'editor', [] );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_registration_names_the_new_user_as_target_and_passes_no_actor(): void {
		$this->wp_user( 7, 'bob', [ 'customer' ], [ 'user_email' => 'bob@example.com', 'display_name' => 'Bob' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_register( 7 );

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( 4000, $recorder->calls[0][0] );
		$fields = $recorder->calls[0][1];
		$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $fields['target'] );
		$this->assertArrayNotHasKey( 'user', $fields );
		$this->assertArrayNotHasKey( 'actor', $fields );
		$this->assertSame( 'bob', $fields['username'] );
		$this->assertSame( 'customer', $fields['role'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_profile_update_reports_what_differs_from_the_user_before(): void {
		$old = $this->wp_user(
			7,
			'bob',
			[ 'subscriber' ],
			[
				'user_email'    => 'bob@example.com',
				'display_name'  => 'Bob',
				'user_url'      => '',
				'user_nicename' => 'bob',
				'user_pass'     => '$P$Bsame',
			],
			false
		);
		$this->wp_user(
			7,
			'bob',
			[ 'subscriber' ],
			[
				'user_email'    => 'robert@example.com',
				'display_name'  => 'Bob',
				'user_url'      => '',
				'user_nicename' => 'bob',
				'user_pass'     => '$P$Bsame',
			]
		);

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_profile_update( 7, $old, [] );

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( 4001, $recorder->calls[0][0] );
		$fields = $recorder->calls[0][1];
		$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $fields['target'] );
		$this->assertArrayNotHasKey( 'user', $fields );
		$this->assertArrayNotHasKey( 'actor', $fields );
		$this->assertSame(
			[
				[
					'field' => 'user_email',
					'old'   => 'bob@example.com',
					'new'   => 'robert@example.com',
				],
			],
			$fields['changes']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_profile_update_flags_a_password_change_without_the_hash(): void {
		$old = $this->wp_user( 7, 'bob', [ 'subscriber' ], [ 'user_pass' => '$P$Bold-hash-value' ], false );
		$this->wp_user( 7, 'bob', [ 'subscriber' ], [ 'user_pass' => '$P$Bnew-hash-value' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_profile_update( 7, $old, [] );

		$fields = $recorder->calls[0][1];
		$this->assertSame(
			[
				[
					'field' => 'password',
					'old'   => null,
					'new'   => null,
				],
			],
			$fields['changes']
		);
		$this->assertStringNotContainsString( 'hash-value', serialize( $fields ) );
	}

	/**
	 * Core slashes the email of the user before the update (to compare it with
	 * the slashed input) and hands that same object to `profile_update`.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_slashed_email_of_the_user_before_is_not_a_change(): void {
		$old = $this->wp_user( 7, 'bob', [ 'subscriber' ], [ 'user_email' => "o\\'brien@example.com" ], false );
		$this->wp_user( 7, 'bob', [ 'subscriber' ], [ 'user_email' => "o'brien@example.com" ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_profile_update( 7, $old, [] );

		$this->assertSame( [], $recorder->calls[0][1]['changes'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_profile_update_without_the_user_before_has_no_changes(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ], [ 'user_email' => 'bob@example.com' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_profile_update( 7 );

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( 4001, $recorder->calls[0][0] );
		$this->assertSame( [], $recorder->calls[0][1]['changes'] );
		$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $recorder->calls[0][1]['target'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deletion_names_the_deleted_user_as_target_and_passes_no_actor(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_delete( 7 );

		$this->assertSame( 4003, $recorder->calls[0][0] );
		$fields = $recorder->calls[0][1];
		$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $fields['target'] );
		$this->assertSame( 'bob', $fields['username'] );
		$this->assertArrayNotHasKey( 'user', $fields );
		$this->assertArrayNotHasKey( 'actor', $fields );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deletion_of_a_user_that_is_gone_still_has_a_target(): void {
		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_delete( 99 );

		$fields = $recorder->calls[0][1];
		$this->assertSame( '99', $fields['target']['id'] );
		$this->assertSame( 'user', $fields['target']['type'] );
		$this->assertSame( '#99', $fields['username'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_login_has_the_user_as_actor_and_as_target(): void {
		$user = $this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		( new LoginLogoutSensor( $recorder ) )->on_login( 'bob', $user );

		$this->assertSame( 1000, $recorder->calls[0][0] );
		$fields = $recorder->calls[0][1];
		$this->assertSame( UserProfileSensor::user_actor( 7, 'bob', [ 'editor' ] ), $fields['actor'] );
		$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $fields['target'] );
		$this->assertArrayNotHasKey( 'user', $fields );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_logout_has_the_user_as_actor_and_as_target(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		( new LoginLogoutSensor( $recorder ) )->on_logout( 7 );

		$this->assertSame( 1001, $recorder->calls[0][0] );
		$fields = $recorder->calls[0][1];
		$this->assertSame( UserProfileSensor::user_actor( 7, 'bob', [ 'editor' ] ), $fields['actor'] );
		$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $fields['target'] );
		$this->assertArrayNotHasKey( 'user', $fields );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_logout_of_an_unknown_user_names_neither_actor_nor_target(): void {
		$recorder = $this->recorder();
		( new LoginLogoutSensor( $recorder ) )->on_logout( 0 );

		$this->assertSame( 1001, $recorder->calls[0][0] );
		$fields = $recorder->calls[0][1];
		$this->assertArrayNotHasKey( 'actor', $fields );
		$this->assertArrayNotHasKey( 'user', $fields );
		$this->assertArrayNotHasKey( 'target', $fields );
		$this->assertSame( '', $fields['username'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_application_password_sign_in_has_the_user_as_actor_and_as_target(): void {
		$user = $this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		( new AppPasswordsSensor( $recorder ) )->on_authenticate( $user, [ 'name' => 'Sync', 'uuid' => 'abc' ] );

		$this->assertSame( 4502, $recorder->calls[0][0] );
		$fields = $recorder->calls[0][1];
		$this->assertSame( UserProfileSensor::user_actor( 7, 'bob', [ 'editor' ] ), $fields['actor'] );
		$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $fields['target'] );
		$this->assertArrayNotHasKey( 'user', $fields );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_creating_and_revoking_an_application_password_name_the_owner_as_target_only(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		$sensor   = new AppPasswordsSensor( $recorder );
		$sensor->on_create( 7, [ 'name' => 'Sync', 'uuid' => 'abc' ], 'secret', [] );
		$sensor->on_delete( 7, 'abc' );

		$this->assertSame( [ 4500, 4501 ], array_column( $recorder->calls, 0 ) );
		foreach ( $recorder->calls as $call ) {
			$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $call[1]['target'] );
			$this->assertArrayNotHasKey( 'user', $call[1] );
			$this->assertArrayNotHasKey( 'actor', $call[1] );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_two_factor_events_name_the_user_as_target_only(): void {
		$user = $this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		$sensor   = new TwoFactorSensor( $recorder );
		$sensor->on_authenticated( $user, 'Two_Factor_Totp' );
		$sensor->detect_profile_toggle( 1, 7, '_two_factor_enabled_providers', [ 'Two_Factor_Totp' ] );
		$sensor->detect_profile_toggle( 2, 7, '_two_factor_enabled_providers', [] );

		$this->assertSame( [ 4400, 4400, 4401 ], array_column( $recorder->calls, 0 ) );
		foreach ( $recorder->calls as $call ) {
			$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $call[1]['target'] );
			$this->assertArrayNotHasKey( 'user', $call[1] );
			$this->assertArrayNotHasKey( 'actor', $call[1] );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_shop_customer_events_name_the_user_as_target(): void {
		$this->wp_user( 7, 'bob', [ 'customer' ] );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceCustomerSensor( $recorder );
		$sensor->on_created( 7, [], true );
		$sensor->on_address_saved( 7, 'billing' );

		$this->assertSame( [ 9300, 9301 ], array_column( $recorder->calls, 0 ) );
		foreach ( $recorder->calls as $call ) {
			$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $call[1]['target'] );
			$this->assertArrayNotHasKey( 'user', $call[1] );
			$this->assertArrayNotHasKey( 'actor', $call[1] );
		}
	}

	/**
	 * WordPress sets the role (`set_user_role`) BEFORE it fires `user_register`.
	 * The role change of a new user must still not reach the log.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_registration_leaves_one_event_whatever_the_hooks_order(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_change( 7, 'subscriber', [] );
		$sensor->on_register( 7 );

		$survivors = $this->survive( $recorder );

		$this->assertSame( [ 4000 ], array_column( $survivors, 'code' ) );
		$this->assertSame( '7', $survivors[0]['target']['id'] );
		$this->assertSame( 1, $survivors[0]['user']['id'], 'The signed-in admin who added the user is the actor.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_shop_registration_leaves_only_the_customer_event(): void {
		$this->wp_user( 7, 'bob', [ 'customer' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_change( 7, 'customer', [] );
		$sensor->on_register( 7 );
		( new WooCommerceCustomerSensor( $recorder ) )->on_created( 7, [], false );

		$this->assertSame( [ 9300 ], array_column( $this->survive( $recorder ), 'code' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_edit_that_only_changes_the_role_leaves_the_role_change(): void {
		$old = $this->wp_user( 7, 'bob', [ 'subscriber' ], [ 'user_email' => 'bob@example.com' ], false );
		$this->wp_user( 7, 'bob', [ 'editor' ], [ 'user_email' => 'bob@example.com' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_change( 7, 'editor', [ 'subscriber' ] );
		$sensor->on_profile_update( 7, $old, [] );

		$survivors = $this->survive( $recorder );

		$this->assertSame( [ 4002 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 'role', $survivors[0]['changes'][0]['field'] );
		$this->assertSame( 1, $survivors[0]['user']['id'] );
		$this->assertSame( '7', $survivors[0]['target']['id'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_edit_that_changes_the_email_and_the_role_leaves_both_events(): void {
		$old = $this->wp_user( 7, 'bob', [ 'subscriber' ], [ 'user_email' => 'bob@example.com' ], false );
		$this->wp_user( 7, 'bob', [ 'editor' ], [ 'user_email' => 'robert@example.com' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_change( 7, 'editor', [ 'subscriber' ] );
		$sensor->on_profile_update( 7, $old, [] );

		$this->assertSame( [ 4002, 4001 ], array_column( $this->survive( $recorder ), 'code' ) );
	}

	/**
	 * A fresh array of user fields with the defaults every test starts from.
	 *
	 * @param array<string, string> $over Fields to override.
	 * @return array<string, string>
	 */
	private function profile( array $over = [] ): array {
		return array_merge(
			[
				'user_email'    => 'bob@example.com',
				'display_name'  => 'Bob',
				'user_url'      => '',
				'user_nicename' => 'bob',
				'user_pass'     => '$P$Bhash',
			],
			$over
		);
	}

	/**
	 * Create a user stand-in. Only call this from a test of its own process.
	 *
	 * @param int                  $id       User id.
	 * @param string               $login    Login name.
	 * @param array<int, string>   $roles    Role slugs.
	 * @param array<string, mixed> $extra    More fields (email, password hash, ...).
	 * @param bool                 $register Whether `get_user_by()` and `get_userdata()` find it.
	 */
	private function wp_user( int $id, string $login, array $roles = [], array $extra = [], bool $register = true ): \WP_User {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';

		$user = new \WP_User(
			array_merge(
				[
					'ID'         => $id,
					'user_login' => $login,
					'roles'      => $roles,
				],
				$extra
			)
		);
		if ( $register ) {
			WpState::$users[ $id ] = $user;
		}

		return $user;
	}

	/**
	 * A dispatcher that records every call as `[ code, fields ]`.
	 */
	private function recorder(): EventDispatcher {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';

		return new class() implements EventDispatcher {

			/** @var array<int, array{0: int, 1: array<string, mixed>}> */
			public array $calls = [];

			public function dispatch( int $code, array $fields = [] ): void {
				$this->calls[] = [ $code, $fields ];
			}
		};
	}

	/**
	 * Build what the recorder saw as an admin (user 1) would on the dashboard
	 * and run the default per-request rules over it.
	 *
	 * @param EventDispatcher $recorder The recording dispatcher.
	 * @return array<int, array<string, mixed>> The payloads that would be written.
	 */
	private function survive( EventDispatcher $recorder ): array {
		$catalog = new EventCatalog();
		CatalogSeeder::seed( $catalog );

		$builder = new class( $catalog ) extends EventBuilder {

			public function __construct( EventCatalog $catalog ) {
				parent::__construct( $catalog, false, static fn(): string => RequestSource::ADMIN );
			}

			protected function current_context(): RequestContext {
				return new RequestContext( 1, 'admin', [ 'administrator' ], null, null, null );
			}
		};

		$payloads = [];
		foreach ( $recorder->calls as [ $code, $fields ] ) {
			$payload = $builder->build( $code, $fields );
			$this->assertIsArray( $payload );
			$payloads[] = $payload;
		}

		return SuppressionRules::defaults()->apply( $payloads );
	}
}
