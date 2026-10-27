<?php
/**
 * Sensor: user registration, profile edits, password and e-mail changes,
 * role changes, deletion.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Events\ActionSchedulerContext;
use HelloLog\Events\ChangeSet;
use HelloLog\Sensors\AbstractSensor;
use Throwable;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Catches user-lifecycle events: register, profile_update, password and
 * e-mail changes, role changes, and deletion.
 *
 * Every event names the affected user as its `target` and leaves the actor to
 * the request's current user: an admin editing a profile or adding a user, for
 * instance. Nobody is the actor when nobody is signed in: a visitor who
 * registers themselves is not the current user during `user_register`, and
 * cron has no user at all. The other user sensors build the same target with
 * {@see self::user_target()}.
 *
 * A profile save is told in up to three events: a new e-mail address (4005)
 * and a new password (4004) are events of their own, and every other field
 * is the profile update (4001). The name fields and the biography live in
 * user meta, which core writes before it fires `profile_update`, so one save
 * can make several profile updates; the per-request rules fold them into one.
 *
 * WordPress fires `set_user_role` for the first role of a new user BEFORE
 * `user_register`, so a registration also produces a role change. This
 * sensor does not try to tell them apart: both events share the target, and
 * the per-request rules drop that first role change when it is only a plain
 * default role (`subscriber`, `customer`), since the registration names it.
 * Any other role, and any change from a role the user already had, stays.
 *
 * `set_role()` fires `remove_user_role` for every old role and
 * `add_user_role` for the new one before `set_user_role`, which already
 * tells the whole change. So a role given or taken on its own (`add_role()`,
 * `remove_role()`) is only noted when it happens; `set_user_role` strikes
 * the notes `set_role()` made, and the rest go out at shutdown, before the
 * request's events are written (and again, before the final flush, for the
 * notes later shutdown callbacks made). What an Action Scheduler job noted
 * goes out when the job ends, while it is still the job's context: the
 * event then comes from the job, not from whoever loaded the page that
 * started the queue runner.
 */
final class UserProfileSensor extends AbstractSensor {

	/**
	 * User fields compared by a profile update, in the order they are reported.
	 * The password hash is compared too, but only ever reported as "changed".
	 *
	 * @var array<int, string>
	 */
	private const PROFILE_FIELDS = [ 'user_email', 'display_name', 'user_url', 'user_nicename' ];

	/**
	 * Profile fields kept in user meta.
	 *
	 * @var array<int, string>
	 */
	private const PROFILE_META = [ 'first_name', 'last_name', 'nickname', 'description' ];

	/**
	 * Profile meta that is text somebody wrote: reported as changed, without
	 * its values.
	 *
	 * @var array<int, string>
	 */
	private const CONTENT_META = [ 'description' ];

	/**
	 * The change item of a new password: it never carries a value.
	 *
	 * @var array{field: string, old: null, new: null}
	 */
	private const PASSWORD_CHANGE = [
		'field' => 'password',
		'old'   => null,
		'new'   => null,
	];

	/**
	 * Roles given or taken one by one in this request and not yet emitted,
	 * oldest first. Each note keeps the site it happened on, the user's
	 * roles before and after, as they were at that moment, and how many
	 * Action Scheduler jobs were running (see {@see ActionSchedulerContext::depth()}).
	 *
	 * @var array<int, array{blog: int, user: int, login: string, added: bool, role: string, old: string, new: string, job: int}>
	 */
	private static array $pending_roles = [];

	public function key(): string {
		return 'core-user-profile';
	}

