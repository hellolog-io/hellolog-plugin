<?php
/**
 * Tests for the core settings events of {@see HelloLog\Sensors\Core\SettingsSensor}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\RequestContext;
use HelloLog\Events\RequestSource;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Core\SettingsSensor;
use HelloLog\Sensors\SensitiveOption;
use HelloLog\Tests\Sensors\Fixtures\RequestState;
use PHPUnit\Framework\TestCase;

/**
 * A core setting is reported the way the plugin settings are: the setting
 * as its target, the old-to-new change, and values that never carry a
 * secret, in the changes, the template values or the metadata.
 */
final class SettingsSensorTest extends TestCase {

	private const URL_WITH_PASSWORD = 'see https://user:p@ss@host/x';

	public function test_a_core_setting_has_the_setting_as_its_target_and_its_change(): void {
		$recorder = $this->recorder();
		( new SettingsSensor( $recorder ) )->on_update( 'blogname', 'Old name', 'New name' );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 6000, $code );
		$this->assertSame(
			[
				'type'  => 'setting',
				'id'    => 'blogname',
				'label' => 'blogname',
			],
			$fields['target']
		);
		$this->assertSame(
			[
				[
					'field' => 'blogname',
					'old'   => 'Old name',
					'new'   => 'New name',
				],
			],
			$fields['changes']
		);
		$this->assertSame(
			[
				'option' => 'blogname',
				'old'    => 'Old name',
				'new'    => 'New name',
			],
			$fields['metadata']
		);
	}

	public function test_a_password_in_a_url_reaches_no_part_of_the_payload(): void {
		$recorder = $this->recorder();
		( new SettingsSensor( $recorder ) )->on_update( 'blogdescription', 'Just another site', self::URL_WITH_PASSWORD );

		$this->assertCount( 1, $recorder->calls );
		$fields = $recorder->calls[0][1];
		$masked = 'see https://' . SensitiveOption::REDACTED . '@host/x';
		$this->assertSame( $masked, $fields['changes'][0]['new'] );
		$this->assertSame( $masked, $fields['metadata']['new'] );

		$payload = $this->payload( $recorder->calls[0] );
		$this->assertSame( 'blogdescription', $payload['target']['id'] );
		$this->assertStringNotContainsString( 'p@ss', (string) json_encode( $payload ) );
		$this->assertStringNotContainsString( 'p@ss', serialize( $fields ) );
	}

	/**
	 * The site address and the admin e-mail tell their values in the message.
	 */
	public function test_the_message_values_are_masked_too(): void {
		$recorder = $this->recorder();
		( new SettingsSensor( $recorder ) )->on_update( 'siteurl', 'https://example.com', 'https://admin:hunter2@example.com' );

		$this->assertSame( 6001, $recorder->calls[0][0] );
		$payload = $this->payload( $recorder->calls[0] );
		$this->assertSame( 'Site URL changed from https://example.com to https://' . SensitiveOption::REDACTED . '@example.com.', $payload['message'] );
		$this->assertStringNotContainsString( 'hunter2', (string) json_encode( $payload ) );
	}

	/**
	 * `updated_option` fires when the stored text differs, which `1` and `'1'`
	 * do, but nothing a reader could see changed.
	 */
	public function test_a_write_that_changes_nothing_a_reader_can_see_logs_nothing(): void {
		$recorder = $this->recorder();
		$sensor   = new SettingsSensor( $recorder );
		$sensor->on_update( 'blog_public', 1, '1' );
		$sensor->on_update( 'posts_per_page', '10', 10 );

		$this->assertSame( [], $recorder->calls );
	}

	public function test_an_option_that_is_not_watched_logs_nothing(): void {
		$recorder = $this->recorder();
		( new SettingsSensor( $recorder ) )->on_update( 'woocommerce_currency', 'USD', 'EUR' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * Unlike plugin settings, a core setting changed by a background run or a
	 * visitor's request is exactly what an audit log has to show.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_core_setting_changed_by_cron_or_a_visitor_is_still_logged(): void {
		require_once __DIR__ . '/../Fixtures/wordpress-request-functions.php';
		RequestState::$admin   = false;
		RequestState::$user_id = 0;

		$recorder = $this->recorder();
		$sensor   = new SettingsSensor( $recorder );
		$sensor->on_update( 'home', 'https://example.com', 'https://example.test' );

		RequestState::$cron = true;
		$sensor->on_update( 'admin_email', 'owner@example.com', 'someone@example.test' );

		$this->assertSame( [ 6001, 6002 ], array_column( $recorder->calls, 0 ) );
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
