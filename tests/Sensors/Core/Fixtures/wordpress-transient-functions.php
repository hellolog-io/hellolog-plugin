<?php
/**
 * Stand-ins for the WordPress transient calls, kept in memory, and for
 * `wp_hash()`, the salted hash transient names are built with.
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
	 * Like core: an HMAC keyed with the site's salt for the scheme.
	 *
	 * @param string $data   Data to hash.
	 * @param string $scheme Salt scheme.
	 */
	function wp_hash( $data, $scheme = 'auth' ): string {
		return hash_hmac( 'md5', (string) $data, 'unit-test-salt-' . $scheme );
	}

	/**
	 * @param string $transient Name.
	 * @return mixed The value, or false when there is none.
	 */
	function get_transient( string $transient ) {
		return WpState::$transients[ $transient ][0] ?? false;
	}

	/**
	 * @param string $transient  Name.
	 * @param mixed  $value      Value.
	 * @param int    $expiration Lifetime in seconds.
	 */
	function set_transient( string $transient, $value, int $expiration = 0 ): bool {
		WpState::$transients[ $transient ] = [ $value, $expiration ];

		return true;
	}
}