	public function boot(): void {
		add_action( 'user_register', [ $this, 'on_register' ], 10, 1 );
		add_action( 'profile_update', [ $this, 'on_profile_update' ], 10, 3 );
		add_action( 'update_user_meta', [ $this, 'on_meta_updated' ], 10, 4 );
		add_action( 'added_user_meta', [ $this, 'on_meta_added' ], 10, 4 );
		add_action( 'add_user_role', [ $this, 'on_role_added' ], 10, 2 );
		add_action( 'remove_user_role', [ $this, 'on_role_removed' ], 10, 2 );
		add_action( 'set_user_role', [ $this, 'on_role_change' ], 10, 3 );
		add_action( 'delete_user', [ $this, 'on_delete' ], 10, 3 );
		// Before the request's events are written, at priority 1.
		add_action( 'shutdown', [ $this, 'emit_pending_role_changes' ], 0 );
		// Notes made by later shutdown callbacks, before the final flush at
		// PHP_INT_MAX.
		add_action( 'shutdown', [ $this, 'emit_pending_role_changes' ], PHP_INT_MAX - 1 );
		// What a job noted, before its context ends at PHP_INT_MAX.
		add_action( 'action_scheduler_after_execute', [ $this, 'emit_job_role_changes' ], PHP_INT_MAX - 1, 0 );
		add_action( 'action_scheduler_failed_execution', [ $this, 'emit_job_role_changes' ], PHP_INT_MAX - 1, 0 );
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
					'roles'        => array_values( (array) $user->roles ),
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

		// Without the user before there is nothing to compare, so the profile
		// update goes out with no changes and the per-request rules drop it.
		$events = self::profile_events( [] );
		if ( $old_user_data instanceof WP_User ) {
			$before = self::snapshot( $old_user_data );

			// Core slashes this one value before the update, to compare it with
			// the slashed input, and passes the same object on to this hook.
			$before['user_email'] = wp_unslash( $before['user_email'] );

			$events = self::profile_events( self::profile_changes( $before, self::snapshot( $user ) ) );
		}

		$fields = [
			'target'   => self::user_target( $user_id, (string) $user->user_login ),
			'username' => (string) $user->user_login,
		];
		if ( null !== $events['email'] ) {
			$this->emit( 4005, $fields + [ 'changes' => [ $events['email'] ] ] );
		}
		if ( $events['password'] ) {
			$this->emit( 4004, $fields + [ 'changes' => [ self::PASSWORD_CHANGE ] ] );
		}
		$this->emit( 4001, $fields + [ 'changes' => $events['profile'] ] );
	}

	/**
	 * A profile field kept in user meta is about to be overwritten. Core
	 * fires this before the write, so the stored value is still the old one.
	 *
	 * @param mixed $meta_id    Row id; not used.
	 * @param mixed $meta_value The new value.
	 */
	public function on_meta_updated( $meta_id, int $user_id, string $meta_key, $meta_value ): void {
		if ( ! in_array( $meta_key, self::PROFILE_META, true ) ) {
			return;
		}

		// Reading the values of a write can fail (an object whose text
		// throws); that must never reach the request that saved the profile.
		try {
			$this->emit_meta_change( $user_id, $meta_key, get_user_meta( $user_id, $meta_key, true ), $meta_value );
		} catch ( Throwable $e ) {
			$this->report_failure(
				$e,
				4001,
				[
					'user_id' => $user_id,
					'field'   => $meta_key,
				]
			);
		}
	}

	/**
	 * A profile field kept in user meta was stored for the first time: a user
	 * created without it (an import, a registration plugin) is saved. An
	 * empty value is no change, the missing field read as empty already.
	 *
	 * @param mixed $meta_id    Row id; not used.
	 * @param mixed $meta_value The value.
	 */
	public function on_meta_added( $meta_id, int $user_id, string $meta_key, $meta_value ): void {
		if ( ! in_array( $meta_key, self::PROFILE_META, true ) || null === $meta_value || '' === $meta_value ) {
			return;
		}

		try {
			$this->emit_meta_change( $user_id, $meta_key, null, $meta_value );
		} catch ( Throwable $e ) {
			$this->report_failure(
				$e,
				4001,
				[
					'user_id' => $user_id,
					'field'   => $meta_key,
				]
			);
		}
	}

	/**
	 * A role was given to a user. Only noted: see the class description.
	 */
	public function on_role_added( int $user_id, string $role ): void {
		$this->note_role( $user_id, $role, true );
	}

	/**
	 * A role was taken from a user. Only noted: see the class description.
	 */
	public function on_role_removed( int $user_id, string $role ): void {
		$this->note_role( $user_id, $role, false );
	}

	/**
	 * @param array<int, string> $old_roles
	 */
	public function on_role_change( int $user_id, string $new_role, array $old_roles ): void {
		// A role named by digits comes as a number: role names are array keys.
		$old_roles = array_values( array_map( static fn( $role ): string => is_scalar( $role ) ? (string) $role : '', $old_roles ) );
		self::strike_set_role_notes( $user_id, $new_role, $old_roles );

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}
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
						'old'   => self::role_list( $old_roles ),
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

