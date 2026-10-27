<?php
/**
 * Stand-ins for `WC()` with its payment gateway registry, and for the
 * WordPress calls that tell whether a hook fired or is running.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Sensors\Integrations\Fixtures\PaymentGatewayState;

	/**
	 * The shop, holding only its payment gateway registry.
	 */
	function WC(): object { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		++PaymentGatewayState::$wc_calls;
		if ( PaymentGatewayState::$wc_throws ) {
			throw new \RuntimeException( 'no shop' );
		}

		return new class() {

			public function payment_gateways(): object {
				return new class() {

					/**
					 * @return array<string, object> Gateway id => gateway.
					 */
					public function payment_gateways(): array {
						$gateways = [];
						foreach ( PaymentGatewayState::$option_keys as $id => $key ) {
							$gateways[ $id ] = new class( $key ) {

								public function __construct( private string $key ) {
								}

								public function get_option_key(): string {
									return $this->key;
								}
							};
						}

						return $gateways;
					}
				};
			}
		};
	}

	function did_action( string $hook_name ): int {
		return PaymentGatewayState::$did[ $hook_name ] ?? 0;
	}

	function doing_action( ?string $hook_name = null ): bool {
		return in_array( $hook_name, PaymentGatewayState::$doing, true );
	}
}
