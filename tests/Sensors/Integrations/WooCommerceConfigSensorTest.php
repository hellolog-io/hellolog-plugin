<?php
/**
 * Tests for what the shop configuration sensor tells about tax rates,
 * shipping methods, webhooks and product attributes.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Integrations;

use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\RequestContext;
use HelloLog\Events\RequestSource;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Integrations\WooCommerceConfigSensor;
use HelloLog\Sensors\SensitiveOption;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Integrations\Fixtures\ConfigState;
use PHPUnit\Framework\TestCase;

/**
 * Tax rates (9410–9412), shipping methods in a zone (9420–9422), webhooks
 * (9430–9432) and product attributes (9440–9442), each on a `setting` target
 * of its own. A webhook's secret and the address it posts to never leave the
 * site: only the host of the address is told, and a new secret is a flag
 * without values.
 *
 * Tests that run the sensor's hooks call into the shop, so they run in their
 * own process with its stand-ins.
 */
final class WooCommerceConfigSensorTest extends TestCase {

	private const SECRET_OLD = 'whsec_OLDSECRETVALUE';

	private const SECRET_NEW = 'whsec_NEWSECRETVALUE';

	private const URL = 'https://hooks.example.com/catch/12345/PRIVATEPATH/?token=PRIVATETOKEN';

	// ------------------------------------------------------------------
	// Tax rates.
	// ------------------------------------------------------------------

	/**
	 * A tax rate is named by where it applies, its rate and its name, the
	 * rate without the zeros the shop stores it with.
	 */
	public function test_a_tax_rate_is_named_by_country_state_rate_and_name(): void {
		$this->assertSame( 'US CA 7.25% (CA Tax)', WooCommerceConfigSensor::tax_rate_name( self::tax_row( 'US', 'CA', '7.2500', 'CA Tax' ) ) );
		$this->assertSame( 'HU 27% (ÁFA)', WooCommerceConfigSensor::tax_rate_name( self::tax_row( 'HU', '', '27.0000', 'ÁFA' ) ) );
		$this->assertSame( 'DE 10%', WooCommerceConfigSensor::tax_rate_name( self::tax_row( 'DE', '', '10', '' ) ) );
		$this->assertSame( '0% (Zero)', WooCommerceConfigSensor::tax_rate_name( self::tax_row( '', '', '0.0000', 'Zero' ) ) );
	}

	/**
	 * A row that names nothing (or is no row) gives no name.
	 */
	public function test_a_tax_rate_without_anything_to_name_it_by_has_no_name(): void {
		$this->assertSame( '', WooCommerceConfigSensor::tax_rate_name( self::tax_row( '', '', '', '' ) ) );
		$this->assertSame( '', WooCommerceConfigSensor::tax_rate_name( [] ) );
		$this->assertSame( '', WooCommerceConfigSensor::tax_rate_name( [ 'tax_rate_country' => [ 'HU' ] ] ) );
	}

