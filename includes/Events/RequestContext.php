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

		if ( function_exists( 'wp_get_current_user' ) && ! self::is_determining_user() ) {
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
		$session_id = function_exists( 'wp_get_session_token' )
			? self::session_fingerprint( (string) wp_get_session_token() )
			: null;

		return new self( $user_id, $username, $roles, $ip, $ua, $session_id );
	}

	/**
	 * Whether WordPress is still working out who the current user is.
	 *
	 * Some sensors fire from inside that work: an application-password
	 * sign-in is reported by `wp_validate_application_password()`, a
	 * `determine_current_user` callback, before the user is the current one.
	 * Reading the current user there (`wp_get_current_user()`,
	 * `get_current_user_id()`) applies `determine_current_user` again, which
	 * reports the sign-in again, until the request runs out of memory. Code
	 * that may run there checks this first; nobody is signed in yet, and the
	 * sensors that fire there name the user themselves.
	 */
	public static function is_determining_user(): bool {
		return function_exists( 'doing_filter' ) && doing_filter( 'determine_current_user' );
	}

	/**
	 * The raw session token is part of the auth cookie, so it never leaves
	 * the site. Its SHA-256 is what core itself keys `session_tokens` by,
	 * which keeps events from one session correlatable on the backend.
	 */
	public static function session_fingerprint( string $token ): ?string {
		return '' === $token ? null : hash( 'sha256', $token );
	}

	/**
	 * The IP the request comes from, as {@see self::capture()} reads it
	 * (never anonymized here); null when there is none. Reads only the
	 * request, so it is safe while the current user is being determined.
	 */
	public static function client_ip(): ?string {
		return self::detect_ip();
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

	public static function anonymize( string $ip ): string {
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
