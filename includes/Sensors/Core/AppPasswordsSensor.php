<?php
/**
 * Sensor: WordPress application passwords.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Events\RequestContext;
use HelloLog\Sensors\AbstractSensor;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks the three application-password actions: created, revoked, used.
 * App passwords are programmatic credentials so each event matters.
 *
 * A client that uses an application password signs in on every request it
 * makes, so a sign-in is logged at most once an hour per user, application
 * password and IP, on each site.
 */
final class AppPasswordsSensor extends AbstractSensor {

	/** How long a logged sign-in keeps the same ones out of the log, in seconds. */
	private const SIGN_IN_WINDOW = 3600;

	public function key(): string {
		return 'core-app-passwords';
	}

	public function boot(): void {
		add_action( 'wp_create_application_password', [ $this, 'on_create' ], 10, 4 );
		add_action( 'wp_delete_application_password', [ $this, 'on_delete' ], 10, 2 );
		add_action( 'application_password_did_authenticate', [ $this, 'on_authenticate' ], 10, 2 );
	}

	/**
	 * @param array<string, mixed> $new_item
	 * @param mixed                $new_password
	 * @param array<string, mixed> $args
	 */
	public function on_create( int $user_id, array $new_item, $new_password, array $args ): void {
		$user = get_userdata( $user_id );
		$this->emit(
			4500,
			[
				'target'   => UserProfileSensor::user_target( $user_id, $user instanceof WP_User ? (string) $user->user_login : '' ),
				'name'     => (string) ( $new_item['name'] ?? '' ),
				'username' => $user instanceof WP_User ? (string) $user->user_login : '#' . $user_id,
				'metadata' => [
					'user_id' => $user_id,
					'uuid'    => (string) ( $new_item['uuid'] ?? '' ),
					'app_id'  => (string) ( $new_item['app_id'] ?? '' ),
				],
			]
		);
	}

	public function on_delete( int $user_id, string $uuid ): void {
		$user = get_userdata( $user_id );
		$this->emit(
			4501,
			[
				'target'   => UserProfileSensor::user_target( $user_id, $user instanceof WP_User ? (string) $user->user_login : '' ),
				'username' => $user instanceof WP_User ? (string) $user->user_login : '#' . $user_id,
				'metadata' => [
					'user_id' => $user_id,
					'uuid'    => $uuid,
				],
			]
		);
	}

	/**
	 * The user is both the actor and the target; this hook fires while the
	 * request is still being authenticated, before the user is the current
	 * one, so the actor is passed explicitly.
	 *
	 * @param array<string, mixed> $item
	 */
	public function on_authenticate( WP_User $user, array $item ): void {
		if ( ! $this->first_sign_in_in_window( (int) $user->ID, (string) ( $item['uuid'] ?? '' ) ) ) {
			return;
		}

		$login = (string) $user->user_login;
		$this->emit(
			4502,
			[
				'actor'    => UserProfileSensor::user_actor( (int) $user->ID, $login, (array) $user->roles ),
				'target'   => UserProfileSensor::user_target( (int) $user->ID, $login ),
				'username' => $login,
				'metadata' => [
					'name'   => (string) ( $item['name'] ?? '' ),
					'uuid'   => (string) ( $item['uuid'] ?? '' ),
					'app_id' => (string) ( $item['app_id'] ?? '' ),
				],
			]
		);
	}

	/**
	 * Whether no sign-in of this user with this application password from
	 * this IP was logged on this site within {@see self::SIGN_IN_WINDOW};
	 * when none was, this one is remembered. Kept in a transient (per site)
	 * named by a hash, so the IP is not stored in the clear.
	 *
	 * This runs inside `determine_current_user`: it reads the IP from the
	 * request and never the current user.
	 *
	 * @param int    $user_id User id.
	 * @param string $uuid    Id of the application password.
	 */
	private function first_sign_in_in_window( int $user_id, string $uuid ): bool {
		$key = 'hellolog_app_login_' . substr( hash( 'sha256', $user_id . '|' . $uuid . '|' . (string) RequestContext::client_ip() ), 0, 40 );
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, self::SIGN_IN_WINDOW );

		return true;
	}
}
