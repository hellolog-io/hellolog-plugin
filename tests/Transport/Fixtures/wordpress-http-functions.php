<?php
/**
 * Stand-ins for the WordPress HTTP calls the transport makes.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Transport\Fixtures\HttpState;

	/**
	 * Records the request and answers with {@see HttpState::$status}.
	 *
	 * @param string               $url  Address.
	 * @param array<string, mixed> $args Request arguments.
	 * @return array<string, mixed>
	 */
	function wp_remote_post( string $url, array $args = [] ): array {
		HttpState::$requests[] = [ $url, $args ];

		return [
			'response' => [ 'code' => HttpState::$status ],
			'body'     => '{}',
		];
	}

	/**
	 * @param mixed $thing Any value.
	 */
	function is_wp_error( $thing ): bool {
		return false;
	}

	/**
	 * @param array<string, mixed> $response Response.
	 * @return int
	 */
	function wp_remote_retrieve_response_code( array $response ) {
		return $response['response']['code'];
	}

	/**
	 * @param array<string, mixed> $response Response.
	 */
	function wp_remote_retrieve_body( array $response ): string {
		return $response['body'];
	}

	function home_url( string $path = '' ): string {
		return 'https://example.test' . $path;
	}

	/**
	 * @param string $url       Address.
	 * @param int    $component Part to return.
	 * @return mixed
	 */
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this is the stand-in.
	}
}
