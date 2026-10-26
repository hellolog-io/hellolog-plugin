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

	/** What `wp_is_large_network()` reports. */
	public static bool $large = false;

	/** Seconds `timer_float()` moves on by every time it is read. */
	public static float $tick = 0.0;

	/** What `timer_float()` read last. */
	public static float $clock = 0.0;

	/** Whether the object cache can flush its in-memory runtime cache. */
	public static bool $flush_runtime_supported = true;

	/** Calls of `wp_cache_flush_runtime()`. */
	public static int $runtime_flushes = 0;

	/**
	 * Every site: blog id => id of its network.
	 *
	 * @var array<int, int>
	 */
	public static array $sites = [ 1 => 1 ];

	/** The site the request is on. */
	public static int $current_blog = 1;

	/**
	 * Sites that exist but are not initialized (no tables, or their tables
	 * are being dropped).
	 *
	 * @var array<int, int>
	 */
	public static array $uninitialized = [];

	/** Calls of `wp_is_site_initialized()`. */
	public static int $initialized_checks = 0;

	/**
	 * Names `delete_site_transient()` was called with.
	 *
	 * @var array<int, string>
	 */
	public static array $deleted_site_transients = [];

	/**
	 * For every `wp_cache_flush()` call, how many statements `$wpdb` had run
	 * by then.
	 *
	 * @var array<int, int>
	 */
	public static array $cache_flushes = [];

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
