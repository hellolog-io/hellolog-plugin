<?php
/**
 * Tests for {@see HelloLog\Sensors\Core\SelfAuditSensor}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\NullEventDispatcher;
use HelloLog\Events\RequestContext;
use HelloLog\Events\RequestSource;
use HelloLog\Scheduler\FlushLock;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Core\SelfAuditSensor;
use HelloLog\Sensors\SensitiveOption;
use HelloLog\Sensors\SensorManager;
use HelloLog\Settings\Options;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use HelloLog\Transport\FinalDelivery;
use PHPUnit\Framework\TestCase;

/**
 * A change to helloLOG's own settings is a critical event of its own (6600):
 * the API key (only that it changed, never its value), IP anonymisation, and
 * each sensor turned on or off. The sensor can not be turned off.
 */
final class SelfAuditSensorTest extends TestCase {

	private const OLD_TOKEN = 'goal_live_abcd1234_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const NEW_TOKEN = 'goal_live_wxyz9876_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	public function test_a_changed_token_is_only_reported_as_changed(): void {
		$changes = SelfAuditSensor::changes_for( Options::KEY_TOKEN, self::OLD_TOKEN, self::NEW_TOKEN );

		$this->assertSame(
			[
				[
					'field' => 'token',
					'old'   => SensitiveOption::REDACTED,
					'new'   => SensitiveOption::REDACTED,
				],
			],
			$changes
		);
		$this->assert_no_token( $changes );
	}

	public function test_a_token_set_where_there_was_none_reads_set(): void {
		foreach ( [ '', null ] as $none ) {
			$changes = SelfAuditSensor::changes_for( Options::KEY_TOKEN, $none, self::NEW_TOKEN );

			$this->assertSame(
				[
					[
						'field' => 'token',
						'old'   => null,
						'new'   => 'set',
					],
				],
				$changes
			);
		}
	}

	public function test_a_token_taken_away_reads_removed(): void {
		foreach ( [ '', null ] as $none ) {
			$changes = SelfAuditSensor::changes_for( Options::KEY_TOKEN, self::OLD_TOKEN, $none );

			$this->assertSame(
				[
					[
						'field' => 'token',
						'old'   => SensitiveOption::REDACTED,
						'new'   => 'removed',
					],
				],
				$changes
			);
			$this->assert_no_token( $changes );
		}
	}

	public function test_a_token_saved_unchanged_or_still_empty_is_no_change(): void {
		$this->assertSame( [], SelfAuditSensor::changes_for( Options::KEY_TOKEN, self::OLD_TOKEN, self::OLD_TOKEN ) );
		$this->assertSame( [], SelfAuditSensor::changes_for( Options::KEY_TOKEN, '', null ) );
	}

	public function test_turning_ip_anonymisation_on_and_off_reads_yes_and_no(): void {
		$this->assertSame(
			[
				[
					'field' => 'anonymize_ip',
					'old'   => 'no',
					'new'   => 'yes',
				],
			],
			SelfAuditSensor::changes_for( Options::KEY_ANONYMIZE_IP, false, true )
		);
		$this->assertSame(
			[
				[
					'field' => 'anonymize_ip',
					'old'   => 'yes',
					'new'   => 'no',
				],
			],
			SelfAuditSensor::changes_for( Options::KEY_ANONYMIZE_IP, '1', false )
		);
	}

	/**
	 * The setting is stored as a boolean but read back as `'1'` or `''`, so
	 * every save writes a value that is not identical to the stored one.
	 *
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	public static function same_anonymisation(): array {
		return [
			'on, read back as 1'      => [ '1', true ],
			'off, read back as empty' => [ '', false ],
			'off, never stored'       => [ null, false ],
			'on, as a number'         => [ 1, true ],
		];
	}

	/**
	 * @dataProvider same_anonymisation
	 *
	 * @param mixed $old Stored value.
	 * @param mixed $new Saved value.
	 */
	public function test_saving_the_anonymisation_it_already_has_is_no_change( $old, $new ): void {
		$this->assertSame( [], SelfAuditSensor::changes_for( Options::KEY_ANONYMIZE_IP, $old, $new ) );
	}

