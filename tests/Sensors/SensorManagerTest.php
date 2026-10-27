<?php
/**
 * Tests for {@see HelloLog\Sensors\SensorManager}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors;

use HelloLog\Events\NullEventDispatcher;
use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\SensorManager;
use PHPUnit\Framework\TestCase;

/**
 * An operator can turn sensors off, except the one that logs changes to
 * helloLOG itself; a sensor the settings do not mention runs as it does by
 * default.
 */
final class SensorManagerTest extends TestCase {

	public function test_a_disabled_sensor_does_not_boot(): void {
		$manager = new SensorManager();
		$media   = $this->sensor( 'core-media' );
		$posts   = $this->sensor( 'core-posts' );
		$manager->register( $media );
		$manager->register( $posts );

		$manager->disable( [ 'core-media' ] );
		$manager->boot();

		$this->assertTrue( $manager->is_disabled( 'core-media' ) );
		$this->assertFalse( $manager->is_disabled( 'core-posts' ) );
		$this->assertFalse( $media->booted );
		$this->assertTrue( $posts->booted );
	}

	public function test_the_self_audit_boots_even_when_told_to_turn_off(): void {
		$manager = new SensorManager();
		$audit   = $this->sensor( 'core-self-audit' );
		$manager->register( $audit );

		$manager->disable( [ 'core-self-audit' ] );
		$manager->boot();

		$this->assertFalse( $manager->is_disabled( 'core-self-audit' ) );
		$this->assertTrue( $audit->booted );
	}

	public function test_only_sensors_that_can_be_turned_off_are_offered_as_switches(): void {
		$manager = new SensorManager();
		$manager->register( $this->sensor( 'core-media' ) );
		$manager->register( $this->sensor( 'core-self-audit' ) );

		$this->assertSame( [ 'core-media' ], array_keys( $manager->toggleable() ) );
		$this->assertSame( [ 'core-media', 'core-self-audit' ], array_keys( $manager->sensors() ) );
	}

	public function test_only_flags_of_switchable_registered_sensors_are_kept(): void {
		$manager = new SensorManager();
		$manager->register( $this->sensor( 'core-auth' ) );
		$manager->register( $this->sensor( 'core-media' ) );
		$manager->register( $this->sensor( 'core-self-audit' ) );

		$this->assertSame(
			[
				'core-auth'  => true,
				'core-media' => false,
			],
			$manager->known_flags(
				[
					'core-auth'       => true,
					'junk-1'          => true,
					'core-media'      => false,
					'core-self-audit' => true,
				]
			)
		);
	}

	/**
	 * Until the API key is verified no sensor is registered, yet the
	 * plugin's own can be switched; a sensor registered by other code joins
	 * them.
	 */
	public function test_the_plugins_sensors_can_be_switched_before_any_is_registered(): void {
		$manager = new SensorManager();

		$this->assertContains( 'core-auth', $manager->switchable() );
		$this->assertContains( 'woocommerce-order', $manager->switchable() );
		$this->assertNotContains( 'core-self-audit', $manager->switchable() );
		$this->assertSame(
			[ 'core-auth' => true ],
			$manager->known_flags(
				[
					'core-auth'       => true,
					'junk-1'          => true,
					'core-self-audit' => true,
				]
			)
		);

		$manager->register( $this->sensor( 'extra-sensor' ) );
		$this->assertContains( 'extra-sensor', $manager->switchable() );
	}

	public function test_the_noisy_sensors_are_off_by_default(): void {
		$this->assertSame(
			[
				'core-request'      => true,
				'core-failed-login' => true,
			],
			SensorManager::OFF_BY_DEFAULT
		);
	}

	public function test_stored_flags_decide_and_unmentioned_sensors_keep_their_default(): void {
		$this->assertSame(
			[ 'core-media', 'core-failed-login' ],
			SensorManager::disabled_by(
				[
					'core-media'   => true,
					'core-posts'   => false,
					'core-request' => false,
				]
			)
		);
		$this->assertSame( [ 'core-request', 'core-failed-login' ], SensorManager::disabled_by( [] ) );
	}

	public function test_the_self_audit_is_never_among_the_disabled(): void {
		$this->assertSame(
			[ 'core-request', 'core-failed-login' ],
			SensorManager::disabled_by( [ 'core-self-audit' => true ] )
		);
	}

	/**
	 * A sensor that notes whether it was booted.
	 *
	 * @param string $key Sensor key.
	 */
	private function sensor( string $key ): AbstractSensor {
		return new class( $key ) extends AbstractSensor {

			public bool $booted = false;

			public function __construct( private string $sensor_key ) {
				parent::__construct( new NullEventDispatcher() );
			}

			public function key(): string {
				return $this->sensor_key;
			}

			public function boot(): void {
				$this->booted = true;
			}
		};
	}
}
