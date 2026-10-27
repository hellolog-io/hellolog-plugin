<?php
/**
 * Tests for {@see HelloLog\Sensors\StoreTargets}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors;

use HelloLog\Sensors\StoreTargets;
use PHPUnit\Framework\TestCase;

/**
 * The shop's objects have one target each, whichever sensor tells about
 * them: the shop's own sensors or the post sensor.
 */
final class StoreTargetsTest extends TestCase {

	public function test_an_order_is_named_by_its_number_else_its_id(): void {
		$this->assertSame(
			[
				'type'  => 'order',
				'id'    => '5',
				'label' => '#A-17',
			],
			StoreTargets::order( 5, 'A-17' )
		);
		$this->assertSame( '#5', StoreTargets::order( 5 )['label'] );
	}

	public function test_a_product_is_named_by_its_name_else_its_id(): void {
		$this->assertSame(
			[
				'type'  => 'product',
				'id'    => '5',
				'label' => 'Mug',
			],
			StoreTargets::product( 5, 'Mug' )
		);
		$this->assertSame( '#5', StoreTargets::product( 5, StoreTargets::PRODUCT_PLACEHOLDER )['label'] );
		$this->assertSame( '#5', StoreTargets::product( 5, '' )['label'] );
		$this->assertSame( 'AUTO-DRAFT mug', StoreTargets::product( 5, 'AUTO-DRAFT mug' )['label'] );
	}

	public function test_a_coupon_is_named_by_its_code(): void {
		$this->assertSame(
			[
				'type'  => 'coupon',
				'id'    => '8',
				'label' => 'summer10',
			],
			StoreTargets::coupon( 8, 'summer10' )
		);
		// WordPress's title of an editor placeholder; the shop reads every
		// code in lower case.
		$this->assertSame( '#8', StoreTargets::coupon( 8, StoreTargets::COUPON_PLACEHOLDER )['label'] );
		$this->assertSame( '#8', StoreTargets::coupon( 8, '' )['label'] );
		$this->assertSame( 'auto draft', StoreTargets::coupon( 8, 'auto draft' )['label'] );
	}

	/**
	 * The post sensor is a sensor of WordPress itself: it names the shop's
	 * objects through this helper, never through a sensor of the shop.
	 */
	public function test_the_post_sensor_does_not_depend_on_the_shops_sensors(): void {
		$source = (string) file_get_contents( __DIR__ . '/../../includes/Sensors/Core/ContentSensor.php' );

		$this->assertStringNotContainsString( 'Sensors\\Integrations', $source );
	}
}
