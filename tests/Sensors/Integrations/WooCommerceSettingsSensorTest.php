<?php
/**
 * Tests for what the shop settings sensor tells about payment gateway
 * settings and the bank accounts of the bank transfer gateway.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Integrations;

use HelloLog\Events\ActionSchedulerContext;
use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\RequestContext;
use HelloLog\Events\RequestSource;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Integrations\WooCommerceSettingsSensor;
use HelloLog\Sensors\SensitiveOption;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Fixtures\RequestState;
use HelloLog\Tests\Sensors\Integrations\Fixtures\PaymentGatewayState;
use PHPUnit\Framework\TestCase;

/**
 * An array-valued shop setting (a payment gateway's settings, say) tells which
 * of its keys changed (9400), credential-like keys without their values. The
 * bank accounts of the bank transfer gateway are one critical event (9401)
 * that names each account by the last four digits of its number, never the
 * number itself. The first save of a payment option is logged too; the first
 * write of any other shop option is not.
 *
 * Tests that run the sensor's hooks read the request through WordPress, so
 * they run in their own process with the stand-ins of the option sensor
 * tests: an administrator on a dashboard screen, saving with a POST.
 */
final class WooCommerceSettingsSensorTest extends TestCase {

	private const IBAN_OLD = 'GB33 EXMP 2000 0055 7799 11';

	private const IBAN_NEW = 'GB94 EXMP 3000 0066 8800 22';

