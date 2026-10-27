<?php
/**
 * WooCommerce: order lifecycle.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use Automattic\WooCommerce\Utilities\OrderUtil;
use HelloLog\Events\ActionSchedulerContext;
use HelloLog\Events\ChangeSet;
use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\StoreTargets;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Order lifecycle (9100–9111). Uses WC's high-level hooks so HPOS-mode
 * stores work identically — no direct `$wpdb` reads.
 *
 * Every event names its order as `target` (labelled with the order number)
 * and, while the order still exists, links to its edit screen. A new order
 * (9100) is told when the request, or the Action Scheduler job that created
 * it, ends, with the total, currency, payment method and channel it has
 * then: WooCommerce announces an order made through its REST API or
 * `wc_create_order()` before it calculates its totals. A long run that
 * creates more than 50 tells the oldest at once. An order note
 * event also carries the text of the note and its kind, see
 * {@see self::note_type()}; a deleted note (9111) its text, unless the note
 * goes with the erasure of the customer's personal data.
 *
 * An item added to an order (9106), changed (9108, one event per item and
 * save, see {@see self::ITEM_FIELDS}) or removed (9107) is told on its order.
 * The items a refund copies onto itself are not (the refund event, 9104,
 * tells the refund), nor are the items of an order that is still being
 * created, or rebuilt from the cart in the request (a retried checkout). An
 * item added in a request is not also changed in it. A refund tells its
 * amount, currency and reason, and whether the gateway returned the money.
 *
 * A save of an existing order tells which of {@see self::ORDER_FIELDS}
 * changed (9105), read from the order before WooCommerce writes it; the
 * customer's details among them ({@see self::CONTENT_FIELDS}) without their
 * values. A save of an order that is still being created tells nothing (its
 * creation is 9100). No event sends the values of the customer's address
 * fields or note, and a new order sends none of them; the text of a note,
 * the reason of a refund and the name of an item are sent as written (a
 * note's secrets masked, see {@see self::note_text()}), so a contact detail
 * typed into one of them is sent too. A status change carries the status
 * as a change, and a status change out of the trash is also a restore
 * (9110), as are WooCommerce's own restore and WordPress's restore of an
 * order stored as a post.
 *
 * Every handler runs inside WooCommerce's work on the order (an exception in
 * the hook before a save aborts the write of the order), so whatever fails
 * while reading the order is reported, never thrown.
 */
final class WooCommerceOrderSensor extends AbstractSensor {

	/**
	 * The fields of an order whose edits are told: the addresses (`.*` stands
	 * for every field under it), the totals, the payment, the customer and
	 * the dates. The status has its own event, the items theirs.
	 *
	 * @var array<int, string>
	 */
	public const ORDER_FIELDS = [
		'billing.*',
		'shipping.*',
		'total',
		'discount_total',
		'shipping_total',
		'payment_method',
		'payment_method_title',
		'customer_id',
		'customer_note',
		'currency',
		'date_created',
		'transaction_id',
		'date_paid',
		'date_completed',
	];

	/**
	 * The fields of {@see self::ORDER_FIELDS} that hold the customer's own
	 * details: every field of the billing and shipping addresses (names,
	 * company, address, postcode, country, e-mail, phone, and whatever else a
	 * shop keeps there) and the customer's note. They are told as changed,
	 * never with their values, so the values of these fields never leave the
	 * site. Texts the order holds elsewhere (a note, a refund's reason, an
	 * item's name) are sent as written.
	 *
	 * @var array<int, string>
	 */
	public const CONTENT_FIELDS = [
		'billing.*',
		'shipping.*',
		'customer_note',
	];

	/**
	 * Amounts of money: `10.00` and `10` are the same amount.
	 *
	 * @var array<int, string>
	 */
	private const MONEY_FIELDS = [ 'total', 'discount_total', 'shipping_total' ];

	/**
	 * The fields of an order item whose changes are told, in this order: its
	 * name (a product's, a fee's, the shipping method's, a coupon's code, a
	 * tax rate's code), its quantity and its amounts.
	 *
	 * @var array<int, string>
	 */
	public const ITEM_FIELDS = [ 'name', 'quantity', 'subtotal', 'total', 'total_tax' ];

	/**
	 * The fields of {@see self::ITEM_FIELDS} that hold a number: `10.00` and
	 * `10` are the same, and so are an empty amount and `0`.
	 *
	 * @var array<int, string>
	 */
	private const ITEM_NUMBER_FIELDS = [ 'quantity', 'subtotal', 'total', 'total_tax' ];

	/** The type of a refund, which WooCommerce keeps as an order of its own. */
	private const REFUND_TYPE = 'shop_order_refund';

	/** The type of an item that is a line of a product, the only one with a quantity. */
	private const LINE_ITEM = 'line_item';

	/** What `metadata.context` of a note deleted with a customer's personal data says. */
	public const PRIVACY_ERASURE = 'privacy-erasure';

	/**
	 * Stored statuses of an order that is still being created: not set yet,
	 * WordPress's and WooCommerce's placeholders, and the drafts of the admin
	 * screen and the checkout. A save of such an order fills its fields in.
	 *
	 * @var array<int, string>
	 */
	private const CREATION_STATUSES = [ '', 'new', 'auto-draft', 'draft', 'checkout-draft' ];

	/** What WooCommerce puts in front of a status where it stores it as a post status. */
	private const STATUS_PREFIX = 'wc-';

	/**
	 * Most new orders held at once (see {@see self::on_created()}). A long
	 * run (a bulk import from the command line) tells the oldest at once
	 * beyond it: by the time this many more orders were created, it is
	 * finished.
	 */
	private const MAX_HELD_ORDERS = 50;

	/** Longest note text sent, in characters, the ellipsis included. */
	private const MAX_NOTE_LENGTH = 500;

	/** What a note reads when nothing is left of it. */
	private const EMPTY_NOTE = '(empty note)';

	/**
	 * Longest note, in bytes, that can still be a status sentence. Status names
	 * are short; the limit only keeps the match cheap on a huge note.
	 */
	private const MAX_STATUS_NOTE_LENGTH = 1000;

	private const ELLIPSIS = '…';

	/**
	 * Numbers of the orders about to be deleted, read while they can still
	 * be loaded: `site:order id` => order number. Taken by the delete event.
	 *
	 * @var array<string, string>
	 */
	private array $deleting = [];

	/**
	 * The orders whose customer's personal data WooCommerce is erasing, from
	 * the start of the erasure to its end: `site:order id` => true.
	 *
	 * @var array<string, true>
	 */
	private array $erasing = [];

	/**
	 * The orders whose items are rebuilt from the cart in this request (a
	 * checkout retried after a failed payment removes them all and adds them
	 * again): `site:order id` => how many Action Scheduler jobs were running
	 * (see {@see ActionSchedulerContext::depth()}). Their items are not told.
	 * What a job noted ends with the job, see {@see self::flush_job_orders()}.
	 *
	 * @var array<string, int>
	 */
	private array $rebuilt = [];

	/**
	 * The items added in this request: `site:item id` => how many Action
	 * Scheduler jobs were running. Saving one again in the request (or the
	 * job that added it) is part of adding it, no change.
	 *
	 * @var array<string, int>
	 */
	private array $added_items = [];

