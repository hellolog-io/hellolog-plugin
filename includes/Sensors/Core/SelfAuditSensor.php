<?php
/**
 * Sensor: changes to helloLOG's own settings.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\OptionChangePolicy;
use HelloLog\Sensors\SensitiveOption;
use HelloLog\Sensors\SensorManager;
use HelloLog\Settings\Options;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Whoever changes how the activity log works is on record: every write that
 * changes one of helloLOG's settings is one critical event (6600), with the
 * setting as its target and what changed:
 *
 *   - the API key (`token`): never its value. A key replaced by another reads
 *     `[redacted]` to `[redacted]`; a key stored where there was none (also
 *     its first write, `added_option`) reads `set`; a key emptied or deleted
 *     (clearing it in the settings is a `delete_option`) reads `removed`.
 *   - IP anonymisation (`anonymize_ip`): `no` or `yes`. The setting is saved
 *     as a boolean and read back as `'1'` or `''`, so it is compared as a
 *     boolean: saving what it already is changes nothing.
 *   - each sensor turned on or off (`sensor:<key>`): `enabled` or `disabled`.
 *     A sensor the settings do not mention is in its default state
 *     ({@see SensorManager::OFF_BY_DEFAULT}), exactly as when the sensors are
 *     set up. These items are built here, not by
 *     {@see \HelloLog\Events\ChangeSet::diff()}: sensor keys are identifiers,
 *     and one that reads like a credential (`core-auth`) must not be masked.
 *     A flag for a sensor that can not be turned off changes nothing and is
 *     not reported.
 *
 * Only the API key is told on its first write and on its deletion: the
 * other settings are first written with the defaults helloLOG seeds on a
 * site, which is not a change.
 *
 * This sensor can not be turned off ({@see SensorManager::ALWAYS_ON}) and is
 * not offered as a switch in the settings. Like every sensor it runs only
 * while helloLOG is active, with a verified API key: a change made while it is
 * not (the first key saved on a new install, settings changed after the key
 * was cleared) is not logged, since nothing queued then would be accepted.
 * Clearing the key is logged, as the sensor still runs in that request, and
 * the event leaves the site once a key is verified again.
 *
 * helloLOG's own deactivation is not seen here; it is logged and sent by
 * {@see \HelloLog\Plugin::deliver_own_deactivation()}.
 */
final class SelfAuditSensor extends AbstractSensor {

	public const KEY = 'core-self-audit';

	/**
	 * The settings this sensor reports.
	 *
	 * @var array<int, string>
	 */
	private const WATCHED = [ Options::KEY_TOKEN, Options::KEY_ANONYMIZE_IP, Options::KEY_SENSOR_FILTERS ];

	public function key(): string {
		return self::KEY;
	}

	public function boot(): void {
		add_action( 'updated_option', [ $this, 'on_update' ], 10, 3 );
		add_action( 'added_option', [ $this, 'on_add' ], 10, 2 );
		add_action( 'deleted_option', [ $this, 'on_deleted' ], 10, 1 );
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public function on_update( string $option, $old_value, $value ): void {
		$this->log( $option, $old_value, $value );
	}

	/**
	 * An option written for the first time: only the API key is told.
	 *
	 * @param mixed $value
	 */
	public function on_add( string $option, $value ): void {
		if ( Options::KEY_TOKEN === $option ) {
			$this->log( $option, null, $value );
		}
	}

	/**
	 * An option deleted: only the API key is told. The hook fires only when
	 * a row was removed, so a key was stored; its value is gone, and only
	 * that there was one matters.
	 */
	public function on_deleted( string $option ): void {
		if ( Options::KEY_TOKEN === $option ) {
			$this->log( $option, SensitiveOption::REDACTED, null );
		}
	}

	/**
	 * What a write changed in one of helloLOG's settings, as change items;
	 * empty for any other option and for a write that changes nothing. See
	 * the class description for each setting.
	 *
	 * @param string $option Option name.
	 * @param mixed  $old    Value before the write; null when there was none.
	 * @param mixed  $new    Value after the write; null when it was deleted.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function changes_for( string $option, mixed $old, mixed $new ): array {
		return match ( $option ) {
			Options::KEY_TOKEN          => self::token_changes( $old, $new ),
			Options::KEY_ANONYMIZE_IP   => self::anonymize_changes( $old, $new ),
			Options::KEY_SENSOR_FILTERS => self::sensor_changes( $old, $new ),
			default                     => [],
		};
	}

	/**
	 * Report a write to one of the settings.
	 *
	 * @param string $option Option name.
	 * @param mixed  $old    Value before the write.
	 * @param mixed  $new    Value after the write.
	 */
	private function log( string $option, $old, $new ): void {
		if ( ! in_array( $option, self::WATCHED, true ) ) {
			return;
		}

		// Nothing here may reach the request that saved the setting.
		try {
			$changes = self::changes_for( $option, $old, $new );
			if ( [] === $changes ) {
				return;
			}

			$this->emit(
				6600,
				[
					'target'   => OptionChangePolicy::target( $option ),
					'changes'  => $changes,
					'metadata' => [ 'option' => $option ],
				]
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 6600, [ 'option' => $option ] );
		}
	}

	/**
	 * @param mixed $old Key before the write.
	 * @param mixed $new Key after the write.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	private static function token_changes( $old, $new ): array {
		$before = self::token_text( $old );
		$after  = self::token_text( $new );
		if ( $before === $after ) {
			return [];
		}

		if ( '' === $after ) {
			$reported = 'removed';
		} else {
			$reported = '' === $before ? 'set' : SensitiveOption::REDACTED;
		}

		return [
			[
				'field' => 'token',
				'old'   => '' === $before ? null : SensitiveOption::REDACTED,
				'new'   => $reported,
			],
		];
	}

	/**
	 * A stored key as text; empty for no key.
	 *
	 * @param mixed $value Stored value.
	 */
	private static function token_text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @param mixed $old Setting before the write.
	 * @param mixed $new Setting after the write.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	private static function anonymize_changes( $old, $new ): array {
		$before = (bool) $old;
		$after  = (bool) $new;
		if ( $before === $after ) {
			return [];
		}

		return [
			[
				'field' => 'anonymize_ip',
				'old'   => $before ? 'yes' : 'no',
				'new'   => $after ? 'yes' : 'no',
			],
		];
	}

	/**
	 * One item per sensor whose state differs, in the order the settings
	 * name them (the old ones first).
	 *
	 * @param mixed $old Stored sensor flags before the write.
	 * @param mixed $new Stored sensor flags after the write.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	private static function sensor_changes( $old, $new ): array {
		$before   = Options::flags( $old );
		$after    = Options::flags( $new );
		$off_then = SensorManager::disabled_by( $before );
		$off_now  = SensorManager::disabled_by( $after );

		$changes = [];
		foreach ( array_keys( $before + $after ) as $key ) {
			$was_off = in_array( (string) $key, $off_then, true );
			$is_off  = in_array( (string) $key, $off_now, true );
			if ( $was_off === $is_off ) {
				continue;
			}

			$changes[] = [
				'field' => 'sensor:' . $key,
				'old'   => $was_off ? 'disabled' : 'enabled',
				'new'   => $is_off ? 'disabled' : 'enabled',
			];
		}

		return $changes;
	}
}
