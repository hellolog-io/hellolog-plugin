<?php
/**
 * Stand-in for WooCommerce's `WC_Tax`, which the shop configuration sensor
 * reads a tax rate's stored row from.
 *
 * Loaded only by tests that run in a separate process: defining it makes
 * `class_exists( 'WC_Tax' )` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Sensors\Integrations\Fixtures\ConfigState;

	if ( ! class_exists( 'WC_Tax', false ) ) {

		/**
		 * Answers only the read of one stored tax rate row.
		 */
		class WC_Tax {

			/**
			 * Like WooCommerce's: the row as an array, null when there is none.
			 *
			 * @param int|string $tax_rate_id Tax rate id.
			 * @param string     $output_type Only the default (an array) is supported.
			 * @return array<string, mixed>|null
			 */
			public static function _get_tax_rate( $tax_rate_id, $output_type = 'ARRAY_A' ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
				if ( ConfigState::$throws ) {
					throw new \RuntimeException( 'no tax rates' );
				}

				return ConfigState::$tax_rates[ ConfigState::$blog_id ][ (int) $tax_rate_id ] ?? null;
			}
		}
	}
}
