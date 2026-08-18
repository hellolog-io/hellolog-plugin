<?php
/**
 * HTTP client for the backend's token-recheck endpoint.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * Same request shape as {@see ApiClient}/{@see EventsReader}: Bearer token +
 * `X-Site-Domain`, nothing else. Used by the daily token-recheck job
 * ({@see \HelloLog\Scheduler\VerifyScheduleBridge}), never by a
 * user-triggered request path — a network hiccup here just means the
 * previous verified state is left untouched until the next tick.
 */
final class TokenVerifier {

	private const TIMEOUT_SEC = 10;

	/** Raw body of the most recent `verify()` response; '' until a 200. */
	private string $last_body = '';

	public function __construct(
		private string $endpoint_url,
		private string $token
	) {
	}

	public function is_configured(): bool {
		return '' !== $this->endpoint_url && '' !== $this->token;
	}

	/**
	 * @return int HTTP status code, or `0` for a transport-level failure
	 *             (`WP_Error`: timeout, DNS, connection refused, ...).
	 */
	public function verify(): int {
		$this->last_body = '';

		if ( ! $this->is_configured() ) {
			return 0;
		}

		$response = wp_remote_get(
			$this->endpoint_url . '/verify',
			[
				'timeout' => self::TIMEOUT_SEC,
				'headers' => [
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/json',
					'X-Site-Domain' => $this->site_domain(),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return 0;
		}

		$this->last_body = (string) wp_remote_retrieve_body( $response );
		return (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Raw body captured by the most recent {@see self::verify()} call.
	 * Callers only care about this on a 200 status; '' otherwise.
	 */
	public function last_body(): string {
		return $this->last_body;
	}

	/**
	 * Hostname the WordPress install lives on, sent as `X-Site-Domain` so
	 * the backend can pin a token to its issuing domain.
	 */
	private function site_domain(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}
}
