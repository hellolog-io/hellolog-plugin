<?php
/**
 * Canonical object types referenced in audit events.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Object strings used in the event payload's `object` field. Keep this list
 * stable across versions — the backend filters by these literals.
 */
final class ObjectType {

	public const USER     = 'user';
	public const SESSION  = 'session';
	public const POST     = 'post';
	public const PAGE     = 'page';
	public const CPT      = 'custom-post-type';
	public const TAXONOMY = 'taxonomy';
	public const TERM     = 'term';
	public const COMMENT  = 'comment';
	public const PLUGIN   = 'plugin';
	public const THEME    = 'theme';
	public const WIDGET   = 'widget';
	public const MENU     = 'menu';
	public const FILE     = 'file';
	public const SETTING  = 'setting';
	public const SYSTEM   = 'system';
	public const DATABASE = 'database';
	public const NETWORK  = 'network';
	public const SITE     = 'site';
	public const REQUEST  = 'request';
	public const PRODUCT  = 'product';
	public const ORDER    = 'order';
	public const COUPON   = 'coupon';
	public const CUSTOMER = 'customer';
	public const FORM     = 'form';
	public const ENTRY    = 'entry';
	public const REDIRECT = 'redirect';
	public const FIELD    = 'field';
	public const MEDIA    = 'media';
}
