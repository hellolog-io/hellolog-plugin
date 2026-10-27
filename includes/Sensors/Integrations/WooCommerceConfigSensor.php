<?php
/**
 * WooCommerce: tax rates, shipping methods, webhooks and product attributes.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Events\ChangeSet;
use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\SensitiveOption;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * The parts of the shop's configuration that are not options: tax rates
 * (9410–9412), the shipping methods of a zone (9420–9422), webhooks
 * (9430–9432) and product attributes (9440–9442). Each is a `setting` target
 * of its own (`tax_rate:<id>`, `shipping_method:<instance id>`,
 * `webhook:<id>`, `attribute:<id>`).
 *
 * - A tax rate is named by where it applies, its rate and its name (see
 *   {@see self::tax_rate_name()}), read from the row the shop stored: its
 *   hooks pass only the columns written. There is no hook before a rate is
 *   written, so a change tells no old values: it carries the row as it is
 *   now, and which columns were written. A deleted rate is gone when the
 *   shop tells it, so it is named by its id.
 * - A shipping method is named by the title of its type and the name of its
 *   zone (the shop names zone 0, the one for every place no other zone
 *   covers). When a method is added its own title is not saved yet, and when
 *   it is removed it is gone, so the type names it every time. Its own
 *   settings (its title, its costs) are an option the settings sensor tells
 *   on the option's target.
 * - A webhook tells its name, topic and status, and the host it posts to:
 *   never the rest of the address (a path or query often holds a token),
 *   never its secret. An edit is read from the shop's save of the webhook,
 *   from the values stored and those the save is about to write; a new
 *   secret is a flag without values. Every delivery saves the webhook too
 *   (its failure count, whether a ping is pending), which tells nothing a
 *   person set, so a save that changes none of {@see self::webhook_changes()}
 *   is not logged; the shop's hook after the save, which fires on every
 *   delivery, is not used. A webhook the shop switches off after too many
 *   failed deliveries is a status change saying so.
 * - An attribute is named by its label. Inside the update hook it still
 *   reads as it was, so its edit tells old and new values; the delete hook
 *   passes the slug, so the label is read just before the delete.
 *
 * Every handler runs inside the shop's own work (a save of a webhook runs
 * these hooks before the shop writes it, with no guard around them), so
 * whatever fails is reported, never thrown, and hook arguments are taken
 * loosely.
 */
final class WooCommerceConfigSensor extends AbstractSensor {

	/** Why a webhook the shop switched off itself was switched off. */
	public const DELIVERY_FAILURES = 'delivery_failures';

	/**
	 * The fields of a webhook a person sets, told when they change, in this
	 * order; the delivery address comes after them, then the rest.
	 *
	 * @var array<int, string>
	 */
	private const WEBHOOK_FIELDS = [ 'name', 'status', 'topic' ];

	/**
	 * More fields of a webhook a person sets, told after its delivery address.
	 *
	 * @var array<int, string>
	 */
	private const WEBHOOK_MORE_FIELDS = [ 'api_version', 'user_id' ];

	/** The field of a webhook that holds the address it posts to. */
	private const DELIVERY_URL = 'delivery_url';

	/** The field of a webhook that holds the secret its deliveries are signed with. */
	private const SECRET = 'secret';

	/**
	 * Columns of an attribute as the shop's hooks pass them => the field they
	 * are told as (the names of the shop's own attribute API), in the order
	 * the fields are told.
	 *
	 * @var array<string, string>
	 */
	private const ATTRIBUTE_COLUMNS = [
		'attribute_label'   => 'name',
		'attribute_name'    => 'slug',
		'attribute_type'    => 'type',
		'attribute_orderby' => 'order_by',
		'attribute_public'  => 'has_archives',
	];

	/** The start of the taxonomy of every product attribute. */
	private const ATTRIBUTE_TAXONOMY_PREFIX = 'pa_';

	/** The start of the name of every column of a tax rate. */
	private const TAX_RATE_COLUMN_PREFIX = 'tax_rate';

	/**
	 * Webhooks the shop is switching off after too many failed deliveries, in
	 * this request: `site:webhook id` => true, until the save that writes it.
	 *
	 * @var array<string, true>
	 */
	private array $disabled_for_failures = [];

