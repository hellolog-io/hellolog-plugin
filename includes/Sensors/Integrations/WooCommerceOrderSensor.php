<?php
/**
 * WooCommerce: order lifecycle.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Sensors\AbstractSensor;

defined( 'ABSPATH' ) || exit;

/**
 * Order lifecycle (9100–9104). Uses WC's high-level hooks so HPOS-mode
 * stores work identically — no direct `$wpdb` reads.
 *
 * Every event names its order as `target` (labelled with the order number)
 * and, while the order still exists, links to its edit screen. An order note
 * event also carries the text of the note and its kind: `customer` (meant for
 * the customer), `system` (written by WooCommerce itself) or `private`.
 */
final class WooCommerceOrderSensor extends AbstractSensor {

	/** Longest note text sent, in characters, the ellipsis included. */
	private const MAX_NOTE_LENGTH = 500;

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
	 * The kind of an order note.
	 *
	 * A note meant for the customer is a customer note, whoever wrote it. Of
	 * the others, the ones WooCommerce writes itself are recognised by the
	 * address it files them under, which starts with `$system_prefix`; every
	 * other note was written by a person.
	 *
	 * @param bool   $is_customer_note Whether the note was meant for the customer.
	 * @param string $author_email     Address the note was written under.
	 * @param string $system_prefix    Start of the address of WooCommerce's own notes.
	 * @return string `customer`, `system` or `private`.
	 */
	public static function note_type( bool $is_customer_note, string $author_email, string $system_prefix ): string {
		if ( $is_customer_note ) {
			return 'customer';
		}

		if ( '' !== $system_prefix && str_starts_with( $author_email, $system_prefix ) ) {
			return 'system';
		}

		return 'private';
	}

	/**
	 * The text of an order note as sent: without markup and at most 500
	 * characters, the ellipsis included.
	 *
	 * @param string $content Text of the note as stored.
	 */
	public static function note_text( string $content ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- only reached when core's helper is not loaded.
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $content ) : trim( strip_tags( $content ) );

		if ( mb_strlen( $text, 'UTF-8' ) <= self::MAX_NOTE_LENGTH ) {
			return $text;
		}

		return mb_substr( $text, 0, self::MAX_NOTE_LENGTH - 1, 'UTF-8' ) . self::ELLIPSIS;
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
		$author   = is_object( $note ) ? (string) ( $note->comment_author_email ?? '' ) : '';

		$this->emit(
			9103,
			array_merge(
				[
					'id'       => $order_id,
					'vars'     => [ 'note' => self::note_text( $content ) ],
					'metadata' => [
						'order_id'  => $order_id,
						'note_id'   => $note_id,
						'note_type' => self::note_type( $this->is_customer_note( $note_id ), $author, $this->system_prefix() ),
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
	 * Start of the address WooCommerce files its own notes under: the lowercase,
	 * translated shop name and an `@`, as `WC_Order::add_order_note()` builds it.
	 */
	private function system_prefix(): string {
		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- WooCommerce's own string, translated the way WooCommerce does when it writes the address.
		return strtolower( __( 'WooCommerce', 'woocommerce' ) ) . '@';
	}
}
