<?php
/**
 * Smoke test for the wire-shape of catalog seeding.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Token;

use HelloLog\Events\EventCatalog;
use HelloLog\Sensors\CatalogSeeder;
use PHPUnit\Framework\TestCase;

/**
 * The catalog has to be exhaustive — every code a sensor emits must have
 * a matching definition. This test seeds the catalog and checks the
 * codes the sensors fire are all present.
 */
final class CatalogSeederIntegrityTest extends TestCase {

	public function test_seeder_registers_every_expected_code(): void {
		$catalog = new EventCatalog();
		CatalogSeeder::seed( $catalog );

		$required = [
			1000, 1001, 1002, 1003,                         // auth
			4000, 4001, 4002, 4003,                         // user lifecycle
			2000, 2001, 2002, 2003, 2004, 2005, 2006,       // content
			2200, 2201, 2202, 2203, 2204, 2205,             // comments
			2120, 2121, 2122,                               // taxonomies
			5000, 5001, 5002, 5003, 5004,                   // plugins
			5100, 5101, 5102, 5103, 5104,                   // themes
			6000, 6001, 6002, 6003, 6004,                   // settings
			6100,                                           // core update
			6300,                                           // file edit
			7100, 7101,                                     // db DDL
			2400, 2401, 2402, 2300, 2301,                   // menus / widgets
			4100, 4101, 4102, 4150,                         // multisite
			6400, 6401, 6402,                               // request
			4400, 4401,                                     // 2fa
			4500, 4501, 4502,                               // app passwords
			9000, 9001, 9002, 9003, 9004, 9005,             // WC products
			9100, 9101, 9102, 9103, 9104,                   // WC orders
			9106, 9107, 9108, 9111,                         // WC order items, deleted notes
			9200, 9201, 9202,                               // WC coupons
			9300, 9301,                                     // WC customers
			9400, 9401,                                     // WC settings, bank accounts
			9410, 9411, 9412, 9420, 9421, 9422,             // WC tax rates, shipping methods
			9430, 9431, 9432, 9440, 9441, 9442,             // WC webhooks, attributes
			5700, 5701, 5702, 5710,                         // Gravity Forms
			5800, 5801, 5810,                               // WPForms
			5850, 5851,                                     // CF7
			5860, 5861, 5870,                               // Fluent
			8800, 8801, 8900, 8901,                         // Yoast / RankMath
			5400, 5401, 5402,                               // ACF
			8000, 8001, 8200, 8201,                         // bbPress / LD
			6500, 6501, 5500,                               // MP / PMP
			8300, 8301, 5300, 5301, 5302,                   // EDD / TablePress
			5200, 5201, 7700, 7701, 8500,                   // Redirection / MainWP / Termly
			9500, 9501, 9600, 9601, 9700, 9800, 9900,       // LW family
		];

		foreach ( $required as $code ) {
			$this->assertTrue(
				$catalog->has( $code ),
				sprintf( 'EventCatalog missing required code %d', $code )
			);
		}
	}
}