	/**
	 * Labels of the attributes about to be deleted, read before the delete:
	 * `site:attribute id` => label.
	 *
	 * @var array<string, string>
	 */
	private array $attribute_labels = [];

	public function key(): string {
		return 'woocommerce-config';
	}

	public function should_load(): bool {
		return class_exists( 'WooCommerce', false );
	}

	public function boot(): void {
		add_action( 'woocommerce_tax_rate_added', [ $this, 'on_tax_rate_added' ], 10, 2 );
		add_action( 'woocommerce_tax_rate_updated', [ $this, 'on_tax_rate_updated' ], 10, 2 );
		add_action( 'woocommerce_tax_rate_deleted', [ $this, 'on_tax_rate_deleted' ], 10, 1 );
		add_action( 'woocommerce_shipping_zone_method_added', [ $this, 'on_shipping_method_added' ], 10, 3 );
		add_action( 'woocommerce_shipping_zone_method_deleted', [ $this, 'on_shipping_method_deleted' ], 10, 3 );
		add_action( 'woocommerce_shipping_zone_method_status_toggled', [ $this, 'on_shipping_method_status_toggled' ], 10, 4 );
		add_action( 'woocommerce_new_webhook', [ $this, 'on_webhook_created' ], 10, 2 );
		// Webhooks have no save hook of their own; coupons fire this one too.
		add_action( 'woocommerce_before_data_object_save', [ $this, 'on_before_save' ], 10, 1 );
		add_action( 'woocommerce_webhook_disabled_due_delivery_failures', [ $this, 'on_webhook_disabled_for_failures' ], 10, 1 );
		add_action( 'woocommerce_webhook_deleted', [ $this, 'on_webhook_deleted' ], 10, 2 );
		add_action( 'woocommerce_attribute_added', [ $this, 'on_attribute_added' ], 10, 2 );
		add_action( 'woocommerce_attribute_updated', [ $this, 'on_attribute_updated' ], 10, 3 );
		add_action( 'woocommerce_before_attribute_delete', [ $this, 'on_attribute_deleting' ], 10, 3 );
		add_action( 'woocommerce_attribute_deleted', [ $this, 'on_attribute_deleted' ], 10, 3 );
	}

	// ------------------------------------------------------------------
	// Tax rates.
	// ------------------------------------------------------------------

	/**
	 * A tax rate was added.
	 *
	 * @param mixed $tax_rate_id Tax rate id.
	 * @param mixed $tax_rate    The columns written (only those the caller passed).
	 */
	public function on_tax_rate_added( $tax_rate_id, $tax_rate = null ): void {
		$this->log_tax_rate( 9410, $tax_rate_id, $tax_rate );
	}

	/**
	 * A tax rate was changed.
	 *
	 * @param mixed $tax_rate_id Tax rate id.
	 * @param mixed $tax_rate    The columns written (only those the caller passed).
	 */
	public function on_tax_rate_updated( $tax_rate_id, $tax_rate = null ): void {
		$this->log_tax_rate( 9411, $tax_rate_id, $tax_rate );
	}

	/**
	 * A tax rate was deleted: its row is gone, so it is named by its id.
	 *
	 * @param mixed $tax_rate_id Tax rate id.
	 */
	public function on_tax_rate_deleted( $tax_rate_id ): void {
		$id = 0;
		try {
			$id = self::id_of( $tax_rate_id );
			if ( $id <= 0 ) {
				return;
			}

			$name = '#' . $id;
			$this->emit(
				9412,
				[
					'target' => self::target( 'tax_rate:' . $id, $name ),
					'vars'   => [ 'name' => $name ],
				]
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9412, [ 'tax_rate_id' => $id ] );
		}
	}

