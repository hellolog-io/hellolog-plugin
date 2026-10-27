<?php
/**
 * What the WooCommerce stand-ins of the shop configuration sensor tests answer.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Integrations\Fixtures;

/**
 * Shared state of `woocommerce-config-functions.php` and the tax, shipping
 * zone and webhook stand-in classes. Only ever used inside a test that runs
 * in its own process, together with those stubs.
 */
final class ConfigState {

	/** The site `get_current_blog_id()` reports as current. */
	public static int $blog_id = 1;

	/**
	 * Site => tax rate id => the stored row `WC_Tax::_get_tax_rate()` reads.
	 * A rate that is not listed has no row.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	public static array $tax_rates = [];

	/**
	 * Zone id => the name of the zone `WC_Shipping_Zones::get_zone()` loads.
	 * A zone that is not listed does not exist.
	 *
	 * @var array<int, string>
	 */
	public static array $zones = [];

	/**
	 * Shipping method id => the title of the method type the shop's registry
	 * lists.
	 *
	 * @var array<string, string>
	 */
	public static array $shipping_methods = [];

	/**
	 * Site => attribute id => what `wc_get_attribute()` finds: `name` (the
	 * label), `slug` (the taxonomy, `pa_…`), `type`, `order_by`,
	 * `has_archives`. An attribute that is not listed is not found.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	public static array $attributes = [];

	/** Whether every lookup of the shop and of the site (and a webhook's data) throws. */
	public static bool $throws = false;
}
