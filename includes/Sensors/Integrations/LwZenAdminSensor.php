<?php
/**
 * The lw-zenadmin sensor.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

defined( 'ABSPATH' ) || exit;

final class LwZenAdminSensor extends LwOptionPrefixSensor {

	public function key(): string {
		return 'lw-zenadmin';
	}

	public function should_load(): bool {
		return defined( 'LW_ZENADMIN_VERSION' ) || class_exists( 'LightweightPlugins\\ZenAdmin\\Plugin', false );
	}

	protected function option_prefix(): string {
		return 'lw_zenadmin';
	}

	protected function event_code(): int {
		return 9900;
	}
}
