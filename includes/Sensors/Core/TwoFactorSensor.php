<?php
/**
 * Sensor: WordPress 2FA toggle (core feature).
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Sensors\AbstractSensor;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks WordPress core's two-factor feature (`two_factor_user_authenticated`
 * + the related profile hooks). The standalone WP 2FA plugin gets its
 * own sensor in M12.
 */
final class TwoFactorSensor extends AbstractSensor {

	public function key(): string {
		return 'core-2fa';
	}

	public function boot(): void {
		add_action( 'two_factor_user_authenticated', [ $this, 'on_authenticated' ], 10, 2 );
		add_action( 'update_user_meta', [ $this, 'detect_profile_toggle' ], 10, 4 );
	}

	/**
	 * @param mixed $provider
	 */
	public function on_authenticated( WP_User $user, $provider ): void {
		$this->emit(
			4400,
			[
				'target'   => UserProfileSensor::user_target( (int) $user->ID, (string) $user->user_login ),
				'username' => (string) $user->user_login,
				'metadata' => [ 'provider' => is_object( $provider ) ? get_class( $provider ) : (string) $provider ],
			]
		);
	}

	/**
	 * @param mixed $meta_value
	 * @param int   $meta_id
	 */
	public function detect_profile_toggle( $meta_id, int $user_id, string $meta_key, $meta_value ): void {
		if ( '_two_factor_enabled_providers' !== $meta_key ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}
		$enabled = is_array( $meta_value ) && ! empty( $meta_value );
		$this->emit(
			$enabled ? 4400 : 4401,
			[
				'target'   => UserProfileSensor::user_target( $user_id, (string) $user->user_login ),
				'username' => (string) $user->user_login,
				'metadata' => [
					'user_id'   => $user_id,
					'providers' => $meta_value,
				],
			]
		);
	}
}
