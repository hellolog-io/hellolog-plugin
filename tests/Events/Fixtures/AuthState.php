<?php
/**
 * What the WordPress current-user stand-ins answer and record.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Events\Fixtures;

/**
 * Shared state of `wordpress-auth-functions.php`. Only ever used inside a
 * test that runs in its own process, together with those stubs.
 */
final class AuthState {

	/** How deep `determine_current_user` may nest before the stand-in gives up. */
	public const MAX_NESTING = 3;

	/**
	 * Filters being applied right now, innermost last.
	 *
	 * @var array<int, string>
	 */
	public static array $filters = [];

	/** The current user once it is known; null until then. */
	public static ?object $current_user = null;

	/** Who `determine_current_user` finds; null is nobody (user 0). */
	public static ?object $determined = null;

	/**
	 * Runs while `determine_current_user` is applied, in place of the
	 * callbacks hooked to it (an application-password check, say).
	 *
	 * @var (callable(): void)|null
	 */
	public static $on_determine = null;

	/** Calls of `wp_get_current_user()`. */
	public static int $current_user_calls = 0;

	/** Deepest nesting of `determine_current_user` seen. */
	public static int $max_nesting = 0;

	/** Current nesting of `determine_current_user`. */
	public static int $nesting = 0;
}
