<?php
/**
 * WooCommerce: order lifecycle.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Events\ChangeSet;
use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\StoreTargets;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Order lifecycle (9100–9105, 9109, 9110). Uses WC's high-level hooks so
 * HPOS-mode stores work identically — no direct `$wpdb` reads.
 *
 * Every event names its order as `target` (labelled with the order number)
 * and, while the order still exists, links to its edit screen. An order note
 * event also carries the text of the note and its kind, see
 * {@see self::note_type()}.
 *
 * A save of an existing order tells which of {@see self::ORDER_FIELDS}
 * changed (9105), read from the order before WooCommerce writes it; the
 * customer's details among them ({@see self::CONTENT_FIELDS}) without their
 * values. A save of an order that is still being created tells nothing (its
 * creation is 9100). No event sends the customer's contact details. A
 * status change carries the status as a change, and a status
 * change out of the trash is also a restore (9110), as are WooCommerce's
 * own restore and WordPress's restore of an order stored as a post.
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
	 * never with their values, so no contact detail leaves the site.
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
	 * Stored statuses of an order that is still being created: not set yet,
	 * WordPress's and WooCommerce's placeholders, and the drafts of the admin
	 * screen and the checkout. A save of such an order fills its fields in.
	 *
	 * @var array<int, string>
	 */
	private const CREATION_STATUSES = [ '', 'new', 'auto-draft', 'draft', 'checkout-draft' ];

	/** What WooCommerce puts in front of a status where it stores it as a post status. */
	private const STATUS_PREFIX = 'wc-';

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
	 * A new order: its total and currency go into `vars`, how it was placed
	 * (`created_via`) and paid into `metadata`.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $order    Order object the hook passed, if any.
	 */
	public function on_created( $order_id, $order = null ): void {
		try {
			$order_id = self::id_of( $order_id );
			$order    = $this->order_object( $order_id, $order );
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

			$this->emit(
				9100,
				array_merge(
					[
						'id'       => $order_id,
						'vars'     => $vars,
						'metadata' => [ 'order_id' => $order_id ] + $metadata,
					],
					$this->order_fields( $order_id, $order )
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9100, [ 'order_id' => self::id_of( $order_id ) ] );
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
	 * changes none of {@see self::ORDER_FIELDS} (every save moves the
	 * modification date) is dispatched without changes and without reading
	 * the stored order at all, whatever its status; the buffer drops it.
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

			$changes = [];
			$pending = self::order_values( $order->get_changes() );
			if ( [] !== $pending ) {
				$data = $order->get_data();
				$data = is_array( $data ) ? $data : [];
				if ( self::is_being_created( $data ) ) {
					return;
				}
				$stored  = self::order_values( $data );
				$changes = WcObjectDiff::changes( $stored, self::real_changes( $stored, $pending ), self::ORDER_FIELDS, self::CONTENT_FIELDS );
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
	 * @param mixed $order_id  Id of the refunded order.
	 * @param mixed $refund_id Id of the refund.
	 */
	public function on_refunded( $order_id, $refund_id ): void {
		try {
			$order_id  = self::id_of( $order_id );
			$refund_id = self::id_of( $refund_id );
			$refund    = function_exists( 'wc_get_order' ) ? wc_get_order( $refund_id ) : null;
			$amount    = $refund && method_exists( $refund, 'get_amount' ) ? (string) $refund->get_amount() : '';
			$this->emit(
				9104,
				array_merge(
					[
						'id'       => $order_id,
						'amount'   => $amount,
						'metadata' => [
							'order_id'  => $order_id,
							'refund_id' => $refund_id,
							'amount'    => $amount,
						],
					],
					$this->order_fields( $order_id, null )
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9104, [ 'order_id' => self::id_of( $order_id ) ] );
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
		$site = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		return $site . ':' . $order_id;
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