	/**
	 * How a tax rate is named: its country, its state and its rate, then its
	 * name in brackets (`US CA 7.25% (CA Tax)`), each left out when it is
	 * empty. The rate drops the zeros the shop stores it with. Empty when the
	 * row names nothing.
	 *
	 * @param array<int|string, mixed> $row The stored row of the rate.
	 */
	public static function tax_rate_name( array $row ): string {
		$parts = [];
		foreach ( [ 'tax_rate_country', 'tax_rate_state' ] as $column ) {
			$text = trim( self::text_of( $row[ $column ] ?? null ) );
			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		$rate = trim( self::text_of( $row['tax_rate'] ?? null ) );
		if ( str_contains( $rate, '.' ) && is_numeric( $rate ) ) {
			$rate = rtrim( rtrim( $rate, '0' ), '.' );
		}
		if ( '' !== $rate ) {
			$parts[] = $rate . '%';
		}

		$label = implode( ' ', $parts );
		$name  = trim( self::text_of( $row['tax_rate_name'] ?? null ) );
		if ( '' === $name ) {
			return $label;
		}

		return '' === $label ? $name : $label . ' (' . $name . ')';
	}

	/**
	 * Log a tax rate that was added or changed, named from its stored row,
	 * with the row in the metadata. A change also tells which columns were
	 * written, never old values: the shop has no hook before the write.
	 *
	 * @param int   $code        9410 or 9411.
	 * @param mixed $tax_rate_id Tax rate id.
	 * @param mixed $written     The columns written.
	 */
	private function log_tax_rate( int $code, $tax_rate_id, $written ): void {
		$id = 0;
		try {
			$id = self::id_of( $tax_rate_id );
			if ( $id <= 0 ) {
				return;
			}

			$row  = self::tax_rate_row( $id );
			$name = self::tax_rate_name( $row );
			$name = '' === $name ? '#' . $id : $name;

			$metadata = [];
			if ( [] !== $row ) {
				$metadata['tax_rate'] = $row;
			}
			if ( 9411 === $code ) {
				$metadata['fields'] = self::tax_rate_columns( $written );
			}

			$this->emit(
				$code,
				[
					'target'   => self::target( 'tax_rate:' . $id, $name ),
					'vars'     => [ 'name' => $name ],
					'metadata' => $metadata,
				]
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, $code, [ 'tax_rate_id' => $id ] );
		}
	}

	/**
	 * The stored row of a tax rate, without its id (the target's) and without
	 * anything that is not a plain value; empty when it cannot be found.
	 *
	 * @param int $tax_rate_id Tax rate id.
	 *
	 * @return array<string, mixed>
	 */
	private static function tax_rate_row( int $tax_rate_id ): array {
		if ( ! class_exists( 'WC_Tax' ) || ! method_exists( 'WC_Tax', '_get_tax_rate' ) ) {
			return [];
		}

		$row = \WC_Tax::_get_tax_rate( $tax_rate_id );
		if ( ! is_array( $row ) ) {
			return [];
		}

		$clean = [];
		foreach ( $row as $column => $value ) {
			if ( is_string( $column ) && 'tax_rate_id' !== $column && ( null === $value || is_scalar( $value ) ) ) {
				$clean[ $column ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * The names of the tax rate columns a write passed.
	 *
	 * @param mixed $written The columns written.
	 *
	 * @return array<int, string>
	 */
	private static function tax_rate_columns( $written ): array {
		if ( ! is_array( $written ) ) {
			return [];
		}

		$columns = [];
		foreach ( array_keys( $written ) as $column ) {
			if ( is_string( $column ) && str_starts_with( $column, self::TAX_RATE_COLUMN_PREFIX ) ) {
				$columns[] = $column;
			}
		}

		return $columns;
	}

	// ------------------------------------------------------------------
	// Shipping methods.
	// ------------------------------------------------------------------

	/**
	 * A shipping method was added to a zone.
	 *
	 * @param mixed $instance_id Id of the method in the zone.
	 * @param mixed $type        Id of the method's type (`flat_rate`).
	 * @param mixed $zone_id     Zone id.
	 */
	public function on_shipping_method_added( $instance_id, $type = null, $zone_id = null ): void {
		$this->log_shipping_method( 9420, $instance_id, $type, $zone_id );
	}

	/**
	 * A shipping method was removed from a zone.
	 *
	 * @param mixed $instance_id Id of the method in the zone.
	 * @param mixed $method_id   Id of the method's type.
	 * @param mixed $zone_id     Zone id.
	 */
	public function on_shipping_method_deleted( $instance_id, $method_id = null, $zone_id = null ): void {
		$this->log_shipping_method( 9421, $instance_id, $method_id, $zone_id );
	}

	/**
	 * A shipping method was switched on or off.
	 *
	 * @param mixed $instance_id Id of the method in the zone.
	 * @param mixed $method_id   Id of the method's type.
	 * @param mixed $zone_id     Zone id.
	 * @param mixed $is_enabled  Whether it is on now: a boolean from the REST API, 0 or 1 from the settings screen.
	 */
	public function on_shipping_method_status_toggled( $instance_id, $method_id = null, $zone_id = null, $is_enabled = null ): void {
		$this->log_shipping_method( 9422, $instance_id, $method_id, $zone_id, $is_enabled );
	}

	/**
	 * Log a shipping method of a zone, named by its type and its zone. A
	 * switch tells which way it went (`vars.state`).
	 *
	 * @param int   $code        9420, 9421 or 9422.
	 * @param mixed $instance_id Id of the method in the zone.
	 * @param mixed $method_id   Id of the method's type.
	 * @param mixed $zone_id     Zone id.
	 * @param mixed $is_enabled  Whether it is on now (9422 only).
	 */
	private function log_shipping_method( int $code, $instance_id, $method_id, $zone_id, $is_enabled = null ): void {
		$id = 0;
		try {
			$id   = self::id_of( $instance_id );
			$zone = self::zone_id_of( $zone_id );
			if ( $id <= 0 || null === $zone ) {
				return;
			}

			$method = self::text_of( $method_id );
			$name   = self::method_title( $method );
			if ( '' === $name ) {
				$name = '' !== $method ? $method : '#' . $id;
			}
			$zone_name = self::zone_name( $zone );
			$zone_name = '' !== $zone_name ? $zone_name : '#' . $zone;

			$vars     = [
				'name' => $name,
				'zone' => $zone_name,
			];
			$metadata = [
				'method_id' => $method,
				'zone_id'   => $zone,
			];
			if ( 9422 === $code ) {
				$enabled             = self::is_on( $is_enabled );
				$vars['state']       = $enabled ? 'enabled' : 'disabled';
				$metadata['enabled'] = $enabled;
			}

			$this->emit(
				$code,
				[
					'target'   => self::target( 'shipping_method:' . $id, $name . ' (' . $zone_name . ')' ),
					'vars'     => $vars,
					'metadata' => $metadata,
				]
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, $code, [ 'instance_id' => $id ] );
		}
	}

	/**
	 * The title of a shipping method type the shop lists; empty when it does
	 * not list it.
	 *
	 * @param string $method_id Id of the method's type.
	 */
	private static function method_title( string $method_id ): string {
		if ( '' === $method_id || ! function_exists( 'WC' ) ) {
			return '';
		}

		$shop     = WC();
		$shipping = is_object( $shop ) && method_exists( $shop, 'shipping' ) ? $shop->shipping() : null;
		$methods  = is_object( $shipping ) && method_exists( $shipping, 'get_shipping_methods' ) ? $shipping->get_shipping_methods() : [];
		$method   = is_array( $methods ) ? ( $methods[ $method_id ] ?? null ) : null;

		return is_object( $method ) && method_exists( $method, 'get_method_title' ) ? trim( self::text_of( $method->get_method_title() ) ) : '';
	}

	/**
	 * The name of a shipping zone; empty when it cannot be loaded. The shop
	 * names zone 0 itself.
	 *
	 * @param int $zone_id Zone id.
	 */
	private static function zone_name( int $zone_id ): string {
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! method_exists( 'WC_Shipping_Zones', 'get_zone' ) ) {
			return '';
		}

		$zone = \WC_Shipping_Zones::get_zone( $zone_id );

		return is_object( $zone ) && method_exists( $zone, 'get_zone_name' ) ? trim( self::text_of( $zone->get_zone_name() ) ) : '';
	}

	/**
	 * A zone id a hook passes, as a number or as text; null for anything
	 * else. Zone 0 is a zone.
	 *
	 * @param mixed $value What the hook passed.
	 */
	private static function zone_id_of( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}

		return is_string( $value ) && ctype_digit( $value ) ? (int) $value : null;
	}

	/**
	 * Whether a switch a hook passes is on: a boolean as it is, a number or
	 * its text as a number other than 0; off for anything else.
	 *
	 * @param mixed $value What the hook passed.
	 */
	private static function is_on( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return ( is_int( $value ) || is_float( $value ) || is_string( $value ) ) && (bool) (int) $value;
	}

	// ------------------------------------------------------------------
	// Webhooks.
	// ------------------------------------------------------------------

	/**
	 * WooCommerce inserted a webhook.
	 *
	 * @param mixed $webhook_id Webhook id.
	 * @param mixed $webhook    The webhook.
	 */
	public function on_webhook_created( $webhook_id, $webhook = null ): void {
		$id = 0;
		try {
			$id = self::id_of( $webhook_id );
			if ( $id <= 0 ) {
				return;
			}

			$this->emit( 9430, self::webhook_fields( $id, self::webhook_data( $webhook ) ) );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9430, [ 'webhook_id' => $id ] );
		}
	}

	/**
	 * A data object is about to be saved by the shop: when it is a webhook
	 * that exists, tell which of the fields a person sets change, from the
	 * values it has stored (`get_data()`) and those the save is about to
	 * write (`get_changes()`). A save that changes none of them (a delivery
	 * writing its bookkeeping) is not dispatched.
	 *
	 * @param mixed $webhook The object WooCommerce is about to save.
	 */
	public function on_before_save( $webhook ): void {
		$id = 0;
		try {
			if ( ! $webhook instanceof \WC_Webhook ) {
				return;
			}
			$id = self::id_of( $webhook->get_id() );
			if ( $id <= 0 ) {
				return;
			}

			$key          = self::state_key( $id );
			$for_failures = isset( $this->disabled_for_failures[ $key ] );
			unset( $this->disabled_for_failures[ $key ] );

			$pending = $webhook->get_changes();
			$pending = is_array( $pending ) ? $pending : [];
			if ( [] === $pending ) {
				return;
			}
			$stored = self::webhook_data( $webhook );

			$changes = self::webhook_changes( $stored, $pending );
			if ( [] === $changes ) {
				return;
			}

			$fields = self::webhook_fields(
				$id,
				array_merge( $stored, $pending ),
				$for_failures ? [ 'reason' => self::DELIVERY_FAILURES ] : []
			);

			$fields['changes'] = $changes;
			$this->emit( 9431, $fields );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9431, [ 'webhook_id' => $id ] );
		}
	}

	/**
	 * The shop is switching a webhook off after too many failed deliveries,
	 * and saves it right after: that save's status change is told saying so.
	 *
	 * @param mixed $webhook_id Webhook id.
	 */
	public function on_webhook_disabled_for_failures( $webhook_id ): void {
		$id = 0;
		try {
			$id = self::id_of( $webhook_id );
			if ( $id > 0 ) {
				$this->disabled_for_failures[ self::state_key( $id ) ] = true;
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9431, [ 'webhook_id' => $id ] );
		}
	}

	/**
	 * WooCommerce deleted a webhook; the object it passes still holds what
	 * the webhook was.
	 *
	 * @param mixed $webhook_id Webhook id.
	 * @param mixed $webhook    The webhook.
	 */
	public function on_webhook_deleted( $webhook_id, $webhook = null ): void {
		$id = 0;
		try {
			$id = self::id_of( $webhook_id );
			if ( $id <= 0 ) {
				return;
			}

			$this->emit( 9432, self::webhook_fields( $id, self::webhook_data( $webhook ) ) );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9432, [ 'webhook_id' => $id ] );
		}
	}

