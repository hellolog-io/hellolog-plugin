<?php
/**
 * Sensor: authentication-related events.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Sensors\AbstractSensor;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the *successful* auth hooks: login, logout, password-reset
 * requests. Failed login attempts moved to {@see FailedLoginSensor}
 * because they're noisy on internet-exposed sites and operators often
 * want them off by default.
 */
final class LoginLogoutSensor extends AbstractSensor {

	public function key(): string {
		return 'core-auth';
	}

	public function boot(): void {
		add_action( 'wp_login', [ $this, 'on_login' ], 10, 2 );
		add_action( 'wp_logout', [ $this, 'on_logout' ], 10, 1 );
		add_action( 'retrieve_password_key', [ $this, 'on_password_reset_request' ], 10, 1 );
	}

	/**
	 * The user is both the actor and the target; being signed in is not yet
	 * true when this hook fires, so the actor is passed explicitly.
	 */
	public function on_login( string $user_login, WP_User $user ): void {
		$this->emit(
			1000,
			[
				'actor'    => UserProfileSensor::user_actor( (int) $user->ID, $user_login, (array) $user->roles ),
				'target'   => UserProfileSensor::user_target( (int) $user->ID, $user_login ),
				'username' => $user_login,
			]
		);
	}

	/**
	 * Core clears the current user before this hook fires, so the actor is
	 * passed explicitly. With no known user there is neither actor nor target.
	 */
	public function on_logout( int $user_id ): void {
		$user = $user_id > 0 ? get_user_by( 'id', $user_id ) : null;
		if ( ! $user instanceof WP_User ) {
			$this->emit( 1001, [ 'username' => '' ] );
			return;
		}

		$login = (string) $user->user_login;
		$this->emit(
			1001,
			[
				'actor'    => UserProfileSensor::user_actor( (int) $user->ID, $login, (array) $user->roles ),
				'target'   => UserProfileSensor::user_target( (int) $user->ID, $login ),
				'username' => $login,
			]
		);
	}

	public function on_password_reset_request( string $username ): void {
		$this->emit(
			1003,
			[ 'username' => $username ]
		);
	}
}
