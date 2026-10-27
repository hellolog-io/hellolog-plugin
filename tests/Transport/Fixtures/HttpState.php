<?php
/**
 * What the WordPress HTTP stand-ins answer, and what they were asked.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Transport\Fixtures;

/**
 * Shared state of `wordpress-http-functions.php`. Only ever used inside a
 * test that runs in its own process, together with those stubs.
 */
final class HttpState {

	/**
	 * Every `wp_remote_post()` call as `[ url, args ]`.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public static array $requests = [];

	/** HTTP status every request answers with. */
	public static int $status = 202;
}
