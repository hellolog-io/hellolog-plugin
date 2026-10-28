<?php
/**
 * Sensor: failed login attempts.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Sensors\AbstractSensor;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Carved out of {@see LoginLogoutSensor} because failed logins are a
 * separate operational concern: noisy on any public site (bot
 * attempts), useful when you're investigating an incident, and rarely
 * worth recording continuously. Defaults to OFF — operators flip it on
 * in `Settings → Filters` when they need it.
 */
final class FailedLoginSensor extends AbstractSensor {

	public function key(): string {
		return 'core-failed-login';
	}

	public function boot(): void {
		add_action( 'wp_login_failed', [ $this, 'on_failed_login' ], 10, 1 );
	}

	/**
	 * The account somebody tried to get into is the target, found the way a
	 * login finds it: by its login name, or by its e-mail address. A name that
	 * is no account has no object to name, so the event has no target.
	 */
	public function on_failed_login( string $username ): void {
		$fields = [
			'username' => $username,
			'metadata' => [ 'attempted_username' => $username ],
		];

		$user = '' === $username ? false : get_user_by( 'login', $username );
		if ( ! $user && str_contains( $username, '@' ) ) {
			$user = get_user_by( 'email', $username );
		}
		if ( $user instanceof WP_User ) {
			$fields['target'] = UserProfileSensor::user_target( (int) $user->ID, (string) $user->user_login );
		}

		$this->emit( 1002, $fields );
	}
}
