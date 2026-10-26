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
		'post'       => true,
		'page'       => true,
		'product'    => true,
		'custom_css' => false,
	];

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
}
