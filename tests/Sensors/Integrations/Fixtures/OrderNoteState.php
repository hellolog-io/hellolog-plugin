<?php
/**
 * What the WordPress and WooCommerce stand-ins of the order note tests answer.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Integrations\Fixtures;

/**
 * Shared state of `wordpress-order-functions.php`. Only ever used inside a
 * test that runs in its own process, together with those stubs.
 */
final class OrderNoteState {

	/**
	 * Comment id => the note `get_comment()` finds. An id that is not listed
	 * has no such comment.
	 *
	 * @var array<int, object>
	 */
	public static array $comments = [];

	/**
	 * Comment id => meta key => value `get_comment_meta()` returns.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $comment_meta = [];

	/**
	 * Order id => the order `wc_get_order()` finds. An id that is not listed
	 * has no such order.
	 *
	 * @var array<int, object>
	 */
	public static array $orders = [];

	/** The statuses a shop starts with, as `wc_get_order_statuses()` lists them. */
	public const DEFAULT_STATUSES = [
		'wc-pending'    => 'Pending payment',
		'wc-processing' => 'Processing',
		'wc-on-hold'    => 'On hold',
		'wc-completed'  => 'Completed',
		'wc-cancelled'  => 'Cancelled',
		'wc-refunded'   => 'Refunded',
		'wc-failed'     => 'Failed',
	];

	/**
	 * Status slug => label, what `wc_get_order_statuses()` returns.
	 *
	 * @var array<string, string>
	 */
	public static array $statuses = self::DEFAULT_STATUSES;

	/**
	 * Source text => its translation, for `__()`. A text that is not listed
	 * is answered as it is.
	 *
	 * @var array<string, string>
	 */
	public static array $translations = [];

	/**
	 * Hook name => how it was registered with `add_action()`.
	 *
	 * @var array<string, array{priority: int, accepted_args: int}>
	 */
	public static array $actions = [];

	/** The site `get_current_blog_id()` answers (`wordpress-site-functions.php`). */
	public static int $blog_id = 1;

	/**
	 * Post id => the post type `get_post_type()` answers
	 * (`wordpress-site-functions.php`). An id that is not listed has no post.
	 *
	 * @var array<int, string>
	 */
	public static array $post_types = [];

	/**
	 * Every `do_action()` call (`wordpress-site-functions.php`), in order, as
	 * `[ hook name, arguments ]`.
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	public static array $fired = [];
}
