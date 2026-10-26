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
use Throwable;

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
		// Reading the values of a write can fail (an object whose text throws);
		// that must never reach the request that saved the setting.
		try {
			if ( ! str_starts_with( $option, 'termly' ) ) {
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
				8500,
				[
					'target'   => OptionChangePolicy::target( $option ),
					'changes'  => $changes,
					'metadata' => array_merge( [ 'option' => $option ], OptionChangePolicy::request_metadata() ),
				]
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 8500, [ 'option' => $option ] );
		}
	}
}
