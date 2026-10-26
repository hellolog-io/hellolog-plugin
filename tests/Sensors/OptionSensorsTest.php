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
use HelloLog\Sensors\Core\SettingsSensor;
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
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Fixtures\RequestState;
use PHPUnit\Framework\TestCase;

/**
 * The sensors read the request through WordPress, so every test runs in its
 * own process with the stand-ins of `Fixtures/wordpress-request-functions.php`.
 * The defaults are an administrator (user 1) on a dashboard screen.
 */
final class OptionSensorsTest extends TestCase {

	/**
	 * The option names here are synthetic: one plausible setting under each
	 * sensor's prefix, not a name any plugin is known to use.
	 *
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
		$this->assertSame( 'POST', $fields['metadata']['http_method'] );
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
	 * Plugins change settings from signed-in GET links (admin-post, nonce
	 * links), and a REST GET can act as a write through `_method`. So the
	 * method never keeps a write out of the log; it is recorded instead.
	 *
	 * @dataProvider sensors
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param class-string<AbstractSensor> $class  Sensor.
	 * @param string                       $method Method `updated_option` calls.
	 * @param string                       $option A setting of the sensor's own.
	 */
	public function test_a_signed_in_write_on_a_get_request_is_logged_with_its_method( string $class, string $method, string $option ): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$recorder = $this->recorder();
		$this->sensor( $class, $recorder )->$method( $option, 'before', 'after' );

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( 'GET', $recorder->calls[0][1]['metadata']['http_method'] );
		$this->assertSame( $option, $recorder->calls[0][1]['metadata']['option'] );
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
	public function test_a_rest_call_is_logged_whatever_its_method( string $class, string $method, string $option ): void {
		define( 'REST_REQUEST', true );
		RequestState::$admin = false;

		$recorder = $this->recorder();
		$sensor   = $this->sensor( $class, $recorder );
		foreach ( [ 'PUT', 'POST', 'DELETE', 'GET', 'HEAD', 'OPTIONS' ] as $http_method ) {
			$_SERVER['REQUEST_METHOD'] = $http_method;
			$sensor->$method( $option, 'before', 'after' );
		}

		$this->assertSame(
			[ 'PUT', 'POST', 'DELETE', 'GET', 'HEAD', 'OPTIONS' ],
			array_column( array_column( array_column( $recorder->calls, 1 ), 'metadata' ), 'http_method' )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_method_is_recorded_in_upper_case(): void {
		$_SERVER['REQUEST_METHOD'] = 'post';

		$recorder = $this->recorder();
		( new TermlySensor( $recorder ) )->on_update( 'termly_banner_position', 'before', 'after' );

		$this->assertSame( 'POST', $recorder->calls[0][1]['metadata']['http_method'] );
	}

	/**
	 * @dataProvider unusable_methods
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $http_method What the server reports as the method.
	 */
	public function test_a_method_that_is_not_a_plain_word_is_not_recorded_but_the_write_is( string $http_method ): void {
		$_SERVER['REQUEST_METHOD'] = $http_method;

		$recorder = $this->recorder();
		( new TermlySensor( $recorder ) )->on_update( 'termly_banner_position', 'before', 'after' );

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( [ 'option' => 'termly_banner_position' ], $recorder->calls[0][1]['metadata'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unusable_methods(): array {
		return [
			'empty'              => [ '' ],
			'a space'            => [ 'PO ST' ],
			'punctuation'        => [ 'POST;x' ],
			'a very long method' => [ str_repeat( 'A', 40 ) ],
			'a number'           => [ '123' ],
		];
	}

	/**
	 * WP-CLI runs as nobody unless `--user` is given; a command is deliberate.
	 *
	 * @dataProvider sensors
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param class-string<AbstractSensor> $class  Sensor.
	 * @param string                       $method Method `updated_option` calls.
	 * @param string                       $option A setting of the sensor's own.
	 */
	public function test_a_command_line_write_is_logged_without_a_signed_in_person( string $class, string $method, string $option ): void {
		define( 'WP_CLI', true );
		RequestState::$admin   = false;
		RequestState::$user_id = 0;

		$recorder = $this->recorder();
		unset( $_SERVER['REQUEST_METHOD'] );
		$this->sensor( $class, $recorder )->$method( $option, 'before', 'after' );

		$this->assertCount( 1, $recorder->calls );
		$this->assertArrayNotHasKey( 'http_method', $recorder->calls[0][1]['metadata'], 'A command has no HTTP method.' );
	}

	/**
	 * `wp cron event run` defines DOING_CRON inside a WP-CLI process, and
	 * Action Scheduler runs jobs from the command line too. Even with a user
	 * given to WP-CLI (`--user`) such a run has no person behind its writes.
	 *
	 * @dataProvider sensors
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param class-string<AbstractSensor> $class  Sensor.
	 * @param string                       $method Method `updated_option` calls.
	 * @param string                       $option A setting of the sensor's own.
	 */
	public function test_a_command_line_cron_run_or_job_logs_nothing( string $class, string $method, string $option ): void {
		define( 'WP_CLI', true );
		RequestState::$admin   = false;
		RequestState::$user_id = 1;
		RequestState::$cron    = true;

		$recorder = $this->recorder();
		$this->sensor( $class, $recorder )->$method( $option, 'before', 'after' );
		$this->assertSame( [], $recorder->calls );

		RequestState::$cron = false;
		ActionSchedulerContext::begin();
		$this->sensor( $class, $recorder )->$method( $option, 'before', 'after' );
		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * `updated_option` fires when the stored text differs, which `1` and `'1'`
	 * do, but nothing a reader could see changed.
	 *
	 * @dataProvider sensors
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param class-string<AbstractSensor> $class  Sensor.
	 * @param string                       $method Method `updated_option` calls.
	 * @param string                       $option A setting of the sensor's own.
	 */
	public function test_a_write_that_changes_nothing_a_reader_can_see_logs_nothing( string $class, string $method, string $option ): void {
		$recorder = $this->recorder();
		$sensor   = $this->sensor( $class, $recorder );
		$sensor->$method( $option, 1, '1' );
		$sensor->$method( $option, [ 'a', 'b' ], [ 'b', 'a' ] );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_real_settings_the_pattern_used_to_hide_are_logged(): void {
		$recorder = $this->recorder();
		$sensor   = new WooCommerceSettingsSensor( $recorder );
		$sensor->on_update( 'woocommerce_demo_store_notice', 'Sale', 'Big sale' );
		$sensor->on_update( 'woocommerce_show_marketplace_suggestions', 'yes', 'no' );
		$sensor->on_update( 'woocommerce_marketplace_suggestions', [ 'a' ], [ 'b' ] );

		$this->assertSame(
			[ 'woocommerce_demo_store_notice', 'woocommerce_show_marketplace_suggestions' ],
			array_column( array_column( $recorder->calls, 1 ), 'option' )
		);
	}

	/**
	 * Rank Math's own settings options are hyphenated.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rank_math_settings_with_hyphenated_names_are_logged(): void {
		$recorder = $this->recorder();
		$sensor   = new RankMathSensor( $recorder );
		foreach ( [ 'rank-math-options-general', 'rank-math-options-titles', 'rank-math-options-sitemap' ] as $option ) {
			$sensor->on_update( $option, [ 'a' => 'before' ], [ 'a' => 'after' ] );
		}

		$this->assertSame( [ 8900, 8900, 8900 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame(
			[ 'rank-math-options-general', 'rank-math-options-titles', 'rank-math-options-sitemap' ],
			array_column( array_column( array_column( $recorder->calls, 1 ), 'target' ), 'id' )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_option_that_only_starts_with_rank_logs_nothing(): void {
		$recorder = $this->recorder();
		( new RankMathSensor( $recorder ) )->on_update( 'ranking_page', 'before', 'after' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * A gateway keeps all its settings, secrets included, in one array option.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_gateway_settings_save_never_carries_its_secrets(): void {
		$settings = static fn( string $enabled, string $suffix ): array => [
			'enabled'         => $enabled,
			'secret_key'      => 'sk_live_' . $suffix,
			'test_secret_key' => 'sk_test_' . $suffix,
			'webhook_secret'  => 'whsec_' . $suffix,
		];

		$recorder = $this->recorder();
		$sensor   = new WooCommerceSettingsSensor( $recorder );
		$sensor->on_update( 'woocommerce_stripe_settings', $settings( 'no', 'OLD' ), $settings( 'yes', 'NEW' ) );
		$sensor->on_update( 'woocommerce_stripe_settings', $settings( 'yes', 'NEW' ), $settings( 'yes', 'NEWER' ) );

		$this->assertCount( 2, $recorder->calls );
		$this->assertSame(
			'{"enabled":"yes","secret_key":"[redacted]","test_secret_key":"[redacted]","webhook_secret":"[redacted]"}',
			$recorder->calls[0][1]['changes'][0]['new']
		);
		$this->assertNull( $recorder->calls[1][1]['changes'][0]['old'], 'A secret that is the only change is a plain changed flag.' );
		$this->assertNull( $recorder->calls[1][1]['changes'][0]['new'] );
		foreach ( $recorder->calls as $call ) {
			$flat = serialize( $call[1] );
			foreach ( [ 'sk_live_', 'sk_test_', 'whsec_', 'OLD', 'NEW' ] as $secret ) {
				$this->assertStringNotContainsString( $secret, $flat );
			}
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_seo_plugin_settings_never_carry_the_nested_tokens(): void {
		$wpseo = static fn( bool $on, string $suffix ): array => [
			'content_analysis_active' => $on,
			'semrush_tokens'          => [
				'access_token'  => 'at_' . $suffix,
				'refresh_token' => 'rt_' . $suffix,
			],
		];

		$recorder = $this->recorder();
		( new YoastSeoSensor( $recorder ) )->on_update( 'wpseo', $wpseo( false, 'OLD' ), $wpseo( true, 'NEW' ) );

		$this->assertCount( 1, $recorder->calls );
		$flat = serialize( $recorder->calls[0][1] );
		foreach ( [ 'at_', 'rt_', 'OLD', 'NEW' ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $flat );
		}
		$this->assertStringContainsString( '"semrush_tokens":"[redacted]"', $recorder->calls[0][1]['changes'][0]['new'] );
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
				'option'      => 'woocommerce_currency',
				'old'         => 'USD',
				'new'         => 'EUR',
				'http_method' => 'POST',
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
	 * A value no masking can read: turning it into text throws. Whatever goes
	 * wrong while a sensor reads a write must stay out of the request that
	 * made it.
	 *
	 * @dataProvider sensors
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param class-string<AbstractSensor> $class  Sensor.
	 * @param string                       $method Method `updated_option` calls.
	 * @param string                       $option A setting of the sensor's own.
	 * @param int                          $code   Event code.
	 */
	public function test_a_value_that_cannot_be_read_is_reported_and_never_thrown( string $class, string $method, string $option, int $code ): void {
		$recorder = $this->recorder();
		require_once __DIR__ . '/../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../Events/Fixtures/wordpress-functions.php';

		$unreadable = new class() implements \Stringable {
			public function __toString(): string {
				throw new \RuntimeException( 'unreadable' );
			}
		};

		$this->sensor( $class, $recorder )->$method( $option, 'before', $unreadable );

		$this->assertSame( [], $recorder->calls );
		$this->assertCount( 1, WpCalls::$actions );
		[ $hook, $args ] = WpCalls::$actions[0];
		$this->assertSame( 'hellolog_dispatch_failed', $hook );
		$this->assertInstanceOf( \RuntimeException::class, $args[0] );
		$this->assertSame( 'unreadable', $args[0]->getMessage() );
		$this->assertSame( $code, $args[1] );
		$this->assertSame( [ 'option' => $option ], $args[2] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_core_setting_that_cannot_be_read_is_reported_and_never_thrown(): void {
		$recorder = $this->recorder();
		require_once __DIR__ . '/../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../Events/Fixtures/wordpress-functions.php';

		$unreadable = new class() implements \JsonSerializable {
			public function jsonSerialize(): mixed {
				throw new \RuntimeException( 'unreadable' );
			}
		};

		( new SettingsSensor( $recorder ) )->on_update( 'admin_email', 'a@example.com', $unreadable );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 'hellolog_dispatch_failed', WpCalls::$actions[0][0] );
		$this->assertSame( 6002, WpCalls::$actions[0][1][1] );
		$this->assertSame( [ 'option' => 'admin_email' ], WpCalls::$actions[0][1][2] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failure_listener_that_throws_does_not_reach_wordpress_either(): void {
		$recorder = $this->recorder();
		require_once __DIR__ . '/../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../Events/Fixtures/wordpress-functions.php';
		WpCalls::$action_throws = true;

		$unreadable = new class() implements \Stringable {
			public function __toString(): string {
				throw new \RuntimeException( 'unreadable' );
			}
		};

		( new TermlySensor( $recorder ) )->on_update( 'termly_banner_position', 'before', $unreadable );

		$this->assertCount( 1, WpCalls::$actions );
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
	 * stand-ins and defaults the request to a POST, so call it before any
	 * sensor runs.
	 */
	private function recorder(): EventDispatcher {
		require_once __DIR__ . '/Fixtures/wordpress-request-functions.php';

		// A dashboard save unless the test set another method first.
		$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'POST';

		return new class() implements EventDispatcher {

			/** @var array<int, array{0: int, 1: array<string, mixed>}> */
			public array $calls = [];

			public function dispatch( int $code, array $fields = [] ): void {
				$this->calls[] = [ $code, $fields ];
			}
		};
	}
}
