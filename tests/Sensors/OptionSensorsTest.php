<?php
/**
 * Tests that the prefix-based option sensors log only a person saving a setting.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors;

use HelloLog\Events\ActionSchedulerContext;
use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\RequestContext;
use HelloLog\Events\RequestSource;
use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Integrations\LwCookieSensor;
use HelloLog\Sensors\Integrations\LwDisableSensor;
use HelloLog\Sensors\Integrations\LwFirewallSensor;
use HelloLog\Sensors\Integrations\LwSeoSensor;
use HelloLog\Sensors\Integrations\LwZenAdminSensor;
use HelloLog\Sensors\Integrations\RankMathSensor;
use HelloLog\Sensors\Integrations\TermlySensor;
use HelloLog\Sensors\Integrations\WooCommerceSettingsSensor;
use HelloLog\Sensors\Integrations\YoastSeoSensor;
use HelloLog\Sensors\SensitiveOption;
use HelloLog\Tests\Sensors\Fixtures\RequestState;
use PHPUnit\Framework\TestCase;

/**
 * The sensors read the request through WordPress, so every test runs in its
 * own process with the stand-ins of `Fixtures/wordpress-request-functions.php`.
 * The defaults are an administrator (user 1) on a dashboard screen.
 */
final class OptionSensorsTest extends TestCase {

	/**
	 * @return array<string, array{0: class-string<AbstractSensor>, 1: string, 2: string, 3: int}>
	 *         Sensor, the method `updated_option` calls, a setting of its own, event code.
	 */
	public static function sensors(): array {
		return [
			'woocommerce settings' => [ WooCommerceSettingsSensor::class, 'on_update', 'woocommerce_currency', 9400 ],
			'rank math'            => [ RankMathSensor::class, 'on_update', 'rank_math_modules', 8900 ],
			'yoast seo'            => [ YoastSeoSensor::class, 'on_update', 'wpseo_titles', 8800 ],
			'termly'               => [ TermlySensor::class, 'on_update', 'termly_banner_position', 8500 ],
			'lw seo'               => [ LwSeoSensor::class, 'on_option', 'lw_seo_title', 9601 ],
			'lw disable'           => [ LwDisableSensor::class, 'on_option', 'lw_disable_emojis', 9700 ],
			'lw cookie'            => [ LwCookieSensor::class, 'on_option', 'lw_cookie_banner', 9800 ],
			'lw zenadmin'          => [ LwZenAdminSensor::class, 'on_option', 'lw_zenadmin_menu', 9900 ],
			'lw firewall'          => [ LwFirewallSensor::class, 'on_option', 'lw_firewall_rules', 9501 ],
		];
	}

	/**
	 * @dataProvider sensors
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param class-string<AbstractSensor> $class  Sensor.
	 * @param string                       $method Method `updated_option` calls.
	 * @param string                       $option A setting of the sensor's own.
	 * @param int                          $code   Event code.
	 */
	public function test_a_setting_saved_in_the_dashboard_is_logged_with_a_setting_target_and_its_change( string $class, string $method, string $option, int $code ): void {
		$recorder = $this->recorder();
		$this->sensor( $class, $recorder )->$method( $option, 'before', 'after' );

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( $code, $recorder->calls[0][0] );
		$fields = $recorder->calls[0][1];
		$this->assertSame(
			[
				'type'  => 'setting',
				'id'    => $option,
				'label' => $option,
			],
			$fields['target']
		);
		$this->assertSame(
			[
				[
					'field' => $option,
					'old'   => 'before',
					'new'   => 'after',
				],
			],
			$fields['changes']
		);
		$this->assertSame( $option, $fields['metadata']['option'] );
	}

	/**
	 * @dataProvider sensors
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param class-string<AbstractSensor> $class  Sensor.
	 * @param string                       $method Method `updated_option` calls.
	 * @param string                       $option A setting of the sensor's own.
	 */
	public function test_a_setting_saved_over_ajax_is_logged( string $class, string $method, string $option ): void {
		RequestState::$admin = false;
		RequestState::$ajax  = true;

		$recorder = $this->recorder();
		$this->sensor( $class, $recorder )->$method( $option, 'before', 'after' );

		$this->assertCount( 1, $recorder->calls );
	}

