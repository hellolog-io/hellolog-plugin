<?php
/**
 * Sensor: WordPress core update.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Events\ChangeSet;
use HelloLog\Sensors\AbstractSensor;

defined( 'ABSPATH' ) || exit;

/**
 * Single-purpose sensor for `_core_updated_successfully`. WordPress fires
 * this once a core update finishes (manual or automatic). The hook
 * argument is the new version string.
 *
 * The update loads the new version before it fires the hook, so the version
 * it came from is read when the sensor boots, at the start of the request.
 */
final class SystemSensor extends AbstractSensor {

	/** The WordPress version the request started with; empty when unknown. */
	private string $version_before = '';

	public function key(): string {
		return 'core-system';
	}

	public function boot(): void {
		$this->version_before = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '';
		add_action( '_core_updated_successfully', [ $this, 'on_core_updated' ], 10, 1 );
	}

	public function on_core_updated( string $wp_version ): void {
		$fields = [
			'version'  => $wp_version,
			'metadata' => [ 'wp_version' => $wp_version ],
		];

		if ( '' !== $this->version_before ) {
			$changes = ChangeSet::diff( [ 'version' => $this->version_before ], [ 'version' => $wp_version ], [ 'version' ] );
			if ( [] !== $changes ) {
				$fields['changes'] = $changes;
			}
		}

		$this->emit( 6100, $fields );
	}
}
