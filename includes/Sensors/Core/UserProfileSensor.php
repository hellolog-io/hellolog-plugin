<?php
/**
 * Sensor: user registration, profile edits, role changes, deletion.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Events\ChangeSet;
use HelloLog\Sensors\AbstractSensor;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Catches user-lifecycle events: register, profile_update, role change,
 * and deletion.
 *
 * Every event names the affected user as its `target` and leaves the actor to
 * the request's current user: an admin editing a profile or adding a user, for
 * instance. Nobody is the actor when nobody is signed in: a visitor who
 * registers themselves is not the current user during `user_register`, and
 * cron has no user at all. The other user sensors build the same target with
 * {@see self::user_target()}.
 *
 * WordPress fires `set_user_role` for the first role of a new user BEFORE
 * `user_register`, so a registration also produces a role change. This
 * sensor does not try to tell them apart: both events share the target, and
 * the per-request rules drop that first role change when it is only a plain
 * default role (`subscriber`, `customer`), since the registration names it.
 * Any other role, and any change from a role the user already had, stays.
 */
final class UserProfileSensor extends AbstractSensor {

	/**
	 * User fields compared by a profile update, in the order they are reported.
	 * The password hash is compared too, but only ever reported as "changed".
	 *
	 * @var array<int, string>
	 */
	private const PROFILE_FIELDS = [ 'user_email', 'display_name', 'user_url', 'user_nicename' ];

	public function key(): string {
		return 'core-user-profile';
	}

	public function boot(): void {
		add_action( 'user_register', [ $this, 'on_register' ], 10, 1 );
		add_action( 'profile_update', [ $this, 'on_profile_update' ], 10, 3 );
		add_action( 'set_user_role', [ $this, 'on_role_change' ], 10, 3 );
		add_action( 'delete_user', [ $this, 'on_delete' ], 10, 1 );
	}

	public function on_register( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}
		$this->emit(
			4000,
			[
				'target'   => self::user_target( $user_id, (string) $user->user_login ),
				'username' => (string) $user->user_login,
				'role'     => $user->roles[0] ?? '',
				'metadata' => [
					'email'        => (string) $user->user_email,
					'display_name' => (string) $user->display_name,
				],
			]
		);
	}

	/**
	 * @param mixed                $old_user_data The user before the update (a WP_User); absent on very old cores.
	 * @param array<string, mixed> $userdata      The raw input of the update. Not read: it can hold a plaintext password.
	 */
	public function on_profile_update( int $user_id, $old_user_data = null, array $userdata = [] ): void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		// Without the user before there is nothing to compare, so the event goes
		// out with no changes and the per-request rules drop it.
		$changes = [];
		if ( $old_user_data instanceof WP_User ) {
			$before = self::snapshot( $old_user_data );

			// Core slashes this one value before the update, to compare it with
			// the slashed input, and passes the same object on to this hook.
			$before['user_email'] = wp_unslash( $before['user_email'] );

			$changes = self::profile_changes( $before, self::snapshot( $user ) );
		}

		$this->emit(
			4001,
			[
				'target'   => self::user_target( $user_id, (string) $user->user_login ),
				'username' => (string) $user->user_login,
				'changes'  => $changes,
			]
		);
	}

	/**
	 * @param array<int, string> $old_roles
	 */
	public function on_role_change( int $user_id, string $new_role, array $old_roles ): void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}
		$old_roles = array_values( $old_roles );
		$this->emit(
			4002,
			[
				'target'   => self::user_target( $user_id, (string) $user->user_login ),
				'username' => (string) $user->user_login,
				'old_role' => $old_roles[0] ?? '',
				'new_role' => $new_role,
				'changes'  => [
					[
						'field' => 'role',
						'old'   => implode( ', ', $old_roles ),
						'new'   => $new_role,
					],
				],
				'metadata' => [
					'old_roles' => $old_roles,
					'new_role'  => $new_role,
				],
			]
		);
	}

	public function on_delete( int $user_id ): void {
		$user  = get_user_by( 'id', $user_id );
		$login = $user instanceof WP_User ? (string) $user->user_login : '';
		$this->emit(
			4003,
			[
				'target'   => self::user_target( $user_id, $login ),
				'username' => '' !== $login ? $login : '#' . $user_id,
				'metadata' => [ 'deleted_user_id' => $user_id ],
			]
		);
	}

	/**
	 * The object an event about a user is about.
	 *
	 * @param int    $id    User id.
	 * @param string $login Login name; empty when the user could not be loaded.
	 * @return array{type: string, id: string, label: string}
	 */
	public static function user_target( int $id, string $login ): array {
		return [
			'type'  => 'user',
			'id'    => (string) $id,
			'label' => $login,
		];
	}

	/**
	 * The actor of an event whose user is not (yet, or any longer) the
	 * current one: a login, a logout, an application-password sign-in.
	 *
	 * @param int                $id       User id.
	 * @param string             $username Login name.
	 * @param array<int, string> $roles    Role slugs.
	 * @return array{id: int, username: string, roles: array<int, string>}
	 */
	public static function user_actor( int $id, string $username, array $roles ): array {
		return [
			'id'       => $id,
			'username' => $username,
			'roles'    => array_values( $roles ),
		];
	}

	/**
	 * What a profile update changed, as a change list.
	 *
	 * Compares the email, display name, URL and nicename. A different
	 * `user_pass` (the password hash) comes out as a `password` item with no
	 * values: the hash itself is never reported.
	 *
	 * @param array<string, mixed> $old User fields before the update.
	 * @param array<string, mixed> $new User fields after the update.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function profile_changes( array $old, array $new ): array {
		$old['password'] = $old['user_pass'] ?? null;
		$new['password'] = $new['user_pass'] ?? null;

		return ChangeSet::diff( $old, $new, array_merge( self::PROFILE_FIELDS, [ 'password' ] ) );
	}

	/**
	 * The compared fields of a user, by name.
	 *
	 * @return array<string, string>
	 */
	private static function snapshot( WP_User $user ): array {
		$values = [];
		foreach ( array_merge( self::PROFILE_FIELDS, [ 'user_pass' ] ) as $field ) {
			$values[ $field ] = (string) $user->$field;
		}

		return $values;
	}
}
