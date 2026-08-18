<?php
/**
 * Minimal WordPress function stand-ins for VerifyScheduleTest.
 *
 * @package HelloLog\Tests
 *
 * No WordPress core is loaded by this test suite (see tests/bootstrap.php),
 * so `VerifyScheduleTest` needs just enough of the real WP HTTP/filter/
 * options/Action Scheduler surface to exercise `TokenVerifier` and
 * `VerifyScheduleBridge` end to end — in particular a genuinely working
 * `pre_http_request` filter, so `wp_remote_get()` really does route through
 * it the same way WP core's `WP_Http::request()` does. Everything here is
 * gated behind `function_exists()`/`class_exists()` so it is safe even if a
 * future test file also needs a subset of these.
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Stand-in for WP core's WP_Error, just enough for is_wp_error()/
	 * get_error_message() round-tripping in these tests.
	 */
	class WP_Error {
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->message = $message;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

/**
 * @var array<string, array<int, array{priority:int, callback:callable}>>
 */
$GLOBALS['__hlg_filters'] = [];
/** @var array<string, mixed> */
$GLOBALS['__hlg_options'] = [];
/** @var array<string, int> */
$GLOBALS['__hlg_as_scheduled'] = [];
/** @var array<string, int> */
$GLOBALS['__hlg_as_calls'] = [
	'schedule'   => 0,
	'unschedule' => 0,
];

/** Reset all stub state between tests. */
function hellolog_test_reset_wp_stubs(): void {
	$GLOBALS['__hlg_filters']       = [];
	$GLOBALS['__hlg_options']       = [];
	$GLOBALS['__hlg_as_scheduled']  = [];
	$GLOBALS['__hlg_as_calls']      = [
		'schedule'   => 0,
		'unschedule' => 0,
	];
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10 ): bool {
		$GLOBALS['__hlg_filters'][ $hook ][] = [
			'priority' => $priority,
			'callback' => $callback,
		];
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10 ): bool {
		return add_filter( $hook, $callback, $priority );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		$extra_args = array_slice( func_get_args(), 2 );
		$hooked     = $GLOBALS['__hlg_filters'][ $hook ] ?? [];
		usort( $hooked, fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
		foreach ( $hooked as $entry ) {
			$value = call_user_func_array( $entry['callback'], array_merge( [ $value ], $extra_args ) );
		}
		return $value;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Mirrors WP core: short-circuits entirely through `pre_http_request`.
	 * There is no real network fallback here — every test must register a
	 * `pre_http_request` filter before calling code that reaches this.
	 */
	function wp_remote_get( string $url, array $args = [] ) {
		return apply_filters( 'pre_http_request', false, $args, $url );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://verify-test.example' . $path;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['__hlg_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value ): bool {
		$GLOBALS['__hlg_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	function as_has_scheduled_action( string $hook, array $args = [], string $group = '' ): bool {
		return ! empty( $GLOBALS['__hlg_as_scheduled'][ $hook ] );
	}
}

if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = [], string $group = '' ): int {
		$GLOBALS['__hlg_as_scheduled'][ $hook ] = 1;
		++$GLOBALS['__hlg_as_calls']['schedule'];
		return 1;
	}
}

if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	function as_unschedule_all_actions( string $hook, array $args = [], string $group = '' ): void {
		unset( $GLOBALS['__hlg_as_scheduled'][ $hook ] );
		++$GLOBALS['__hlg_as_calls']['unschedule'];
	}
}