	/**
	 * New orders, held until the request (or the job that created them)
	 * ends, oldest first, at most {@see self::MAX_HELD_ORDERS}:
	 * `site:order id` => the site, how many Action Scheduler jobs were
	 * running (see {@see ActionSchedulerContext::depth()}) and the creation
	 * as the order told it when it was announced.
	 *
	 * @var array<string, array{blog: int, depth: int, order_id: int, fields: array<string, mixed>}>
	 */
	private array $created = [];

	public function key(): string {
		return 'woocommerce-order';
	}

	public function should_load(): bool {
		return class_exists( 'WooCommerce', false );
	}

	public function boot(): void {
		add_action( 'woocommerce_new_order', [ $this, 'on_created' ], 10, 2 );
		add_action( 'woocommerce_before_order_object_save', [ $this, 'on_before_save' ], 10, 1 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 10, 4 );
		add_action( 'woocommerce_trash_order', [ $this, 'on_trashed' ], 10, 1 );
		add_action( 'woocommerce_untrash_order', [ $this, 'on_untrashed' ], 10, 2 );
		add_action( 'untrashed_post', [ $this, 'on_post_untrashed' ], 10, 2 );
		add_action( 'woocommerce_before_delete_order', [ $this, 'on_before_delete' ], 10, 2 );
		add_action( 'woocommerce_delete_order', [ $this, 'on_deleted' ], 10, 1 );
		add_action( 'woocommerce_order_note_added', [ $this, 'on_note_added' ], 10, 2 );
		add_action( 'woocommerce_order_refunded', [ $this, 'on_refunded' ], 10, 2 );
		add_action( 'woocommerce_new_order_item', [ $this, 'on_item_added' ], 10, 3 );
		// Every type of item fires this hook.
		add_action( 'woocommerce_before_order_item_object_save', [ $this, 'on_item_saving' ], 10, 1 );
		add_action( 'woocommerce_before_delete_order_item', [ $this, 'on_item_deleting' ], 10, 1 );
		add_action( 'woocommerce_order_note_deleted', [ $this, 'on_note_deleted' ], 10, 2 );
		// The erasure of a customer's personal data deletes every note of the
		// order between these two.
		add_action( 'woocommerce_privacy_before_remove_order_personal_data', [ $this, 'on_privacy_erasing' ], 10, 1 );
		add_action( 'woocommerce_privacy_remove_order_personal_data', [ $this, 'on_privacy_erased' ], 10, 1 );
		// An order's items rebuilt from the cart: removed all at once, with no
		// hook per item, then added again.
		add_action( 'woocommerce_resume_order', [ $this, 'on_order_resumed' ], 10, 1 );
		add_action( 'woocommerce_remove_order_items', [ $this, 'on_items_removing' ], 10, 2 );
		// New orders, before the request's events are written at priority 1.
		add_action( 'shutdown', [ $this, 'flush_new_orders' ], 0 );
		// Those a later shutdown callback created, before the final write at
		// PHP_INT_MAX.
		add_action( 'shutdown', [ $this, 'flush_new_orders' ], PHP_INT_MAX - 1 );
		// Those a job created, before its context ends at PHP_INT_MAX.
		add_action( 'action_scheduler_after_execute', [ $this, 'flush_job_orders' ], PHP_INT_MAX - 1, 0 );
		add_action( 'action_scheduler_failed_execution', [ $this, 'flush_job_orders' ], PHP_INT_MAX - 1, 0 );
	}

	/**
	 * The object an event about an order is about; the label is the order
	 * number, which differs from the id on shops with sequential numbering
	 * (see {@see StoreTargets::order()}).
	 *
	 * @param int    $id     Order id.
	 * @param string $number Order number; the id is used when empty.
	 * @return array{type: string, id: string, label: string}
	 */
	public static function order_target( int $id, string $number ): array {
		return StoreTargets::order( $id, $number );
	}

	/**
	 * The kind of an order note, in this order of precedence:
	 *
	 * - `customer`: the note is meant for the customer, whoever wrote it.
	 * - `status`: the note is nothing but WooCommerce's sentence about a status
	 *   change. It says what the status change event already says, and it is
	 *   the same sentence whether the shop or an admin changing the status by
	 *   hand has it filed under their name.
	 * - `system`: the note was filed under the shop's own name and address.
	 *   That is what WooCommerce notes about payments, stock and errors.
	 * - `private`: any other note: one a person wrote, or another plugin.
	 *
	 * @param bool $is_customer_note Whether the note was meant for the customer.
	 * @param bool $is_status_note   Whether the note is exactly a status sentence.
	 * @param bool $is_system_note   Whether the note was filed under the shop's own name and address.
	 * @return string `customer`, `status`, `system` or `private`.
	 */
	public static function note_type( bool $is_customer_note, bool $is_status_note, bool $is_system_note ): string {
		if ( $is_customer_note ) {
			return 'customer';
		}

		if ( $is_status_note ) {
			return 'status';
		}

		return $is_system_note ? 'system' : 'private';
	}

