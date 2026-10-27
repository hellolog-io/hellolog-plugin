<?php
/**
 * What the WordPress stand-ins of the post and comment sensor tests answer.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core\Fixtures;

/**
 * Shared state of `wordpress-content-functions.php`,
 * `wordpress-user-functions.php` and `wordpress-option-functions.php`. Only ever used inside a test that runs in
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

	/**
	 * Hook name => every callback `add_action()` registered on it, in the
	 * order of registration, for a test to fire.
	 *
	 * @var array<string, array<int, array{callback: callable, priority: int, accepted_args: int}>>
	 */
	public static array $callbacks = [];

	/**
	 * Post type => the taxonomies `get_object_taxonomies()` reports for it. A
	 * type that is not listed has none.
	 *
	 * @var array<string, array<int, string>>
	 */
	public static array $taxonomies = [
		'post' => [ 'category', 'post_tag', 'post_format' ],
	];

	/**
	 * Term taxonomy id => the term `get_term_by( 'term_taxonomy_id' )` finds,
	 * as an object with core's `term_id`, `term_taxonomy_id`, `name` and
	 * `taxonomy`. An id that is not listed has no such term.
	 *
	 * @var array<int, object>
	 */
	public static array $terms = [];

	/**
	 * Post id => taxonomy => the term taxonomy ids of the post's terms, which
	 * `get_the_terms()` answers with.
	 *
	 * @var array<int, array<string, array<int, int>>>
	 */
	public static array $post_terms = [];

	/** Whether `get_the_terms()` and `get_term_by()` fail the way a broken filter on them would. */
	public static bool $term_reads_throw = false;

	/**
	 * Site id => option name => the value `get_option()` finds. An option
	 * that is not listed does not exist.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $options = [];

	/** Whether `get_option()` fails the way a broken filter on it would. */
	public static bool $option_reads_throw = false;

	/** What `wp_installing()` answers: whether WordPress is setting up a site. */
	public static bool $installing = false;

	/**
	 * Ids of the sticky posts.
	 *
	 * @var array<int, int>
	 */
	public static array $sticky = [];

	/**
	 * Post id => id of its featured image. A post that is not listed has none.
	 *
	 * @var array<int, int>
	 */
	public static array $thumbnails = [];

	/**
	 * Post id => its page template file. A post that is not listed uses the
	 * default template.
	 *
	 * @var array<int, string>
	 */
	public static array $templates = [];

	/**
	 * Post id => id of its latest revision. A post that is not listed has none.
	 *
	 * @var array<int, int>
	 */
	public static array $revisions = [];

	/**
	 * Post id => meta key => the value `get_post_meta()` finds. A key that is
	 * not listed reads as an empty string, as core's single read does.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $post_meta = [];

	/** Whether `get_post_meta()` fails the way a broken filter on it would. */
	public static bool $post_meta_throws = false;

	/**
	 * The capabilities `current_user_can()` grants the current user.
	 *
	 * @var array<int, string>
	 */
	public static array $caps = [];

	/**
	 * Hook => the argument lists of its scheduled events, which
	 * `wp_next_scheduled()` finds.
	 *
	 * @var array<string, array<int, array<int, mixed>>>
	 */
	public static array $scheduled = [];
}