	/**
	 * The shop passes only the columns it wrote: the name comes from the row
	 * it stored, read inside the hook.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_added_tax_rate_is_named_from_its_stored_row(): void {
		$this->stubs();
		ConfigState::$tax_rates[1][7] = self::tax_row( 'HU', '', '27.0000', 'ÁFA' );

		$recorder = $this->recorder();
		( new WooCommerceConfigSensor( $recorder ) )->on_tax_rate_added( 7, [ 'tax_rate_country' => 'HU' ] );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9410, $code );
		$this->assertSame( self::target( 'tax_rate:7', 'HU 27% (ÁFA)' ), $fields['target'] );
		$this->assertSame( [ 'name' => 'HU 27% (ÁFA)' ], $fields['vars'] );
		$this->assertSame( self::stored_row( 'HU', '', '27.0000', 'ÁFA' ), $fields['metadata']['tax_rate'] );
		$this->assertArrayNotHasKey( 'changes', $fields );

		$payload = $this->build( $code, $fields );
		$this->assertSame( 'WC tax rate HU 27% (ÁFA) added.', $payload['message'] );
		$this->assertSame( 'high', $payload['severity'] );
	}

	/**
	 * WooCommerce has no hook before a tax rate is written, and passes only
	 * what it wrote: a change sends no old → new values (they would read as
	 * "was empty"), but the whole row as it is now, and the columns written.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_changed_tax_rate_sends_its_new_row_and_no_changes(): void {
		$this->stubs();
		ConfigState::$tax_rates[1][7] = self::tax_row( 'HU', '', '18.0000', 'ÁFA' );

		$recorder = $this->recorder();
		( new WooCommerceConfigSensor( $recorder ) )->on_tax_rate_updated( '7', [ 'tax_rate' => '18' ] );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9411, $code );
		$this->assertArrayNotHasKey( 'changes', $fields );
		$this->assertSame( self::target( 'tax_rate:7', 'HU 18% (ÁFA)' ), $fields['target'] );
		$this->assertSame( self::stored_row( 'HU', '', '18.0000', 'ÁFA' ), $fields['metadata']['tax_rate'] );
		$this->assertSame( [ 'tax_rate' ], $fields['metadata']['fields'] );

		$payload = $this->build( $code, $fields );
		$this->assertSame( 'WC tax rate HU 18% (ÁFA) changed.', $payload['message'] );
		$this->assertArrayNotHasKey( 'changes', $payload );
	}

	/**
	 * The row is gone when WooCommerce tells the delete: the rate is named by
	 * its id, on the same target as its earlier events.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deleted_tax_rate_is_named_by_its_id(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceConfigSensor( $recorder ) )->on_tax_rate_deleted( '7' );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9412, $code );
		$this->assertSame( self::target( 'tax_rate:7', '#7' ), $fields['target'] );
		$this->assertSame( 'WC tax rate #7 deleted.', $this->build( $code, $fields )['message'] );
	}

	/**
	 * A row that cannot be found (another plugin deleted it in the meantime)
	 * leaves the rate named by its id, without a row.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_tax_rate_without_a_stored_row_is_named_by_its_id(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceConfigSensor( $recorder ) )->on_tax_rate_updated( 7, [ 'tax_rate' => '18' ] );

		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9411, $code );
		$this->assertSame( [ 'name' => '#7' ], $fields['vars'] );
		$this->assertArrayNotHasKey( 'tax_rate', $fields['metadata'] );
	}

	/**
	 * The row is read on the site the rate was written on.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_tax_rate_is_read_on_its_own_site(): void {
		$this->stubs();
		ConfigState::$tax_rates[1][7] = self::tax_row( 'HU', '', '27.0000', 'ÁFA' );
		ConfigState::$tax_rates[2][7] = self::tax_row( 'AT', '', '20.0000', 'USt' );
		ConfigState::$blog_id         = 2;

		$recorder = $this->recorder();
		( new WooCommerceConfigSensor( $recorder ) )->on_tax_rate_added( 7, [] );

		$this->assertSame( 'AT 20% (USt)', $recorder->calls[0][1]['vars']['name'] );
	}

	// ------------------------------------------------------------------
	// Shipping methods.
	// ------------------------------------------------------------------

	/**
	 * A method added to a zone is named by its type's title (its own title is
	 * not saved yet) and the zone by its name.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_shipping_method_added_names_its_type_and_its_zone(): void {
		$this->stubs();
		ConfigState::$shipping_methods = [ 'flat_rate' => 'Flat rate' ];
		ConfigState::$zones            = [ 1 => 'Domestic' ];

		$recorder = $this->recorder();
		( new WooCommerceConfigSensor( $recorder ) )->on_shipping_method_added( 3, 'flat_rate', 1 );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9420, $code );
		$this->assertSame( self::target( 'shipping_method:3', 'Flat rate (Domestic)' ), $fields['target'] );
		$this->assertSame(
			[
				'name' => 'Flat rate',
				'zone' => 'Domestic',
			],
			$fields['vars']
		);
		$this->assertSame(
			[
				'method_id' => 'flat_rate',
				'zone_id'   => 1,
			],
			$fields['metadata']
		);

		$payload = $this->build( $code, $fields );
		$this->assertSame( 'WC shipping method Flat rate added in zone Domestic.', $payload['message'] );
		$this->assertSame( 'medium', $payload['severity'] );
	}

	/**
	 * Zone 0 is the shop's zone for every place no other zone covers: it is
	 * loaded like any other (the shop names it), whether its id comes as a
	 * number or as text.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_zone_for_other_locations_is_named_by_the_shop(): void {
		$this->stubs();
		ConfigState::$shipping_methods = [ 'free_shipping' => 'Free shipping' ];
		ConfigState::$zones            = [ 0 => 'Locations not covered by your other zones' ];

		$recorder = $this->recorder();
		$sensor   = new WooCommerceConfigSensor( $recorder );
		$sensor->on_shipping_method_added( 4, 'free_shipping', 0 );
		$sensor->on_shipping_method_status_toggled( '4', 'free_shipping', '0', 1 );

		$this->assertSame( [ 9420, 9422 ], array_column( $recorder->calls, 0 ) );
		foreach ( $recorder->calls as [ , $fields ] ) {
			$this->assertSame( 'Locations not covered by your other zones', $fields['vars']['zone'] );
		}
	}

	/**
	 * When the shop tells a removal the method and its settings are gone: it
	 * is named by its type. A type the shop no longer lists is named by its
	 * id, a zone that cannot be loaded by its id.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_removed_shipping_method_is_named_by_its_type(): void {
		$this->stubs();
		ConfigState::$shipping_methods = [ 'flat_rate' => 'Flat rate' ];
		ConfigState::$zones            = [ 1 => 'Domestic' ];

		$recorder = $this->recorder();
		$sensor   = new WooCommerceConfigSensor( $recorder );
		$sensor->on_shipping_method_deleted( 3, 'flat_rate', '1' );
		$sensor->on_shipping_method_deleted( 5, 'gone_method', 9 );

		$this->assertSame( [ 9421, 9421 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame(
			[
				'name' => 'Flat rate',
				'zone' => 'Domestic',
			],
			$recorder->calls[0][1]['vars']
		);
		$this->assertSame(
			[
				'name' => 'gone_method',
				'zone' => '#9',
			],
			$recorder->calls[1][1]['vars']
		);
		$this->assertSame( 'shipping_method:5', $recorder->calls[1][1]['target']['id'] );
		$this->assertSame( 'WC shipping method Flat rate removed from zone Domestic.', $this->build( 9421, $recorder->calls[0][1] )['message'] );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}> What the shop passes, the state told.
	 */
	public static function toggles(): array {
		return [
			'REST false'      => [ false, 'disabled' ],
			'REST true'       => [ true, 'enabled' ],
			'admin 0'         => [ 0, 'disabled' ],
			'admin 1'         => [ 1, 'enabled' ],
			'text 0'          => [ '0', 'disabled' ],
			'text 1'          => [ '1', 'enabled' ],
			'anything else'   => [ null, 'disabled' ],
		];
	}

