<?php
/**
 * What the WordPress network stand-ins answer and record.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Queue\Fixtures;

/**
 * Shared state of `wordpress-network-functions.php`. Only ever used inside a
 * test that runs in its own process, together with those stubs.
 */
final class NetworkState {

	/** What `is_multisite()` reports. */
	public static bool $multisite = true;

	/**
	 * Every site: blog id => id of its network.
	 *
	 * @var array<int, int>
	 */
	public static array $sites = [ 1 => 1 ];

	/** The site the request is on. */
	public static int $current_blog = 1;

	/** Calls of `switch_to_blog()`. */
	public static int $switch_count = 0;

	/**
	 * Sites `switch_to_blog()` left, innermost last.
	 *
	 * @var array<int, int>
	 */
	public static array $switched = [];

	/**
	 * Site options: blog id => option name => value.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $options = [];

	/**
	 * Network options: network id => option name => value.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $network_options = [];
}
