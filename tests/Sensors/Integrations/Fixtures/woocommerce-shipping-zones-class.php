<?php
/**
 * Stand-in for WooCommerce's `WC_Shipping_Zones`, which the shop configuration
 * sensor loads a zone's name from.
 *
 * Loaded only by tests that run in a separate process: defining it makes
 * `class_exists( 'WC_Shipping_Zones' )` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Sensors\Integrations\Fixtures\ConfigState;

	if ( ! class_exists( 'WC_Shipping_Zones', false ) ) {

		/**
		 * Answers only the load of one zone.
		 */
		class WC_Shipping_Zones {

			/**
			 * Like WooCommerce's: the zone, false when there is none.
			 *
			 * @param int|string $zone_id Zone id.
			 * @return object|false
			 */
			public static function get_zone( $zone_id ) {
				if ( ConfigState::$throws ) {
					throw new \RuntimeException( 'no zones' );
				}
				if ( ! is_int( $zone_id ) ) {
					throw new \LogicException( 'The zone id must be an integer.' );
				}
				if ( ! array_key_exists( $zone_id, ConfigState::$zones ) ) {
					return false;
				}

				return new class( ConfigState::$zones[ $zone_id ] ) {

					public function __construct( private string $name ) {
					}

					public function get_zone_name(): string {
						return $this->name;
					}
				};
			}
		}
	}
}