	// ------------------------------------------------------------------
	// Gateway settings, key by key.
	// ------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_gateway_save_tells_each_changed_key_and_masks_the_secret(): void {
		$recorder = $this->recorder();
		( new WooCommerceSettingsSensor( $recorder ) )->on_update(
			'woocommerce_stripe_settings',
			[
				'enabled'    => 'no',
				'title'      => 'Card',
				'secret_key' => 'sk_live_OLDSECRET',
			],
			[
				'enabled'    => 'yes',
				'title'      => 'Card',
				'secret_key' => 'sk_live_NEWSECRET',
			]
		);

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9400, $code );
		$this->assertSame(
			[
				self::change( 'enabled', 'no', 'yes' ),
				self::change( 'secret_key', SensitiveOption::REDACTED, SensitiveOption::REDACTED ),
			],
			$fields['changes']
		);
		$this->assertSame( self::setting( 'woocommerce_stripe_settings' ), $fields['target'] );
		$this->assertSame( 'woocommerce_stripe_settings', $fields['option'] );
		$this->assertSame(
			[
				'option'      => 'woocommerce_stripe_settings',
				'old'         => '<complex>',
				'new'         => '<complex>',
				'http_method' => 'POST',
			],
			$fields['metadata']
		);
		$this->assertStringNotContainsString( 'SECRET', serialize( $fields ) );
	}

	/**
	 * Gateway credentials under every common name: a key whose name looks
	 * like a credential reads `[redacted]`, a password reads as a plain
	 * "changed" flag, and no value appears anywhere in the result.
	 */
	public function test_every_credential_like_gateway_key_is_told_without_its_value(): void {
		$settings = static fn( string $suffix ): array => [
			'secret_key'     => 'sk_' . $suffix,
			'api_key'        => 'ak_' . $suffix,
			'password'       => 'pw_' . $suffix,
			'webhook_secret' => 'whsec_' . $suffix,
			'private_key'    => 'pk_' . $suffix,
		];

		$changes = WooCommerceSettingsSensor::setting_changes( 'woocommerce_example_gateway_settings', $settings( 'OLD' ), $settings( 'NEW' ) );

		$this->assertSame(
			[
				self::change( 'secret_key', SensitiveOption::REDACTED, SensitiveOption::REDACTED ),
				self::change( 'api_key', SensitiveOption::REDACTED, SensitiveOption::REDACTED ),
				self::change( 'password', null, null ),
				self::change( 'webhook_secret', SensitiveOption::REDACTED, SensitiveOption::REDACTED ),
				self::change( 'private_key', SensitiveOption::REDACTED, SensitiveOption::REDACTED ),
			],
			$changes
		);
		$this->assertNoneOf( [ 'OLD', 'NEW' ], $changes );
	}

	/**
	 * A key that holds a structure is told as one value, with the secrets
	 * inside it masked at every depth, also in name/value pairs.
	 */
	public function test_a_nested_gateway_setting_keeps_its_secrets_masked(): void {
		$settings = static fn( string $mode, string $suffix ): array => [
			'connection' => [
				'mode'          => $mode,
				'client_secret' => 'cs_' . $suffix,
			],
			'fields'     => [
				[
					'name'  => 'api_token',
					'value' => 'tok_' . $suffix,
				],
			],
		];

		$changes = WooCommerceSettingsSensor::setting_changes( 'woocommerce_example_gateway_settings', $settings( 'test', 'OLD' ), $settings( 'live', 'NEW' ) );

		$this->assertSame(
			[
				self::change( 'connection', '{"client_secret":"[redacted]","mode":"test"}', '{"client_secret":"[redacted]","mode":"live"}' ),
				self::change( 'fields', null, null ),
			],
			$changes
		);
		$this->assertNoneOf( [ 'OLD', 'NEW' ], $changes );
	}

	/**
	 * A key that is new, or gone, with nothing in it on the other side is not
	 * a change: a first save does not list every empty field.
	 */
	public function test_a_key_that_is_empty_on_both_sides_is_not_a_change(): void {
		$this->assertSame(
			[
				self::change( 'enabled', null, 'yes' ),
				self::change( 'title', null, 'Check payments' ),
			],
			WooCommerceSettingsSensor::setting_changes(
				'woocommerce_cheque_settings',
				null,
				[
					'enabled'      => 'yes',
					'title'        => 'Check payments',
					'instructions' => '',
					'methods'      => [],
				]
			)
		);
		$this->assertSame(
			[ self::change( 'legacy', 'on', null ) ],
			WooCommerceSettingsSensor::setting_changes(
				'woocommerce_cheque_settings',
				[
					'enabled' => 'yes',
					'legacy'  => 'on',
					'notes'   => '',
				],
				[ 'enabled' => 'yes' ]
			)
		);
	}

	/**
	 * Keys with a leading underscore are the gateway's own bookkeeping
	 * (PayPal Standard's `_should_load`, written by the shop itself).
	 */
	public function test_bookkeeping_keys_of_a_setting_are_not_changes(): void {
		$this->assertSame(
			[ self::change( 'enabled', 'no', 'yes' ) ],
			WooCommerceSettingsSensor::setting_changes( 'woocommerce_paypal_settings', [ 'enabled' => 'no' ], [ 'enabled' => 'yes', '_should_load' => 'no' ] )
		);
		$this->assertSame(
			[],
			WooCommerceSettingsSensor::setting_changes( 'woocommerce_paypal_settings', [ 'enabled' => 'yes' ], [ 'enabled' => 'yes', '_should_load' => 'yes' ] )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_write_of_bookkeeping_keys_only_logs_nothing(): void {
		$recorder = $this->recorder();
		$this->gateways_loaded( [ 'paypal' => 'woocommerce_paypal_settings' ] );

		$sensor = new WooCommerceSettingsSensor( $recorder );
		$sensor->on_add( 'woocommerce_paypal_settings', [ '_should_load' => 'no' ] );
		$sensor->on_update( 'woocommerce_paypal_settings', [ 'enabled' => 'no' ], [ 'enabled' => 'no', '_should_load' => 'no' ] );

		$this->assertSame( [], $recorder->calls );
	}

	public function test_a_list_valued_setting_stays_one_change(): void {
		$this->assertSame(
			[ self::change( 'woocommerce_specific_allowed_countries', 'AT, HU', 'AT, DE, HU' ) ],
			WooCommerceSettingsSensor::setting_changes( 'woocommerce_specific_allowed_countries', [ 'HU', 'AT' ], [ 'HU', 'AT', 'DE' ] )
		);
	}

	/**
	 * A setting whose own name looks like a credential is masked whole, keys
	 * and all.
	 */
	public function test_a_credential_like_setting_stays_one_masked_change(): void {
		$changes = WooCommerceSettingsSensor::setting_changes(
			'woocommerce_example_credentials',
			[
				'client_id' => 'id_OLD',
				'mode'      => 'test',
			],
			[
				'client_id' => 'id_NEW',
				'mode'      => 'live',
			]
		);

		$this->assertSame(
			[ self::change( 'woocommerce_example_credentials', SensitiveOption::REDACTED, SensitiveOption::REDACTED ) ],
			$changes
		);
	}

	/**
	 * A setting that is itself a name/value pair would lose the pair's masking
	 * if its keys were told one by one.
	 */
	public function test_a_setting_that_is_a_secret_pair_stays_one_masked_change(): void {
		$changes = WooCommerceSettingsSensor::setting_changes(
			'woocommerce_example_field',
			[
				'name'  => 'api_key',
				'value' => 'sk_OLD',
			],
			[
				'name'  => 'api_key',
				'value' => 'sk_NEW',
			]
		);

		$this->assertSame( [ self::change( 'woocommerce_example_field', null, null ) ], $changes );
		$this->assertNoneOf( [ 'sk_' ], $changes );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_key_that_cannot_be_read_is_reported_and_never_thrown(): void {
		$recorder = $this->recorder();
		require_once __DIR__ . '/../../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';

		( new WooCommerceSettingsSensor( $recorder ) )->on_update( 'woocommerce_stripe_settings', [ 'title' => 'Card' ], [ 'title' => self::unreadable() ] );

		$this->assertSame( [], $recorder->calls );
		$this->assertFailure( 9400, 'woocommerce_stripe_settings' );
	}

	// ------------------------------------------------------------------
	// First writes (`added_option`).
	// ------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_sensor_listens_to_updates_and_first_writes(): void {
		require_once __DIR__ . '/../../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-hook-functions.php';

		( new WooCommerceSettingsSensor( $this->recorder() ) )->boot();

		$this->assertSame(
			[
				'priority'      => 10,
				'accepted_args' => 3,
			],
			WpCalls::$hooks['updated_option']
		);
		$this->assertSame(
			[
				'priority'      => 10,
				'accepted_args' => 2,
			],
			WpCalls::$hooks['added_option']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_first_save_of_a_gateway_is_logged_without_an_old_value(): void {
		$recorder = $this->recorder();
		$this->gateways_loaded( [ 'stripe' => 'woocommerce_stripe_settings' ] );

		( new WooCommerceSettingsSensor( $recorder ) )->on_add(
			'woocommerce_stripe_settings',
			[
				'enabled'    => 'yes',
				'title'      => '',
				'secret_key' => 'sk_live_FIRSTSECRET',
			]
		);

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9400, $code );
		$this->assertSame(
			[
				self::change( 'enabled', null, 'yes' ),
				self::change( 'secret_key', SensitiveOption::REDACTED, SensitiveOption::REDACTED ),
			],
			$fields['changes']
		);
		$this->assertSame( self::setting( 'woocommerce_stripe_settings' ), $fields['target'] );
		$this->assertNull( $fields['metadata']['old'] );
		$this->assertSame( '<complex>', $fields['metadata']['new'] );
		$this->assertStringNotContainsString( 'FIRSTSECRET', serialize( $fields ) );
	}

	/**
	 * A gateway may keep its settings under a key of its own; the gateway
	 * tells which.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_gateway_with_its_own_option_key_is_logged_on_its_first_save(): void {
		$recorder = $this->recorder();
		$this->gateways_loaded(
			[
				'bacs'         => 'woocommerce_bacs_settings',
				'ppcp-gateway' => 'woocommerce_ppcp-gateway_settings',
			]
		);

		( new WooCommerceSettingsSensor( $recorder ) )->on_add( 'woocommerce_ppcp-gateway_settings', [ 'enabled' => 'yes' ] );

		$this->assertSame( [ 9400 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ self::change( 'enabled', null, 'yes' ) ], $recorder->calls[0][1]['changes'] );
	}

	/**
	 * The shop adds plenty of its options on the fly; only a payment option's
	 * first write is a setting somebody saved.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_first_write_of_any_other_shop_option_logs_nothing(): void {
		$recorder = $this->recorder();
		$this->gateways_loaded( [ 'bacs' => 'woocommerce_bacs_settings' ] );

		$sensor = new WooCommerceSettingsSensor( $recorder );
		$sensor->on_add( 'woocommerce_flat_rate_1_settings', [ 'cost' => '5' ] );
		$sensor->on_add( 'woocommerce_coming_soon', 'yes' );
		$sensor->on_add( 'blogname', 'Example' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * The installer adds every default setting, also on an upgrade.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_while_the_shop_installs_a_first_write_logs_nothing(): void {
		$recorder = $this->recorder();
		$this->gateways_loaded( [ 'bacs' => 'woocommerce_bacs_settings' ] );
		define( 'WC_INSTALLING', true );

		$sensor = new WooCommerceSettingsSensor( $recorder );
		$sensor->on_add( 'woocommerce_bacs_settings', [ 'enabled' => 'no' ] );
		$sensor->on_add( 'woocommerce_bacs_accounts', [ self::account( '12345678' ) ] );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 0, PaymentGatewayState::$wc_calls );
	}

	/**
	 * Before the gateways are loaded the sensor does not load them (that
	 * builds every gateway, and a gateway being built writes its own
	 * settings). Only the bank accounts are known then.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_before_the_gateways_are_loaded_only_the_bank_accounts_are_a_payment_option(): void {
		$recorder = $this->recorder();
		require_once __DIR__ . '/Fixtures/woocommerce-payment-gateway-functions.php';
		PaymentGatewayState::$option_keys = [ 'paypal' => 'woocommerce_paypal_settings' ];

		$sensor = new WooCommerceSettingsSensor( $recorder );
		$sensor->on_add( 'woocommerce_paypal_settings', [ '_should_load' => 'no' ] );
		$sensor->on_add( 'woocommerce_bacs_accounts', [ self::account( '12345678' ) ] );

		$this->assertSame( [ 9401 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 0, PaymentGatewayState::$wc_calls );
	}

	/**
	 * While the gateways are being loaded the registry is not complete yet,
	 * and asking for it would build a second one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_while_the_gateways_are_being_loaded_the_registry_is_not_asked(): void {
		$recorder = $this->recorder();
		$this->gateways_loaded( [ 'paypal' => 'woocommerce_paypal_settings' ] );
		PaymentGatewayState::$doing = [ 'wc_payment_gateways_initialized' ];

		( new WooCommerceSettingsSensor( $recorder ) )->on_add( 'woocommerce_paypal_settings', [ '_should_load' => 'no' ] );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 0, PaymentGatewayState::$wc_calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_gateway_first_write_by_a_background_run_or_a_visitor_logs_nothing_and_asks_no_registry(): void {
		$recorder = $this->recorder();
		$this->gateways_loaded( [ 'stripe' => 'woocommerce_stripe_settings' ] );
		$sensor = new WooCommerceSettingsSensor( $recorder );

		RequestState::$cron = true;
		$sensor->on_add( 'woocommerce_stripe_settings', [ 'enabled' => 'yes' ] );
		RequestState::$cron    = false;
		RequestState::$admin   = false;
		RequestState::$user_id = 0;
		$sensor->on_add( 'woocommerce_stripe_settings', [ 'enabled' => 'yes' ] );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 0, PaymentGatewayState::$wc_calls, 'The policy is asked before the registry.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_registry_that_cannot_be_read_is_reported_and_never_thrown(): void {
		$recorder = $this->recorder();
		require_once __DIR__ . '/../../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';
		$this->gateways_loaded( [ 'stripe' => 'woocommerce_stripe_settings' ] );
		PaymentGatewayState::$wc_throws = true;

		( new WooCommerceSettingsSensor( $recorder ) )->on_add( 'woocommerce_stripe_settings', [ 'enabled' => 'yes' ] );

		$this->assertSame( [], $recorder->calls );
		$this->assertFailure( 9400, 'woocommerce_stripe_settings' );
	}

	// ------------------------------------------------------------------
	// Bank accounts (BACS).
	// ------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_bank_account_change_is_one_critical_event_and_no_setting_event(): void {
		$recorder = $this->recorder();
		( new WooCommerceSettingsSensor( $recorder ) )->on_update(
			'woocommerce_bacs_accounts',
			[ self::account( '12345678', '', 'First Bank' ) ],
			[ self::account( '12345678', '', 'Second Bank' ) ]
		);

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9401, $code );
		$this->assertSame( [ self::change( 'account:…5678.bank_name', 'First Bank', 'Second Bank' ) ], $fields['changes'] );
		$this->assertSame( self::setting( 'woocommerce_bacs_accounts' ), $fields['target'] );
		$this->assertSame(
			[
				'option'      => 'woocommerce_bacs_accounts',
				'http_method' => 'POST',
			],
			$fields['metadata']
		);
		$this->assertStringNotContainsString( '12345678', serialize( $fields ) );

		$payload = $this->build( $code, $fields );
		$this->assertSame( 'Bank account details (BACS) changed.', $payload['message'] );
		$this->assertSame( 'critical', $payload['severity'] );
		$this->assertSame( 'setting', $payload['object'] );
		$this->assertSame( 'updated', $payload['event_type'] );
		$this->assertSame( [ self::change( 'account:…5678.bank_name', 'First Bank', 'Second Bank' ) ], $payload['changes'] );
	}

	/**
	 * The very first bank account: the option did not exist before.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_first_bank_account_is_logged(): void {
		$recorder = $this->recorder();
		$sensor   = new WooCommerceSettingsSensor( $recorder );
		$sensor->on_add( 'woocommerce_bacs_accounts', [] );
		$sensor->on_add( 'woocommerce_bacs_accounts', [ self::account( '', self::IBAN_OLD ) ] );

		$this->assertSame( [ 9401 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ self::change( 'account:…9911', null, 'added' ) ], $recorder->calls[0][1]['changes'] );
		$this->assertStringNotContainsString( '557799', serialize( $recorder->calls[0][1] ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bank_accounts_saved_in_another_order_log_nothing(): void {
		$recorder = $this->recorder();
		$first    = self::account( '11112222' );
		$second   = self::account( '33334444' );
		( new WooCommerceSettingsSensor( $recorder ) )->on_update( 'woocommerce_bacs_accounts', [ $first, $second ], [ $second, $first ] );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * The shop only ever writes its bank accounts for a person on a settings
	 * screen or the REST API. A write from a visitor's request is exactly what
	 * the alert is for: it is logged, with its source and without an actor.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_bank_account_change_by_a_visitor_is_logged_without_an_actor(): void {
		$recorder              = $this->recorder();
		RequestState::$admin   = false;
		RequestState::$user_id = 0;

		$sensor = new WooCommerceSettingsSensor( $recorder );
		$sensor->on_add( 'woocommerce_bacs_accounts', [ self::account( '12345678' ) ] );
		$sensor->on_update( 'woocommerce_bacs_accounts', [ self::account( '12345678' ) ], [ self::account( '12345678', self::IBAN_NEW ) ] );

		$this->assertSame( [ 9401, 9401 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ self::change( 'account:…5678', null, 'added' ) ], $recorder->calls[0][1]['changes'] );

		$payload = $this->build(
			9401,
			$recorder->calls[1][1],
			[ RequestSource::class, 'current' ],
			new RequestContext( null, null, [], null, null, null )
		);
		$this->assertSame( RequestSource::FRONTEND, $payload['source'] );
		$this->assertArrayNotHasKey( 'user', $payload );
		$this->assertSame( [ self::change( 'account:…5678.iban', SensitiveOption::REDACTED, SensitiveOption::REDACTED ) ], $payload['changes'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function background_runs(): array {
		return [
			'a cron run'      => [ RequestSource::CRON ],
			'a queued job'    => [ RequestSource::ACTION_SCHEDULER ],
		];
	}

	/**
	 * A background run that changes the bank accounts is logged as that run,
	 * even when it runs inside a signed-in person's request: no actor.
	 *
	 * @dataProvider background_runs
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $source The run's source.
	 */
	public function test_a_bank_account_change_by_a_background_run_is_logged_as_that_run( string $source ): void {
		$recorder = $this->recorder();
		if ( RequestSource::CRON === $source ) {
			RequestState::$cron = true;
		} else {
			ActionSchedulerContext::begin( 7 );
		}

		( new WooCommerceSettingsSensor( $recorder ) )->on_update( 'woocommerce_bacs_accounts', [ self::account( '12345678' ) ], [ self::account( '87654321' ) ] );

		$this->assertSame( [ 9401 ], array_column( $recorder->calls, 0 ) );
		$payload = $this->build(
			9401,
			$recorder->calls[0][1],
			[ RequestSource::class, 'current' ],
			new RequestContext( 1, 'admin', [ 'administrator' ], null, null, null )
		);
		$this->assertSame( $source, $payload['source'] );
		$this->assertArrayNotHasKey( 'user', $payload );
		$this->assertSame(
			[
				self::change( 'account:…5678', 'present', null ),
				self::change( 'account:…4321', null, 'added' ),
			],
			$payload['changes']
		);
	}

	/**
	 * The installer never writes the bank accounts, but it adds defaults the
	 * way a first save would; its writes stay unlogged.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_while_the_shop_installs_the_first_bank_account_logs_nothing_even_unattended(): void {
		$recorder           = $this->recorder();
		RequestState::$cron = true;
		define( 'WC_INSTALLING', true );

		( new WooCommerceSettingsSensor( $recorder ) )->on_add( 'woocommerce_bacs_accounts', [ self::account( '12345678' ) ] );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * The account number stays, only the IBAN is new: one masked change on
	 * that account, all the way to the payload.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_new_iban_on_a_kept_account_number_is_one_masked_change(): void {
		$recorder = $this->recorder();
		( new WooCommerceSettingsSensor( $recorder ) )->on_update(
			'woocommerce_bacs_accounts',
			[ self::account( '12345678', self::IBAN_OLD ) ],
			[ self::account( '12345678', self::IBAN_NEW ) ]
		);

		$this->assertSame( [ 9401 ], array_column( $recorder->calls, 0 ) );
		$payload = $this->build( 9401, $recorder->calls[0][1] );
		$this->assertSame( 'Bank account details (BACS) changed.', $payload['message'] );
		$this->assertSame( 'critical', $payload['severity'] );
		$this->assertSame( self::setting( 'woocommerce_bacs_accounts' ), $payload['target'] );
		$this->assertSame( [ self::change( 'account:…5678.iban', SensitiveOption::REDACTED, SensitiveOption::REDACTED ) ], $payload['changes'] );
		$this->assertNoneOf(
			[ '12345678', self::IBAN_OLD, self::IBAN_NEW, str_replace( ' ', '', self::IBAN_OLD ), str_replace( ' ', '', self::IBAN_NEW ), '557799', '668800' ],
			[ $recorder->calls, $payload ]
		);
	}

	/**
	 * A payload keeps 50 changes. Many accounts added in front of a changed
	 * one must not push the change of the existing account out.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_changed_account_survives_many_added_ones_in_front_of_it(): void {
		$added = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$added[] = self::account( sprintf( '9%07d', $i ) );
		}

		$recorder = $this->recorder();
		( new WooCommerceSettingsSensor( $recorder ) )->on_update(
			'woocommerce_bacs_accounts',
			[ self::account( '12345678', self::IBAN_OLD ) ],
			[ ...$added, self::account( '12345678', self::IBAN_NEW ) ]
		);

		$payload = $this->build( 9401, $recorder->calls[0][1] );
		$this->assertCount( 50, $payload['changes'] );
		$this->assertSame( self::change( 'account:…5678.iban', SensitiveOption::REDACTED, SensitiveOption::REDACTED ), $payload['changes'][0] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_bank_account_that_cannot_be_read_is_reported_and_never_thrown(): void {
		$recorder = $this->recorder();
		require_once __DIR__ . '/../../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';

		$changed              = self::account( '12345678' );
		$changed['bank_name'] = self::unreadable();
		( new WooCommerceSettingsSensor( $recorder ) )->on_update( 'woocommerce_bacs_accounts', [ self::account( '12345678' ) ], [ $changed ] );

		$this->assertSame( [], $recorder->calls );
		$this->assertFailure( 9401, 'woocommerce_bacs_accounts' );
	}

	public function test_accounts_in_another_order_are_no_change(): void {
		$first  = self::account( '11112222', self::IBAN_OLD );
		$second = self::account( '33334444', '', 'Other Bank' );
		$blank  = self::account( '', '', '' );

		$this->assertSame( [], WooCommerceSettingsSensor::bacs_changes( [ $first, $second, $blank ], [ $blank, $second, $first ] ) );
	}

	/**
	 * A new IBAN on an account with no other number is another account: the
	 * old one is gone, the new one added. Neither number is in the result.
	 */
	public function test_a_replaced_iban_is_a_removed_and_an_added_account(): void {
		$changes = WooCommerceSettingsSensor::bacs_changes(
			[ self::account( '', self::IBAN_OLD ) ],
			[ self::account( '', self::IBAN_NEW ) ]
		);

		$this->assertSame(
			[
				self::change( 'account:…9911', 'present', null ),
				self::change( 'account:…0022', null, 'added' ),
			],
			$changes
		);
		$this->assertNoneOf(
			[ self::IBAN_OLD, self::IBAN_NEW, str_replace( ' ', '', self::IBAN_OLD ), str_replace( ' ', '', self::IBAN_NEW ), '20000055', '30000066' ],
			$changes
		);
	}

	/**
	 * On an account found again by its number, the bank and the holder's name
	 * are told with their values; the sort code, BIC and IBAN only as changed.
	 */
	public function test_a_changed_account_tells_names_with_values_and_bank_codes_without(): void {
		$old = [
			'account_name'   => 'Example Ltd',
			'account_number' => '12345678',
			'bank_name'      => 'First Bank',
			'sort_code'      => '12-34-56',
			'iban'           => self::IBAN_OLD,
			'bic'            => 'EXMPGB2L',
		];
		$new = [
			'account_name'   => 'Example Trading Ltd',
			'account_number' => '12345678',
			'bank_name'      => 'Second Bank',
			'sort_code'      => '65-43-21',
			'iban'           => self::IBAN_NEW,
			'bic'            => 'EXMPGB3L',
		];

		$changes = WooCommerceSettingsSensor::bacs_changes( [ $old ], [ $new ] );

		$this->assertSame(
			[
				self::change( 'account:…5678.account_name', 'Example Ltd', 'Example Trading Ltd' ),
				self::change( 'account:…5678.bank_name', 'First Bank', 'Second Bank' ),
				self::change( 'account:…5678.sort_code', SensitiveOption::REDACTED, SensitiveOption::REDACTED ),
				self::change( 'account:…5678.iban', SensitiveOption::REDACTED, SensitiveOption::REDACTED ),
				self::change( 'account:…5678.bic', SensitiveOption::REDACTED, SensitiveOption::REDACTED ),
			],
			$changes
		);
		$this->assertNoneOf( [ '12345678', '12-34-56', '65-43-21', 'EXMPGB', '557799', '668800' ], $changes );
	}

	/**
	 * A new account number on an account found again by its IBAN: the IBAN
	 * names it.
	 */
	public function test_an_account_found_by_its_iban_is_named_by_it(): void {
		$changes = WooCommerceSettingsSensor::bacs_changes(
			[ self::account( '00012345', self::IBAN_OLD ) ],
			[ self::account( '00067890', self::IBAN_OLD ) ]
		);

		$this->assertSame(
			[ self::change( 'account:…9911.account_number', SensitiveOption::REDACTED, SensitiveOption::REDACTED ) ],
			$changes
		);
	}

	/**
	 * Spaces and dashes are how a number is written, not which account it is,
	 * nor a change of it.
	 */
	public function test_an_account_is_found_again_whatever_the_spacing_of_its_number(): void {
		$changes = WooCommerceSettingsSensor::bacs_changes(
			[ self::account( '1234-5678', '', 'First Bank' ) ],
			[ self::account( '1234 5678', '', 'Second Bank' ) ]
		);

		$this->assertSame( [ self::change( 'account:…5678.bank_name', 'First Bank', 'Second Bank' ) ], $changes );
	}

	/**
	 * Every bank code compares the way it is matched: without spaces, dashes
	 * or case.
	 */
	public function test_bank_codes_written_another_way_are_no_change(): void {
		$old = [
			'account_name'   => 'Example Ltd',
			'account_number' => '12345678',
			'bank_name'      => 'First Bank',
			'sort_code'      => '12-34-56',
			'iban'           => self::IBAN_OLD,
			'bic'            => 'EXMPGB2L',
		];
		$new = [
			'account_name'   => 'Example Ltd',
			'account_number' => '1234 5678',
			'bank_name'      => 'First Bank',
			'sort_code'      => '123456',
			'iban'           => strtolower( str_replace( ' ', '', self::IBAN_OLD ) ),
			'bic'            => 'exmpgb2l',
		];

		$this->assertSame( [], WooCommerceSettingsSensor::bacs_changes( [ $old ], [ $new ] ) );
	}

	/**
	 * Removed accounts first, then the changes of kept ones, then the added
	 * ones, whatever their places in the list.
	 */
	public function test_changes_list_removed_then_changed_then_added_accounts(): void {
		$this->assertSame(
			[
				self::change( 'account:…4444', 'present', null ),
				self::change( 'account:…5678.iban', SensitiveOption::REDACTED, SensitiveOption::REDACTED ),
				self::change( 'account:…6666', null, 'added' ),
			],
			WooCommerceSettingsSensor::bacs_changes(
				[ self::account( '12345678', self::IBAN_OLD ), self::account( '33334444' ) ],
				[ self::account( '55556666' ), self::account( '12345678', self::IBAN_NEW ) ]
			)
		);
	}

	/**
	 * Two accounts that end in the same four digits are told apart by their
	 * place in the list.
	 */
	public function test_accounts_that_share_their_last_four_digits_get_an_ordinal(): void {
		$first  = self::account( '11111234', '', 'First Bank' );
		$second = self::account( '22221234', '', 'Second Bank' );
		$third  = self::account( '33331234', '', 'Third Bank' );

		$moved              = $second;
		$moved['bank_name'] = 'Moved Bank';

		$this->assertSame(
			[ self::change( 'account:…1234 #2.bank_name', 'Second Bank', 'Moved Bank' ) ],
			WooCommerceSettingsSensor::bacs_changes( [ $first, $second ], [ $first, $moved ] )
		);
		$this->assertSame(
			[ self::change( 'account:…1234 #3', null, 'added' ) ],
			WooCommerceSettingsSensor::bacs_changes( [ $first, $second ], [ $first, $second, $third ] )
		);
		$this->assertSame(
			[ self::change( 'account:…1234 #1.bank_name', 'Second Bank', 'Moved Bank' ) ],
			WooCommerceSettingsSensor::bacs_changes( [ $first, $second ], [ $moved, $first ] ),
			'The place is the place in the new list.'
		);
	}

	public function test_a_removed_account_is_told_as_gone(): void {
		$this->assertSame(
			[ self::change( 'account:…4444', 'present', null ) ],
			WooCommerceSettingsSensor::bacs_changes( [ self::account( '11112222' ), self::account( '33334444' ) ], [ self::account( '11112222' ) ] )
		);
	}

	/**
	 * An account with neither a number nor an IBAN (a row left blank on the
	 * screen) has nothing to be named by.
	 */
	public function test_an_account_without_a_number_is_named_so(): void {
		$blank = self::account( '', '', '' );

		$blank['account_name'] = '';
		$named                 = $blank;
		$named['account_name'] = 'Savings';

		$this->assertSame(
			[ self::change( 'account:no number.account_name', '', 'Savings' ) ],
			WooCommerceSettingsSensor::bacs_changes( [ $blank ], [ $named ] )
		);
	}

	public function test_entries_that_are_not_accounts_are_ignored(): void {
		$account = self::account( '12345678' );

		$this->assertSame( [], WooCommerceSettingsSensor::bacs_changes( [ 'junk', $account, 7 ], [ $account, null ] ) );
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * A bank account the way the bank transfer gateway stores it.
	 *
	 * @param string $number Account number.
	 * @param string $iban   IBAN.
	 * @param string $bank   Bank name.
	 *
	 * @return array<string, string>
	 */
	private static function account( string $number, string $iban = '', string $bank = 'Example Bank' ): array {
		return [
			'account_name'   => 'Example Ltd',
			'account_number' => $number,
			'bank_name'      => $bank,
			'sort_code'      => '',
			'iban'           => $iban,
			'bic'            => '',
		];
	}

	/**
	 * @return array{field: string, old: ?string, new: ?string}
	 */
	private static function change( string $field, ?string $old, ?string $new ): array {
		return [
			'field' => $field,
			'old'   => $old,
			'new'   => $new,
		];
	}

	/**
	 * @return array{type: string, id: string, label: string}
	 */
	private static function setting( string $option ): array {
		return [
			'type'  => 'setting',
			'id'    => $option,
			'label' => $option,
		];
	}

	/**
	 * A value whose text throws when read.
	 */
	private static function unreadable(): \Stringable {
		return new class() implements \Stringable {
			public function __toString(): string {
				throw new \RuntimeException( 'unreadable' );
			}
		};
	}

	/**
	 * None of the texts appears in the JSON of the value, nor in its PHP
	 * serialization.
	 *
	 * @param array<int, string> $texts Texts that must not appear.
	 * @param mixed              $value What is checked.
	 */
	private function assertNoneOf( array $texts, $value ): void {
		$json = (string) json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$php  = serialize( $value );
		foreach ( $texts as $text ) {
			$this->assertStringNotContainsString( $text, $json );
			$this->assertStringNotContainsString( $text, $php );
		}
	}

	/**
	 * The sensor reported one failure of the given event and option.
	 */
	private function assertFailure( int $code, string $option ): void {
		$this->assertCount( 1, WpCalls::$actions );
		[ $hook, $args ] = WpCalls::$actions[0];
		$this->assertSame( 'hellolog_dispatch_failed', $hook );
		$this->assertInstanceOf( \RuntimeException::class, $args[0] );
		$this->assertSame( $code, $args[1] );
		$this->assertSame( [ 'option' => $option ], $args[2] );
	}

	/**
	 * Load the gateway stand-ins with the gateways already loaded.
	 *
	 * @param array<string, string> $option_keys Gateway id => option key.
	 */
	private function gateways_loaded( array $option_keys ): void {
		require_once __DIR__ . '/Fixtures/woocommerce-payment-gateway-functions.php';
		PaymentGatewayState::$option_keys                              = $option_keys;
		PaymentGatewayState::$did['wc_payment_gateways_initialized'] = 1;
	}

	/**
	 * The wire payload of a recorded event; by default built for an
	 * administrator on a dashboard screen.
	 *
	 * @param int                  $code    Event code.
	 * @param array<string, mixed> $fields  What the sensor dispatched.
	 * @param callable|null        $source  Returns the request source.
	 * @param RequestContext|null  $context Who made the request.
	 *
	 * @return array<string, mixed>
	 */
	private function build( int $code, array $fields, ?callable $source = null, ?RequestContext $context = null ): array {
		$catalog = new EventCatalog();
		CatalogSeeder::seed( $catalog );
		$builder = new class(
			$catalog,
			$source ?? static fn(): string => RequestSource::ADMIN,
			$context ?? new RequestContext( 1, 'admin', [ 'administrator' ], null, null, null )
		) extends EventBuilder {

			public function __construct( EventCatalog $catalog, callable $source, private RequestContext $context ) {
				parent::__construct( $catalog, false, $source );
			}

			protected function current_context(): RequestContext {
				return $this->context;
			}
		};

		$payload = $builder->build( $code, $fields );
		$this->assertIsArray( $payload );

		return $payload;
	}

	/**
	 * A dispatcher that keeps what it was given. Also loads the request
	 * stand-ins and defaults the request to a POST, so call it before any
	 * sensor runs.
	 */
	private function recorder(): EventDispatcher {
		require_once __DIR__ . '/../Fixtures/wordpress-request-functions.php';

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