	/**
	 * What changed in a webhook: its name, status and topic, the address it
	 * posts to, its API version and its user, then its secret.
	 *
	 * The address is told by its host only (in lower case): another address
	 * on the same host is a change without values. A new secret is told as
	 * `[redacted]` on both sides. Nothing else of the webhook is compared,
	 * so the bookkeeping a delivery writes (its failure count, whether a ping
	 * is pending, the time) is no change.
	 *
	 * @param array<int|string, mixed> $stored  The webhook's values as stored.
	 * @param array<int|string, mixed> $pending The values a save is about to write.
	 *
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function webhook_changes( array $stored, array $pending ): array {
		$after  = array_merge( $stored, $pending );
		$before = self::webhook_values( $stored );
		$now    = self::webhook_values( $after );

		$changes = array_merge(
			ChangeSet::diff( $before, $now, self::WEBHOOK_FIELDS ),
			self::address_changes( $stored[ self::DELIVERY_URL ] ?? null, $after[ self::DELIVERY_URL ] ?? null ),
			ChangeSet::diff( $before, $now, self::WEBHOOK_MORE_FIELDS )
		);

		// The secret is compared here and nowhere else, and never reported.
		if ( array_key_exists( self::SECRET, $pending ) && self::text_of( $pending[ self::SECRET ] ) !== self::text_of( $stored[ self::SECRET ] ?? null ) ) {
			$changes[] = [
				'field' => self::SECRET,
				'old'   => SensitiveOption::REDACTED,
				'new'   => SensitiveOption::REDACTED,
			];
		}

		return $changes;
	}

	/**
	 * The change of the address a webhook posts to: its host old → new, or,
	 * when only the rest of the address changed, a change without values.
	 *
	 * @param mixed $old The address before.
	 * @param mixed $new The address after.
	 *
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	private static function address_changes( $old, $new ): array {
		$old = self::text_of( $old );
		$new = self::text_of( $new );
		if ( $old === $new ) {
			return [];
		}

		$hosts = ChangeSet::diff(
			[ self::DELIVERY_URL => self::host( $old ) ],
			[ self::DELIVERY_URL => self::host( $new ) ],
			[ self::DELIVERY_URL ]
		);
		if ( [] !== $hosts ) {
			return $hosts;
		}

		// Same host, another address: told as changed, with neither address.
		return ChangeSet::diff( [ self::DELIVERY_URL => $old ], [ self::DELIVERY_URL => $new ], [ self::DELIVERY_URL ], [ self::DELIVERY_URL ] );
	}

	/**
	 * The fields of a webhook a person sets that are compared as they are,
	 * as plain values: never its address, never its secret.
	 *
	 * @param array<int|string, mixed> $data A webhook's values.
	 *
	 * @return array<string, mixed>
	 */
	private static function webhook_values( array $data ): array {
		$values = [];
		foreach ( array_merge( self::WEBHOOK_FIELDS, self::WEBHOOK_MORE_FIELDS ) as $field ) {
			$value            = $data[ $field ] ?? null;
			$values[ $field ] = is_scalar( $value ) ? $value : null;
		}

		return $values;
	}

