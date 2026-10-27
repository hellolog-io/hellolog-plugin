<?php
/**
 * What the WooCommerce payment gateway stand-ins of the settings sensor tests
 * answer.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Integrations\Fixtures;

/**
 * Shared state of `woocommerce-payment-gateway-functions.php`. Only ever used
 * inside a test that runs in its own process, together with those stubs.
 */
final class PaymentGatewayState {

	/**
	 * Gateway id => the option key its `get_option_key()` returns, for the
	 * gateways `WC()->payment_gateways()->payment_gateways()` lists.
	 *
	 * @var array<string, string>
	 */
	public static array $option_keys = [];

	/**
	 * Hook name => how many times `did_action()` reports it fired.
	 *
	 * @var array<string, int>
	 */
	public static array $did = [];

	/**
	 * Hooks `doing_action()` reports as running.
	 *
	 * @var array<int, string>
	 */
	public static array $doing = [];

	/** Calls of `WC()`. */
	public static int $wc_calls = 0;

	/** Whether `WC()` throws. */
	public static bool $wc_throws = false;
}
