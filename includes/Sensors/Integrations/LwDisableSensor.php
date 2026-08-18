<?php
/**
 * The lw-disable sensor.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

defined( 'ABSPATH' ) || exit;

final class LwDisableSensor extends LwOptionPrefixSensor {

	public function key(): string {
		return 'lw-disable';
	}

	public function should_load(): bool {
		return defined( 'LW_DISABLE_VERSION' ) || class_exists( 'LightweightPlugins\\Disable\\Plugin', false );
	}

	protected function option_prefix(): string {
		return 'lw_disable';
	}

	protected function event_code(): int {
		return 9700;
	}
}