	/**
	 * Emit the roles given or taken on their own in this request, each on the
	 * site it happened on. Runs at shutdown, twice: what the first run
	 * emitted is not emitted again.
	 */
	public function emit_pending_role_changes(): void {
		$this->emit_role_notes( 0 );
	}

	/**
	 * Emit the roles given or taken on their own by the Action Scheduler job
	 * that is ending, while it is still the context. Nothing outside a job.
	 */
	public function emit_job_role_changes(): void {
		$depth = ActionSchedulerContext::depth();
		if ( $depth > 0 ) {
			$this->emit_role_notes( $depth );
		}
	}

	/**
	 * Emit the pending notes made while at least `$depth` jobs were running,
	 * and forget them; the others stay.
	 *
	 * @param int $depth Jobs running when the note was made, at least.
	 */
	private function emit_role_notes( int $depth ): void {
		$pending = [];
		$kept    = [];
		foreach ( self::$pending_roles as $note ) {
			if ( $note['job'] >= $depth ) {
				$pending[] = $note;
			} else {
				$kept[] = $note;
			}
		}
		self::$pending_roles = $kept;

		foreach ( $pending as $note ) {
			try {
				$this->emit_on_site(
					$note['blog'],
					4002,
					[
						'target'   => self::user_target( $note['user'], $note['login'] ),
						'username' => $note['login'],
						'old_role' => $note['old'],
						'new_role' => $note['new'],
						'changes'  => [
							[
								'field' => 'role',
								'old'   => $note['old'],
								'new'   => $note['new'],
							],
						],
						'metadata' => [
							( $note['added'] ? 'added_role' : 'removed_role' ) => $note['role'],
						],
					]
				);
			} catch ( Throwable $e ) {
				$this->report_failure(
					$e,
					4002,
					[
						'user_id' => $note['user'],
						'role'    => $note['role'],
					]
				);
			}
		}
	}

