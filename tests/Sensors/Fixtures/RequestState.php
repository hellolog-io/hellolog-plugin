<?php
/**
 * What the WordPress request stand-ins of the option sensor tests answer.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Fixtures;

/**
 * Shared state of `wordpress-request-functions.php`. Only ever used inside a
 * test that runs in its own process, together with those stubs.
 */
final class RequestState {

	/** Id `get_current_user_id()` reports; 0 is nobody signed in. */
	public static int $user_id = 1;

	/** What `is_admin()` reports. */
	public static bool $admin = true;

	/** What `wp_doing_ajax()` reports. */
	public static bool $ajax = false;

	/** What `wp_doing_cron()` reports. */
	public static bool $cron = false;
}
