<?php
/**
 * Registers every WooCommerce sensor with the SensorManager.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Events\EventDispatcher;
use HelloLog\Sensors\SensorManager;

defined( 'ABSPATH' ) || exit;

/**
 * One-line entry point Plugin.php uses to attach the WooCommerce sensor
 * family. Each child sensor decides individually whether it should boot
 * via {@see \HelloLog\Sensors\AbstractSensor::should_load()};
 * this loader just hands them to the manager.
 */
final class WooCommerceLoader {

	public function attach( SensorManager $manager, EventDispatcher $dispatcher ): void {
		$manager->register( new WooCommerceProductSensor( $dispatcher ) );
		$manager->register( new WooCommerceOrderSensor( $dispatcher ) );
		$manager->register( new WooCommerceCouponSensor( $dispatcher ) );
		$manager->register( new WooCommerceCustomerSensor( $dispatcher ) );
		$manager->register( new WooCommerceSettingsSensor( $dispatcher ) );
		$manager->register( new WooCommerceConfigSensor( $dispatcher ) );
	}
}
