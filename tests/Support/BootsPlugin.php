<?php
/**
 * Boots the real plugin on the WordPress stand-ins.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Support;

use HelloLog\Plugin;
use HelloLog\Settings\Options;
use HelloLog\Tests\Queue\Fixtures\FakeWpdb;
use HelloLog\Tests\Transport\Fixtures\HttpState;

/**
 * For tests that run in their own process: loads the stand-ins (hooks that
 * run, options that fire core's option hooks, a database whose queue keeps
 * its rows, HTTP calls that are recorded), puts helloLOG's main file in a
 * plugins directory of the process, and boots the plugin. What the plugin
 * sent is read back per request: the key it used and the events.
 */
trait BootsPlugin {

	protected FakeWpdb $wpdb;

	/**
	 * A key in the shape the backend issues.
	 *
	 * @param string $prefix Its 8-character prefix.
	 * @param string $fill   The character its secret is made of.
	 */
	protected static function key( string $prefix = 'abcd1234', string $fill = 'a' ): string {
		return 'goal_live_' . $prefix . '_' . str_repeat( $fill, 40 );
	}

	/**
	 * Load the stand-ins and put helloLOG's main file in place.
	 *
	 * @param string $directory The plugin's directory under the plugins directory.
	 */
	protected function load_wordpress( string $directory = 'hellolog' ): void {
		require_once __DIR__ . '/../Queue/Fixtures/wordpress-queue-functions.php';
		require_once __DIR__ . '/../Transport/Fixtures/wordpress-http-functions.php';
		require_once __DIR__ . '/wordpress-functions.php';

		define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/hellolog-test-plugins-' . getmypid() );
		define( 'HELLOLOG_FILE', WP_PLUGIN_DIR . '/' . $directory . '/hellolog.php' );
		if ( ! is_dir( dirname( HELLOLOG_FILE ) ) ) {
			mkdir( dirname( HELLOLOG_FILE ), 0777, true );
		}
		file_put_contents( HELLOLOG_FILE, "<?php\n/**\n * Plugin Name: helloLOG\n * Version: 0.5.0\n */\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a local test file.

		$this->wpdb       = new FakeWpdb();
		$this->wpdb->live = true;
		$GLOBALS['wpdb']  = $this->wpdb;
	}

	/**
	 * Store the settings of the current site and boot the plugin.
	 *
	 * @param string $key      API key; empty for none.
	 * @param bool   $verified Whether the key was verified.
	 * @param array<string, mixed> $more Other options.
	 */
	protected function boot_with( string $key, bool $verified = true, array $more = [] ): Plugin {
		Wp::$options[ Wp::$blog ] = array_merge(
			[
				Options::KEY_TOKEN          => $key,
				Options::KEY_TOKEN_VERIFIED => $verified ? '1' : '0',
				Options::KEY_ANONYMIZE_IP   => '',
				Options::KEY_SENSOR_FILTERS => [
					'core-request'      => true,
					'core-failed-login' => true,
				],
			],
			$more
		);
		Plugin::instance()->boot();

		return Plugin::instance();
	}

	/**
	 * What each request to the backend carried: the key it was sent with and
	 * its events, in order.
	 *
	 * @return array<int, array{key: string, events: array<int, array<string, mixed>>}>
	 */
	protected function sent(): array {
		$sent = [];
		foreach ( HttpState::$requests as [ , $args ] ) {
			$body   = json_decode( (string) gzdecode( $args['body'] ), true );
			$sent[] = [
				'key'    => substr( (string) $args['headers']['Authorization'], strlen( 'Bearer ' ) ),
				'events' => $body['events'] ?? [],
			];
		}

		return $sent;
	}

	/**
	 * The codes of the events sent with a key, in order.
	 *
	 * @param string $key API key.
	 * @return array<int, int>
	 */
	protected function codes_sent_with( string $key ): array {
		$codes = [];
		foreach ( $this->sent() as $request ) {
			if ( $key === $request['key'] ) {
				$codes = array_merge( $codes, array_column( $request['events'], 'code' ) );
			}
		}

		return $codes;
	}

	/**
	 * Remove the plugins directory of this process.
	 */
	protected function remove_plugins_directory(): void {
		if ( ! defined( 'WP_PLUGIN_DIR' ) || ! str_contains( (string) WP_PLUGIN_DIR, 'hellolog-test-plugins-' ) || ! is_dir( WP_PLUGIN_DIR ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( WP_PLUGIN_DIR, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( WP_PLUGIN_DIR );
	}
}
