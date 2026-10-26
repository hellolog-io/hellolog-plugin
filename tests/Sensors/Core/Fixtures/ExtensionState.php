<?php
/**
 * What the WordPress stand-ins of the plugin, theme and core sensor tests answer.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core\Fixtures;

/**
 * Shared state of `wordpress-extension-functions.php`. Only ever used inside
 * a test that runs in its own process, together with those stubs.
 *
 * Core keeps what it read about plugins and themes for the rest of the
 * request, so the stand-ins do too: `get_plugins()` answers its first list
 * until {@see self::$plugin_cache} is cleared, and a theme read once answers
 * from {@see self::$theme_cache} until `WP_Theme::cache_delete()`. The files
 * themselves ({@see self::$plugins}, {@see self::$themes}) are what
 * `get_plugin_data()` and a fresh theme read see.
 */
final class ExtensionState {

	/**
	 * Plugin file => its headers as the file on disk has them now.
	 *
	 * @var array<string, array<string, string>>
	 */
	public static array $plugins = [];

	/**
	 * What `get_plugins()` built on its first call; null when nothing is kept.
	 *
	 * @var array<string, array<string, string>>|null
	 */
	public static ?array $plugin_cache = null;

	/** How often `get_plugins()` was called. */
	public static int $plugin_lists = 0;

	/**
	 * Stylesheet => the theme's headers as its files have them now.
	 *
	 * @var array<string, array<string, string>>
	 */
	public static array $themes = [];

	/**
	 * Stylesheet => the headers a theme read earlier in the request kept.
	 *
	 * @var array<string, array<string, string>>
	 */
	public static array $theme_cache = [];

	/** How often `wp_get_themes()` was called. */
	public static int $theme_lists = 0;

	/** Stylesheet of the active theme. */
	public static string $active_theme = '';

	/** What `is_multisite()` answers. */
	public static bool $multisite = false;

	/** What `get_bloginfo( 'version' )` answers. */
	public static string $wp_version = '';

	/** Whether listing or reading plugins and themes throws. */
	public static bool $reads_throw = false;

	/**
	 * Hook name => how it was registered with `add_filter()`.
	 *
	 * @var array<string, array{priority: int, accepted_args: int}>
	 */
	public static array $filters = [];
}
