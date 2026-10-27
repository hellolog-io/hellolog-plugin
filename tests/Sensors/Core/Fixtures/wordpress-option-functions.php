<?php
/**
 * Stand-ins for the WordPress option read and the installing flag the
 * settings sensor calls. Load `wordpress-user-functions.php` with it: the
 * options are kept per site.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Sensors\Core\Fixtures\WpState;

	/**
	 * @param string $option        Option name.
	 * @param mixed  $default_value Answer when the option does not exist.
	 * @return mixed
	 */
	function get_option( string $option, $default_value = false ) {
		if ( WpState::$option_reads_throw ) {
			throw new \RuntimeException( 'unreadable' );
		}

		return WpState::$options[ WpState::$blog_id ][ $option ] ?? $default_value;
	}

	function wp_installing(): bool {
		return WpState::$installing;
	}
}