	/**
	 * What every webhook event carries: its target, its name, and its topic,
	 * the host it posts to and its status as metadata (those it has).
	 *
	 * @param int                      $webhook_id Webhook id.
	 * @param array<int|string, mixed> $data       The webhook's values.
	 * @param array<string, mixed>     $more       More metadata.
	 *
	 * @return array<string, mixed>
	 */
	private static function webhook_fields( int $webhook_id, array $data, array $more = [] ): array {
		$name = trim( self::text_of( $data['name'] ?? null ) );
		$name = '' === $name ? '#' . $webhook_id : $name;

		$metadata = self::without_empty(
			[
				'topic'            => self::text_of( $data['topic'] ?? null ),
				self::DELIVERY_URL => self::host( self::text_of( $data[ self::DELIVERY_URL ] ?? null ) ),
				'status'           => self::text_of( $data['status'] ?? null ),
			]
		);

		return [
			'target'   => self::target( 'webhook:' . $webhook_id, $name ),
			'vars'     => [ 'name' => $name ],
			'metadata' => array_merge( $metadata, $more ),
		];
	}

	/**
	 * A webhook's stored values (secret included: only ever compared, see
	 * {@see self::webhook_changes()}); empty when it passes none.
	 *
	 * @param mixed $webhook The webhook a hook passed.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function webhook_data( $webhook ): array {
		if ( ! is_object( $webhook ) || ! method_exists( $webhook, 'get_data' ) ) {
			return [];
		}

		$data = $webhook->get_data();

		return is_array( $data ) ? $data : [];
	}

	/**
	 * The host of an address, in lower case; empty when it has none.
	 *
	 * @param string $url Address.
	 */
	private static function host( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$host = function_exists( 'wp_parse_url' )
			? wp_parse_url( $url, PHP_URL_HOST )
			: parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- outside WordPress only.

		// Not strtolower(): before PHP 8.2 it follows the locale.
		return is_string( $host ) ? strtr( $host, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' ) : '';
	}

	// ------------------------------------------------------------------
	// Attributes.
	// ------------------------------------------------------------------

	/**
	 * A product attribute was created.
	 *
	 * @param mixed $attribute_id Attribute id.
	 * @param mixed $data         Its columns.
	 */
	public function on_attribute_added( $attribute_id, $data = null ): void {
		$id = 0;
		try {
			$id = self::id_of( $attribute_id );
			if ( $id <= 0 ) {
				return;
			}

			$values = self::attribute_values( $data );
			$this->emit(
				9440,
				self::attribute_fields( $id, self::attribute_name( $id, $values['name'] ?? '', $values['slug'] ?? '' ), array_diff_key( $values, [ 'name' => true ] ) )
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9440, [ 'attribute_id' => $id ] );
		}
	}

	/**
	 * A product attribute was changed. Inside this hook the attribute still
	 * reads as it was (the shop drops its cache after the hook), and the hook
	 * passes the slug it had: those are the old values, `$data` the new ones.
	 * An attribute that cannot be read as it was is logged with what the hook
	 * tells (the slug); one that reads the same as it is now is not logged.
	 *
	 * @param mixed $attribute_id Attribute id.
	 * @param mixed $data         Its new columns.
	 * @param mixed $old_slug     The slug it had.
	 */
	public function on_attribute_updated( $attribute_id, $data = null, $old_slug = null ): void {
		$id = 0;
		try {
			$id = self::id_of( $attribute_id );
			if ( $id <= 0 ) {
				return;
			}

			$after    = self::attribute_values( $data );
			$stored   = self::stored_attribute( $id );
			$old_slug = self::text_of( $old_slug );

			$before   = $stored ?? [];
			$compared = null === $stored ? [] : array_values( self::ATTRIBUTE_COLUMNS );
			if ( '' !== $old_slug ) {
				$before['slug'] = $old_slug;
				$compared[]     = 'slug';
			}
			$compared = array_values( array_intersect( array_values( self::ATTRIBUTE_COLUMNS ), $compared, array_keys( $after ) ) );

			$changes = ChangeSet::diff( $before, $after, $compared );
			if ( [] === $changes && null !== $stored ) {
				return;
			}

			$metadata = self::without_empty(
				[
					'old_slug' => $old_slug,
					'slug'     => $after['slug'] ?? '',
				]
			);

			$fields = self::attribute_fields( $id, self::attribute_name( $id, $after['name'] ?? ( $stored['name'] ?? '' ), $after['slug'] ?? $old_slug ), $metadata );
			if ( [] !== $changes ) {
				$fields['changes'] = $changes;
			}
			$this->emit( 9441, $fields );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9441, [ 'attribute_id' => $id ] );
		}
	}

