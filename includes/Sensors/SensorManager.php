<?php
/**
 * Sensor registry — gates and boots the active integrations.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors;

use HelloLog\Sensors\Core\SelfAuditSensor;

defined( 'ABSPATH' ) || exit;

/**
 * Holds every {@see AbstractSensor} the plugin knows about and wires the
 * ones that should run on this request:
 *
 *   - skipped if {@see AbstractSensor::should_load()} returns false (e.g.
 *     WooCommerce sensor on a site without WooCommerce),
 *   - skipped if the operator turned that sensor off in the settings
 *     (`hellolog_sensor_filters` option, keyed by {@see AbstractSensor::key()}),
 *     unless it is one that can not be turned off ({@see self::ALWAYS_ON}).
 *
 * Keeps `boot()` idempotent so the manager can be safely re-run during
 * tests or after late registrations from third-party code.
 */
final class SensorManager {

	/**
	 * Sensors an operator can not turn off: the one that logs changes to
	 * helloLOG itself. They are not offered as switches either.
	 *
	 * @var array<int, string>
	 */
	public const ALWAYS_ON = [ SelfAuditSensor::KEY ];

	/**
	 * Sensors that are off until the operator turns them on, shaped like the
	 * stored settings (key => disabled). They are noisy on a typical site and
	 * not useful enough to justify their volume by default: REST polling,
	 * XML-RPC and the 404 monitor, and failed logins, which bot dictionary
	 * attacks fire dozens of per minute on an internet-exposed site
	 * (operators turn it on for an incident investigation).
	 *
	 * @var array<string, bool>
	 */
	public const OFF_BY_DEFAULT = [
		'core-request'      => true,
		'core-failed-login' => true,
	];

	/** @var array<string, AbstractSensor> */
	private array $sensors = [];

	/** @var array<string, bool> */
	private array $disabled = [];

	private bool $booted = false;

	public function register( AbstractSensor $sensor ): void {
		$this->sensors[ $sensor->key() ] = $sensor;
	}

	/**
	 * The sensors stored settings turn off: those flagged `true`, then the
	 * off-by-default ones ({@see self::OFF_BY_DEFAULT}) the settings do not
	 * mention. A sensor flagged `false` runs, and one that can not be turned
	 * off ({@see self::ALWAYS_ON}) is never among them.
	 *
	 * @param array<string, bool> $flags Stored settings: sensor key => disabled.
	 * @return array<int, string>
	 */
	public static function disabled_by( array $flags ): array {
		$disabled = [];
		foreach ( $flags as $key => $is_disabled ) {
			if ( $is_disabled ) {
				$disabled[] = (string) $key;
			}
		}
		foreach ( array_keys( self::OFF_BY_DEFAULT ) as $key ) {
			if ( ! array_key_exists( $key, $flags ) ) {
				$disabled[] = $key;
			}
		}

		return array_values( array_diff( $disabled, self::ALWAYS_ON ) );
	}

	/**
	 * Mark the given sensor keys as disabled by the operator. A sensor that
	 * can not be turned off ({@see self::ALWAYS_ON}) is left on.
	 *
	 * @param array<int, string> $keys
	 */
	public function disable( array $keys ): void {
		foreach ( $keys as $key ) {
			if ( is_string( $key ) && '' !== $key && ! in_array( $key, self::ALWAYS_ON, true ) ) {
				$this->disabled[ $key ] = true;
			}
		}
	}

	/**
	 * Whether a sensor is turned off for this request.
	 */
	public function is_disabled( string $key ): bool {
		return isset( $this->disabled[ $key ] );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		foreach ( $this->sensors as $key => $sensor ) {
			if ( isset( $this->disabled[ $key ] ) ) {
				continue;
			}
			if ( ! $sensor->should_load() ) {
				continue;
			}
			$sensor->boot();
		}
	}

	/**
	 * @return array<string, AbstractSensor>
	 */
	public function sensors(): array {
		return $this->sensors;
	}

	/**
	 * The registered sensors an operator can turn on and off: every one but
	 * those that are always on.
	 *
	 * @return array<string, AbstractSensor>
	 */
	public function toggleable(): array {
		return array_diff_key( $this->sensors, array_flip( self::ALWAYS_ON ) );
	}
}
