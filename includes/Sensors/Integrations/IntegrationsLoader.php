<?php
/**
 * Registers the catch-all integration sensors.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Events\EventDispatcher;
use HelloLog\Sensors\SensorManager;

defined( 'ABSPATH' ) || exit;

/**
 * Hands every misc-integration sensor to the manager. Each sensor
 * decides individually whether it should boot based on `should_load()`.
 */
final class IntegrationsLoader {

	public function attach( SensorManager $manager, EventDispatcher $dispatcher ): void {
		$manager->register( new BbPressSensor( $dispatcher ) );
		$manager->register( new LearnDashSensor( $dispatcher ) );
		$manager->register( new MemberPressSensor( $dispatcher ) );
		$manager->register( new PmpSensor( $dispatcher ) );
		$manager->register( new EddSensor( $dispatcher ) );
		$manager->register( new TablePressSensor( $dispatcher ) );
		$manager->register( new RedirectionSensor( $dispatcher ) );
		$manager->register( new MainWpSensor( $dispatcher ) );
		$manager->register( new TermlySensor( $dispatcher ) );
	}
}