	/**
	 * A product attribute is about to be deleted: note its label, which the
	 * delete hook does not pass.
	 *
	 * @param mixed $attribute_id Attribute id.
	 * @param mixed $name         Its slug.
	 * @param mixed $taxonomy     Its taxonomy.
	 */
	public function on_attribute_deleting( $attribute_id, $name = null, $taxonomy = null ): void {
		$id = 0;
		try {
			$id = self::id_of( $attribute_id );
			if ( $id <= 0 ) {
				return;
			}

			$label = self::stored_attribute( $id )['name'] ?? '';
			if ( '' !== $label ) {
				$this->attribute_labels[ self::state_key( $id ) ] = $label;
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9442, [ 'attribute_id' => $id ] );
		}
	}

	/**
	 * A product attribute was deleted, named by the label read before the
	 * delete; else by its slug, else by its id.
	 *
	 * @param mixed $attribute_id Attribute id.
	 * @param mixed $name         Its slug.
	 * @param mixed $taxonomy     Its taxonomy.
	 */
	public function on_attribute_deleted( $attribute_id, $name = null, $taxonomy = null ): void {
		$id = 0;
		try {
			$id = self::id_of( $attribute_id );
			if ( $id <= 0 ) {
				return;
			}

			$key   = self::state_key( $id );
			$label = $this->attribute_labels[ $key ] ?? '';
			unset( $this->attribute_labels[ $key ] );

			$slug     = self::text_of( $name );
			$metadata = self::without_empty(
				[
					'slug'     => $slug,
					'taxonomy' => self::text_of( $taxonomy ),
				]
			);

			$this->emit( 9442, self::attribute_fields( $id, self::attribute_name( $id, $label, $slug ), $metadata ) );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9442, [ 'attribute_id' => $id ] );
		}
	}

	/**
	 * An attribute's columns as the fields they are told as: texts, and
	 * whether it has archives as a yes/no.
	 *
	 * @param mixed $data Columns a hook passed.
	 *
	 * @return array<string, string|bool>
	 */
	private static function attribute_values( $data ): array {
		if ( ! is_array( $data ) ) {
			return [];
		}

		$values = [];
		foreach ( self::ATTRIBUTE_COLUMNS as $column => $field ) {
			if ( array_key_exists( $column, $data ) ) {
				$values[ $field ] = 'has_archives' === $field ? (bool) $data[ $column ] : self::text_of( $data[ $column ] );
			}
		}

		return $values;
	}

	/**
	 * An attribute as the shop reads it now, as the fields it is told as (the
	 * slug without the taxonomy's `pa_`); null when it cannot be found.
	 *
	 * @param int $attribute_id Attribute id.
	 *
	 * @return array<string, string|bool>|null
	 */
	private static function stored_attribute( int $attribute_id ): ?array {
		if ( ! function_exists( 'wc_get_attribute' ) ) {
			return null;
		}

		$attribute = wc_get_attribute( $attribute_id );
		if ( ! is_object( $attribute ) ) {
			return null;
		}

		$slug = self::text_of( $attribute->slug ?? null );
		if ( str_starts_with( $slug, self::ATTRIBUTE_TAXONOMY_PREFIX ) ) {
			$slug = substr( $slug, strlen( self::ATTRIBUTE_TAXONOMY_PREFIX ) );
		}

		return [
			'name'         => self::text_of( $attribute->name ?? null ),
			'slug'         => $slug,
			'type'         => self::text_of( $attribute->type ?? null ),
			'order_by'     => self::text_of( $attribute->order_by ?? null ),
			'has_archives' => (bool) ( $attribute->has_archives ?? false ),
		];
	}

	/**
	 * How an attribute is named: its label, else its slug, else its id.
	 *
	 * @param int         $attribute_id Attribute id.
	 * @param string|bool $label        Its label.
	 * @param string|bool $slug         Its slug.
	 */
	private static function attribute_name( int $attribute_id, $label, $slug ): string {
		foreach ( [ $label, $slug ] as $name ) {
			$name = is_string( $name ) ? trim( $name ) : '';
			if ( '' !== $name ) {
				return $name;
			}
		}

		return '#' . $attribute_id;
	}

	/**
	 * What every attribute event carries.
	 *
	 * @param int                  $attribute_id Attribute id.
	 * @param string               $name         How it is named.
	 * @param array<string, mixed> $metadata     Its metadata.
	 *
	 * @return array<string, mixed>
	 */
	private static function attribute_fields( int $attribute_id, string $name, array $metadata ): array {
		return [
			'target'   => self::target( 'attribute:' . $attribute_id, $name ),
			'vars'     => [ 'name' => $name ],
			'metadata' => $metadata,
		];
	}

	// ------------------------------------------------------------------
	// Shared.
	// ------------------------------------------------------------------

	/**
	 * A `setting` target.
	 *
	 * @param string $id    Target id.
	 * @param string $label Its label.
	 *
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
	 * An id a hook passes, as a number or as text; 0 for anything else.
	 *
	 * @param mixed $value What the hook passed.
	 */
	private static function id_of( $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}

		return is_string( $value ) && ctype_digit( $value ) ? (int) $value : 0;
	}

	/**
	 * A text a hook passes: a string as it is, a number as its text, '' for
	 * anything else.
	 *
	 * @param mixed $value What the hook passed.
	 */
	private static function text_of( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		return is_int( $value ) || is_float( $value ) ? (string) $value : '';
	}

	/**
	 * The texts that are not empty, under their keys.
	 *
	 * @param array<string, string|bool> $texts Texts by key.
	 *
	 * @return array<string, string|bool>
	 */
	private static function without_empty( array $texts ): array {
		$kept = [];
		foreach ( $texts as $key => $text ) {
			if ( '' !== $text ) {
				$kept[ $key ] = $text;
			}
		}

		return $kept;
	}

	/**
	 * The key of a per-request note about an object: an id is unique on its
	 * own site only.
	 *
	 * @param int $id Object id.
	 */
	private static function state_key( int $id ): string {
		return self::blog_id() . ':' . $id;
	}

	/**
	 * The current site; 0 outside WordPress.
	 */
	private static function blog_id(): int {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	}
}
