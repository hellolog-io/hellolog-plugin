<?php
/**
 * Typed wrapper around the plugin's `hellolog_*` options.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One source of truth for option keys, defaults, and read/write coercion.
 * The Settings page, the dispatcher, and the transport all go through here
 * so a typo or schema change doesn't silently produce a wrong value.
 *
 * The endpoint URL is fixed across the whole hellolog.io fleet and never
 * stored as an option — see {@see self::endpoint_url()}.
 */
final class Options {

	/**
	 * Production backend URL. Operators only configure the site token;
	 * the endpoint itself is not user-changeable on purpose.
	 */
	public const ENDPOINT_URL = 'https://api.hellolog.io/v1/wordpress-activity-audit-log';

	/**
	 * The hosted dashboard the Vue admin SPA links out to (onboarding,
	 * "get a key", account management). Not user-changeable, same as
	 * {@see self::ENDPOINT_URL}.
	 */
	public const DASHBOARD_URL = 'https://app.hellolog.io';

	public const KEY_TOKEN          = 'hellolog_token';
	public const KEY_ANONYMIZE_IP   = 'hellolog_anonymize_ip';
	public const KEY_SENSOR_FILTERS = 'hellolog_sensor_filters';
	// `1` once the stored token successfully delivered a test event;
	// reset to `0` whenever the operator changes (or clears) the key.
	// Sensors only attach hooks when this flag is `1`, otherwise we
	// would queue thousands of events that the backend rejects.
	public const KEY_TOKEN_VERIFIED = 'hellolog_token_verified';

	public function endpoint_url(): string {
		return self::ENDPOINT_URL;
	}

	public function token(): string {
		return (string) get_option( self::KEY_TOKEN, '' );
	}

	/**
	 * Convenience predicate: are we ready to talk to the backend?
	 *
	 * Used to gate the queue dispatcher and the Activity Log admin page,
	 * neither of which has anything sensible to do without a token.
	 * A "non-empty token" is not enough — we also confirm it matches the
	 * backend's expected `goal_<env>_<prefix>_<secret>` shape so a
	 * stray paste (a UUID, a sentence, …) doesn't flip the UI to "Active".
	 */
	public function is_configured(): bool {
		return self::is_valid_token( $this->token() );
	}

	/**
	 * `true` when the stored token has at least once delivered a test
	 * event to the backend successfully. The activation flow flips this
	 * on; changing or clearing the key flips it back off.
	 *
	 * `is_configured()` is necessary but not sufficient for the plugin
	 * to start collecting — without `is_active()` the sensors stay
	 * detached.
	 */
	public function is_active(): bool {
		return $this->is_configured() && (bool) get_option( self::KEY_TOKEN_VERIFIED, false );
	}

	public function mark_active( bool $active ): void {
		update_option( self::KEY_TOKEN_VERIFIED, $active ? 1 : 0 );
	}

	/**
	 * Backend's token shape: `goal_<env>_<prefix>_<secret>`, with
	 * env ∈ {live, test}, prefix exactly 8 chars and secret exactly
	 * 40 chars. Kept in lock-step with `internal/token/token.go::Parse`.
	 */
	public static function is_valid_token( string $token ): bool {
		return 1 === preg_match( '/^goal_(live|test)_[a-z0-9]{8}_[a-z0-9]{40}$/', $token );
	}

	public function anonymize_ip(): bool {
		return (bool) get_option( self::KEY_ANONYMIZE_IP, false );
	}

	/**
	 * @return array<string, bool>
	 */
	public function sensor_filters(): array {
		$raw = get_option( self::KEY_SENSOR_FILTERS, [] );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $key => $value ) {
			if ( is_string( $key ) ) {
				$out[ $key ] = (bool) $value;
			}
		}
		return $out;
	}
}
