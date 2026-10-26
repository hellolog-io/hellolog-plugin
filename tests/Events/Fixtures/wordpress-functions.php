<?php
/**
 * Stand-ins for the few WordPress functions the dispatcher uses when present.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Events\Fixtures\WpCalls;

	function do_action( string $hook, ...$args ): void {
		WpCalls::$actions[] = [ $hook, $args ];
		if ( WpCalls::$action_throws ) {
			throw new \RuntimeException( 'a callback failed' );
		}
	}

	function wp_json_encode( $data, int $flags = 0, int $depth = 512 ) {
		WpCalls::$json_flags[] = $flags;

		return json_encode( $data, $flags, $depth );
	}
}