	public function test_each_sensor_turned_on_or_off_is_one_item(): void {
		$changes = SelfAuditSensor::changes_for(
			Options::KEY_SENSOR_FILTERS,
			[ 'core-request' => true ],
			[
				'core-request' => false,
				'core-media'   => true,
			]
		);

		$this->assertSame(
			[
				[
					'field' => 'sensor:core-request',
					'old'   => 'disabled',
					'new'   => 'enabled',
				],
				[
					'field' => 'sensor:core-media',
					'old'   => 'enabled',
					'new'   => 'disabled',
				],
			],
			$changes
		);
	}

	/**
	 * A sensor the settings do not mention runs as it does by default: the
	 * noisy ones are off, every other one is on.
	 */
	public function test_a_sensor_not_mentioned_is_in_its_default_state(): void {
		// Writing down what is already the default changes nothing.
		$this->assertSame(
			[],
			SelfAuditSensor::changes_for(
				Options::KEY_SENSOR_FILTERS,
				[],
				[
					'core-request' => true,
					'core-media'   => false,
				]
			)
		);

		// Dropping an opt-in puts a noisy sensor back to off.
		$this->assertSame(
			[
				[
					'field' => 'sensor:core-failed-login',
					'old'   => 'enabled',
					'new'   => 'disabled',
				],
			],
			SelfAuditSensor::changes_for( Options::KEY_SENSOR_FILTERS, [ 'core-failed-login' => false ], [] )
		);

		// Dropping an opt-out puts any other sensor back to on.
		$this->assertSame(
			[
				[
					'field' => 'sensor:core-comments',
					'old'   => 'disabled',
					'new'   => 'enabled',
				],
			],
			SelfAuditSensor::changes_for( Options::KEY_SENSOR_FILTERS, [ 'core-comments' => true ], null )
		);
	}

	/**
	 * Sensor keys are identifiers: one that reads like a credential is never
	 * masked.
	 */
	public function test_sensor_keys_that_read_like_credentials_are_never_masked(): void {
		$changes = SelfAuditSensor::changes_for(
			Options::KEY_SENSOR_FILTERS,
			[],
			[
				'core-auth'          => true,
				'core-app-passwords' => true,
			]
		);

		$this->assertSame( [ 'sensor:core-auth', 'sensor:core-app-passwords' ], array_column( $changes, 'field' ) );
		$this->assertSame( [ 'enabled', 'enabled' ], array_column( $changes, 'old' ) );
		$this->assertSame( [ 'disabled', 'disabled' ], array_column( $changes, 'new' ) );
	}

	/**
	 * Only sensors the plugin has are reported: flags for made-up keys can
	 * not crowd the real ones out of an event's limited list of changes.
	 */
	public function test_flags_of_unknown_sensors_are_left_out(): void {
		$new = [ 'core-auth' => true ];
		for ( $i = 1; $i <= 60; $i++ ) {
			$new[ 'junk-' . $i ] = true;
		}
		$new['core-app-passwords'] = true;

		$changes = SelfAuditSensor::changes_for( Options::KEY_SENSOR_FILTERS, [], $new, [ 'core-auth', 'core-app-passwords', 'core-media' ] );

		$this->assertSame( [ 'sensor:core-auth', 'sensor:core-app-passwords' ], array_column( $changes, 'field' ) );
		$this->assertCount( 62, SelfAuditSensor::changes_for( Options::KEY_SENSOR_FILTERS, [], $new ), 'Without a list every key counts.' );
	}

	/**
	 * Flags that do nothing are not reported: one for the self-audit itself
	 * (it can not be turned off), and keys that are not sensor names.
	 */
	public function test_flags_that_change_nothing_are_left_out(): void {
		$this->assertSame(
			[],
			SelfAuditSensor::changes_for(
				Options::KEY_SENSOR_FILTERS,
				[ 'core-media' => '1' ],
				[
					'core-media'         => 1,
					SelfAuditSensor::KEY => true,
					7                    => true,
				]
			)
		);
	}

	public function test_other_options_are_not_helloLOG_settings(): void {
		$this->assertSame( [], SelfAuditSensor::changes_for( 'blogname', 'Old', 'New' ) );
		$this->assertSame( [], SelfAuditSensor::changes_for( Options::KEY_API_ACCESS, 1, 0 ) );
	}