	/**
	 * A method switched on or off tells which way, however the shop passes
	 * it (the REST API a boolean, the settings screen 0 or 1).
	 *
	 * @dataProvider toggles
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param mixed  $is_enabled What the shop passes.
	 * @param string $state      The state told.
	 */
	public function test_a_shipping_toggle_tells_the_new_state( $is_enabled, string $state ): void {
		$this->stubs();
		ConfigState::$shipping_methods = [ 'flat_rate' => 'Flat rate' ];
		ConfigState::$zones            = [ 1 => 'Domestic' ];

		$recorder = $this->recorder();
		( new WooCommerceConfigSensor( $recorder ) )->on_shipping_method_status_toggled( 3, 'flat_rate', 1, $is_enabled );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9422, $code );
		$this->assertSame( $state, $fields['vars']['state'] );
		$this->assertSame( 'enabled' === $state, $fields['metadata']['enabled'] );
		$this->assertSame( 'WC shipping method Flat rate toggled in zone Domestic.', $this->build( $code, $fields )['message'] );
	}

	// ------------------------------------------------------------------
	// Webhooks.
	// ------------------------------------------------------------------

	/**
	 * A new webhook tells its name, topic and status, and the host it posts
	 * to: never the rest of the address (a path or query often carries a
	 * token), never its secret, not even the key.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_created_webhook_tells_its_host_but_never_its_address_or_secret(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceConfigSensor( $recorder ) )->on_webhook_created( 4, new \WC_Webhook( 4, self::webhook() ) );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9430, $code );
		$this->assertSame( self::target( 'webhook:4', 'Order feed' ), $fields['target'] );
		$this->assertSame( [ 'name' => 'Order feed' ], $fields['vars'] );
		$this->assertSame(
			[
				'topic'        => 'order.created',
				'delivery_url' => 'hooks.example.com',
				'status'       => 'active',
			],
			$fields['metadata']
		);

		$payload = $this->build( $code, $fields );
		$this->assertSame( 'WC webhook "Order feed" created.', $payload['message'] );
		$this->assertSame( 'high', $payload['severity'] );
		$this->assertNoneOf( [ 'secret', 'OLDSECRETVALUE', 'PRIVATEPATH', 'PRIVATETOKEN', '/catch', '?token' ], [ $fields, $payload ] );
		$this->assertSame( [], WpCalls::$actions, 'the secret was never read' );
	}

	/**
	 * A person's edit of a webhook is one event with the fields that changed,
	 * named by the webhook's new name.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_webhook_edit_is_one_event_with_its_changes(): void {
		$this->stubs();
		$webhook = new \WC_Webhook(
			4,
			self::webhook(),
			[
				'name'   => 'Order feed (new)',
				'status' => 'paused',
			]
		);

		$recorder = $this->recorder();
		( new WooCommerceConfigSensor( $recorder ) )->on_before_save( $webhook );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9431, $code );
		$this->assertSame( self::target( 'webhook:4', 'Order feed (new)' ), $fields['target'] );
		$this->assertSame(
			[
				self::change( 'name', 'Order feed', 'Order feed (new)' ),
				self::change( 'status', 'active', 'paused' ),
			],
			$fields['changes']
		);
		$this->assertSame( 'WC webhook "Order feed (new)" changed.', $this->build( $code, $fields )['message'] );
		$this->assertSame( [], WpCalls::$actions );
	}

	/**
	 * Every field a person sets on a webhook is told, the delivery address by
	 * its host; a new secret is a flag without either value.
	 */
	public function test_every_webhook_field_is_told_and_the_secret_without_values(): void {
		$changes = WooCommerceConfigSensor::webhook_changes(
			self::webhook(),
			[
				'topic'        => 'order.updated',
				'delivery_url' => 'https://Collector.Example.NET/in?key=PRIVATEKEY',
				'api_version'  => 2,
				'user_id'      => 9,
				'secret'       => self::SECRET_NEW,
			]
		);

		$this->assertSame(
			[
				self::change( 'topic', 'order.created', 'order.updated' ),
				self::change( 'delivery_url', 'hooks.example.com', 'collector.example.net' ),
				self::change( 'api_version', '3', '2' ),
				self::change( 'user_id', '1', '9' ),
				self::change( 'secret', SensitiveOption::REDACTED, SensitiveOption::REDACTED ),
			],
			$changes
		);
		$this->assertNoneOf( [ 'OLDSECRETVALUE', 'NEWSECRETVALUE', 'PRIVATE', '/in', '/catch' ], $changes );
	}

	/**
	 * The address moved to another path on the same host: told as changed,
	 * with neither address.
	 */
	public function test_a_webhook_address_moved_on_its_host_is_told_without_values(): void {
		$changes = WooCommerceConfigSensor::webhook_changes(
			self::webhook(),
			[ 'delivery_url' => 'https://hooks.example.com/catch/12345/OTHERPATH/' ]
		);

		$this->assertSame( [ self::change( 'delivery_url', null, null ) ], $changes );
	}

	/**
	 * The same secret written again is no change.
	 */
	public function test_the_same_secret_written_again_is_no_change(): void {
		$this->assertSame( [], WooCommerceConfigSensor::webhook_changes( self::webhook(), [ 'secret' => self::SECRET_OLD ] ) );
	}

	/**
	 * Every delivery saves the webhook (its failure count, whether a ping is
	 * pending, the time): that is the shop's bookkeeping, not an edit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_delivery_save_of_a_webhook_logs_nothing(): void {
		$this->stubs();
		$webhook = new \WC_Webhook(
			4,
			self::webhook(),
			[
				'failure_count'    => 0,
				'pending_delivery' => false,
				'date_modified'    => new \DateTimeImmutable( '2026-10-27 10:00:00' ),
			]
		);

		$recorder = $this->recorder();
		( new WooCommerceConfigSensor( $recorder ) )->on_before_save( $webhook );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( [], WpCalls::$actions );
	}

	/**
	 * Only a webhook with an id is edited: one being inserted is told by its
	 * creation, and every other object the shop saves through the same hook
	 * (a coupon, say) is not a webhook.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_only_saves_of_existing_webhooks_are_edits(): void {
		$this->stubs();
		$other = new class() {
			/**
			 * @return array<string, mixed>
			 */
			public function get_changes(): array {
				return [ 'name' => 'Changed' ];
			}
		};

		$recorder = $this->recorder();
		$sensor   = new WooCommerceConfigSensor( $recorder );
		$sensor->on_before_save( new \WC_Webhook( 0, [], [ 'name' => 'New' ] ) );
		$sensor->on_before_save( $other );
		$sensor->on_before_save( null );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( [], WpCalls::$actions );
	}

	/**
	 * A webhook the shop switches off after too many failed deliveries is a
	 * change of its status, saying why; the same save without that hook
	 * before it is a plain status change.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_webhook_disabled_for_failed_deliveries_is_a_status_change_saying_why(): void {
		$this->stubs();
		$disabling = static fn(): \WC_Webhook => new \WC_Webhook( 4, self::webhook(), [ 'status' => 'disabled' ] );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceConfigSensor( $recorder );
		$sensor->on_webhook_disabled_for_failures( '4' );
		$sensor->on_before_save( $disabling() );
		$sensor->on_before_save( $disabling() );

		$this->assertSame( [ 9431, 9431 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ self::change( 'status', 'active', 'disabled' ) ], $recorder->calls[0][1]['changes'] );
		$this->assertSame( 'delivery_failures', $recorder->calls[0][1]['metadata']['reason'] );
		$this->assertSame( [ self::change( 'status', 'active', 'disabled' ) ], $recorder->calls[1][1]['changes'] );
		$this->assertArrayNotHasKey( 'reason', $recorder->calls[1][1]['metadata'] );
	}

	/**
	 * The failed-delivery mark is kept per site: the same webhook id on
	 * another site is another webhook.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_failed_delivery_mark_is_kept_per_site(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceConfigSensor( $recorder );
		$sensor->on_webhook_disabled_for_failures( 4 );
		ConfigState::$blog_id = 2;
		$sensor->on_before_save( new \WC_Webhook( 4, self::webhook(), [ 'status' => 'disabled' ] ) );

		$this->assertArrayNotHasKey( 'reason', $recorder->calls[0][1]['metadata'] );
	}

	/**
	 * A deleted webhook is named by the object WooCommerce still passes, with
	 * its topic and host.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deleted_webhook_is_named_by_what_it_was(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceConfigSensor( $recorder );
		$sensor->on_webhook_deleted( 4, new \WC_Webhook( 4, self::webhook() ) );
		$sensor->on_webhook_deleted( '6', null );

		$this->assertSame( [ 9432, 9432 ], array_column( $recorder->calls, 0 ) );
		[ , $fields ] = $recorder->calls[0];
		$this->assertSame( self::target( 'webhook:4', 'Order feed' ), $fields['target'] );
		$this->assertSame( 'hooks.example.com', $fields['metadata']['delivery_url'] );
		$this->assertSame( 'WC webhook "Order feed" deleted.', $this->build( 9432, $fields )['message'] );
		$this->assertNoneOf( [ 'secret', 'OLDSECRETVALUE', 'PRIVATEPATH' ], $fields );

		$this->assertSame( self::target( 'webhook:6', '#6' ), $recorder->calls[1][1]['target'] );
		$this->assertSame( [ 'name' => '#6' ], $recorder->calls[1][1]['vars'] );
	}

	// ------------------------------------------------------------------
	// Attributes.
	// ------------------------------------------------------------------

	/**
	 * A new attribute is named by its label.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_added_attribute_is_named_by_its_label(): void {
		$this->stubs();

		$recorder = $this->recorder();
		( new WooCommerceConfigSensor( $recorder ) )->on_attribute_added( 5, self::attribute_data( 'Colour', 'colour' ) );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9440, $code );
		$this->assertSame( self::target( 'attribute:5', 'Colour' ), $fields['target'] );
		$this->assertSame( [ 'name' => 'Colour' ], $fields['vars'] );
		$this->assertSame( 'colour', $fields['metadata']['slug'] );

		$payload = $this->build( $code, $fields );
		$this->assertSame( 'WC attribute "Colour" created.', $payload['message'] );
		$this->assertSame( 'low', $payload['severity'] );
	}

	/**
	 * Inside the update hook the attribute still reads as it was: its old
	 * label and settings, and the old slug the hook passes, against the new
	 * values.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_changed_attribute_tells_its_old_and_new_values(): void {
		$this->stubs();
		ConfigState::$attributes[1][5] = self::attribute( 'Color', 'pa_color' );
		$data                          = self::attribute_data( 'Colour', 'colour' );
		$data['attribute_public']      = 1;

		$recorder = $this->recorder();
		( new WooCommerceConfigSensor( $recorder ) )->on_attribute_updated( '5', $data, 'color' );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9441, $code );
		$this->assertSame( self::target( 'attribute:5', 'Colour' ), $fields['target'] );
		$this->assertSame(
			[
				self::change( 'name', 'Color', 'Colour' ),
				self::change( 'slug', 'color', 'colour' ),
				self::change( 'has_archives', 'no', 'yes' ),
			],
			$fields['changes']
		);
		$this->assertSame( 'color', $fields['metadata']['old_slug'] );
		$this->assertSame( 'WC attribute "Colour" changed.', $this->build( $code, $fields )['message'] );
	}

	/**
	 * An attribute saved without a change logs nothing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_attribute_saved_unchanged_logs_nothing(): void {
		$this->stubs();
		ConfigState::$attributes[1][5] = self::attribute( 'Color', 'pa_color' );

		$recorder = $this->recorder();
		( new WooCommerceConfigSensor( $recorder ) )->on_attribute_updated( 5, self::attribute_data( 'Color', 'color' ), 'color' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * When the attribute as it was cannot be read, the update is still told,
	 * with what the hook itself says: the slug change.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_attribute_not_readable_before_its_update_is_still_logged(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceConfigSensor( $recorder );
		$sensor->on_attribute_updated( 5, self::attribute_data( 'Colour', 'colour' ), 'color' );
		$sensor->on_attribute_updated( 6, self::attribute_data( 'Size', 'size' ), 'size' );

		$this->assertSame( [ 9441, 9441 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ self::change( 'slug', 'color', 'colour' ) ], $recorder->calls[0][1]['changes'] );
		$this->assertArrayNotHasKey( 'changes', $recorder->calls[1][1] );
		$this->assertSame( [ 'name' => 'Size' ], $recorder->calls[1][1]['vars'] );
	}

	/**
	 * The delete hook passes the attribute's slug, not its label: the label is
	 * read just before the delete.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deleted_attribute_is_named_by_its_label_from_before_the_delete(): void {
		$this->stubs();
		ConfigState::$attributes[1][5] = self::attribute( 'Color', 'pa_color' );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceConfigSensor( $recorder );
		$sensor->on_attribute_deleting( '5', 'color', 'pa_color' );
		unset( ConfigState::$attributes[1][5] );
		$sensor->on_attribute_deleted( '5', 'color', 'pa_color' );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 9442, $code );
		$this->assertSame( self::target( 'attribute:5', 'Color' ), $fields['target'] );
		$this->assertSame( [ 'name' => 'Color' ], $fields['vars'] );
		$this->assertSame(
			[
				'slug'     => 'color',
				'taxonomy' => 'pa_color',
			],
			$fields['metadata']
		);
		$this->assertSame( 'WC attribute "Color" deleted.', $this->build( $code, $fields )['message'] );
	}

	/**
	 * Without a label from before the delete, the slug names the attribute;
	 * without a slug, its id. A label read on one site never names an
	 * attribute of another.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_deleted_attribute_without_a_label_is_named_by_its_slug(): void {
		$this->stubs();
		ConfigState::$attributes[1][5] = self::attribute( 'Color', 'pa_color' );

		$recorder = $this->recorder();
		$sensor   = new WooCommerceConfigSensor( $recorder );
		$sensor->on_attribute_deleting( 5, 'color', 'pa_color' );
		ConfigState::$blog_id = 2;
		$sensor->on_attribute_deleted( 5, 'size', 'pa_size' );
		$sensor->on_attribute_deleted( 7, '', '' );

		$this->assertSame( [ 'name' => 'size' ], $recorder->calls[0][1]['vars'] );
		$this->assertSame( [ 'name' => '#7' ], $recorder->calls[1][1]['vars'] );
	}

	// ------------------------------------------------------------------
	// Every handler.
	// ------------------------------------------------------------------

	/**
	 * @return array<string, array{0: string, 1: array<int, mixed>, 2: int, 3: array<string, int>, 4: bool, 5: bool}> Handler, arguments, code, what identifies the object, whether it reads the shop or the site, whether it dispatches.
	 */
	public static function handlers(): array {
		return [
			'tax rate added'          => [ 'on_tax_rate_added', [ 7, [] ], 9410, [ 'tax_rate_id' => 7 ], true, true ],
			'tax rate updated'        => [ 'on_tax_rate_updated', [ 7, [] ], 9411, [ 'tax_rate_id' => 7 ], true, true ],
			'tax rate deleted'        => [ 'on_tax_rate_deleted', [ 7 ], 9412, [ 'tax_rate_id' => 7 ], false, true ],
			'shipping method added'   => [ 'on_shipping_method_added', [ 3, 'flat_rate', 1 ], 9420, [ 'instance_id' => 3 ], true, true ],
			'shipping method deleted' => [ 'on_shipping_method_deleted', [ 3, 'flat_rate', 1 ], 9421, [ 'instance_id' => 3 ], true, true ],
			'shipping method toggled' => [ 'on_shipping_method_status_toggled', [ 3, 'flat_rate', 1, 1 ], 9422, [ 'instance_id' => 3 ], true, true ],
			'webhook created'         => [ 'on_webhook_created', [ 4, 'webhook' ], 9430, [ 'webhook_id' => 4 ], true, true ],
			'webhook saved'           => [ 'on_before_save', [ 'webhook' ], 9431, [ 'webhook_id' => 4 ], true, true ],
			'webhook disabled'        => [ 'on_webhook_disabled_for_failures', [ 4 ], 9431, [ 'webhook_id' => 4 ], true, false ],
			'webhook deleted'         => [ 'on_webhook_deleted', [ 4, 'webhook' ], 9432, [ 'webhook_id' => 4 ], true, true ],
			'attribute added'         => [ 'on_attribute_added', [ 5, [ 'attribute_label' => 'Colour' ] ], 9440, [ 'attribute_id' => 5 ], false, true ],
			'attribute updated'       => [ 'on_attribute_updated', [ 5, [ 'attribute_label' => 'Colour' ], 'colour' ], 9441, [ 'attribute_id' => 5 ], true, true ],
			'attribute about to go'   => [ 'on_attribute_deleting', [ 5, 'colour', 'pa_colour' ], 9442, [ 'attribute_id' => 5 ], true, false ],
			'attribute deleted'       => [ 'on_attribute_deleted', [ 5, 'colour', 'pa_colour' ], 9442, [ 'attribute_id' => 5 ], true, true ],
		];
	}

	/**
	 * Whatever fails, a read of the shop or the site, or the dispatch itself,
	 * is reported and never thrown into the shop's own work (an exception
	 * thrown before a save can abort it).
	 *
	 * @dataProvider handlers
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string             $handler    Handler.
	 * @param array<int, mixed>  $args       Its arguments; `webhook` stands for a webhook.
	 * @param int                $code       Code of the event it works on.
	 * @param array<string, int> $ids        What identifies the object in the report.
	 * @param bool               $reads      Whether it reads the shop or the site.
	 * @param bool               $dispatches Whether it dispatches an event.
	 */
	public function test_every_handler_reports_instead_of_throwing( string $handler, array $args, int $code, array $ids, bool $reads, bool $dispatches ): void {
		$this->stubs();
		$args = array_map(
			static fn( $arg ) => 'webhook' === $arg ? new \WC_Webhook( 4, self::webhook(), [ 'name' => 'Renamed' ] ) : $arg,
			$args
		);
		ConfigState::$attributes[1][5] = self::attribute( 'Color', 'pa_color' );
		ConfigState::$tax_rates[1][7]  = self::tax_row( 'HU', '', '27.0000', 'ÁFA' );
		ConfigState::$zones            = [ 1 => 'Domestic' ];

		// Every read fails.
		ConfigState::$throws = true;
		$recorder            = $this->recorder();
		( new WooCommerceConfigSensor( $recorder ) )->$handler( ...$args );
		if ( $reads ) {
			$this->assertSame( [], $recorder->calls );
			$this->assertFailure( $code, $ids );
		} else {
			$this->assertSame( [], WpCalls::$actions );
		}

		// The dispatch fails.
		ConfigState::$throws = false;
		WpCalls::$actions    = [];
		( new WooCommerceConfigSensor( $this->throwing_dispatcher() ) )->$handler( ...$args );
		if ( $dispatches ) {
			$this->assertFailure( $code, $ids );
		} else {
			$this->assertSame( [], WpCalls::$actions );
		}
	}

	/**
	 * Hooks may pass ids as text; anything else they pass is ignored, never a
	 * failure.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_hooks_take_ids_as_text_and_never_fail_on_an_argument(): void {
		$this->stubs();

		$recorder = $this->recorder();
		$sensor   = new WooCommerceConfigSensor( $recorder );
		$sensor->on_tax_rate_added( 'x', [] );
		$sensor->on_tax_rate_updated( 0, null );
		$sensor->on_tax_rate_deleted( [] );
		$sensor->on_shipping_method_added( null, 'flat_rate', 1 );
		$sensor->on_shipping_method_deleted( 3, 'flat_rate', 'zone' );
		$sensor->on_shipping_method_status_toggled( -3, 'flat_rate', 1, 1 );
		$sensor->on_webhook_created( 0, null );
		$sensor->on_webhook_disabled_for_failures( 'x' );
		$sensor->on_webhook_deleted( [], null );
		$sensor->on_attribute_added( 0, [] );
		$sensor->on_attribute_updated( 'x', [], '' );
		$sensor->on_attribute_deleting( null, null, null );
		$sensor->on_attribute_deleted( 1.5, null, null );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( [], WpCalls::$actions );
	}

	/**
	 * The sensor listens to the shop's hooks with their arguments. The hook
	 * WooCommerce fires after a webhook is saved also fires on every delivery,
	 * so it is not used.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_sensor_listens_to_the_shop_hooks_with_their_arguments(): void {
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-hook-functions.php';

		$sensor = new WooCommerceConfigSensor( $this->recorder() );
		$this->assertSame( 'woocommerce-config', $sensor->key() );
		$sensor->boot();

		$expected = [
			'woocommerce_tax_rate_added'                         => 2,
			'woocommerce_tax_rate_updated'                       => 2,
			'woocommerce_tax_rate_deleted'                       => 1,
			'woocommerce_shipping_zone_method_added'             => 3,
			'woocommerce_shipping_zone_method_deleted'           => 3,
			'woocommerce_shipping_zone_method_status_toggled'    => 4,
			'woocommerce_new_webhook'                            => 2,
			'woocommerce_before_data_object_save'                => 1,
			'woocommerce_webhook_disabled_due_delivery_failures' => 1,
			'woocommerce_webhook_deleted'                        => 2,
			'woocommerce_attribute_added'                        => 2,
			'woocommerce_attribute_updated'                      => 3,
			'woocommerce_before_attribute_delete'                => 3,
			'woocommerce_attribute_deleted'                      => 3,
		];
		$this->assertSame( array_keys( $expected ), array_keys( WpCalls::$hooks ) );
		foreach ( $expected as $hook => $args ) {
			$this->assertSame(
				[
					'priority'      => 10,
					'accepted_args' => $args,
				],
				WpCalls::$hooks[ $hook ],
				$hook
			);
		}
		$this->assertArrayNotHasKey( 'woocommerce_webhook_updated', WpCalls::$hooks );
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Load the shop's stand-ins and the failure report.
	 */
	private function stubs(): void {
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';
		require_once __DIR__ . '/Fixtures/woocommerce-config-functions.php';
		require_once __DIR__ . '/Fixtures/woocommerce-tax-class.php';
		require_once __DIR__ . '/Fixtures/woocommerce-shipping-zones-class.php';
		require_once __DIR__ . '/Fixtures/woocommerce-webhook-class.php';
	}

	/**
	 * A tax rate row as the shop's form passes it.
	 *
	 * @return array<string, string>
	 */
	private static function tax_row( string $country, string $state, string $rate, string $name ): array {
		return [
			'tax_rate_id'       => '7',
			'tax_rate_country'  => $country,
			'tax_rate_state'    => $state,
			'tax_rate'          => $rate,
			'tax_rate_name'     => $name,
			'tax_rate_priority' => '1',
			'tax_rate_compound' => '0',
			'tax_rate_shipping' => '1',
			'tax_rate_order'    => '0',
			'tax_rate_class'    => '',
		];
	}

	/**
	 * A tax rate row as the event's metadata carries it: without its id,
	 * which is the target's.
	 *
	 * @return array<string, string>
	 */
	private static function stored_row( string $country, string $state, string $rate, string $name ): array {
		$row = self::tax_row( $country, $state, $rate, $name );
		unset( $row['tax_rate_id'] );

		return $row;
	}

	/**
	 * A webhook's stored values, secret included.
	 *
	 * @return array<string, mixed>
	 */
	private static function webhook(): array {
		return [
			'date_created'     => new \DateTimeImmutable( '2026-10-01 10:00:00' ),
			'date_modified'    => null,
			'status'           => 'active',
			'delivery_url'     => self::URL,
			'secret'           => self::SECRET_OLD,
			'name'             => 'Order feed',
			'topic'            => 'order.created',
			'hooks'            => '',
			'resource'         => '',
			'event'            => '',
			'failure_count'    => 0,
			'user_id'          => 1,
			'api_version'      => 3,
			'pending_delivery' => false,
		];
	}

	/**
	 * An attribute as `wc_get_attribute()` finds it.
	 *
	 * @return array<string, mixed>
	 */
	private static function attribute( string $label, string $taxonomy ): array {
		return [
			'name'         => $label,
			'slug'         => $taxonomy,
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		];
	}

	/**
	 * An attribute as the shop's add and update hooks pass it.
	 *
	 * @return array<string, mixed>
	 */
	private static function attribute_data( string $label, string $slug ): array {
		return [
			'attribute_label'   => $label,
			'attribute_name'    => $slug,
			'attribute_type'    => 'select',
			'attribute_orderby' => 'menu_order',
			'attribute_public'  => 0,
		];
	}

	/**
	 * @return array{type: string, id: string, label: string}
	 */
	private static function target( string $id, string $label ): array {
		return [
			'type'  => 'setting',
			'id'    => $id,
			'label' => $label,
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
	 * The sensor reported one failure, of the given event and object.
	 *
	 * @param int                $code Event code.
	 * @param array<string, int> $ids  What identifies the object.
	 */
	private function assertFailure( int $code, array $ids ): void {
		$this->assertCount( 1, WpCalls::$actions );
		[ $hook, $args ] = WpCalls::$actions[0];
		$this->assertSame( 'hellolog_dispatch_failed', $hook );
		$this->assertInstanceOf( \Throwable::class, $args[0] );
		$this->assertSame( $code, $args[1] );
		$this->assertSame( $ids, $args[2] );
	}

	/**
	 * The wire payload of a recorded event, built for an administrator on a
	 * dashboard screen.
	 *
	 * @param int                  $code   Event code.
	 * @param array<string, mixed> $fields What the sensor dispatched.
	 *
	 * @return array<string, mixed>
	 */
	private function build( int $code, array $fields ): array {
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

		$payload = $builder->build( $code, $fields );
		$this->assertIsArray( $payload, "code $code is in the catalog" );

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

	/**
	 * A dispatcher that fails on every event.
	 */
	private function throwing_dispatcher(): EventDispatcher {
		return new class() implements EventDispatcher {

			public function dispatch( int $code, array $fields = [] ): void {
				throw new \RuntimeException( 'the dispatch failed' );
			}
		};
	}
}
