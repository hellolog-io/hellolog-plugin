<?php
/**
 * Tests for who the user sensors name as actor and who as target.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Events\ActionSchedulerContext;
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
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Core\Fixtures\BuildingRecorder;
use HelloLog\Tests\Sensors\Core\Fixtures\Hooks;
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

	public function test_profile_events_puts_an_email_change_on_its_own(): void {
		$events = UserProfileSensor::profile_events(
			UserProfileSensor::profile_changes(
				$this->profile( [ 'user_email' => 'bob@example.com' ] ),
				$this->profile( [ 'user_email' => 'robert@example.com' ] )
			)
		);

		$this->assertSame(
			[
				'email'    => [
					'field' => 'user_email',
					'old'   => 'bob@example.com',
					'new'   => 'robert@example.com',
				],
				'password' => false,
				'profile'  => [],
			],
			$events
		);
	}

	public function test_profile_events_says_a_password_changed_without_any_hash(): void {
		$events = UserProfileSensor::profile_events(
			UserProfileSensor::profile_changes(
				$this->profile( [ 'user_pass' => '$P$Bold-hash-value' ] ),
				$this->profile( [ 'user_pass' => '$P$Bnew-hash-value' ] )
			)
		);

		$this->assertTrue( $events['password'] );
		$this->assertNull( $events['email'] );
		$this->assertSame( [], $events['profile'] );
		$json = (string) json_encode( $events );
		$this->assertStringNotContainsString( 'hash-value', $json );
		$this->assertStringNotContainsString( '$P$B', $json );
	}

	public function test_profile_events_keeps_a_display_name_change_as_a_profile_change(): void {
		$events = UserProfileSensor::profile_events(
			UserProfileSensor::profile_changes(
				$this->profile( [ 'display_name' => 'Bob' ] ),
				$this->profile( [ 'display_name' => 'Robert' ] )
			)
		);

		$this->assertNull( $events['email'] );
		$this->assertFalse( $events['password'] );
		$this->assertSame(
			[
				[
					'field' => 'display_name',
					'old'   => 'Bob',
					'new'   => 'Robert',
				],
			],
			$events['profile']
		);
	}

	public function test_profile_events_of_no_changes_is_empty(): void {
		$this->assertSame(
			[
				'email'    => null,
				'password' => false,
				'profile'  => [],
			],
			UserProfileSensor::profile_events( [] )
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
	 * The change and the message name the old roles the same way: sorted, as
	 * every `role` item does.
	 *
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
					'old'   => 'author, subscriber',
					'new'   => 'editor',
				],
			],
			$fields['changes']
		);
		$this->assertSame( 'bob', $fields['username'] );
		$this->assertSame( 'author, subscriber', $fields['old_role'] );
		$this->assertSame( 'editor', $fields['new_role'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_change_names_every_previous_role_in_the_message(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_role_change( 7, 'editor', [ 'subscriber', 'author', 'subscriber' ] );

		$survivors = $this->survive( $recorder );

		$this->assertSame( 'author, subscriber', $survivors[0]['vars']['old_role'] );
		$this->assertSame( 'Role for bob changed from author, subscriber to editor.', $survivors[0]['message'] );
	}

	/**
	 * A user who had no role is given one: the change reads as an addition,
	 * with nothing on the old side.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_change_from_no_role_has_no_old_role(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_role_change( 7, 'editor', [] );

		$fields = $recorder->calls[0][1];
		$this->assertNull( $fields['old_role'] );
		$this->assertSame(
			[
				[
					'field' => 'role',
					'old'   => null,
					'new'   => 'editor',
				],
			],
			$fields['changes']
		);
		$this->assertSame( [], $fields['metadata']['old_roles'] );
	}

	/**
	 * A null is no template value: the message must not keep `{old_role}`,
	 * and the var stays what the template has always been rendered with.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_change_from_no_role_leaves_no_placeholder_in_the_message(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_role_change( 7, 'editor', [] );

		$survivors = $this->survive( $recorder );

		$this->assertCount( 1, $survivors );
		$this->assertNull( $survivors[0]['changes'][0]['old'] );
		$this->assertSame( '', $survivors[0]['vars']['old_role'] );
		$this->assertStringNotContainsString( '{', $survivors[0]['message'] );
		$this->assertStringStartsWith( 'Role for bob changed from ', $survivors[0]['message'] );
		$this->assertStringEndsWith( ' to editor.', $survivors[0]['message'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_change_whose_previous_role_has_no_name_is_a_change_from_no_role(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_role_change( 7, 'editor', [ [] ] );

		$fields = $recorder->calls[0][1];
		$this->assertNull( $fields['old_role'] );
		$this->assertNull( $fields['changes'][0]['old'] );
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
	 * The template names the first role; a user can be given more than one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_registration_lists_every_role_of_the_new_user(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber', 'shop_manager' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_register( 7 );

		$fields = $recorder->calls[0][1];
		$this->assertSame( 'subscriber', $fields['role'] );
		$this->assertSame( [ 'subscriber', 'shop_manager' ], $fields['metadata']['roles'] );
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
				'user_email'    => 'bob@example.com',
				'display_name'  => 'Robert',
				'user_url'      => 'https://bob.example.com',
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
			$fields['changes']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_email_change_is_an_event_of_its_own_with_the_old_and_the_new_address(): void {
		$old = $this->wp_user( 7, 'bob', [ 'subscriber' ], [ 'user_email' => 'bob@example.com' ], false );
		$this->wp_user( 7, 'bob', [ 'subscriber' ], [ 'user_email' => 'robert@example.com' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_profile_update( 7, $old, [] );

		$this->assertSame( [ 4005, 4001 ], array_column( $recorder->calls, 0 ) );
		$fields = $recorder->calls[0][1];
		$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $fields['target'] );
		$this->assertSame( 'bob', $fields['username'] );
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
		$this->assertSame( [], $recorder->calls[1][1]['changes'] );

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 4005 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 'E-mail address changed for bob.', $survivors[0]['message'] );
		$this->assertSame( 'high', $survivors[0]['severity'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_password_change_is_an_event_of_its_own_without_the_hash(): void {
		$old = $this->wp_user( 7, 'bob', [ 'subscriber' ], [ 'user_pass' => '$P$Bold-hash-value' ], false );
		$this->wp_user( 7, 'bob', [ 'subscriber' ], [ 'user_pass' => '$P$Bnew-hash-value' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_profile_update( 7, $old, [] );

		$this->assertSame( [ 4004, 4001 ], array_column( $recorder->calls, 0 ) );
		$fields = $recorder->calls[0][1];
		$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $fields['target'] );
		$this->assertArrayNotHasKey( 'actor', $fields );
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
		$this->assertStringNotContainsString( 'hash-value', serialize( $recorder->calls ) );

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 4004 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 'Password changed for bob.', $survivors[0]['message'] );
		$this->assertStringNotContainsString( 'hash-value', (string) json_encode( $survivors ) );
	}

	/**
	 * An edit that changes the e-mail, the password and the display name at
	 * once keeps all three: the profile event only carries the profile
	 * fields, so nothing of it overlaps the other two.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_edit_of_email_password_and_name_keeps_three_events(): void {
		$old = $this->wp_user(
			7,
			'bob',
			[ 'subscriber' ],
			[
				'user_email'   => 'bob@example.com',
				'display_name' => 'Bob',
				'user_pass'    => '$P$Bold-hash-value',
			],
			false
		);
		$this->wp_user(
			7,
			'bob',
			[ 'subscriber' ],
			[
				'user_email'   => 'robert@example.com',
				'display_name' => 'Robert',
				'user_pass'    => '$P$Bnew-hash-value',
			]
		);

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_profile_update( 7, $old, [] );

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 4005, 4004, 4001 ], array_column( $survivors, 'code' ) );
		$this->assertSame( [ 'display_name' ], array_column( $survivors[2]['changes'], 'field' ) );
	}

	/**
	 * Registration flows set the e-mail and the password of the user they
	 * just created; the registration says that already.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_email_and_password_change_right_after_a_registration_leave_the_registration(): void {
		$old = $this->wp_user(
			7,
			'bob',
			[ 'subscriber' ],
			[
				'user_email' => 'bob@example.com',
				'user_pass'  => '$P$Bold-hash-value',
			],
			false
		);
		$this->wp_user(
			7,
			'bob',
			[ 'subscriber' ],
			[
				'user_email' => 'robert@example.com',
				'user_pass'  => '$P$Bnew-hash-value',
			]
		);

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_register( 7 );
		$sensor->on_profile_update( 7, $old, [] );

		$this->assertSame( [ 4000 ], array_column( $this->survive( $recorder ), 'code' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_email_and_password_change_right_after_a_shop_sign_up_leave_the_sign_up(): void {
		$old = $this->wp_user(
			7,
			'bob',
			[ 'customer' ],
			[
				'user_email' => 'bob@example.com',
				'user_pass'  => '$P$Bold-hash-value',
			],
			false
		);
		$this->wp_user(
			7,
			'bob',
			[ 'customer' ],
			[
				'user_email' => 'robert@example.com',
				'user_pass'  => '$P$Bnew-hash-value',
			]
		);

		$recorder = $this->recorder();
		( new WooCommerceCustomerSensor( $recorder ) )->on_created( 7, [], false );
		( new UserProfileSensor( $recorder ) )->on_profile_update( 7, $old, [] );

		$this->assertSame( [ 9300 ], array_column( $this->survive( $recorder ), 'code' ) );
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

		$this->assertSame( [ 4001 ], array_column( $recorder->calls, 0 ) );
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
	 * The user core hands to the hook names the target, even when a lookup
	 * would no longer find it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deletion_with_reassignment_names_who_gets_the_content(): void {
		$this->wp_user( 8, 'alice', [ 'editor' ] );
		$user = $this->wp_user( 7, 'bob', [ 'subscriber' ], [], false );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_delete( 7, 8, $user );

		$fields = $recorder->calls[0][1];
		$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $fields['target'] );
		$this->assertSame( 'bob', $fields['username'] );
		$this->assertSame(
			[
				'deleted_user_id' => 7,
				'reassign_to'     => 'alice',
			],
			$fields['metadata']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deletion_without_reassignment_says_nobody_gets_the_content(): void {
		$user = $this->wp_user( 7, 'bob', [ 'subscriber' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_delete( 7, null, $user );

		$this->assertArrayHasKey( 'reassign_to', $recorder->calls[0][1]['metadata'] );
		$this->assertNull( $recorder->calls[0][1]['metadata']['reassign_to'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_reassignment_to_a_user_that_cannot_be_loaded_keeps_its_id(): void {
		$user = $this->wp_user( 7, 'bob', [ 'subscriber' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_delete( 7, 42, $user );

		$this->assertSame( '#42', $recorder->calls[0][1]['metadata']['reassign_to'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_deletion_hook_accepts_the_reassignment_and_the_user(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		( new UserProfileSensor( $this->recorder() ) )->boot();

		$this->assertSame( 3, WpState::$actions['delete_user']['accepted_args'] );
	}

	/**
	 * The new password is the hook's second argument: the sensor never takes
	 * it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_completed_password_reset_names_the_user_as_target_and_passes_no_actor(): void {
		$user = $this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		( new LoginLogoutSensor( $recorder ) )->on_password_reset( $user );

		$this->assertSame( [ 1004 ], array_column( $recorder->calls, 0 ) );
		$fields = $recorder->calls[0][1];
		$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $fields['target'] );
		$this->assertSame( 'bob', $fields['username'] );
		$this->assertArrayNotHasKey( 'actor', $fields );
		$this->assertArrayNotHasKey( 'user', $fields );

		$survivors = $this->survive( $recorder );
		$this->assertSame( 'Password reset completed for bob.', $survivors[0]['message'] );
		$this->assertSame( 'medium', $survivors[0]['severity'] );
		$this->assertSame( 'password-reset', $survivors[0]['event_type'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_password_reset_hook_never_accepts_the_new_password(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		( new LoginLogoutSensor( $this->recorder() ) )->boot();

		$this->assertSame( 1, WpState::$actions['after_password_reset']['accepted_args'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_password_reset_of_something_that_is_not_a_user_emits_nothing(): void {
		$recorder = $this->recorder();
		( new LoginLogoutSensor( $recorder ) )->on_password_reset( null );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_password_reset_of_a_user_that_cannot_be_read_is_reported_and_never_thrown(): void {
		$recorder = $this->recorder();
		$this->failure_listener();

		( new LoginLogoutSensor( $recorder ) )->on_password_reset( $this->unreadable_user( 7 ) );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 'hellolog_dispatch_failed', WpCalls::$actions[0][0] );
		$this->assertSame( 1004, WpCalls::$actions[0][1][1] );
		$this->assertSame( [ 'user_id' => 7 ], WpCalls::$actions[0][1][2] );
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
		require_once __DIR__ . '/Fixtures/wordpress-transient-functions.php';

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
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		$sensor   = new TwoFactorSensor( $recorder );
		$sensor->detect_profile_toggle( 1, 7, '_two_factor_enabled_providers', [ 'Two_Factor_Totp' ] );
		$sensor->detect_profile_toggle( 2, 7, '_two_factor_enabled_providers', [] );

		$this->assertSame( [ 4400, 4401 ], array_column( $recorder->calls, 0 ) );
		foreach ( $recorder->calls as $call ) {
			$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $call[1]['target'] );
			$this->assertArrayNotHasKey( 'user', $call[1] );
			$this->assertArrayNotHasKey( 'actor', $call[1] );
		}
	}

	/**
	 * A two-factor LOGIN is a login (`wp_login`, 1000), not "2FA enabled".
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_two_factor_only_listens_for_the_enable_disable_toggle(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		( new TwoFactorSensor( $this->recorder() ) )->boot();

		$this->assertSame( [ 'update_user_meta' ], array_keys( WpState::$actions ) );
		$this->assertFalse( method_exists( TwoFactorSensor::class, 'on_authenticated' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_profile_update_hook_accepts_the_user_before_and_the_input(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		( new UserProfileSensor( $this->recorder() ) )->boot();

		$this->assertSame( 3, WpState::$actions['profile_update']['accepted_args'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_password_reset_request_names_the_user_as_target_and_passes_no_actor(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		( new LoginLogoutSensor( $recorder ) )->on_password_reset_request( 'bob' );

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( 1003, $recorder->calls[0][0] );
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
	public function test_a_password_reset_request_for_an_unknown_login_has_no_target(): void {
		$recorder = $this->recorder();
		( new LoginLogoutSensor( $recorder ) )->on_password_reset_request( 'nobody' );

		$this->assertSame( 1003, $recorder->calls[0][0] );
		$fields = $recorder->calls[0][1];
		$this->assertArrayNotHasKey( 'target', $fields );
		$this->assertArrayNotHasKey( 'actor', $fields );
		$this->assertSame( 'nobody', $fields['username'] );
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
	 * WordPress sets the role (`set_user_role`) BEFORE it fires `user_register`;
	 * the first role of a new user must not reach the log as a role change,
	 * whichever order the two hooks arrive in.
	 *
	 * @return array<string, array{0: bool}> Whether the role is set first.
	 */
	public static function hook_orders(): array {
		return [
			'role first, as core does' => [ true ],
			'registration first'       => [ false ],
		];
	}

	/**
	 * @dataProvider hook_orders
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_first_role_of_a_new_user_is_not_logged_as_a_role_change( bool $role_first ): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		if ( $role_first ) {
			$sensor->on_role_change( 7, 'subscriber', [] );
			$sensor->on_register( 7 );
		} else {
			$sensor->on_register( 7 );
			$sensor->on_role_change( 7, 'subscriber', [] );
		}

		$survivors = $this->survive( $recorder );

		$this->assertSame( [ 4000 ], array_column( $survivors, 'code' ) );
		$this->assertSame( '7', $survivors[0]['target']['id'] );
		$this->assertSame( 1, $survivors[0]['user']['id'], 'The signed-in admin who added the user is the actor.' );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}> Role, whether it is set first.
	 */
	public static function privileged_registrations(): array {
		$cases = [];
		foreach ( [ 'administrator', 'editor', 'shop_manager' ] as $role ) {
			$cases[ $role . ', role first' ]         = [ $role, true ];
			$cases[ $role . ', registration first' ] = [ $role, false ];
		}

		return $cases;
	}

	/**
	 * A first role other than a plain default stays on record twice: the
	 * registration names it, and the grant itself stays as a role change (an
	 * acceptable duplicate; the grant must never be hidden).
	 *
	 * @dataProvider privileged_registrations
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_registration_with_a_privileged_role_keeps_the_registration_and_the_grant( string $role, bool $role_first ): void {
		$this->wp_user( 7, 'bob', [ $role ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		if ( $role_first ) {
			$sensor->on_role_change( 7, $role, [] );
			$sensor->on_register( 7 );
		} else {
			$sensor->on_register( 7 );
			$sensor->on_role_change( 7, $role, [] );
		}

		$survivors = $this->survive( $recorder );

		$by_code = array_column( $survivors, null, 'code' );
		$this->assertCount( 2, $survivors );
		$this->assertSame( $role, $by_code[4000]['vars']['role'] );
		$this->assertStringContainsString( $role, $by_code[4000]['message'] );
		$this->assertSame( $role, $by_code[4002]['changes'][0]['new'] );
		$this->assertSame( '7', $by_code[4002]['target']['id'] );
	}

	/**
	 * Multisite creates the user with the default role, strips it, and then
	 * grants the real one when it adds the user to the site: the grant has no
	 * previous role either.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_multisite_style_admin_grant_after_a_default_role_registration_is_kept(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_change( 7, 'subscriber', [] );
		$sensor->on_register( 7 );
		$sensor->on_role_change( 7, 'administrator', [] );

		$survivors = $this->survive( $recorder );

		$this->assertSame( [ 4000, 4002 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 'subscriber', $survivors[0]['vars']['role'] );
		$this->assertSame(
			[
				[
					'field' => 'role',
					'old'   => null,
					'new'   => 'administrator',
				],
			],
			$survivors[1]['changes']
		);
		$this->assertSame( 'administrator', $survivors[1]['vars']['new_role'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_shop_registration_as_administrator_keeps_the_registration_the_grant_and_the_customer_event(): void {
		$this->wp_user( 7, 'bob', [ 'administrator' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_change( 7, 'administrator', [] );
		$sensor->on_register( 7 );
		( new WooCommerceCustomerSensor( $recorder ) )->on_created( 7, [], false );

		$survivors = $this->survive( $recorder );

		$this->assertSame( [ 4002, 4000, 9300 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 'administrator', $survivors[1]['vars']['role'] );
	}

	/**
	 * Every role change is its own event: a merge would keep the first one's
	 * message and understate the final privilege.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_successive_role_changes_of_a_user_stay_separate_events(): void {
		$this->wp_user( 7, 'bob', [ 'administrator' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_change( 7, 'editor', [ 'subscriber' ] );
		$sensor->on_role_change( 7, 'administrator', [ 'editor' ] );

		$survivors = $this->survive( $recorder );

		$this->assertSame( [ 4002, 4002 ], array_column( $survivors, 'code' ) );
		$this->assertStringContainsString( 'to editor', $survivors[0]['message'] );
		$this->assertStringContainsString( 'to administrator', $survivors[1]['message'] );
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
	public function test_a_role_change_after_a_shop_registration_in_the_same_request_is_kept(): void {
		$this->wp_user( 7, 'bob', [ 'customer' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_change( 7, 'customer', [] );
		$sensor->on_register( 7 );
		( new WooCommerceCustomerSensor( $recorder ) )->on_created( 7, [], false );
		$sensor->on_role_change( 7, 'administrator', [ 'customer' ] );

		$survivors = $this->survive( $recorder );

		$this->assertSame( [ 9300, 4002 ], array_column( $survivors, 'code' ) );
		$this->assertSame(
			[
				[
					'field' => 'role',
					'old'   => 'customer',
					'new'   => 'administrator',
				],
			],
			$survivors[1]['changes']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_change_after_a_registration_in_the_same_request_is_kept(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_change( 7, 'subscriber', [] );
		$sensor->on_register( 7 );
		$sensor->on_role_change( 7, 'administrator', [ 'subscriber' ] );

		$survivors = $this->survive( $recorder );

		$this->assertSame( [ 4000, 4002 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 'administrator', $survivors[1]['vars']['new_role'] );
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
		$sensor->on_role_removed( 7, 'subscriber' );
		$sensor->on_role_added( 7, 'editor' );
		$sensor->on_role_change( 7, 'editor', [ 'subscriber' ] );
		$sensor->on_profile_update( 7, $old, [] );
		$sensor->emit_pending_role_changes();

		$this->assertSame( [ 4002, 4005 ], array_column( $this->survive( $recorder ), 'code' ) );
	}

	// ---- roles given or taken one by one ------------------------------------

	/**
	 * `WP_User::set_role()` fires `remove_user_role` for every old role,
	 * `add_user_role` for the new one, and then `set_user_role`. The last one
	 * says it all: the change is logged once.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_setting_a_role_logs_one_role_change(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_removed( 7, 'subscriber' );
		$sensor->on_role_added( 7, 'editor' );
		$sensor->on_role_change( 7, 'editor', [ 'subscriber' ] );
		$sensor->emit_pending_role_changes();

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 4002 ], array_column( $survivors, 'code' ) );
		$this->assertSame(
			[
				[
					'field' => 'role',
					'old'   => 'subscriber',
					'new'   => 'editor',
				],
			],
			$survivors[0]['changes']
		);
	}

	/**
	 * `set_role()` strips every old role, one `remove_user_role` each.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_setting_a_role_over_several_old_ones_logs_one_role_change(): void {
		$this->wp_user( 7, 'bob', [ 'administrator' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_removed( 7, 'subscriber' );
		$sensor->on_role_removed( 7, 'author' );
		$sensor->on_role_added( 7, 'administrator' );
		$sensor->on_role_change( 7, 'administrator', [ 'subscriber', 'author' ] );
		$sensor->emit_pending_role_changes();

		$this->assertSame( [ 4002 ], array_column( $this->survive( $recorder ), 'code' ) );
	}

	/**
	 * Setting a role the user already has among others only strips the
	 * others: there is no `add_user_role`.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_narrowing_down_to_a_role_the_user_already_has_logs_one_role_change(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_removed( 7, 'subscriber' );
		$sensor->on_role_change( 7, 'editor', [ 'subscriber', 'editor' ] );
		$sensor->emit_pending_role_changes();

		$this->assertSame( [ 4002 ], array_column( $this->survive( $recorder ), 'code' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_added_on_its_own_is_a_role_change_listing_every_role(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber', 'administrator' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_added( 7, 'administrator' );

		$this->assertSame( [], $recorder->calls, 'Nothing goes out before shutdown.' );

		$sensor->emit_pending_role_changes();

		$this->assertSame( [ 4002 ], array_column( $recorder->calls, 0 ) );
		$fields = $recorder->calls[0][1];
		$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $fields['target'] );
		$this->assertSame( 'bob', $fields['username'] );
		$this->assertArrayNotHasKey( 'actor', $fields );
		$this->assertSame(
			[
				[
					'field' => 'role',
					'old'   => 'subscriber',
					'new'   => 'administrator, subscriber',
				],
			],
			$fields['changes']
		);

		$this->assertSame( [ 'added_role' => 'administrator' ], $fields['metadata'] );

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 4002 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 'Role for bob changed from subscriber to administrator, subscriber.', $survivors[0]['message'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_added_to_a_user_without_roles_has_no_old_role(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_added( 7, 'editor' );
		$sensor->emit_pending_role_changes();

		$fields = $recorder->calls[0][1];
		$this->assertNull( $fields['old_role'] );
		$this->assertSame( 'editor', $fields['new_role'] );
		$this->assertSame(
			[
				[
					'field' => 'role',
					'old'   => null,
					'new'   => 'editor',
				],
			],
			$fields['changes']
		);

		$survivors = $this->survive( $recorder );
		$this->assertSame( '', $survivors[0]['vars']['old_role'] );
		$this->assertStringNotContainsString( '{', $survivors[0]['message'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_removed_on_its_own_is_a_role_change_listing_every_role(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_removed( 7, 'editor' );
		$sensor->emit_pending_role_changes();

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 4002 ], array_column( $survivors, 'code' ) );
		$this->assertSame(
			[
				[
					'field' => 'role',
					'old'   => 'editor, subscriber',
					'new'   => 'subscriber',
				],
			],
			$survivors[0]['changes']
		);
		$this->assertSame( [ 'removed_role' => 'editor' ], $survivors[0]['metadata'] );
	}

	/**
	 * Role names are array keys in WordPress, so a role named by digits comes
	 * to `set_user_role` as a number.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_setting_a_role_over_a_role_named_by_digits_logs_one_role_change(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_removed( 7, '2024' );
		$sensor->on_role_added( 7, 'editor' );
		$sensor->on_role_change( 7, 'editor', [ 2024 ] );
		$sensor->emit_pending_role_changes();

		$this->assertSame( [ 4002 ], array_column( $this->survive( $recorder ), 'code' ) );
	}

	/**
	 * Each role given or taken is its own step, read when it happens.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_roles_added_one_after_the_other_are_separate_steps(): void {
		$this->wp_user( 7, 'bob', [ 'editor', 'subscriber' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_added( 7, 'editor' );
		$this->wp_user( 7, 'bob', [ 'editor', 'subscriber', 'shop_manager' ] );
		$sensor->on_role_added( 7, 'shop_manager' );
		$sensor->emit_pending_role_changes();

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 4002, 4002 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 'subscriber', $survivors[0]['changes'][0]['old'] );
		$this->assertSame( 'editor, subscriber', $survivors[0]['changes'][0]['new'] );
		$this->assertSame( 'editor, subscriber', $survivors[1]['changes'][0]['old'] );
		$this->assertSame( 'editor, shop_manager, subscriber', $survivors[1]['changes'][0]['new'] );
	}

	/**
	 * A role a plugin adds on its own stays logged when `set_role()` runs
	 * later in the same request: only what `set_role()` itself fired is
	 * covered by its role change.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_added_on_its_own_is_kept_next_to_a_later_set_role(): void {
		$this->wp_user( 7, 'bob', [ 'shop_manager', 'subscriber' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_added( 7, 'shop_manager' );
		$this->wp_user( 7, 'bob', [ 'editor' ] );
		$sensor->on_role_removed( 7, 'subscriber' );
		$sensor->on_role_removed( 7, 'shop_manager' );
		$sensor->on_role_added( 7, 'editor' );
		$sensor->on_role_change( 7, 'editor', [ 'shop_manager', 'subscriber' ] );
		$sensor->emit_pending_role_changes();

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 4002, 4002 ], array_column( $survivors, 'code' ) );
		$this->assertSame( 'editor', $survivors[0]['changes'][0]['new'], 'The set_role() change, dispatched at once.' );
		$this->assertSame( 'shop_manager, subscriber', $survivors[1]['changes'][0]['new'], 'The earlier grant, at shutdown.' );
	}

	/**
	 * Core sets the first role of a new user with `set_role()`: the
	 * registration still leaves only the registration.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_registration_with_the_role_hooks_core_fires_leaves_the_registration(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_added( 7, 'subscriber' );
		$sensor->on_role_change( 7, 'subscriber', [] );
		$sensor->on_register( 7 );
		$sensor->emit_pending_role_changes();

		$this->assertSame( [ 4000 ], array_column( $this->survive( $recorder ), 'code' ) );
	}

	/**
	 * A role change on one site of a network does not cover a role given on
	 * another, and the role given there is logged on that site.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_given_on_another_site_is_logged_on_that_site(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		switch_to_blog( 2 );
		$sensor->on_role_added( 7, 'editor' );
		restore_current_blog();
		$sensor->on_role_change( 7, 'editor', [ 'subscriber' ] );
		$sensor->emit_pending_role_changes();

		$this->assertSame( [ 4002, 4002 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ 1, 2 ], $recorder->blogs );
		$this->assertSame( 1, WpState::$blog_id, 'Back on the site of the request.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pending_role_changes_go_out_once(): void {
		$this->wp_user( 7, 'bob', [ 'editor', 'subscriber' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_added( 7, 'editor' );
		$sensor->emit_pending_role_changes();
		$sensor->emit_pending_role_changes();

		$this->assertCount( 1, $recorder->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_given_to_an_unknown_user_emits_nothing(): void {
		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_added( 99, 'editor' );
		$sensor->emit_pending_role_changes();

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_given_to_a_user_that_cannot_be_read_is_reported_and_never_thrown(): void {
		$user              = $this->unreadable_user( 7 );
		WpState::$users[7] = $user;
		$recorder          = $this->recorder();
		$this->failure_listener();

		$sensor = new UserProfileSensor( $recorder );
		$sensor->on_role_added( 7, 'subscriber' );
		$sensor->emit_pending_role_changes();

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 'hellolog_dispatch_failed', WpCalls::$actions[0][0] );
		$this->assertSame( 4002, WpCalls::$actions[0][1][1] );
		$this->assertSame(
			[
				'user_id' => 7,
				'role'    => 'subscriber',
			],
			WpCalls::$actions[0][1][2]
		);
	}

	/**
	 * WordPress only lists the roles it knows among a user's roles: giving a
	 * role it does not know changes none of them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_wordpress_does_not_know_is_no_role_change(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_role_added( 7, 'no_such_role' );
		$sensor->emit_pending_role_changes();

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_role_hooks_and_the_shutdown_emission_are_registered(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		( new UserProfileSensor( $this->recorder() ) )->boot();

		$this->assertSame( 2, WpState::$actions['add_user_role']['accepted_args'] );
		$this->assertSame( 2, WpState::$actions['remove_user_role']['accepted_args'] );
		$this->assertSame( 3, WpState::$actions['set_user_role']['accepted_args'] );
		// Before the buffer is written at priority 1, and again before the
		// final flush at PHP_INT_MAX for notes made by later callbacks.
		$this->assertSame( [ 0, PHP_INT_MAX - 1 ], array_column( WpState::$callbacks['shutdown'], 'priority' ) );
		// When a job ends, before its context closes at PHP_INT_MAX.
		foreach ( [ 'action_scheduler_after_execute', 'action_scheduler_failed_execution' ] as $hook ) {
			$this->assertSame(
				[
					'priority'      => PHP_INT_MAX - 1,
					'accepted_args' => 0,
				],
				WpState::$actions[ $hook ],
				$hook
			);
		}
	}

	/**
	 * A role a shutdown callback gives (after the first emission) still goes
	 * out, before the final flush.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_given_by_a_later_shutdown_callback_still_goes_out(): void {
		$this->wp_user( 7, 'bob', [ 'administrator', 'subscriber' ] );
		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->boot();
		add_action( 'shutdown', static fn() => $sensor->on_role_added( 7, 'administrator' ), 5 );

		Hooks::fire( 'shutdown' );

		$this->assertSame( [ 4002 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 'administrator, subscriber', $recorder->calls[0][1]['changes'][0]['new'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function job_ends(): array {
		return [
			'a job that succeeds' => [ 'action_scheduler_after_execute' ],
			'a job that fails'    => [ 'action_scheduler_failed_execution' ],
		];
	}

	/**
	 * A role an Action Scheduler job gives on its own goes out when the job
	 * ends, while the job is still the context: the event comes from the job
	 * and keeps who set it in motion, instead of reading as the doing of
	 * whoever loaded the page that started the queue runner.
	 *
	 * @dataProvider job_ends
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $end The hook that ends the job.
	 */
	public function test_a_role_a_job_gives_goes_out_when_the_job_ends_as_the_job( string $end ): void {
		$this->wp_user( 7, 'bob', [ 'administrator', 'subscriber' ] );
		$recorder = new BuildingRecorder();
		ActionSchedulerContext::register();
		$sensor = new UserProfileSensor( $recorder );
		$sensor->boot();

		Hooks::fire( 'action_scheduler_begin_execute', 41, 'WP Cron' );
		$sensor->on_role_added( 7, 'administrator' );
		Hooks::fire( $end, 41, 'action_scheduler_failed_execution' === $end ? new \RuntimeException( 'failed' ) : null, 'WP Cron' );

		$this->assertSame( [ 4002 ], array_column( $recorder->payloads, 'code' ) );
		$this->assertSame( 'action-scheduler', $recorder->payloads[0]['source'] );
		$this->assertSame( 'admin', $recorder->payloads[0]['metadata']['initiated_by'] );
		$this->assertArrayNotHasKey( 'user', $recorder->payloads[0] );

		Hooks::fire( 'shutdown' );
		$this->assertCount( 1, $recorder->payloads, 'Not again at shutdown.' );
	}

	/**
	 * Only what the job did goes out when it ends: a role given before the
	 * job began waits for shutdown, outside the job.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_given_before_a_job_waits_for_shutdown(): void {
		$this->wp_user( 7, 'bob', [ 'administrator', 'subscriber' ] );
		$recorder = new BuildingRecorder();
		ActionSchedulerContext::register();
		$sensor = new UserProfileSensor( $recorder );
		$sensor->boot();

		$sensor->on_role_added( 7, 'administrator' );
		Hooks::fire( 'action_scheduler_begin_execute', 41, 'WP Cron' );
		Hooks::fire( 'action_scheduler_after_execute', 41, null, 'WP Cron' );
		$this->assertSame( [], $recorder->payloads );

		Hooks::fire( 'shutdown' );
		$this->assertSame( [ 4002 ], array_column( $recorder->payloads, 'code' ) );
		$this->assertNotSame( 'action-scheduler', $recorder->payloads[0]['source'] );
	}

	// ---- profile fields kept in user meta -----------------------------------

	/**
	 * `update_user_meta` fires before the write, so the stored value is still
	 * the one before.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_first_name_edit_is_a_profile_change_from_the_stored_value(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );
		WpState::$user_meta[7]['first_name'] = 'Bob';

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_meta_updated( 11, 7, 'first_name', 'Robert' );

		$this->assertSame( [ 4001 ], array_column( $recorder->calls, 0 ) );
		$fields = $recorder->calls[0][1];
		$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $fields['target'] );
		$this->assertSame( 'bob', $fields['username'] );
		$this->assertArrayNotHasKey( 'actor', $fields );
		$this->assertSame(
			[
				[
					'field' => 'first_name',
					'old'   => 'Bob',
					'new'   => 'Robert',
				],
			],
			$fields['changes']
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function profile_meta_keys(): array {
		return [
			'first name' => [ 'first_name' ],
			'last name'  => [ 'last_name' ],
			'nickname'   => [ 'nickname' ],
		];
	}

	/**
	 * @dataProvider profile_meta_keys
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_every_name_field_in_user_meta_is_a_profile_change( string $key ): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );
		WpState::$user_meta[7][ $key ] = 'before';

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_meta_updated( 11, 7, $key, 'after' );

		$this->assertSame( $key, $recorder->calls[0][1]['changes'][0]['field'] );
	}

	/**
	 * The biography is text somebody wrote: only that it changed is told.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_biography_edit_is_flagged_without_its_text(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );
		WpState::$user_meta[7]['description'] = 'Old bio.';

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_meta_updated( 11, 7, 'description', 'New bio.' );

		$this->assertSame(
			[
				[
					'field' => 'description',
					'old'   => null,
					'new'   => null,
				],
			],
			$recorder->calls[0][1]['changes']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_other_user_meta_is_not_a_profile_change(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_meta_updated( 11, 7, 'rich_editing', 'false' );
		$sensor->on_meta_updated( 12, 7, '_two_factor_enabled_providers', [ 'Two_Factor_Totp' ] );
		$sensor->on_meta_added( 13, 7, 'session_tokens', [ 'abc' => [] ] );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_name_saved_unchanged_is_not_a_profile_change(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );
		WpState::$user_meta[7]['last_name'] = 'Smith';

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_meta_updated( 11, 7, 'last_name', 'Smith' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * A user created without the row (an import, a registration plugin) gets
	 * it added on the first save, through `added_user_meta`.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_first_name_added_to_a_user_without_one_has_no_value_before(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_meta_added( 11, 7, 'first_name', 'Bob' );

		$this->assertSame( [ 4001 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame(
			[
				[
					'field' => 'first_name',
					'old'   => null,
					'new'   => 'Bob',
				],
			],
			$recorder->calls[0][1]['changes']
		);
	}

	/**
	 * The first save writes every row, empty ones included; an empty name
	 * that was missing before is no change.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_empty_name_added_to_a_user_without_one_is_not_a_change(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );

		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_meta_added( 11, 7, 'last_name', '' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_name_edit_of_an_unknown_user_emits_nothing(): void {
		$recorder = $this->recorder();
		( new UserProfileSensor( $recorder ) )->on_meta_updated( 11, 99, 'first_name', 'Robert' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * A profile save writes the meta first and fires `profile_update` last;
	 * the request leaves one profile event with every change.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_name_edits_and_the_profile_update_fold_into_one_profile_event(): void {
		$old = $this->wp_user( 7, 'bob', [ 'subscriber' ], [ 'display_name' => 'Bob' ], false );
		$this->wp_user( 7, 'bob', [ 'subscriber' ], [ 'display_name' => 'Robert Smith' ] );
		WpState::$user_meta[7] = [
			'first_name' => 'Bob',
			'last_name'  => '',
		];

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_meta_updated( 11, 7, 'first_name', 'Robert' );
		$sensor->on_meta_updated( 12, 7, 'last_name', 'Smith' );
		$sensor->on_profile_update( 7, $old, [] );

		$survivors = $this->survive( $recorder );
		$this->assertSame( [ 4001 ], array_column( $survivors, 'code' ) );
		$this->assertSame( [ 'first_name', 'last_name', 'display_name' ], array_column( $survivors[0]['changes'], 'field' ) );
		$this->assertSame( UserProfileSensor::user_target( 7, 'bob' ), $survivors[0]['target'] );
	}

	/**
	 * Core writes the meta of a new user before `user_register`.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_names_of_a_new_user_leave_the_registration(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );

		$recorder = $this->recorder();
		$sensor   = new UserProfileSensor( $recorder );
		$sensor->on_meta_added( 11, 7, 'nickname', 'bob' );
		$sensor->on_meta_added( 12, 7, 'first_name', 'Bob' );
		$sensor->on_role_added( 7, 'subscriber' );
		$sensor->on_role_change( 7, 'subscriber', [] );
		$sensor->on_register( 7 );
		$sensor->emit_pending_role_changes();

		$this->assertSame( [ 4000 ], array_column( $this->survive( $recorder ), 'code' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_name_that_cannot_be_read_is_reported_and_never_thrown(): void {
		$this->wp_user( 7, 'bob', [ 'subscriber' ] );
		$recorder = $this->recorder();
		$this->failure_listener();

		$unreadable = new class() implements \Stringable {
			public function __toString(): string {
				throw new \RuntimeException( 'unreadable' );
			}
		};
		( new UserProfileSensor( $recorder ) )->on_meta_updated( 11, 7, 'first_name', $unreadable );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 'hellolog_dispatch_failed', WpCalls::$actions[0][0] );
		$this->assertSame( 4001, WpCalls::$actions[0][1][1] );
		$this->assertSame(
			[
				'user_id' => 7,
				'field'   => 'first_name',
			],
			WpCalls::$actions[0][1][2]
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_user_meta_hooks_accept_the_key_and_the_value(): void {
		$this->wp_user( 7, 'bob', [ 'editor' ] );

		( new UserProfileSensor( $this->recorder() ) )->boot();

		$this->assertSame( 4, WpState::$actions['update_user_meta']['accepted_args'] );
		$this->assertSame( 4, WpState::$actions['added_user_meta']['accepted_args'] );
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
	 * A user whose login can not be read: turning it into text throws. Only
	 * call this from a test of its own process.
	 *
	 * @param int $id User id.
	 */
	private function unreadable_user( int $id ): \WP_User {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';

		return new \WP_User(
			[
				'ID'         => $id,
				'user_login' => new class() implements \Stringable {
					public function __toString(): string {
						throw new \RuntimeException( 'unreadable' );
					}
				},
				'roles'      => [ 'subscriber' ],
			]
		);
	}

	/**
	 * Load the `do_action()` stand-in, which records the failure reports.
	 * Only call this from a test of its own process.
	 */
	private function failure_listener(): void {
		require_once __DIR__ . '/../../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';
	}

	/**
	 * A dispatcher that records every call as `[ code, fields ]`, and the
	 * site each one was dispatched on in `blogs`.
	 */
	private function recorder(): EventDispatcher {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';

		return new class() implements EventDispatcher {

			/** @var array<int, array{0: int, 1: array<string, mixed>}> */
			public array $calls = [];

			/** @var array<int, int> */
			public array $blogs = [];

			public function dispatch( int $code, array $fields = [] ): void {
				$this->calls[] = [ $code, $fields ];
				$this->blogs[] = get_current_blog_id();
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
