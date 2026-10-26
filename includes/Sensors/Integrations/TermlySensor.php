<?php
/**
 * Termly (cookie consent) sensor.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\OptionChangePolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Termly settings live under the `termly_` option prefix. Only a person
 * saving a setting is logged, see {@see OptionChangePolicy}.
 */
final class TermlySensor extends AbstractSensor {

	public function key(): string {
		return 'termly';
	}

	public function should_load(): bool {
		return defined( 'TERMLY_VERSION' ) || class_exists( 'Termly', false );
	}

	public function boot(): void {
		add_action( 'updated_option', [ $this, 'on_update' ], 10, 3 );
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public function on_update( string $option, $old_value, $value ): void {
		if ( ! str_starts_with( $option, 'termly' ) ) {
			return;
		}
		if ( $old_value === $value ) {
			return;
		}
		if ( ! OptionChangePolicy::should_log_current( $option ) ) {
			return;
		}
		$this->emit(
			8500,
			[
				'target'   => OptionChangePolicy::target( $option ),
				'changes'  => OptionChangePolicy::changes( $option, $old_value, $value ),
				'metadata' => [ 'option' => $option ],
			]
		);
	}
}
