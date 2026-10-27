<?php
/**
 * The targets of the shop's objects.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors;

defined( 'ABSPATH' ) || exit;

/**
 * How an event names an order, a product or a coupon of the shop as its
 * target. The shop's own sensors and the post sensor (which tells the trash,
 * restore and revert of the shop's posts) name them here, so one object's
 * history stays on one target with one label. It never calls into
 * WordPress, and into the shop only for the way it writes a coupon code in
 * lower case.
 */
final class StoreTargets {

	/** The title WooCommerce gives a product WordPress keeps as an editor placeholder. */
	public const PRODUCT_PLACEHOLDER = 'AUTO-DRAFT';

	/**
	 * The title WordPress gives a coupon it keeps as an editor placeholder
	 * (WooCommerce keeps it). The shop reads every code in lower case, so a
	 * code it knows never reads like this.
	 */
	public const COUPON_PLACEHOLDER = 'Auto Draft';

	/**
	 * An order, labelled with its number, which differs from the id on shops
	 * with sequential numbering; with the id when there is no number (an
	 * order's post has none of its own).
	 *
	 * @param int    $id     Order id.
	 * @param string $number Order number; empty for none.
	 * @return array{type: string, id: string, label: string}
	 */
	public static function order( int $id, string $number = '' ): array {
		return [
			'type'  => 'order',
			'id'    => (string) $id,
			'label' => '#' . ( '' !== $number ? $number : (string) $id ),
		];
	}

	/**
	 * A product or variation, labelled with its name (a variation's is its
	 * parent's name and its attributes). A product without a real name (none,
	 * or WooCommerce's placeholder) is labelled `#<id>`.
	 *
	 * @param int    $id   Product or variation id.
	 * @param string $name Its name.
	 * @return array{type: string, id: string, label: string}
	 */
	public static function product( int $id, string $name ): array {
		return [
			'type'  => 'product',
			'id'    => (string) $id,
			'label' => '' === $name || self::PRODUCT_PLACEHOLDER === $name ? '#' . $id : $name,
		];
	}

	/**
	 * A coupon, labelled with its code as the shop stores it (see
	 * {@see self::coupon_code()}): the post title keeps the code as it was
	 * typed. A coupon without a real code (none, or WordPress's placeholder
	 * title) is labelled `#<id>`.
	 *
	 * @param int    $id   Coupon id.
	 * @param string $code Its code, or its post title.
	 * @return array{type: string, id: string, label: string}
	 */
	public static function coupon( int $id, string $code ): array {
		return [
			'type'  => 'coupon',
			'id'    => (string) $id,
			'label' => '' === $code || self::COUPON_PLACEHOLDER === $code ? '#' . $id : self::coupon_code( $code ),
		];
	}

	/**
	 * A coupon code the way the shop stores and compares it: in lower case
	 * (`SUMMER10` and `summer10` are one code to it), with the shop's own
	 * function when it is loaded.
	 *
	 * @param string $code A code, or the post title of a coupon.
	 */
	public static function coupon_code( string $code ): string {
		if ( function_exists( 'wc_strtolower' ) ) {
			return (string) wc_strtolower( $code );
		}

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $code ) : strtolower( $code );
	}
}
