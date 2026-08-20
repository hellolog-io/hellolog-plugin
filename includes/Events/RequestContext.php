<?php
/**
 * Captures per-request context (user, IP, UA, session) for events.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshot of who and where the current request comes from. Cheap to build
 * (no DB calls) and re-used across every event emitted within one request.
 */
final class RequestContext {

	public function __construct(
		public ?int $user_id,
		public ?string $username,
		/** @var array<int, string> */
		public array $roles,
		public ?string $client_ip,
		public ?string $user_agent,
		public ?string $session_id
	) {
	}

	public static function capture( bool $anonymize_ip = false ): self {
		$user_id  = null;
		$username = null;
		$roles    = [];

		if ( function_exists( 'wp_get_current_user' ) && ! self::is_authenticating() ) {
			$user = wp_get_current_user();
			if ( $user && $user->ID > 0 ) {
				$user_id  = (int) $user->ID;
				$username = (string) $user->user_login;
				$roles    = array_values( (array) $user->roles );
			}
		}

		$ip = self::detect_ip();
		if ( $ip && $anonymize_ip ) {
			$ip = self::anonymize( $ip );
		}

		$ua         = isset( $_SERVER['HTTP_USER_AGENT'] )
			? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 )
			: null;
		$session_id = function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : null;
		if ( '' === $session_id ) {
			$session_id = null;
		}

		return new self( $user_id, $username, $roles, $ip, $ua, $session_id );
	}

	/**
	 * True while WP core is still resolving who the current user is.
	 *
	 * Some sensors fire from inside that resolution — e.g.
	 * `application_password_did_authenticate` is emitted by
	 * `wp_validate_application_password()`, itself a `determine_current_user`
	 * callback. `$current_user` is not set yet at that point, so calling
	 * `wp_get_current_user()` would re-run `determine_current_user`, re-fire
	 * the sensor, and recurse until memory is exhausted. Sensors that fire
	 * there already carry the user in their own payload.
	 */
	private static function is_authenticating(): bool {
		return function_exists( 'doing_filter' ) && doing_filter( 'determine_current_user' );
	}

	private static function detect_ip(): ?string {
		// Trust the request-time IP only; reverse-proxy headers are
		// out of scope for this capture path — operators that need
		// X-Forwarded-For handling should configure it on the LB.
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		return ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) ? $ip : null;
	}

	private static function anonymize( string $ip ): string {
		if ( str_contains( $ip, ':' ) ) {
			// IPv6 — keep the /48 prefix, zero the rest.
			$parts = array_slice( explode( ':', $ip ), 0, 3 );
			return implode( ':', $parts ) . '::';
		}

		// IPv4 — zero the last octet (/24 anonymization).
		$parts = explode( '.', $ip );
		if ( 4 !== count( $parts ) ) {
			return $ip;
		}
		$parts[3] = '0';
		return implode( '.', $parts );
	}
}