	/**
	 * A verified key that is no longer verified stops the sensors; a key
	 * turned on, or a flag written again, is not a change to report.
	 */
	public function test_the_verified_flag_is_a_change_only_when_it_turns_off(): void {
		$this->assertSame(
			[
				[
					'field' => 'key_verified',
					'old'   => 'yes',
					'new'   => 'no',
				],
			],
			SelfAuditSensor::changes_for( Options::KEY_TOKEN_VERIFIED, '1', 0 )
		);
		$this->assertSame( [], SelfAuditSensor::changes_for( Options::KEY_TOKEN_VERIFIED, '0', 1 ) );
		$this->assertSame( [], SelfAuditSensor::changes_for( Options::KEY_TOKEN_VERIFIED, '1', 1 ) );
		$this->assertSame( [], SelfAuditSensor::changes_for( Options::KEY_TOKEN_VERIFIED, '0', 0 ) );
	}

	/**
	 * The delivery sends the queue of the site it was made on, under that
	 * site's key: a key written on another site of the network is queued
	 * there like any event, never sent under this key.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_key_change_on_another_site_is_only_queued(): void {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';
		WpState::$blog_id = 1;
		$sends            = 0;
		$delivery         = new FinalDelivery(
			new NullEventDispatcher(),
			static function () use ( &$sends ): int {
				++$sends;
				return 0;
			},
			new FlushLock()
		);
		$recorder         = $this->recorder();

		switch_to_blog( 2 );
		( new SelfAuditSensor( $recorder, $delivery ) )->on_update( Options::KEY_TOKEN, self::OLD_TOKEN, self::NEW_TOKEN );

		$this->assertSame( [ 6600 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 0, $sends );
	}

	public function test_the_self_audit_can_not_be_turned_off(): void {
		$manager = new SensorManager();
		$manager->register( new SelfAuditSensor( new NullEventDispatcher() ) );
		$manager->disable( [ SelfAuditSensor::KEY, 'core-request' ] );

		$this->assertSame( 'core-self-audit', SelfAuditSensor::KEY );
		$this->assertFalse( $manager->is_disabled( SelfAuditSensor::KEY ) );
		$this->assertTrue( $manager->is_disabled( 'core-request' ) );
		$this->assertArrayNotHasKey( SelfAuditSensor::KEY, $manager->toggleable(), 'Not offered as a switch.' );
	}

	public function test_a_settings_change_is_a_critical_event_with_its_changes(): void {
		$recorder = $this->recorder();
		( new SelfAuditSensor( $recorder ) )->on_update( Options::KEY_ANONYMIZE_IP, '', true );

		$this->assertCount( 1, $recorder->calls );
		$payload = $this->payload( $recorder->calls[0] );
		$this->assertSame( 6600, $payload['code'] );
		$this->assertSame( 'critical', $payload['severity'] );
		$this->assertSame( 'setting', $payload['object'] );
		$this->assertSame( 'updated', $payload['event_type'] );
		$this->assertSame( 'HelloLog settings changed.', $payload['message'] );
		$this->assertSame(
			[
				'type'  => 'setting',
				'id'    => Options::KEY_ANONYMIZE_IP,
				'label' => Options::KEY_ANONYMIZE_IP,
			],
			$payload['target']
		);
		$this->assertSame(
			[
				[
					'field' => 'anonymize_ip',
					'old'   => 'no',
					'new'   => 'yes',
				],
			],
			$payload['changes']
		);
		$this->assertSame( Options::KEY_ANONYMIZE_IP, $payload['metadata']['option'] );
	}

	public function test_no_part_of_a_token_event_carries_a_token(): void {
		$recorder = $this->recorder();
		$sensor   = new SelfAuditSensor( $recorder );

		$sensor->on_update( Options::KEY_TOKEN, self::OLD_TOKEN, self::NEW_TOKEN );
		$sensor->on_add( Options::KEY_TOKEN, self::NEW_TOKEN );
		$sensor->on_deleted( Options::KEY_TOKEN );

		$this->assertSame( [ 6600, 6600, 6600 ], array_column( $recorder->calls, 0 ) );
		foreach ( $recorder->calls as $call ) {
			$this->assert_no_token( $call );
			$this->assert_no_token( $this->payload( $call ) );
		}
		$this->assertSame( SensitiveOption::REDACTED, $recorder->calls[0][1]['changes'][0]['new'] );
		$this->assertSame( 'set', $recorder->calls[1][1]['changes'][0]['new'] );
		$this->assertSame( 'removed', $recorder->calls[2][1]['changes'][0]['new'] );
	}

	/**
	 * Only the API key is told on its first write and on its removal; the
	 * defaults helloLOG seeds on a new site are not a change.
	 */
	public function test_a_first_write_or_a_removal_is_only_told_for_the_token(): void {
		$recorder = $this->recorder();
		$sensor   = new SelfAuditSensor( $recorder );

		$sensor->on_add( Options::KEY_TOKEN, '' );
		$sensor->on_add( Options::KEY_ANONYMIZE_IP, true );
		$sensor->on_add( Options::KEY_SENSOR_FILTERS, [ 'core-media' => true ] );
		$sensor->on_add( 'blogname', 'Example' );
		$sensor->on_deleted( Options::KEY_SENSOR_FILTERS );
		$sensor->on_deleted( 'blogname' );

		$this->assertSame( [], $recorder->calls );
	}