	/**
	 * Whether a note is exactly one of the sentences WooCommerce writes for a
	 * status change, and nothing else.
	 *
	 * A placeholder of a format stands for the name of one of the shop's
	 * statuses, so words of a person after the sentence ("... to Processing.
	 * Refund promised.") are not part of it, and a status name with a dot in
	 * it still is. Text of a person in front of the sentence (WooCommerce puts
	 * the text it was given before the sentence) makes it more than the
	 * sentence too.
	 *
	 * @param string             $content  Text of the note as stored.
	 * @param array<int, string> $formats  `sprintf` formats of the sentences, as translated for the site.
	 * @param array<int, string> $statuses Names of the statuses the shop has. With none given a name is
	 *                                     any text up to the next dot.
	 */
	public static function is_status_note( string $content, array $formats, array $statuses = [] ): bool {
		$text = trim( $content );
		if ( '' === $text || strlen( $text ) > self::MAX_STATUS_NOTE_LENGTH ) {
			return false;
		}

		$name = self::status_name_pattern( $statuses );
		foreach ( $formats as $format ) {
			$pattern = self::status_pattern( (string) $format, $name );
			if ( null !== $pattern && 1 === preg_match( $pattern, $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a note was filed under the shop's own name and address.
	 *
	 * Both must be exactly what WooCommerce files its own notes under: the
	 * address alone is not enough, a person can have any address. A shop that
	 * has no address of its own files no note as its own.
	 *
	 * @param string $author        Name the note was written under.
	 * @param string $author_email  Address the note was written under.
	 * @param string $system_author Name WooCommerce files its own notes under.
	 * @param string $system_email  Address WooCommerce files its own notes under.
	 */
	public static function is_system_note( string $author, string $author_email, string $system_author, string $system_email ): bool {
		return '' !== $system_author && '' !== $system_email && $author === $system_author && $author_email === $system_email;
	}

	/**
	 * The text of an order note as sent: without markup, character entities
	 * decoded, secrets masked, and at most 500 characters, the ellipsis
	 * included. Masking comes before the cut, so the cut cannot leave a piece
	 * of a secret that no longer looks like one.
	 *
	 * @param string $content Text of the note as stored.
	 */
	public static function note_text( string $content ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- only reached when core's helper is not loaded.
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $content ) : strip_tags( $content );
		$text = ChangeSet::redact_text( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		// Trailing blanks are only tried from the start of a blank run and
		// taken whole (possessive), so a long run inside the text is read
		// once, not once from every position in it: linear even without JIT.
		$text = (string) preg_replace( '/^[\p{Z}\s]++|(?<![\p{Z}\s])[\p{Z}\s]++$/u', '', $text );

		if ( '' === $text ) {
			return self::EMPTY_NOTE;
		}

		if ( mb_strlen( $text, 'UTF-8' ) <= self::MAX_NOTE_LENGTH ) {
			return $text;
		}

		return mb_substr( $text, 0, self::MAX_NOTE_LENGTH - 1, 'UTF-8' ) . self::ELLIPSIS;
	}

	/**
	 * The expression that matches a status name: one of the given names, as
	 * written; any text up to the next dot when there are none.
	 *
	 * @param array<int, string> $statuses Names of the statuses the shop has.
	 */
	private static function status_name_pattern( array $statuses ): string {
		$names = array_unique( array_filter( array_map( 'strval', $statuses ), static fn( string $name ): bool => '' !== $name ) );
		if ( [] === $names ) {
			return '[^.]+?';
		}

		// The longest first, so a name never loses to one that is its beginning.
		usort( $names, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		return '(?:' . implode( '|', array_map( static fn( string $name ): string => preg_quote( $name, '/' ), $names ) ) . ')';
	}

	/**
	 * The expression that matches a note that is exactly the sentence of a
	 * `sprintf` format, a status name in place of each placeholder; null when
	 * the format has no text of its own to tell the sentence by.
	 *
	 * @param string $format `sprintf` format of the sentence.
	 * @param string $name   Expression matching a status name, see {@see self::status_name_pattern()}.
	 */
	private static function status_pattern( string $format, string $name ): ?string {
		$parts = preg_split( '/(%(?:\d+\$)?s)/', $format, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return null;
		}

		$body    = '';
		$literal = '';
		foreach ( $parts as $index => $part ) {
			if ( 1 === $index % 2 ) {
				$body .= $name;
				continue;
			}
			$literal .= $part;
			$body    .= preg_quote( $part, '/' );
		}

		return '' === trim( $literal ) ? null : '/^' . $body . '$/uD';
	}

	/**
	 * A new order. WooCommerce announces some orders before their totals are
	 * calculated: the REST API, and `wc_create_order()` (which renewals,
	 * point-of-sale and import tools use) save the order, announce it, and
	 * only then add its items and calculate its totals. So the creation is
	 * held until the request (or the Action Scheduler job that created it)
	 * ends, and told then with what the order holds at that point, see
	 * {@see self::flush_new_orders()}. What the order holds now is kept for
	 * an order that can no longer be loaded then. Beyond
	 * {@see self::MAX_HELD_ORDERS} held orders the oldest is told at once,
	 * with what it holds then, so a long run never holds thousands of them.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $order    Order object the hook passed, if any.
	 */
	public function on_created( $order_id, $order = null ): void {
		try {
			$order_id = self::id_of( $order_id );
			$fields   = $this->creation_fields( $order_id, $this->order_object( $order_id, $order ) );
			if ( $order_id <= 0 ) {
				// Nothing to load again later.
				$this->emit( 9100, $fields );
				return;
			}

			// An order announced again is the newest.
			$key = self::state_key( $order_id );
			unset( $this->created[ $key ] );
			$this->created[ $key ] = [
				'blog'     => self::blog_id(),
				'depth'    => ActionSchedulerContext::depth(),
				'order_id' => $order_id,
				'fields'   => $fields,
			];

			$over = count( $this->created ) - self::MAX_HELD_ORDERS;
			foreach ( array_slice( $this->created, 0, max( 0, $over ), true ) as $oldest => $held ) {
				unset( $this->created[ $oldest ] );
				$this->tell_held( $held );
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9100, [ 'order_id' => self::id_of( $order_id ) ] );
		}
	}

	/**
	 * Tell the new orders still held when the request ends, each on the site
	 * it was created on. Runs at shutdown before the request's events are
	 * written, and again for orders a later shutdown callback created.
	 */
	public function flush_new_orders(): void {
		$this->tell_created( 0 );
	}

	/**
	 * Tell the new orders the Action Scheduler job that is ending created,
	 * while the job is still the context, and forget the items it added and
	 * the orders whose items it rebuilt: a later job of the same run that
	 * changes or removes one logs it. Nothing outside a job.
	 */
	public function flush_job_orders(): void {
		$depth = ActionSchedulerContext::depth();
		if ( $depth > 0 ) {
			$this->tell_created( $depth );

			$earlier           = static fn( int $at ): bool => $at < $depth;
			$this->rebuilt     = array_filter( $this->rebuilt, $earlier );
			$this->added_items = array_filter( $this->added_items, $earlier );
		}
	}

	/**
	 * An existing order is about to be saved: tell which of its fields
	 * change, from the values it has stored (`get_data()`) and those the save
	 * is about to write (`get_changes()`). An order is whatever can be asked
	 * for those; WooCommerce fires this hook for orders only (a refund has a
	 * hook of its own).
	 *
	 * An order without an id is being inserted, and one whose stored status
	 * is one of {@see self::CREATION_STATUSES} is still being created: its
	 * fields are filled in, not edited, so neither is an edit. A save that
	 * writes none of {@see self::ORDER_FIELDS} (every save moves the
	 * modification date; a checkout or a bulk action saves an order many
	 * times) returns at once, without reading the stored order, whatever its
	 * status; one that writes them only in another form is not dispatched
	 * either.
	 *
	 * @param mixed $order The order WooCommerce is about to save.
	 */
	public function on_before_save( $order ): void {
		$order_id = 0;
		try {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) || ! method_exists( $order, 'get_data' ) || ! method_exists( $order, 'get_changes' ) ) {
				return;
			}
			$order_id = (int) $order->get_id();
			if ( $order_id <= 0 ) {
				return;
			}

			$pending = self::order_values( $order->get_changes() );
			if ( [] === $pending ) {
				return;
			}
			$data = $order->get_data();
			$data = is_array( $data ) ? $data : [];
			if ( self::is_being_created( $data ) ) {
				return;
			}
			$stored  = self::order_values( $data );
			$changes = WcObjectDiff::changes( $stored, self::real_changes( $stored, $pending ), self::ORDER_FIELDS, self::CONTENT_FIELDS );
			if ( [] === $changes ) {
				return;
			}

			$this->emit(
				9105,
				array_merge(
					[
						'id'       => $order_id,
						'changes'  => $changes,
						'metadata' => [ 'order_id' => $order_id ],
					],
					$this->order_fields( $order_id, $order )
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9105, [ 'order_id' => $order_id ] );
		}
	}

	/**
	 * The status changed: it is told as a change too. A status change out of
	 * the trash is also the restore of the order, whichever way it came (a
	 * status update through the REST API fires no restore hook); the rules
	 * keep only the restore.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $old      Status before, without the `wc-` prefix.
	 * @param mixed $new      Status after, without the `wc-` prefix.
	 * @param mixed $order    The order.
	 */
	public function on_status_changed( $order_id, $old, $new, $order = null ): void {
		try {
			$order_id = self::id_of( $order_id );
			$old      = self::text_of( $old );
			$new      = self::text_of( $new );
			$fields   = $this->order_fields( $order_id, $order );

			$this->emit(
				9101,
				array_merge(
					[
						'id'       => $order_id,
						'old'      => $old,
						'new'      => $new,
						'changes'  => [
							[
								'field' => 'status',
								'old'   => $old,
								'new'   => $new,
							],
						],
						'metadata' => [
							'order_id'   => $order_id,
							'old_status' => $old,
							'new_status' => $new,
						],
					],
					$fields
				)
			);

			if ( 'trash' === self::status_slug( $old ) ) {
				$this->emit_restored( $order_id, $new, $fields );
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9101, [ 'order_id' => self::id_of( $order_id ) ] );
		}
	}

	/**
	 * WooCommerce moved an order to the trash.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function on_trashed( $order_id ): void {
		try {
			$order_id = self::id_of( $order_id );
			$this->emit(
				9109,
				array_merge(
					[
						'id'       => $order_id,
						'metadata' => [ 'order_id' => $order_id ],
					],
					$this->order_fields( $order_id, null )
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9109, [ 'order_id' => self::id_of( $order_id ) ] );
		}
	}

	/**
	 * WooCommerce is restoring an order from the trash.
	 *
	 * @param mixed $order_id        Order id.
	 * @param mixed $previous_status Status the order had before the trash, as stored (`wc-processing`).
	 */
	public function on_untrashed( $order_id, $previous_status = '' ): void {
		try {
			$order_id = self::id_of( $order_id );
			$this->emit_restored( $order_id, self::text_of( $previous_status ), $this->order_fields( $order_id, null ) );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9110, [ 'order_id' => self::id_of( $order_id ) ] );
		}
	}

	/**
	 * WordPress restored a post from the trash: for an order stored as a post
	 * (the classic order screen restores it this way, firing no hook of
	 * WooCommerce) it is the restore of the order.
	 *
	 * @param mixed $post_id         Post id.
	 * @param mixed $previous_status Status the post had before the trash (`wc-processing`).
	 */
	public function on_post_untrashed( $post_id, $previous_status = '' ): void {
		try {
			$post_id = self::id_of( $post_id );
			if ( $post_id <= 0 || ! function_exists( 'get_post_type' ) || 'shop_order' !== get_post_type( $post_id ) ) {
				return;
			}
			$this->emit_restored( $post_id, self::text_of( $previous_status ), $this->order_fields( $post_id, null ) );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9110, [ 'order_id' => self::id_of( $post_id ) ] );
		}
	}

	/**
	 * An order is about to be deleted for good: read its number while it can
	 * still be loaded, for the delete event that follows.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $order    The order WooCommerce is about to delete.
	 */
	public function on_before_delete( $order_id, $order = null ): void {
		try {
			$order_id = self::id_of( $order_id );
			$number   = is_object( $order ) && method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : '';
			if ( '' !== $number ) {
				$this->deleting[ self::state_key( $order_id ) ] = $number;
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9102, [ 'order_id' => self::id_of( $order_id ) ] );
		}
	}

	/**
	 * An order was deleted for good. It can no longer be loaded: it is named
	 * by the number read just before the delete, else by its id, and there is
	 * nothing left to open.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function on_deleted( $order_id ): void {
		try {
			$order_id = self::id_of( $order_id );
			$key      = self::state_key( $order_id );
			$number   = $this->deleting[ $key ] ?? '';
			unset( $this->deleting[ $key ] );

			$this->emit(
				9102,
				array_merge(
					[
						'id'       => $order_id,
						'metadata' => [ 'order_id' => $order_id ],
					],
					$order_id > 0 ? [ 'target' => self::order_target( $order_id, $number ) ] : []
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9102, [ 'order_id' => $order_id ] );
		}
	}

	/**
	 * @param mixed $note_id Comment id of the note.
	 * @param mixed $order   The order.
	 */
	public function on_note_added( $note_id, $order = null ): void {
		try {
			$note_id  = self::id_of( $note_id );
			$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
			$note     = function_exists( 'get_comment' ) ? get_comment( $note_id ) : null;
			$content  = is_object( $note ) ? (string) ( $note->comment_content ?? '' ) : '';
			$author   = is_object( $note ) ? (string) ( $note->comment_author ?? '' ) : '';
			$email    = is_object( $note ) ? (string) ( $note->comment_author_email ?? '' ) : '';
			$type     = self::note_type(
				$this->is_customer_note( $note_id ),
				self::is_status_note( $content, $this->status_formats(), $this->status_names() ),
				self::is_system_note( $author, $email, $this->system_author(), $this->system_email() )
			);

			$this->emit(
				9103,
				array_merge(
					[
						'id'       => $order_id,
						'vars'     => [ 'note' => self::note_text( $content ) ],
						'metadata' => [
							'order_id'  => $order_id,
							'note_id'   => $note_id,
							'note_type' => $type,
						],
					],
					$this->order_fields( $order_id, $order )
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9103, [ 'note_id' => self::id_of( $note_id ) ] );
		}
	}

	/**
	 * A refund was issued: its amount as stored (the message's), its
	 * currency and reason (`vars`, a reason only when one was given) and
	 * whether the gateway returned the money (`metadata.refunded_payment`;
	 * false when the shop only recorded the refund). What the refund cannot
	 * tell is left out.
	 *
	 * @param mixed $order_id  Id of the refunded order.
	 * @param mixed $refund_id Id of the refund.
	 */
	public function on_refunded( $order_id, $refund_id ): void {
		try {
			$order_id  = self::id_of( $order_id );
			$refund_id = self::id_of( $refund_id );
			$refund    = $this->order_object( $refund_id, null );
			$amount    = null !== $refund && method_exists( $refund, 'get_amount' ) ? (string) $refund->get_amount() : '';
			$vars      = array_filter(
				[
					'currency' => self::read( $refund, 'get_currency' ),
					'reason'   => self::read( $refund, 'get_reason' ),
				],
				'is_string'
			);
			$metadata  = [
				'order_id'  => $order_id,
				'refund_id' => $refund_id,
				'amount'    => $amount,
			];
			if ( null !== $refund && method_exists( $refund, 'get_refunded_payment' ) ) {
				$metadata['refunded_payment'] = (bool) $refund->get_refunded_payment();
			}

			$this->emit(
				9104,
				array_merge(
					[
						'id'       => $order_id,
						'amount'   => $amount,
						'vars'     => $vars,
						'metadata' => $metadata,
					],
					$this->order_fields( $order_id, null )
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9104, [ 'order_id' => self::id_of( $order_id ) ] );
		}
	}

	/**
	 * An item was added to an order: told on the order with its name and
	 * type, and the quantity of a product line. The hook also fires for the
	 * copies a refund makes of the refunded items and for the items of an
	 * order still being created; neither is told, see
	 * {@see self::item_order_fields()}. Nor are the items of an order rebuilt
	 * from the cart in this request, see {@see self::on_order_resumed()}.
	 * Told or not, the item counts as added in this request.
	 *
	 * @param mixed $item_id  Item id.
	 * @param mixed $item     The item; `wc_add_order_item()` may pass none, then it is read by its id.
	 * @param mixed $order_id Id of the order; the item's own when the hook passes none.
	 */
	public function on_item_added( $item_id, $item = null, $order_id = 0 ): void {
		$ids = [
			'order_id' => 0,
			'item_id'  => 0,
		];
		try {
			$ids['item_id'] = self::id_of( $item_id );
			if ( $ids['item_id'] <= 0 ) {
				return;
			}
			$this->added_items[ self::state_key( $ids['item_id'] ) ] = ActionSchedulerContext::depth();

			$item = is_object( $item ) ? $item : self::stored_item( $ids['item_id'] );
			if ( null === $item ) {
				return;
			}
			$ids['order_id'] = self::id_of( $order_id );
			if ( $ids['order_id'] <= 0 ) {
				$ids['order_id'] = self::item_order_id( $item );
			}
			if ( $this->is_rebuilt( $ids['order_id'] ) ) {
				return;
			}

			$fields = $this->item_order_fields( $ids['order_id'], $item );
			if ( null === $fields ) {
				return;
			}

			$type = self::item_type( $item );
			$vars = [ 'name' => self::item_name( $item ) ];
			$qty  = self::LINE_ITEM === $type && method_exists( $item, 'get_quantity' ) ? self::number_text( $item->get_quantity() ) : null;
			if ( null !== $qty ) {
				$vars['qty'] = $qty;
			}

			$this->emit(
				9106,
				array_merge(
					[
						'id'       => $ids['order_id'],
						'vars'     => $vars,
						'metadata' => self::item_metadata( $ids['order_id'], $ids['item_id'], $type ),
					],
					$fields
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9106, $ids );
		}
	}

	/**
	 * An existing item of an order is about to be saved: tell which of
	 * {@see self::ITEM_FIELDS} change, from the values it has stored
	 * (`get_data()`) and those the save is about to write (`get_changes()`),
	 * one event per item. Every save of an order saves its items again,
	 * mostly without a change: such a save reads nothing more and tells
	 * nothing. An item without an id is being inserted, and one added in
	 * this request is still being added (the order's taxes and totals are
	 * recalculated after it): its addition tells it.
	 *
	 * @param mixed $item The item WooCommerce is about to save.
	 */
	public function on_item_saving( $item ): void {
		$ids = [
			'order_id' => 0,
			'item_id'  => 0,
		];
		try {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_id' ) || ! method_exists( $item, 'get_data' ) || ! method_exists( $item, 'get_changes' ) ) {
				return;
			}
			$ids['item_id'] = self::id_of( $item->get_id() );
			if ( $ids['item_id'] <= 0 || isset( $this->added_items[ self::state_key( $ids['item_id'] ) ] ) ) {
				return;
			}
			$ids['order_id'] = self::item_order_id( $item );

			$pending = self::item_values( $item->get_changes() );
			if ( [] === $pending ) {
				return;
			}
			$changes = self::item_changes( self::item_values( $item->get_data() ), $pending );
			if ( [] === $changes ) {
				return;
			}

			$fields = $this->item_order_fields( $ids['order_id'], $item );
			if ( null === $fields ) {
				return;
			}

			$this->emit(
				9108,
				array_merge(
					[
						'id'       => $ids['order_id'],
						'changes'  => $changes,
						'vars'     => [ 'name' => self::item_name( $item ) ],
						'metadata' => self::item_metadata( $ids['order_id'], $ids['item_id'], self::item_type( $item ) ),
					],
					$fields
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9108, $ids );
		}
	}

	/**
	 * An item of an order is about to be deleted: read while it still can
	 * be, and told on its order with its name. An item that cannot be read
	 * has no order to tell it on; the items of an order rebuilt from the
	 * cart in this request are not told.
	 *
	 * @param mixed $item_id Item id.
	 */
	public function on_item_deleting( $item_id ): void {
		$ids = [
			'order_id' => 0,
			'item_id'  => 0,
		];
		try {
			$ids['item_id'] = self::id_of( $item_id );
			if ( $ids['item_id'] <= 0 ) {
				return;
			}
			$item = self::stored_item( $ids['item_id'] );
			if ( null === $item ) {
				return;
			}
			$ids['order_id'] = self::item_order_id( $item );
			if ( $this->is_rebuilt( $ids['order_id'] ) ) {
				return;
			}

			$fields = $this->item_order_fields( $ids['order_id'], $item );
			if ( null === $fields ) {
				return;
			}

			$this->emit(
				9107,
				array_merge(
					[
						'id'       => $ids['order_id'],
						'vars'     => [ 'name' => self::item_name( $item ) ],
						'metadata' => self::item_metadata( $ids['order_id'], $ids['item_id'], self::item_type( $item ) ),
					],
					$fields
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9107, $ids );
		}
	}

	/**
	 * A note of an order was deleted. WooCommerce read it before the delete:
	 * it is told on its order with its text (see {@see self::note_text()}).
	 * A note deleted with the customer's personal data (WooCommerce deletes
	 * every note of the order then, as notes can hold it) is tagged as such
	 * in `metadata.context` and keeps its text to itself. A note of no order
	 * is not told; one whose text is unknown is told without.
	 *
	 * @param mixed $note_id Comment id of the note; text when it comes from the REST API.
	 * @param mixed $note    The note as WooCommerce read it (`id`, `content`, `order_id`, …).
	 */
	public function on_note_deleted( $note_id, $note = null ): void {
		$ids = [
			'order_id' => 0,
			'note_id'  => 0,
		];
		try {
			$ids['note_id']  = self::id_of( $note_id );
			$ids['order_id'] = is_object( $note ) ? self::id_of( $note->order_id ?? null ) : 0;
			if ( $ids['order_id'] <= 0 ) {
				return;
			}

			$erased  = isset( $this->erasing[ self::state_key( $ids['order_id'] ) ] );
			$content = isset( $note->content ) && is_scalar( $note->content ) ? (string) $note->content : null;

			$this->emit(
				9111,
				array_merge(
					[
						'id'       => $ids['order_id'],
						'vars'     => null === $content || $erased ? [] : [ 'note' => self::note_text( $content ) ],
						'metadata' => $ids + ( $erased ? [ 'context' => self::PRIVACY_ERASURE ] : [] ),
					],
					$this->order_fields( $ids['order_id'], null )
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9111, $ids );
		}
	}

	/**
	 * WooCommerce is about to erase the personal data of an order's customer
	 * (asked by the customer, from the order list, or for old orders on a
	 * schedule): the notes it deletes until the erasure ends go with it.
	 *
	 * @param mixed $order The order.
	 */
	public function on_privacy_erasing( $order ): void {
		try {
			$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? self::id_of( $order->get_id() ) : 0;
			if ( $order_id > 0 ) {
				$this->erasing[ self::state_key( $order_id ) ] = true;
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9111 );
		}
	}

	/**
	 * WooCommerce erased the personal data of an order's customer.
	 *
	 * @param mixed $order The order.
	 */
	public function on_privacy_erased( $order ): void {
		try {
			$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? self::id_of( $order->get_id() ) : 0;
			if ( $order_id > 0 ) {
				unset( $this->erasing[ self::state_key( $order_id ) ] );
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9111 );
		}
	}

	/**
	 * The checkout resumes an order still waiting for its payment (a retry
	 * after a failed payment, with the same cart): it removes all of the
	 * order's items at once, with no hook per item, and adds them again from
	 * the cart. Nothing changed, so no item of that order is told for the
	 * rest of the request.
	 *
	 * @param mixed $order_id Id of the order resumed.
	 */
	public function on_order_resumed( $order_id ): void {
		try {
			$this->note_rebuilt( self::id_of( $order_id ) );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9106, [ 'order_id' => self::id_of( $order_id ) ] );
		}
	}

	/**
	 * All of an order's items (or all of one type) are about to be removed at
	 * once, with no hook per item: WooCommerce does that only to add them
	 * again from the cart (the resumed checkout, the Store API's draft, a
	 * recomputation of the fees), so the order is rebuilt as with
	 * {@see self::on_order_resumed()}. An order not saved yet has none.
	 *
	 * @param mixed $order The order.
	 * @param mixed $type  Item type removed; null for all. Not used.
	 */
	public function on_items_removing( $order, $type = null ): void {
		$order_id = 0;
		try {
			$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? self::id_of( $order->get_id() ) : 0;
			$this->note_rebuilt( $order_id );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9106, $order_id > 0 ? [ 'order_id' => $order_id ] : [] );
		}
	}

	/**
	 * Note the items of an order as rebuilt in this request.
	 *
	 * @param int $order_id Order id; nothing is noted for 0.
	 */
	private function note_rebuilt( int $order_id ): void {
		if ( $order_id > 0 ) {
			$this->rebuilt[ self::state_key( $order_id ) ] = ActionSchedulerContext::depth();
		}
	}

	/**
	 * Whether the items of an order are rebuilt from the cart in this
	 * request.
	 *
	 * @param int $order_id Order id.
	 */
	private function is_rebuilt( int $order_id ): bool {
		return $order_id > 0 && isset( $this->rebuilt[ self::state_key( $order_id ) ] );
	}

	/**
	 * The fields of a new order's event: its total and currency go into
	 * `vars`, how it was placed (`created_via`) and paid into `metadata`,
	 * with its target and link (see {@see self::order_fields()}).
	 *
	 * @param int         $order_id Order id.
	 * @param object|null $order    The order, if it could be loaded.
	 * @return array<string, mixed>
	 */
	private function creation_fields( int $order_id, ?object $order ): array {
		$vars     = array_filter(
			[
				'total'    => self::read( $order, 'get_total' ),
				'currency' => self::read( $order, 'get_currency' ),
			],
			'is_string'
		);
		$metadata = array_filter(
			[
				'created_via'    => self::read( $order, 'get_created_via' ),
				'payment_method' => self::read( $order, 'get_payment_method' ),
			],
			'is_string'
		);

		return array_merge(
			[
				'id'       => $order_id,
				'vars'     => $vars,
				'metadata' => [ 'order_id' => $order_id ] + $metadata,
			],
			$this->order_fields( $order_id, $order )
		);
	}

	/**
	 * Tell the new orders held from at least `$depth` running jobs on (0: all
	 * of them), each on its own site, and forget them; the rest stays held.
	 *
	 * @param int $depth Jobs running when the order was created, at least.
	 */
	private function tell_created( int $depth ): void {
		foreach ( $this->created as $key => $held ) {
			if ( $held['depth'] < $depth ) {
				continue;
			}
			unset( $this->created[ $key ] );
			$this->tell_held( $held );
		}
	}

	/**
	 * Tell one held new order on its site, read as it is now (see
	 * {@see self::final_creation_fields()}); a failure is reported, never
	 * thrown.
	 *
	 * @param array{blog: int, depth: int, order_id: int, fields: array<string, mixed>} $held The held order.
	 */
	private function tell_held( array $held ): void {
		try {
			$this->on_site( $held['blog'], fn() => $this->emit( 9100, $this->final_creation_fields( $held['order_id'], $held['fields'] ) ) );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9100, [ 'order_id' => $held['order_id'] ] );
		}
	}

	/**
	 * The fields of a new order's event read from the order as it is now. An
	 * order that can no longer be loaded is told as it was announced, with
	 * nothing left to open; one that fails to be read, as it was announced.
	 *
	 * @param int                  $order_id Order id.
	 * @param array<string, mixed> $captured The fields read when the order was announced.
	 * @return array<string, mixed>
	 */
	private function final_creation_fields( int $order_id, array $captured ): array {
		try {
			$order = $this->order_object( $order_id, null );
			if ( null === $order ) {
				unset( $captured['links'] );
				return $captured;
			}

			return $this->creation_fields( $order_id, $order );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9100, [ 'order_id' => $order_id ] );
			return $captured;
		}
	}

	/**
	 * Run something on a site of the network, and come back.
	 *
	 * @param int      $blog_id Site id; 0 for the current one.
	 * @param callable $work    What to run.
	 */
	private function on_site( int $blog_id, callable $work ): void {
		$switch = $blog_id > 0 && self::blog_id() !== $blog_id && function_exists( 'switch_to_blog' );
		if ( $switch ) {
			switch_to_blog( $blog_id );
		}

		try {
			$work();
		} finally {
			if ( $switch ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Dispatch the restore of an order from the trash.
	 *
	 * @param int                  $order_id        Order id.
	 * @param string               $previous_status Status the order goes back to, as stored or as a slug.
	 * @param array<string, mixed> $fields          Target and links of the order, see {@see self::order_fields()}.
	 */
	private function emit_restored( int $order_id, string $previous_status, array $fields ): void {
		$metadata = [ 'order_id' => $order_id ];
		$status   = self::status_slug( $previous_status );
		if ( '' !== $status ) {
			$metadata['previous_status'] = $status;
		}

		$this->emit(
			9110,
			array_merge(
				[
					'id'       => $order_id,
					'metadata' => $metadata,
				],
				$fields
			)
		);
	}

	/**
	 * The order the hook passed or, failing that, the one WooCommerce finds
	 * for the id; null when there is none.
	 *
	 * @param int   $order_id Order id.
	 * @param mixed $order    Order object the hook passed, if any.
	 */
	private function order_object( int $order_id, $order ): ?object {
		if ( is_object( $order ) ) {
			return $order;
		}
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$found = wc_get_order( $order_id );

		return is_object( $found ) ? $found : null;
	}

	/**
	 * The `target` and `links` of an order event.
	 *
	 * Without an order id there is nothing to name. The order is the one the
	 * hook passed or, failing that, the one WooCommerce finds for the id; if
	 * there is none, the target is labelled with the id and has no link.
	 *
	 * @param int   $order_id Order id.
	 * @param mixed $order    Order object the hook passed, if any.
	 * @return array<string, mixed>
	 */
	private function order_fields( int $order_id, $order ): array {
		if ( $order_id <= 0 ) {
			return [];
		}

		$order  = $this->order_object( $order_id, $order );
		$number = null !== $order && method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : '';
		$fields = [ 'target' => self::order_target( $order_id, $number ) ];

		if ( null !== $order && method_exists( $order, 'get_edit_order_url' ) ) {
			$edit = $order->get_edit_order_url();
			if ( is_string( $edit ) && '' !== $edit ) {
				$fields['links'] = [ 'edit' => $edit ];
			}
		}

		return $fields;
	}

	/**
	 * What a getter of the order answers, as text; null when the order or the
	 * getter is missing, or the answer is empty or no scalar.
	 *
	 * @param object|null $order  The order.
	 * @param string      $method Getter name.
	 */
	private static function read( ?object $order, string $method ): ?string {
		if ( null === $order || ! method_exists( $order, $method ) ) {
			return null;
		}

		$value = $order->$method();

		return is_scalar( $value ) && '' !== (string) $value ? (string) $value : null;
	}

	/**
	 * The `target` and `links` an event about an item of the order is told
	 * with, see {@see self::order_fields()}; null when the items of that
	 * order are not told: there is no order (no id), it is a refund (the
	 * refund event tells what it holds), or it is still being created (its
	 * items are part of making it, whichever request adds them, see
	 * {@see self::CREATION_STATUSES}). An order that cannot be loaded is
	 * asked for its type by its id, and its items are told.
	 *
	 * @param int         $order_id Id of the item's order.
	 * @param object|null $item     The item, which may hold on to its order.
	 * @return array<string, mixed>|null
	 */
	private function item_order_fields( int $order_id, ?object $item ): ?array {
		if ( $order_id <= 0 ) {
			return null;
		}

		$order = self::held_order( $item, $order_id ) ?? $this->order_object( $order_id, null );
		if ( self::REFUND_TYPE === self::order_type( $order_id, $order ) || self::is_in_creation( $order ) ) {
			return null;
		}

		return $this->order_fields( $order_id, $order );
	}

	/**
	 * The order an item holds on to (WooCommerce's items keep the order they
	 * were read or added with, so a save of the order does not load it again
	 * for every item), when it is the order of that id; null otherwise.
	 *
	 * @param object|null $item     The item.
	 * @param int         $order_id Id of the item's order.
	 */
	private static function held_order( ?object $item, int $order_id ): ?object {
		if ( null === $item || ! method_exists( $item, 'get_order' ) ) {
			return null;
		}

		$order = $item->get_order();

		return is_object( $order ) && method_exists( $order, 'get_id' ) && self::id_of( $order->get_id() ) === $order_id ? $order : null;
	}

	/**
	 * The type of an order (`shop_order`, `shop_order_refund`): asked of the
	 * order, else of WooCommerce by the id; '' when neither tells.
	 *
	 * @param int         $order_id Order id.
	 * @param object|null $order    The order, if it could be loaded.
	 */
	private static function order_type( int $order_id, ?object $order ): string {
		if ( null !== $order && method_exists( $order, 'get_type' ) ) {
			$type = $order->get_type();
		} elseif ( class_exists( OrderUtil::class ) && method_exists( OrderUtil::class, 'get_order_type' ) ) {
			$type = OrderUtil::get_order_type( $order_id );
		} else {
			$type = '';
		}

		return is_string( $type ) ? $type : '';
	}

	/**
	 * Whether an order is still being created, by its status (see
	 * {@see self::CREATION_STATUSES}). Like every getter of the shop, the
	 * status read is the one the order holds: a status set but not saved yet
	 * counts. An order loaded for the item holds what is stored; one an item
	 * holds on to is the one being worked on, saved before its items are. An
	 * order that cannot tell is not being created.
	 *
	 * @param object|null $order The order.
	 */
	private static function is_in_creation( ?object $order ): bool {
		if ( null === $order || ! method_exists( $order, 'get_status' ) ) {
			return false;
		}

		// `edit` skips the filters and the default an unset status reads for
		// display (`pending`); it still reads a pending change.
		$status = $order->get_status( 'edit' );

		return ( null === $status || is_string( $status ) ) && in_array( self::status_slug( (string) $status ), self::CREATION_STATUSES, true );
	}

	/**
	 * The order item WooCommerce finds for an id; null when there is none.
	 *
	 * @param int $item_id Item id.
	 */
	private static function stored_item( int $item_id ): ?object {
		if ( ! class_exists( 'WC_Order_Factory' ) || ! method_exists( 'WC_Order_Factory', 'get_order_item' ) ) {
			return null;
		}

		$item = \WC_Order_Factory::get_order_item( $item_id );

		return is_object( $item ) ? $item : null;
	}

	/**
	 * The id of the order an item belongs to; 0 when it does not tell.
	 *
	 * @param object $item The item.
	 */
	private static function item_order_id( object $item ): int {
		return method_exists( $item, 'get_order_id' ) ? self::id_of( $item->get_order_id() ) : 0;
	}

	/**
	 * The name of an item (a tax item's is the code of its rate); '' when it
	 * has none to tell.
	 *
	 * @param object $item The item.
	 */
	private static function item_name( object $item ): string {
		$name = method_exists( $item, 'get_name' ) ? $item->get_name() : '';

		return is_scalar( $name ) ? (string) $name : '';
	}

	/**
	 * The type of an item (`line_item`, `fee`, `shipping`, `coupon`, `tax`);
	 * '' when it does not tell.
	 *
	 * @param object $item The item.
	 */
	private static function item_type( object $item ): string {
		$type = method_exists( $item, 'get_type' ) ? $item->get_type() : '';

		return is_string( $type ) ? $type : '';
	}

	/**
	 * The `metadata` of an item event.
	 *
	 * @param int    $order_id Id of the item's order.
	 * @param int    $item_id  Item id.
	 * @param string $type     Type of the item.
	 * @return array{order_id: int, item_id: int, item_type: string}
	 */
	private static function item_metadata( int $order_id, int $item_id, string $type ): array {
		return [
			'order_id'  => $order_id,
			'item_id'   => $item_id,
			'item_type' => $type,
		];
	}

	/**
	 * The values of an item (stored or pending) that hold one of
	 * {@see self::ITEM_FIELDS}: its taxes by rate and its meta are never
	 * read.
	 *
	 * @param mixed $values Values of the item by key.
	 * @return array<string, mixed>
	 */
	private static function item_values( $values ): array {
		return is_array( $values ) ? array_intersect_key( $values, array_flip( self::ITEM_FIELDS ) ) : [];
	}

	/**
	 * The changes between an item's stored and pending values, in the order
	 * of {@see self::ITEM_FIELDS}, without those that only write the stored
	 * value in another form (`10` for `10.00`, `0` for an empty amount, an
	 * empty name where there was none).
	 *
	 * @param array<string, mixed> $stored  Stored values.
	 * @param array<string, mixed> $pending Values the save is about to write.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	private static function item_changes( array $stored, array $pending ): array {
		$real = [];
		foreach ( $pending as $key => $value ) {
			$old = $stored[ $key ] ?? null;
			if ( self::is_blank( $old ) && self::is_blank( $value ) ) {
				continue;
			}
			if ( in_array( $key, self::ITEM_NUMBER_FIELDS, true ) && self::same_number( $old, $value ) ) {
				continue;
			}

			$real[ $key ] = $value;
		}

		return [] === $real ? [] : WcObjectDiff::changes( $stored, $real, self::ITEM_FIELDS );
	}

	/**
	 * Whether two values are the same number, an empty one being 0.
	 *
	 * @param mixed $a A value.
	 * @param mixed $b Another value.
	 */
	private static function same_number( $a, $b ): bool {
		$a = self::is_blank( $a ) ? 0 : $a;
		$b = self::is_blank( $b ) ? 0 : $b;

		return is_numeric( $a ) && is_numeric( $b ) && (float) $a === (float) $b;
	}

	/**
	 * Whether a value is none: null or an empty string.
	 *
	 * @param mixed $value Any value.
	 */
	private static function is_blank( $value ): bool {
		return null === $value || '' === $value;
	}

	/**
	 * A number as text, without trailing zeros (`2` for `2.0`); null for
	 * anything that is no number.
	 *
	 * @param mixed $value Any value.
	 */
	private static function number_text( $value ): ?string {
		return is_numeric( $value ) ? (string) ( $value + 0 ) : null;
	}

	/**
	 * The values of an order (stored or pending) that hold one of
	 * {@see self::ORDER_FIELDS}: only those are read, so the items an order
	 * keeps in its data are never turned into text.
	 *
	 * @param mixed $values Values of the order by key.
	 * @return array<string, mixed>
	 */
	private static function order_values( $values ): array {
		if ( ! is_array( $values ) ) {
			return [];
		}

		$roots = [];
		foreach ( self::ORDER_FIELDS as $field ) {
			$roots[ explode( '.', $field, 2 )[0] ] = true;
		}

		return array_intersect_key( $values, $roots );
	}

	/**
	 * The pending values without those that only write the stored value in
	 * another form: an empty value where null was (or the other way round),
	 * or an amount of {@see self::MONEY_FIELDS} written differently (`10`
	 * for `10.00`). Nested values (the addresses) are compared key by key.
	 *
	 * @param array<int|string, mixed> $stored  Stored values.
	 * @param array<int|string, mixed> $pending Values the save is about to write.
	 * @param string                   $prefix  Dot path of the arrays compared, for nested values.
	 * @return array<int|string, mixed>
	 */
	private static function real_changes( array $stored, array $pending, string $prefix = '' ): array {
		$real = [];
		foreach ( $pending as $key => $value ) {
			$old = $stored[ $key ] ?? null;
			if ( is_array( $value ) && is_array( $old ) ) {
				$nested = self::real_changes( $old, $value, $prefix . $key . '.' );
				if ( [] !== $nested ) {
					$real[ $key ] = $nested;
				}
				continue;
			}

			if ( ( null === $old || '' === $old ) && ( null === $value || '' === $value ) ) {
				continue;
			}
			if ( in_array( $prefix . $key, self::MONEY_FIELDS, true ) && is_numeric( $old ) && is_numeric( $value ) && (float) $old === (float) $value ) {
				continue;
			}

			$real[ $key ] = $value;
		}

		return $real;
	}

	/**
	 * Whether the stored values are those of an order that is still being
	 * created, see {@see self::CREATION_STATUSES}. Values that hold no status
	 * at all are not: the save is told rather than lost.
	 *
	 * @param array<string, mixed> $data Stored values of the order.
	 */
	private static function is_being_created( array $data ): bool {
		if ( ! array_key_exists( 'status', $data ) || ! ( null === $data['status'] || is_string( $data['status'] ) ) ) {
			return false;
		}

		return in_array( self::status_slug( (string) $data['status'] ), self::CREATION_STATUSES, true );
	}

	/**
	 * An id as a hook passes it: an integer, or a string of digits (some
	 * callers pass ids as text); 0 for anything else.
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
	 * A text a hook passes (a status): a string as it is, an integer as text,
	 * '' for anything else.
	 *
	 * @param mixed $value What the hook passed.
	 */
	private static function text_of( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		return is_int( $value ) ? (string) $value : '';
	}

	/**
	 * A status without the prefix WooCommerce stores it with as a post status
	 * (`wc-processing` is `processing`).
	 *
	 * @param string $status Status, prefixed or not.
	 */
	private static function status_slug( string $status ): string {
		return str_starts_with( $status, self::STATUS_PREFIX ) ? substr( $status, strlen( self::STATUS_PREFIX ) ) : $status;
	}

	/**
	 * The key of an order's per-request state: `site:order id`. An order id
	 * is unique on its own site only.
	 *
	 * @param int $order_id Order id.
	 */
	private static function state_key( int $order_id ): string {
		return self::blog_id() . ':' . $order_id;
	}

	/**
	 * The current site; 0 outside WordPress.
	 */
	private static function blog_id(): int {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	}

	/**
	 * Whether the note was meant for the customer (WooCommerce flags it in the
	 * comment meta).
	 */
	private function is_customer_note( int $note_id ): bool {
		return function_exists( 'get_comment_meta' ) && ! empty( get_comment_meta( $note_id, 'is_customer_note', true ) );
	}

	/**
	 * The `sprintf` formats of the sentences WooCommerce writes for a status
	 * change, translated as it translates them when it writes them
	 * (`WC_Order::status_transition()`).
	 *
	 * @return array<int, string>
	 */
	private function status_formats(): array {
		// The strings are WooCommerce's own, so the text domain is not ours.
		return [
			// translators: 1: old order status, 2: new order status.
			__( 'Order status changed from %1$s to %2$s.', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			// translators: %s: new order status.
			__( 'Order status set to %s.', 'woocommerce' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		];
	}

	/**
	 * The names of the statuses the shop has, as WooCommerce writes them into a
	 * status sentence (`wc_get_order_status_name()`): the labels of its listed
	 * statuses, custom ones included, and the two it names by their slug. Empty
	 * when WooCommerce lists none.
	 *
	 * @return array<int, string>
	 */
	private function status_names(): array {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];
		$names    = is_array( $statuses ) ? array_values( array_filter( $statuses, 'is_string' ) ) : [];

		return [] === $names ? [] : array_merge( $names, [ 'auto-draft', 'trash' ] );
	}

	/**
	 * The name WooCommerce files its own notes under.
	 */
	private function system_author(): string {
		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own string, translated the way WooCommerce does when it writes the note.
		return __( 'WooCommerce', 'woocommerce' );
	}

	/**
	 * The address WooCommerce files its own notes under, built the way
	 * `WC_Order::add_order_note()` builds it: the lowercase translated name, an
	 * `@` and the host of the request without `www.`. Empty when no valid
	 * address comes out of it (a translated name made of characters an address
	 * cannot hold).
	 */
	private function system_email(): string {
		$host = 'noreply.com';
		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			$host = str_replace( 'www.', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) );
		}

		return sanitize_email( strtolower( $this->system_author() ) . '@' . $host );
	}
}
