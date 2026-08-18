<?php
/**
 * The lw-seo sensor.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

defined( 'ABSPATH' ) || exit;

final class LwSeoSensor extends LwOptionPrefixSensor {

	public function key(): string {
		return 'lw-seo';
	}

	public function should_load(): bool {
		return defined( 'LW_SEO_VERSION' ) || class_exists( 'LightweightPlugins\\SEO\\Plugin', false );
	}

	protected function option_prefix(): string {
		return 'lw_seo';
	}

	protected function event_code(): int {
		return 9601;
	}
}
