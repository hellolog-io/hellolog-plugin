<?php
/**
 * Stand-ins for the WordPress network, site and option calls activation
 * makes, over the `$wpdb` of `FakeWpdb.php`.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` and `class_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Queue\Fixtures\NetworkState;

	/**
	 * A site of the network: just its ids.
	 */
	class WP_Site {

		/** @var string */
		public $blog_id;

		/** @var string */
		public $network_id;

		public function __construct( int $blog_id, int $network_id ) {
			// Core keeps both as numeric strings.
			$this->blog_id    = (string) $blog_id;
			$this->network_id = (string) $network_id;
		}
	}

	function is_multisite(): bool {
		return NetworkState::$multisite;
	}

	function get_current_network_id(): int {
		return NetworkState::$sites[ NetworkState::$current_blog ] ?? 1;
	}

	/**
	 * Like core: 100 sites unless `number` says otherwise (0 is no limit).
	 *
	 * @param array<string, mixed> $args Query arguments; `fields` is always `ids` here.
	 * @return array<int, int>
	 */
	function get_sites( array $args = [] ): array {
		$ids = [];
		foreach ( NetworkState::$sites as $blog_id => $network_id ) {
			if ( empty( $args['network_id'] ) || (int) $args['network_id'] === $network_id ) {
				$ids[] = $blog_id;
			}
		}
		$number = (int) ( $args['number'] ?? 100 );

		return $number > 0 ? array_slice( $ids, 0, $number ) : $ids;
	}

	function switch_to_blog( int $blog_id ): bool {
		global $wpdb;

		++NetworkState::$switch_count;
		NetworkState::$switched[]   = NetworkState::$current_blog;
		NetworkState::$current_blog = $blog_id;
		$wpdb->prefix               = $wpdb->get_blog_prefix( $blog_id );

		return true;
	}

	function restore_current_blog(): bool {
		global $wpdb;

		NetworkState::$current_blog = (int) array_pop( NetworkState::$switched );
		$wpdb->prefix               = $wpdb->get_blog_prefix( NetworkState::$current_blog );

		return true;
	}

	/**
	 * @param string $option        Name.
	 * @param mixed  $default_value Answer when the option does not exist.
	 * @return mixed
	 */
	function get_option( string $option, $default_value = false ) {
		return NetworkState::$options[ NetworkState::$current_blog ][ $option ] ?? $default_value;
	}

	/**
	 * @param string    $option     Name.
	 * @param mixed     $value      Value.
	 * @param string    $deprecated Unused.
	 * @param bool|null $autoload   Unused.
	 */
	function add_option( string $option, $value = '', $deprecated = '', $autoload = null ): bool {
		if ( isset( NetworkState::$options[ NetworkState::$current_blog ][ $option ] ) ) {
			return false;
		}
		NetworkState::$options[ NetworkState::$current_blog ][ $option ] = $value;

		return true;
	}

	/**
	 * @param string    $option   Name.
	 * @param mixed     $value    Value.
	 * @param bool|null $autoload Unused.
	 */
	function update_option( string $option, $value, $autoload = null ): bool {
		NetworkState::$options[ NetworkState::$current_blog ][ $option ] = $value;

		return true;
	}

	function delete_option( string $option ): bool {
		unset( NetworkState::$options[ NetworkState::$current_blog ][ $option ] );

		return true;
	}

	/**
	 * @param int    $network_id    Network.
	 * @param string $option        Name.
	 * @param mixed  $default_value Answer when the option does not exist.
	 * @return mixed
	 */
	function get_network_option( $network_id, string $option, $default_value = false ) {
		return NetworkState::$network_options[ (int) $network_id ][ $option ] ?? $default_value;
	}

	function plugin_basename( string $file ): string {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}
