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

defined( 'ABSPATH' ) || exit;

/**
 * Order lifecycle (9100–9104). Uses WC's high-level hooks so HPOS-mode
 * stores work identically — no direct `$wpdb` reads.
 *
 * Every event names its order as `target` (labelled with the order number)
 * and, while the order still exists, links to its edit screen. An order note
 * event also carries the text of the note and its kind, see
 * {@see self::note_type()}.
 */
final class WooCommerceOrderSensor extends AbstractSensor {

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

	public function key(): string {
		return 'woocommerce-order';
	}

	public function should_load(): bool {
		return class_exists( 'WooCommerce', false );
	}

	public function boot(): void {
		add_action( 'woocommerce_new_order', [ $this, 'on_created' ], 10, 2 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 10, 4 );
		add_action( 'woocommerce_delete_order', [ $this, 'on_deleted' ], 10, 1 );
		add_action( 'woocommerce_order_note_added', [ $this, 'on_note_added' ], 10, 2 );
		add_action( 'woocommerce_order_refunded', [ $this, 'on_refunded' ], 10, 2 );
	}

	/**
	 * The object an event about an order is about; the label is the order
	 * number, which differs from the id on shops with sequential numbering.
	 *
	 * @param int    $id     Order id.
	 * @param string $number Order number; the id is used when empty.
	 * @return array{type: string, id: string, label: string}
	 */
	public static function order_target( int $id, string $number ): array {
		return [
			'type'  => 'order',
			'id'    => (string) $id,
			'label' => '#' . ( '' !== $number ? $number : (string) $id ),
		];
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
	 * @param mixed $order Order object the hook passed, if any.
	 */
	public function on_created( int $order_id, $order = null ): void {
		$this->emit(
			9100,
			array_merge(
				[
					'id'       => $order_id,
					'metadata' => [ 'order_id' => $order_id ],
				],
				$this->order_fields( $order_id, $order )
			)
		);
	}

	/**
	 * @param mixed $order
	 */
	public function on_status_changed( int $order_id, string $old, string $new, $order ): void {
		$this->emit(
			9101,
			array_merge(
				[
					'id'       => $order_id,
					'old'      => $old,
					'new'      => $new,
					'metadata' => [
						'order_id'   => $order_id,
						'old_status' => $old,
						'new_status' => $new,
					],
				],
				$this->order_fields( $order_id, $order )
			)
		);
	}

	public function on_deleted( int $order_id ): void {
		// A deleted order is gone: it can be named, but there is nothing to open.
		$this->emit(
			9102,
			array_merge(
				[
					'id'       => $order_id,
					'metadata' => [ 'order_id' => $order_id ],
				],
				$this->order_fields( $order_id, null, false )
			)
		);
	}

	/**
	 * @param mixed $order
	 */
	public function on_note_added( int $note_id, $order ): void {
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
	}

	public function on_refunded( int $order_id, int $refund_id ): void {
		$refund = function_exists( 'wc_get_order' ) ? wc_get_order( $refund_id ) : null;
		$amount = $refund && method_exists( $refund, 'get_amount' ) ? (string) $refund->get_amount() : '';
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
	 * @param bool  $link     Whether the order still exists to be opened.
	 * @return array<string, mixed>
	 */
	private function order_fields( int $order_id, $order, bool $link = true ): array {
		if ( $order_id <= 0 ) {
			return [];
		}

		if ( ! is_object( $order ) && $link && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}

		$number = is_object( $order ) && method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : '';
		$fields = [ 'target' => self::order_target( $order_id, $number ) ];

		if ( $link && is_object( $order ) && method_exists( $order, 'get_edit_order_url' ) ) {
			$edit = $order->get_edit_order_url();
			if ( is_string( $edit ) && '' !== $edit ) {
				$fields['links'] = [ 'edit' => $edit ];
			}
		}

		return $fields;
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