	/**
	 * @dataProvider sensors
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param class-string<AbstractSensor> $class  Sensor.
	 * @param string                       $method Method `updated_option` calls.
	 * @param string                       $option A setting of the sensor's own.
	 */
	public function test_a_background_run_logs_nothing( string $class, string $method, string $option ): void {
		RequestState::$admin   = false;
		RequestState::$cron    = true;
		RequestState::$user_id = 0;

		$recorder = $this->recorder();
		$this->sensor( $class, $recorder )->$method( $option, 'before', 'after' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * An Action Scheduler job that runs inside a signed-in person's request
	 * (a loopback or the dashboard's own queue runner) has no person behind
	 * its writes.
	 *
	 * @dataProvider sensors
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param class-string<AbstractSensor> $class  Sensor.
	 * @param string                       $method Method `updated_option` calls.
	 * @param string                       $option A setting of the sensor's own.
	 */
	public function test_a_job_run_from_a_signed_in_request_logs_nothing( string $class, string $method, string $option ): void {
		ActionSchedulerContext::begin();

		$recorder = $this->recorder();
		$this->sensor( $class, $recorder )->$method( $option, 'before', 'after' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @dataProvider sensors
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param class-string<AbstractSensor> $class  Sensor.
	 * @param string                       $method Method `updated_option` calls.
	 * @param string                       $option A setting of the sensor's own.
	 */
	public function test_a_visitor_on_the_frontend_logs_nothing( string $class, string $method, string $option ): void {
		RequestState::$admin = false;

		$recorder = $this->recorder();
		$this->sensor( $class, $recorder )->$method( $option, 'before', 'after' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @dataProvider sensors
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param class-string<AbstractSensor> $class  Sensor.
	 * @param string                       $method Method `updated_option` calls.
	 * @param string                       $option A setting of the sensor's own.
	 */
	public function test_nobody_signed_in_logs_nothing_even_in_the_dashboard( string $class, string $method, string $option ): void {
		RequestState::$user_id = 0;

		$recorder = $this->recorder();
		$this->sensor( $class, $recorder )->$method( $option, 'before', 'after' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @dataProvider sensors
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param class-string<AbstractSensor> $class  Sensor.
	 * @param string                       $method Method `updated_option` calls.
	 * @param string                       $option A setting of the sensor's own.
	 */
	public function test_plugin_bookkeeping_logs_nothing_even_in_the_dashboard( string $class, string $method, string $option ): void {
		$recorder = $this->recorder();
		$sensor   = $this->sensor( $class, $recorder );
		foreach ( [ '_queue', '_notifications', '_db_version', '_tracks_x', '_last_check' ] as $suffix ) {
			$sensor->$method( $option . $suffix, 'before', 'after' );
		}

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @dataProvider sensors
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param class-string<AbstractSensor> $class  Sensor.
	 * @param string                       $method Method `updated_option` calls.
	 * @param string                       $option A setting of the sensor's own.
	 */
	public function test_an_unchanged_value_logs_nothing( string $class, string $method, string $option ): void {
		$recorder = $this->recorder();
		$this->sensor( $class, $recorder )->$method( $option, 'same', 'same' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @dataProvider sensors
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param class-string<AbstractSensor> $class  Sensor.
	 * @param string                       $method Method `updated_option` calls.
	 */
	public function test_an_option_of_another_plugin_logs_nothing( string $class, string $method ): void {
		$recorder = $this->recorder();
		$this->sensor( $class, $recorder )->$method( 'blogname', 'before', 'after' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_credential_like_setting_is_masked_in_the_changes_and_the_metadata(): void {
		$recorder = $this->recorder();
		( new WooCommerceSettingsSensor( $recorder ) )->on_update( 'woocommerce_shipping_api_key', 'old-secret-value', 'new-secret-value' );

		$this->assertCount( 1, $recorder->calls );
		$fields = $recorder->calls[0][1];
		$this->assertSame(
			[
				[
					'field' => 'woocommerce_shipping_api_key',
					'old'   => SensitiveOption::REDACTED,
					'new'   => SensitiveOption::REDACTED,
				],
			],
			$fields['changes']
		);
		$this->assertSame( SensitiveOption::REDACTED, $fields['metadata']['old'] );
		$this->assertSame( SensitiveOption::REDACTED, $fields['metadata']['new'] );
		$this->assertStringNotContainsString( 'secret-value', serialize( $fields ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_shop_setting_keeps_its_existing_fields(): void {
		$recorder = $this->recorder();
		( new WooCommerceSettingsSensor( $recorder ) )->on_update( 'woocommerce_currency', 'USD', 'EUR' );

		$fields = $recorder->calls[0][1];
		$this->assertSame( 'woocommerce_currency', $fields['option'] );
		$this->assertSame(
			[
				'option' => 'woocommerce_currency',
				'old'    => 'USD',
				'new'    => 'EUR',
			],
			$fields['metadata']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_shared_option_sensor_keeps_its_feature_and_state(): void {
		$recorder = $this->recorder();
		( new LwSeoSensor( $recorder ) )->on_option( 'lw_seo_title', 'before', 'after' );

		$fields = $recorder->calls[0][1];
		$this->assertSame( 'lw_seo_title', $fields['option'] );
		$this->assertSame( 'lw_seo_title', $fields['feature'] );
		$this->assertSame( 'after', $fields['state'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_firewall_rule_change_keeps_its_rule_field(): void {
		$recorder = $this->recorder();
		( new LwFirewallSensor( $recorder ) )->on_option( 'lw_firewall_rules', 'before', 'after' );

		$this->assertSame( 'lw_firewall_rules', $recorder->calls[0][1]['rule'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_event_payload_carries_the_setting_target_and_the_change(): void {
		$recorder = $this->recorder();
		( new RankMathSensor( $recorder ) )->on_update( 'rank_math_modules', [ 'seo' ], [ 'seo', 'sitemap' ] );

		$catalog = new EventCatalog();
		CatalogSeeder::seed( $catalog );
		$builder = new class( $catalog ) extends EventBuilder {

			public function __construct( EventCatalog $catalog ) {
				parent::__construct( $catalog, false, static fn(): string => RequestSource::ADMIN );
			}

			protected function current_context(): RequestContext {
				return new RequestContext( 1, 'admin', [ 'administrator' ], null, null, null );
			}
		};

		$payload = $builder->build( $recorder->calls[0][0], $recorder->calls[0][1] );

		$this->assertIsArray( $payload );
		$this->assertSame( 'admin', $payload['source'] );
		$this->assertSame(
			[
				'type'  => 'setting',
				'id'    => 'rank_math_modules',
				'label' => 'rank_math_modules',
			],
			$payload['target']
		);
		$this->assertSame(
			[
				[
					'field' => 'rank_math_modules',
					'old'   => 'seo',
					'new'   => 'seo, sitemap',
				],
			],
			$payload['changes']
		);
	}

	/**
	 * @param class-string<AbstractSensor> $class    Sensor.
	 * @param EventDispatcher              $recorder The recording dispatcher.
	 */
	private function sensor( string $class, EventDispatcher $recorder ): AbstractSensor {
		return new $class( $recorder );
	}

	/**
	 * A dispatcher that keeps what it was given. Also loads the request
	 * stand-ins, so call it before any sensor runs.
	 */
	private function recorder(): EventDispatcher {
		require_once __DIR__ . '/Fixtures/wordpress-request-functions.php';

		return new class() implements EventDispatcher {

			/** @var array<int, array{0: int, 1: array<string, mixed>}> */
			public array $calls = [];

			public function dispatch( int $code, array $fields = [] ): void {
				$this->calls[] = [ $code, $fields ];
			}
		};
	}
}
