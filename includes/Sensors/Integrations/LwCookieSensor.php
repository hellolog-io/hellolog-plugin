<?php
/**
 * The lw-cookie sensor.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

defined( 'ABSPATH' ) || exit;

final class LwCookieSensor extends LwOptionPrefixSensor {

	public function key(): string {
		return 'lw-cookie';
	}

	public function should_load(): bool {
		return defined( 'LW_COOKIE_VERSION' ) || class_exists( 'LightweightPlugins\\Cookie\\Plugin', false );
	}

	protected function option_prefix(): string {
		return 'lw_cookie';
	}

	protected function event_code(): int {
		return 9800;
	}
}
