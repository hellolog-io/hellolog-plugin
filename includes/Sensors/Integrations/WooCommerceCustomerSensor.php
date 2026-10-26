<?php
/**
 * WooCommerce: customer registration / details.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\Core\UserProfileSensor;

defined( 'ABSPATH' ) || exit;

/**
 * Customer-specific WC hooks that the generic UserProfileSensor would
 * miss: created_customer fires on the shop-side registration flow and
 * carries the role marker we want to log even when the role is identical
 * to `customer` (which the core role-change hook ignores).
 */
final class WooCommerceCustomerSensor extends AbstractSensor {

	public function key(): string {
		return 'woocommerce-customer';
	}

	public function should_load(): bool {
		return class_exists( 'WooCommerce', false );
	}

	public function boot(): void {
		add_action( 'woocommerce_created_customer', [ $this, 'on_created' ], 10, 3 );
		add_action( 'woocommerce_customer_save_address', [ $this, 'on_address_saved' ], 10, 2 );
	}

	/**
	 * @param array<string, mixed> $new_customer_data
	 */
	public function on_created( int $customer_id, array $new_customer_data, bool $password_generated ): void {
		$user = get_user_by( 'id', $customer_id );
		$this->emit(
			9300,
			[
				'target'   => UserProfileSensor::user_target( $customer_id, $user ? (string) $user->user_login : '' ),
				'username' => $user ? (string) $user->user_login : '#' . $customer_id,
				'metadata' => [
					'customer_id'        => $customer_id,
					'password_generated' => $password_generated,
				],
			]
		);
	}

	public function on_address_saved( int $user_id, string $address_type ): void {
		$user = get_user_by( 'id', $user_id );
		$this->emit(
			9301,
			[
				'target'   => UserProfileSensor::user_target( $user_id, $user ? (string) $user->user_login : '' ),
				'username' => $user ? (string) $user->user_login : '#' . $user_id,
				'metadata' => [
					'customer_id'  => $user_id,
					'address_type' => $address_type,
				],
			]
		);
	}
}
