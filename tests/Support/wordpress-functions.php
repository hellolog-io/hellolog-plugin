<?php
/**
 * Stand-ins for the WordPress functions and classes a booted plugin calls:
 * hooks that really run, options that fire core's option hooks, sites of a
 * network, plugin files, and the REST request and response.
 *
 * Load with `Queue/Fixtures/FakeWpdb.php` and `wordpress-queue-functions.php`
 * (the database) and `Transport/Fixtures/wordpress-http-functions.php` (the
 * HTTP calls). Loaded only by tests that run in a separate process: defining
 * these makes `function_exists()` and `class_exists()` true for the rest of
 * the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Support\Wp;

	defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
	defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
	defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
	defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 604800 );

	/**
	 * @param string   $hook_name     Hook.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Arguments the callback takes.
	 */
	function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		Wp::$hooks[ $hook_name ][] = [
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		];

		return true;
	}

	/**
	 * @param string   $hook_name     Hook.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Arguments the callback takes.
	 */
	function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return add_action( $hook_name, $callback, $priority, $accepted_args );
	}

	/**
	 * @param string $hook_name Hook.
	 * @param mixed  ...$args   Arguments.
	 */
	function do_action( string $hook_name, ...$args ): void {
		Wp::$actions[] = [ $hook_name, $args ];
		Wp::run( $hook_name, $args );
	}

	/**
	 * @param string $hook_name Hook.
	 * @param mixed  $value     Value to filter.
	 * @param mixed  ...$args   More arguments.
	 * @return mixed
	 */
	function apply_filters( string $hook_name, $value, ...$args ) {
		return Wp::run( $hook_name, array_merge( [ $value ], $args ), true );
	}

	function doing_action( ?string $hook_name = null ): bool {
		return null === $hook_name ? [] !== Wp::$doing : in_array( $hook_name, Wp::$doing, true );
	}

	function doing_filter( ?string $hook_name = null ): bool {
		return doing_action( $hook_name );
	}

	/**
	 * @param string $option        Option name.
	 * @param mixed  $default_value Answer when the option does not exist.
	 * @return mixed
	 */
	function get_option( string $option, $default_value = false ) {
		return array_key_exists( $option, Wp::$options[ Wp::$blog ] ?? [] ) ? Wp::$options[ Wp::$blog ][ $option ] : $default_value;
	}

	/**
	 * Like core: nothing when the option exists; `added_option` after the write.
	 *
	 * @param string $option     Option name.
	 * @param mixed  $value      Value.
	 * @param mixed  $deprecated Not used.
	 * @param mixed  $autoload   Not used.
	 */
	function add_option( string $option, $value = '', $deprecated = '', $autoload = null ): bool {
		if ( array_key_exists( $option, Wp::$options[ Wp::$blog ] ?? [] ) ) {
			return false;
		}
		Wp::$options[ Wp::$blog ][ $option ] = $value;
		do_action( 'added_option', $option, $value );

		return true;
	}

	/**
	 * Like core: adds a missing option, does nothing for an identical value,
	 * `updated_option` after the write.
	 *
	 * @param string $option   Option name.
	 * @param mixed  $value    Value.
	 * @param mixed  $autoload Not used.
	 */
	function update_option( string $option, $value, $autoload = null ): bool {
		if ( ! array_key_exists( $option, Wp::$options[ Wp::$blog ] ?? [] ) ) {
			return add_option( $option, $value );
		}
		$old = Wp::$options[ Wp::$blog ][ $option ];
		if ( $value === $old ) {
			return false;
		}
		Wp::$options[ Wp::$blog ][ $option ] = $value;
		do_action( 'updated_option', $option, $old, $value );

		return true;
	}

	/**
	 * Like core: `delete_option` before the row goes, `deleted_option` after;
	 * nothing when the option does not exist.
	 *
	 * @param string $option Option name.
	 */
	function delete_option( string $option ): bool {
		if ( ! array_key_exists( $option, Wp::$options[ Wp::$blog ] ?? [] ) ) {
			return false;
		}
		do_action( 'delete_option', $option );
		unset( Wp::$options[ Wp::$blog ][ $option ] );
		do_action( 'deleted_option', $option );

		return true;
	}

	function is_multisite(): bool {
		return Wp::$multisite;
	}

	function get_current_blog_id(): int {
		return Wp::$blog;
	}

	function get_current_network_id(): int {
		return 1;
	}

	function get_main_site_id(): int {
		return 1;
	}

	function switch_to_blog( int $blog_id ): bool {
		global $wpdb;

		Wp::$stack[] = Wp::$blog;
		Wp::$blog    = $blog_id;
		$wpdb->prefix = $wpdb->get_blog_prefix( $blog_id );

		return true;
	}

	function restore_current_blog(): bool {
		global $wpdb;

		if ( [] === Wp::$stack ) {
			return false;
		}
		Wp::$blog     = array_pop( Wp::$stack );
		$wpdb->prefix = $wpdb->get_blog_prefix( Wp::$blog );

		return true;
	}

	/**
	 * @param array<string, mixed> $args Query; only ids of active sites are answered.
	 * @return array<int, int>
	 */
	function get_sites( array $args = [] ): array {
		return Wp::$sites;
	}

	/**
	 * @param mixed $blog_id Site id.
	 */
	function get_site( $blog_id ): ?object {
		return in_array( (int) $blog_id, Wp::$sites, true ) ? (object) [ 'blog_id' => (int) $blog_id ] : null;
	}

	/**
	 * Like core: the file relative to the plugins directory.
	 *
	 * @param string $file Full path of a plugin file.
	 */
	function plugin_basename( string $file ): string {
		return ltrim( str_replace( WP_PLUGIN_DIR, '', $file ), '/' );
	}

	/**
	 * The `Plugin Name` and `Version` headers of a plugin file.
	 *
	 * @param string $plugin_file Full path of a plugin file.
	 * @param bool   $markup      Not used.
	 * @param bool   $translate   Not used.
	 * @return array<string, string>
	 */
	function get_plugin_data( string $plugin_file, bool $markup = true, bool $translate = true ): array {
		$text = (string) file_get_contents( $plugin_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test file.
		preg_match( '/Plugin Name:\s*(.+)/', $text, $name );
		preg_match( '/Version:\s*(.+)/', $text, $version );

		return [
			'Name'    => trim( $name[1] ?? '' ),
			'Version' => trim( $version[1] ?? '' ),
		];
	}

	/**
	 * @param mixed ...$args Not used.
	 */
	function load_plugin_textdomain( ...$args ): bool {
		return true;
	}

	function is_admin(): bool {
		return false;
	}

	function get_bloginfo( string $show = '' ): string {
		return 'version' === $show ? '7.0' : '';
	}

	/**
	 * @param mixed $data  Data.
	 * @param int   $flags Flags.
	 * @param int   $depth Depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, int $flags = 0, int $depth = 512 ) {
		return json_encode( $data, $flags, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- this is the stand-in.
	}

	/**
	 * @param mixed $value Text.
	 */
	function sanitize_text_field( $value ): string {
		return trim( (string) $value );
	}

	/**
	 * @param mixed $key Key.
	 */
	function sanitize_key( $key ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}

	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
	}

	/**
	 * A REST request with a JSON body.
	 */
	class WP_REST_Request {

		/** @param array<string, mixed> $json Decoded body. */
		public function __construct( private array $json = [] ) {
		}

		/** @return array<string, mixed> */
		public function get_json_params(): array {
			return $this->json;
		}
	}

	/**
	 * A REST response.
	 */
	class WP_REST_Response {

		/**
		 * @param mixed $data   Body.
		 * @param int   $status HTTP status.
		 */
		public function __construct( public $data = null, public int $status = 200 ) {
		}

		/** @return mixed */
		public function get_data() {
			return $this->data;
		}
	}

	/**
	 * @param mixed $response Response or data.
	 */
	function rest_ensure_response( $response ): WP_REST_Response {
		return $response instanceof WP_REST_Response ? $response : new WP_REST_Response( $response );
	}
}
