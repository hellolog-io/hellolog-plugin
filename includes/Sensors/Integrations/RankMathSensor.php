<?php
/**
 * Rank Math SEO sensor.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\OptionChangePolicy;

defined( 'ABSPATH' ) || exit;

/**
 * RankMath options live under `rank_math_*`, and its settings screens save
 * hyphenated ones (`rank-math-options-general`, `-titles`, `-sitemap`). We
 * tag changes with the dedicated 8900 code so SEO traffic stays
 * distinguishable in the log. Only a person saving a setting is logged, see
 * {@see OptionChangePolicy}.
 */
final class RankMathSensor extends AbstractSensor {

	public function key(): string {
		return 'rank-math';
	}

	public function should_load(): bool {
		return defined( 'RANK_MATH_VERSION' );
	}

	public function boot(): void {
		add_action( 'updated_option', [ $this, 'on_update' ], 10, 3 );
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public function on_update( string $option, $old_value, $value ): void {
		if ( ! str_starts_with( $option, 'rank_math' ) && ! str_starts_with( $option, 'rank-math' ) ) {
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
			8900,
			[
				'option'   => $option,
				'target'   => OptionChangePolicy::target( $option ),
				'changes'  => $changes,
				'metadata' => [ 'option' => $option ],
			]
		);
	}
}
