<?php
/**
 * What the WordPress stand-ins of the post and comment sensor tests answer.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core\Fixtures;

/**
 * Shared state of `wordpress-content-functions.php` and
 * `wordpress-user-functions.php`. Only ever used inside a test that runs in
 * its own process, together with those stubs.
 */
final class WpState {

	/**
	 * Post type => whether it has an admin screen. A type that is not listed
	 * is not registered.
	 *
	 * @var array<string, bool>
	 */
	public static array $post_types = [
		'post'        => true,
		'page'        => true,
		'product'     => true,
		'shop_coupon' => true,
		'shop_order'  => true,
		'attachment'  => true,
		'revision'    => false,
		'custom_css'  => false,
	];

	/**
	 * Post id => the post `get_post()` finds. An id that is not listed is a
	 * plain post titled "Parent post".
	 *
	 * @var array<int, \WP_Post>
	 */
	public static array $posts = [];

	/** Whether the current user may edit posts (core's edit link needs it). */
	public static bool $can_edit = true;

	/** The action `doing_action()` reports as running, if any. */
	public static ?string $doing_action = null;

	/** What `get_comment()` returns. */
	public static ?\WP_Comment $comment = null;

	/**
	 * User id => the user `get_user_by()` and `get_userdata()` find. An id
	 * that is not listed has no such user.
	 *
	 * @var array<int, \WP_User>
	 */
	public static array $users = [];

	/**
	 * User id => meta key => the value `get_user_meta()` finds. A key that is
	 * not listed reads as an empty string, as core's single read does.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $user_meta = [];

	/** The site `get_current_blog_id()` reports as current. */
	public static int $blog_id = 1;

	/**
	 * Sites `switch_to_blog()` left, the last one on top.
	 *
	 * @var array<int, int>
	 */
	public static array $blog_stack = [];

	/**
	 * Transient name => `[ value, expiration ]`, as `set_transient()` stored it.
	 *
	 * @var array<string, array{0: mixed, 1: int}>
	 */
	public static array $transients = [];

	/**
	 * Hook name => how it was registered with `add_action()`.
	 *
	 * @var array<string, array{priority: int, accepted_args: int}>
	 */
	public static array $actions = [];
}
