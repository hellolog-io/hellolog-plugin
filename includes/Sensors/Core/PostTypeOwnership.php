<?php
/**
 * Which post events another sensor reports in its own words.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Some post types belong to a sensor of their own: the store sensors report
 * products, orders and coupons, the bbPress, LearnDash, Easy Digital
 * Downloads and ACF sensors report forums, courses, downloads and field
 * groups, and {@see MediaSensor} reports files of the media library. Each
 * of them takes exactly the post events it reports itself (its "owned"
 * codes); every other event of the type stays with {@see ContentSensor}. No
 * owner reports a trash, a restore or a revert to `auto-draft`, and the
 * permanent delete of an order stays with the post sensor too (see
 * {@see self::RELEASED_CODES}).
 *
 * A plugin's types are its own only while the plugin is active: without it
 * a type of that name belongs to some other plugin (a forum plugin is not
 * the only one with topics) and nobody else reports it. WordPress's internal
 * records nobody edits (revisions, menu items, customizer drafts, embed
 * caches, privacy requests) and the files of the media library are always
 * owned.
 *
 * {@see self::is_owned_elsewhere()} is pure; {@see self::owner_active()}
 * reads which plugins are active.
 */
final class PostTypeOwnership {

	/**
	 * Post type => who reports it.
	 *
	 * @var array<string, string>
	 */
	public const OWNERS = [
		'product'              => 'woocommerce',
		'product_variation'    => 'woocommerce',
		'shop_order'           => 'woocommerce',
		'shop_order_placehold' => 'woocommerce',
		'shop_order_refund'    => 'woocommerce',
		'shop_coupon'          => 'woocommerce',
		'forum'                => 'bbpress',
		'topic'                => 'bbpress',
		'reply'                => 'bbpress',
		'sfwd-courses'         => 'learndash',
		'sfwd-lessons'         => 'learndash',
		'sfwd-topic'           => 'learndash',
		'sfwd-quiz'            => 'learndash',
		'download'             => 'edd',
		'acf-field-group'      => 'acf',
		'attachment'           => 'media',
		'revision'             => 'internal',
		'nav_menu_item'        => 'internal',
		'customize_changeset'  => 'internal',
		'oembed_cache'         => 'internal',
		'user_request'         => 'internal',
	];

	/**
	 * Owner => the post event codes it takes: the events its sensor reports
	 * in its own words. bbPress reports a publish and a delete, LearnDash
	 * and Easy Digital Downloads a publish, ACF every save and the delete of
	 * a field group; the store sensors, the media sensor and the internal
	 * records (which nobody reports) take everything but trash, restore and
	 * revert.
	 *
	 * @var array<string, array<int, int>>
	 */
	private const OWNED_CODES = [
		'woocommerce' => [ 2000, 2001, 2002, 2005, 2006, 2007, 2008, 2009 ],
		'bbpress'     => [ 2001, 2005 ],
		'learndash'   => [ 2001 ],
		'edd'         => [ 2001 ],
		'acf'         => [ 2000, 2001, 2002, 2005, 2006 ],
		'media'       => [ 2000, 2001, 2002, 2005, 2006, 2007, 2008, 2009 ],
		'internal'    => [ 2000, 2001, 2002, 2005, 2006, 2007, 2008, 2009 ],
	];

	/**
	 * Owners that are always there: this plugin's media sensor and
	 * WordPress itself.
	 *
	 * @var array<int, string>
	 */
	private const ALWAYS_ACTIVE = [ 'media', 'internal' ];

	/**
	 * Post type => codes of its owner that stay with the post sensor for
	 * this type. The classic order screens delete an order stored as a post
	 * ("Delete permanently", "Empty Trash") with WordPress's own delete,
	 * which fires no hook of the shop: only the post sensor sees it.
	 *
	 * @var array<string, array<int, int>>
	 */
	private const RELEASED_CODES = [
		'shop_order'           => [ 2005 ],
		'shop_order_placehold' => [ 2005 ],
	];

	/**
	 * Whether an event about a post of a type is reported by the type's owner
	 * instead of the post sensor.
	 *
	 * @param string $post_type    Post type name.
	 * @param int    $code         Code of the event.
	 * @param bool   $owner_active Whether the plugin that owns the type is active; not read for the owners that are always there.
	 */
	public static function is_owned_elsewhere( string $post_type, int $code, bool $owner_active ): bool {
		$owner = self::OWNERS[ $post_type ] ?? null;
		if ( null === $owner || ( ! $owner_active && ! in_array( $owner, self::ALWAYS_ACTIVE, true ) ) ) {
			return false;
		}

		return in_array( $code, self::OWNED_CODES[ $owner ], true ) && ! in_array( $code, self::RELEASED_CODES[ $post_type ] ?? [], true );
	}

	/**
	 * Whether the plugin that owns a type is active, told the way that
	 * plugin's sensor decides to load. True for a type nobody owns and for
	 * the owners that are always there.
	 *
	 * @param string $post_type Post type name.
	 */
	public static function owner_active( string $post_type ): bool {
		switch ( self::OWNERS[ $post_type ] ?? '' ) {
			case 'woocommerce':
				return class_exists( 'WooCommerce', false );
			case 'bbpress':
				return class_exists( 'bbPress', false ) || function_exists( 'bbp_get_version' );
			case 'learndash':
				return defined( 'LEARNDASH_VERSION' );
			case 'edd':
				return defined( 'EDD_VERSION' );
			case 'acf':
				return class_exists( 'ACF', false );
			default:
				return true;
		}
	}
}