	/**
	 * @param mixed $reassign Id of the user the deleted user's content goes to; null for nobody.
	 * @param mixed $user     The user being deleted (a WP_User); absent on older cores.
	 */
	public function on_delete( int $user_id, $reassign = null, $user = null ): void {
		if ( ! $user instanceof WP_User ) {
			$user = get_user_by( 'id', $user_id );
		}
		$login = $user instanceof WP_User ? (string) $user->user_login : '';
		$this->emit(
			4003,
			[
				'target'   => self::user_target( $user_id, $login ),
				'username' => '' !== $login ? $login : '#' . $user_id,
				'metadata' => [
					'deleted_user_id' => $user_id,
					'reassign_to'     => self::reassigned_to( $reassign ),
				],
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
	 * A profile update's change list split by the event each part goes to:
	 * the e-mail item (4005), whether the password changed (4004), and the
	 * other items (4001) in their order.
	 *
	 * @param array<int, array{field: string, old: ?string, new: ?string}> $changes Output of {@see self::profile_changes()}.
	 * @return array{email: ?array{field: string, old: ?string, new: ?string}, password: bool, profile: array<int, array{field: string, old: ?string, new: ?string}>}
	 */
	public static function profile_events( array $changes ): array {
		$events = [
			'email'    => null,
			'password' => false,
			'profile'  => [],
		];
		foreach ( $changes as $item ) {
			$field = $item['field'] ?? null;
			if ( 'user_email' === $field ) {
				$events['email'] = $item;
			} elseif ( 'password' === $field ) {
				$events['password'] = true;
			} else {
				$events['profile'][] = $item;
			}
		}

		return $events;
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

	/**
	 * Emit a profile update for one field kept in user meta, when its value
	 * really changes and the user exists.
	 *
	 * @param mixed $old Value before; null when there was none.
	 * @param mixed $new Value after.
	 */
	private function emit_meta_change( int $user_id, string $meta_key, $old, $new ): void {
		$changes = ChangeSet::diff( [ $meta_key => $old ], [ $meta_key => $new ], [ $meta_key ], self::CONTENT_META );
		if ( [] === $changes ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		// The same fields as the profile update of the same save, which is
		// folded into this one when it comes later.
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
	 * Note a role given or taken, with the user's roles before and after.
	 * The role hooks fire after the write, so the user read now has the roles
	 * after.
	 *
	 * @param bool $added Whether the role was given (or taken).
	 */
	private function note_role( int $user_id, string $role, bool $added ): void {
		// Reading the user can fail; that must never reach the code that
		// changed the role.
		try {
			$user = get_userdata( $user_id );
			if ( '' === $role || ! $user instanceof WP_User ) {
				return;
			}

			$after  = array_map( 'strval', array_values( (array) $user->roles ) );
			$before = $added ? array_diff( $after, [ $role ] ) : array_merge( $after, [ $role ] );
			$old    = self::role_list( $before );
			$new    = self::role_list( $after );
			if ( $old === $new ) {
				// A role WordPress does not know never shows among the user's roles.
				return;
			}

			self::$pending_roles[] = [
				'blog'  => self::blog_id(),
				'user'  => $user_id,
				'login' => (string) $user->user_login,
				'added' => $added,
				'role'  => $role,
				'old'   => $old,
				'new'   => $new,
				'job'   => ActionSchedulerContext::depth(),
			];
		} catch ( Throwable $e ) {
			$this->report_failure(
				$e,
				4002,
				[
					'user_id' => $user_id,
					'role'    => $role,
				]
			);
		}
	}

	/**
	 * Strike the notes `set_role()` made on its way to `set_user_role`: the
	 * removal of every old role but the new one, and the addition of the new
	 * role when the user did not have it. Of each kind, the latest note on
	 * this site is the one `set_role()` made.
	 *
	 * @param array<int, string> $old_roles Roles before, as `set_user_role` passes them.
	 */
	private static function strike_set_role_notes( int $user_id, string $new_role, array $old_roles ): void {
		$blog = self::blog_id();
		foreach ( $old_roles as $old_role ) {
			if ( '' !== $old_role && $old_role !== $new_role ) {
				self::strike_note( $blog, $user_id, false, $old_role );
			}
		}
		if ( '' !== $new_role && ! in_array( $new_role, $old_roles, true ) ) {
			self::strike_note( $blog, $user_id, true, $new_role );
		}
	}

	/**
	 * Remove the latest pending note that matches.
	 *
	 * @param bool $added Whether the note is of a role given (or taken).
	 */
	private static function strike_note( int $blog, int $user_id, bool $added, string $role ): void {
		for ( $i = count( self::$pending_roles ) - 1; $i >= 0; $i-- ) {
			$note = self::$pending_roles[ $i ];
			if ( $note['blog'] === $blog && $note['user'] === $user_id && $note['added'] === $added && $note['role'] === $role ) {
				array_splice( self::$pending_roles, $i, 1 );
				return;
			}
		}
	}

	/**
	 * Emit as if on the given site, so the event lands on the site where it
	 * happened; the current site is restored afterwards.
	 *
	 * @param int                  $blog_id Site id; 0 when unknown.
	 * @param int                  $code    Event code.
	 * @param array<string, mixed> $fields  Event fields.
	 */
	private function emit_on_site( int $blog_id, int $code, array $fields ): void {
		$switch = $blog_id > 0 && self::blog_id() !== $blog_id && function_exists( 'switch_to_blog' );
		if ( $switch ) {
			switch_to_blog( $blog_id );
		}

		try {
			$this->emit( $code, $fields );
		} finally {
			if ( $switch ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * The current site; 0 outside WordPress.
	 */
	private static function blog_id(): int {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	}

	/**
	 * Role slugs as the `role` change reports them: each once, sorted, joined
	 * by `, `.
	 *
	 * @param array<int, string> $roles Role slugs.
	 */
	private static function role_list( array $roles ): string {
		$roles = array_values( array_unique( $roles ) );
		sort( $roles, SORT_STRING );

		return implode( ', ', $roles );
	}

	/**
	 * Who the content of a deleted user goes to: their login, `#id` when they
	 * can not be loaded, or null when it goes to nobody.
	 *
	 * @param mixed $reassign User id, or null.
	 */
	private static function reassigned_to( $reassign ): ?string {
		if ( ! is_numeric( $reassign ) || (int) $reassign <= 0 ) {
			return null;
		}

		$user = get_user_by( 'id', (int) $reassign );

		return $user instanceof WP_User ? (string) $user->user_login : '#' . (int) $reassign;
	}
}
