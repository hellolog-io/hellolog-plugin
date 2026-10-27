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

	/**
	 * The key ({@see AbstractSensor::key()}) of every sensor the plugin
	 * ships, core and integrations, in the order they are registered. Known
	 * without setting any sensor up: until the API key is verified no sensor
	 * is registered, and the switches are still checked against these.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = [
		'core-auth',
		'core-failed-login',
		'core-user-profile',
		'core-content',
		'core-media',
		'core-comments',
		'core-taxonomy',
		'core-plugins',
		'core-themes',
		'core-settings',
		SelfAuditSensor::KEY,
		'core-system',
		'core-files',
		'core-database',
		'core-menus',
		'core-widgets',
		'core-multisite',
		'core-request',
		'core-2fa',
		'core-app-passwords',
		'woocommerce-product',
		'woocommerce-order',
		'woocommerce-coupon',
		'woocommerce-customer',
		'woocommerce-settings',
		'gravity-forms',
		'wpforms',
		'contact-form-7',
		'fluent-forms',
		'yoast-seo',
		'rank-math',
		'acf',
		'bbpress',
		'learndash',
		'memberpress',
		'paid-memberships-pro',
		'edd',
		'tablepress',
		'redirection',
		'mainwp',
		'termly',
		'lw-firewall',
		'lw-seo',
		'lw-disable',
		'lw-cookie',
		'lw-zenadmin',
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

	/**
	 * The keys of the sensors an operator can turn on and off, whether or not
	 * they are set up in this request: the plugin's own ({@see self::KEYS}),
	 * then any other registered one, but none that is always on.
	 *
	 * @return array<int, string>
	 */
	public function switchable(): array {
		$keys = array_unique( array_merge( self::KEYS, array_map( 'strval', array_keys( $this->sensors ) ) ) );

		return array_values( array_diff( $keys, self::ALWAYS_ON ) );
	}

	/**
	 * The switches the settings screen offers: every sensor an operator can
	 * switch ({@see self::switchable()}), with a label made from its key and
	 * whether it runs under the stored flags. A sensor that is off by
	 * default reads as off until the flags turn it on, so saving the screen
	 * as shown changes nothing.
	 *
	 * @param array<string, bool> $flags Stored settings: sensor key => disabled.
	 * @return array<int, array{key: string, label: string, enabled: bool}>
	 */
	public function switches( array $flags ): array {
		$off = self::disabled_by( $flags );
		$out = [];
		foreach ( $this->switchable() as $key ) {
			$out[] = [
				'key'     => $key,
				'label'   => ucwords( str_replace( [ '-', '_' ], ' ', $key ) ),
				'enabled' => ! in_array( $key, $off, true ),
			];
		}

		return $out;
	}

	/**
	 * The stored-settings flags of the sensors an operator can switch
	 * ({@see self::switchable()}); flags of any other key are dropped. Made-up
	 * keys saved with real ones would otherwise crowd the real changes out of
	 * the settings event.
	 *
	 * @param array<string, bool> $flags Sensor key => disabled.
	 * @return array<string, bool>
	 */
	public function known_flags( array $flags ): array {
		return array_intersect_key( $flags, array_flip( $this->switchable() ) );
	}
}
