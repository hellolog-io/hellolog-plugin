<?php
/**
 * WooCommerce: settings options changes.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\SensitiveOption;

defined( 'ABSPATH' ) || exit;

/**
 * Logs option mutations from WC's General / Shipping / Tax / Payments
 * screens. Listens to `updated_option` and filters by the `woocommerce_`
 * prefix so we don't echo every core option.
 */
final class WooCommerceSettingsSensor extends AbstractSensor {

	public function key(): string {
		return 'woocommerce-settings';
	}

	public function should_load(): bool {
		return class_exists( 'WooCommerce', false );
	}

	public function boot(): void {
		add_action( 'updated_option', [ $this, 'on_update' ], 10, 3 );
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public function on_update( string $option, $old_value, $value ): void {
		if ( ! str_starts_with( $option, 'woocommerce_' ) ) {
			return;
		}
		if ( $old_value === $value ) {
			return;
		}

		$this->emit(
			9400,
			[
				'option'   => $option,
				'metadata' => [
					'option' => $option,
					'old'    => SensitiveOption::value( $option, $old_value ),
					'new'    => SensitiveOption::value( $option, $value ),
				],
			]
		);
	}
}
