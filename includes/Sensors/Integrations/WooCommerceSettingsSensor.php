<?php
/**
 * WooCommerce: settings options changes.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\OptionChangePolicy;
use HelloLog\Sensors\SensitiveOption;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Logs option mutations from WC's General / Shipping / Tax / Payments
 * screens. Listens to `updated_option` and filters by the `woocommerce_`
 * prefix so we don't echo every core option. Only a person saving a setting
 * is logged, see {@see OptionChangePolicy}.
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
		// Reading the values of a write can fail (an object whose text throws);
		// that must never reach the request that saved the setting.
		try {
			if ( ! str_starts_with( $option, 'woocommerce_' ) ) {
				return;
			}
			if ( $old_value === $value ) {
				return;
			}
			$changes = OptionChangePolicy::saved_changes( $option, $old_value, $value );
			if ( [] === $changes ) {
				return;
			}

			$this->emit(
				9400,
				[
					'option'   => $option,
					'target'   => OptionChangePolicy::target( $option ),
					'changes'  => $changes,
					'metadata' => array_merge(
						[
							'option' => $option,
							'old'    => SensitiveOption::value( $option, $old_value ),
							'new'    => SensitiveOption::value( $option, $value ),
						],
						OptionChangePolicy::request_metadata()
					),
				]
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9400, [ 'option' => $option ] );
		}
	}
}
