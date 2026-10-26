<?php
/**
 * Shared base for the LW family option-watching sensors.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\OptionChangePolicy;
use HelloLog\Sensors\SensitiveOption;

defined( 'ABSPATH' ) || exit;

/**
 * Many LW plugins ship as "settings-only" surfaces. For those we only
 * need to flag option changes under a prefix. Children specify the
 * prefix, the catalog code, and (optionally) the load guard. Only a person
 * saving a setting is logged, see {@see OptionChangePolicy}.
 */
abstract class LwOptionPrefixSensor extends AbstractSensor {

	public function boot(): void {
		add_action( 'updated_option', [ $this, 'on_option' ], 10, 3 );
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public function on_option( string $option, $old_value, $value ): void {
		if ( ! str_starts_with( $option, $this->option_prefix() ) ) {
			return;
		}
		if ( $old_value === $value ) {
			return;
		}
		if ( ! OptionChangePolicy::should_log_current( $option ) ) {
			return;
		}
		$this->emit(
			$this->event_code(),
			array_merge(
				[
					'option'  => $option,
					'feature' => $option,
					'state'   => SensitiveOption::value( $option, $value ),
					'target'  => OptionChangePolicy::target( $option ),
					'changes' => OptionChangePolicy::changes( $option, $old_value, $value ),
				],
				[ 'metadata' => [ 'option' => $option ] ]
			)
		);
	}

	abstract protected function option_prefix(): string;

	abstract protected function event_code(): int;
}
