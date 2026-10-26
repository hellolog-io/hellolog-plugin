<?php
/**
 * Stand-ins for the WordPress theme class and the plugin, theme and version
 * lookups the plugin, theme and core sensors call from their hooks.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` and `class_exists()` true for the rest of the process.
 * Load `wordpress-user-functions.php` with it for the site id and
 * `add_action()`.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Sensors\Core\Fixtures\ExtensionState;

	// A directory of this process only: the sensor checks a plugin's file exists.
	defined( 'WP_PLUGIN_DIR' ) || define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/hellolog-test-plugins-' . getmypid() );

	/**
	 * A theme: its stylesheet and headers, read when it was constructed.
	 */
	class WP_Theme {

		/**
		 * @param string                $stylesheet Directory name.
		 * @param array<string, string> $headers    Name, Version, ...
		 * @param bool                  $exists     Whether the theme was found.
		 */
		public function __construct( private string $stylesheet, private array $headers, private bool $exists ) {
		}

		/**
		 * Core answers false for a header the theme does not have.
		 *
		 * @return string|false
		 */
		public function get( string $header ) {
			return $this->headers[ $header ] ?? false;
		}

		public function get_stylesheet(): string {
			return $this->stylesheet;
		}

		public function exists(): bool {
			return $this->exists;
		}

		public function cache_delete(): void {
			unset( ExtensionState::$theme_cache[ $this->stylesheet ] );
		}
	}

	/**
	 * Like core, a theme read once is answered from the request's cache.
	 */
	function wp_get_theme( string $stylesheet = '', string $theme_root = '' ): WP_Theme {
		if ( ExtensionState::$reads_throw ) {
			throw new \RuntimeException( 'unreadable' );
		}
		if ( '' === $stylesheet ) {
			$stylesheet = ExtensionState::$active_theme;
		}
		if ( isset( ExtensionState::$theme_cache[ $stylesheet ] ) ) {
			return new WP_Theme( $stylesheet, ExtensionState::$theme_cache[ $stylesheet ], true );
		}
		if ( isset( ExtensionState::$themes[ $stylesheet ] ) ) {
			ExtensionState::$theme_cache[ $stylesheet ] = ExtensionState::$themes[ $stylesheet ];

			return new WP_Theme( $stylesheet, ExtensionState::$themes[ $stylesheet ], true );
		}

		return new WP_Theme( $stylesheet, [], false );
	}

	/**
	 * @param array<string, mixed> $args Not used.
	 * @return array<string, WP_Theme>
	 */
	function wp_get_themes( array $args = [] ): array {
		++ExtensionState::$theme_lists;
		$themes = [];
		foreach ( array_keys( ExtensionState::$themes ) as $stylesheet ) {
			$themes[ $stylesheet ] = wp_get_theme( (string) $stylesheet );
		}

		return $themes;
	}

	/**
	 * Like core, the first list is kept for the rest of the request.
	 *
	 * @return array<string, array<string, string>>
	 */
	function get_plugins( string $plugin_folder = '' ): array {
		++ExtensionState::$plugin_lists;
		if ( ExtensionState::$reads_throw ) {
			throw new \RuntimeException( 'unreadable' );
		}
		if ( null === ExtensionState::$plugin_cache ) {
			ExtensionState::$plugin_cache = ExtensionState::$plugins;
		}

		return ExtensionState::$plugin_cache;
	}

	/**
	 * Reads the file: what is on disk now, never a kept list.
	 *
	 * @return array<string, string>
	 */
	function get_plugin_data( string $plugin_file, bool $markup = true, bool $translate = true ): array {
		if ( ExtensionState::$reads_throw ) {
			throw new \RuntimeException( 'unreadable' );
		}
		$file = substr( $plugin_file, strlen( WP_PLUGIN_DIR . '/' ) );

		return array_merge(
			[
				'Name'    => '',
				'Version' => '',
			],
			ExtensionState::$plugins[ $file ] ?? []
		);
	}

	function is_multisite(): bool {
		return ExtensionState::$multisite;
	}

	function get_bloginfo( string $show = '' ): string {
		return 'version' === $show ? ExtensionState::$wp_version : '';
	}

	/**
	 * Records the registration; nothing is ever applied.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of arguments the callback accepts.
	 */
	function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		ExtensionState::$filters[ $hook_name ] = [
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		];

		return true;
	}
}