	public function test_writes_that_change_nothing_or_other_options_log_nothing(): void {
		$recorder = $this->recorder();
		$sensor   = new SelfAuditSensor( $recorder );

		$sensor->on_update( Options::KEY_ANONYMIZE_IP, '1', true );
		$sensor->on_update( Options::KEY_SENSOR_FILTERS, [ 'core-request' => true ], [ 'core-request' => true ] );
		$sensor->on_update( 'blogname', 'Old', 'New' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failure_is_reported_and_never_thrown(): void {
		require_once __DIR__ . '/../../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';
		$failing = new class() implements EventDispatcher {

			public function dispatch( int $code, array $fields = [] ): void {
				throw new \RuntimeException( 'queue unavailable' );
			}
		};

		( new SelfAuditSensor( $failing ) )->on_update( Options::KEY_TOKEN, self::OLD_TOKEN, self::NEW_TOKEN );

		$this->assertCount( 1, WpCalls::$actions );
		[ $hook, $args ] = WpCalls::$actions[0];
		$this->assertSame( 'hellolog_dispatch_failed', $hook );
		$this->assertSame( 'queue unavailable', $args[0]->getMessage() );
		$this->assertSame( 6600, $args[1] );
		$this->assertSame( [ 'option' => Options::KEY_TOKEN ], $args[2] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_option_hooks_are_registered_with_the_arguments_they_read(): void {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';
		( new SelfAuditSensor( $this->recorder() ) )->boot();

		$this->assertSame( 3, WpState::$actions['updated_option']['accepted_args'] );
		$this->assertSame( 2, WpState::$actions['added_option']['accepted_args'] );
		$this->assertSame( 1, WpState::$actions['deleted_option']['accepted_args'] );
	}

	/**
	 * Neither token appears anywhere in what is given.
	 *
	 * @param mixed $data Changes, a recorded call or a payload.
	 */
	private function assert_no_token( $data ): void {
		$json = (string) json_encode( $data );

		$this->assertStringNotContainsString( self::OLD_TOKEN, $json );
		$this->assertStringNotContainsString( self::NEW_TOKEN, $json );
		$this->assertStringNotContainsString( 'abcd1234', $json );
		$this->assertStringNotContainsString( 'wxyz9876', $json );
	}

	/**
	 * @param array{0: int, 1: array<string, mixed>} $call A recorded dispatch.
	 *
	 * @return array<string, mixed>
	 */
	private function payload( array $call ): array {
		$catalog = new EventCatalog();
		CatalogSeeder::seed( $catalog );
		$builder = new class( $catalog ) extends EventBuilder {

			public function __construct( EventCatalog $catalog ) {
				parent::__construct( $catalog, false, static fn(): string => RequestSource::REST );
			}

			protected function current_context(): RequestContext {
				return new RequestContext( 1, 'admin', [ 'administrator' ], null, null, null );
			}
		};

		$payload = $builder->build( $call[0], $call[1] );
		$this->assertIsArray( $payload );

		return $payload;
	}

	/**
	 * A dispatcher that keeps what it was given.
	 */
	private function recorder(): EventDispatcher {
		return new class() implements EventDispatcher {

			/** @var array<int, array{0: int, 1: array<string, mixed>}> */
			public array $calls = [];

			public function dispatch( int $code, array $fields = [] ): void {
				$this->calls[] = [ $code, $fields ];
			}
		};
	}
}
