<?php
/**
 * REST endpoints backing the Vue admin SPA.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Rest;

use HelloLog\Plugin;
use HelloLog\Settings\Options;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * `/wp-json/hellolog/v1/settings` — read/write the options-backed config.
 *
 * Replaces the `options.php` form workflow now that the admin UI is a Vue
 * SPA. WP-native nonce flow (`X-WP-Nonce` header + `current_user_can`).
 */
final class SettingsController {

	public const NAMESPACE = 'hellolog/v1';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		$can_manage = static fn(): bool => current_user_can( 'manage_options' );

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => 'GET',
					'permission_callback' => $can_manage,
					'callback'            => [ $this, 'get_settings' ],
				],
				[
					'methods'             => 'POST',
					'permission_callback' => $can_manage,
					'callback'            => [ $this, 'save_settings' ],
				],
			]
		);
	}

	public function get_settings(): WP_REST_Response {
		$opts    = new Options();
		$sensors = $this->sensor_descriptions( $opts );

		return rest_ensure_response(
			[
				'endpoint'      => Options::ENDPOINT_URL,
				'isConfigured'  => $opts->is_configured(),
				'tokenLastFour' => '' !== $opts->token() ? substr( $opts->token(), -4 ) : '',
				'anonymizeIp'   => $opts->anonymize_ip(),
				'sensors'       => $sensors,
			]
		);
	}

	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}

		// `clearToken: true` wipes the bearer so the operator can sever the
		// link to hellolog.io from inside the SPA. We check it first because if
		// both `token` and `clearToken` are sent we treat the explicit clear
		// as the operator's intent.
		if ( ! empty( $body['clearToken'] ) ) {
			delete_option( Options::KEY_TOKEN );
			// Clearing the key invalidates the license — sensors stop on
			// the next boot.
			( new Options() )->mark_active( false );
		} elseif ( isset( $body['token'] ) && is_string( $body['token'] ) && '' !== trim( $body['token'] ) ) {
			$candidate = sanitize_text_field( trim( $body['token'] ) );
			if ( ! Options::is_valid_token( $candidate ) ) {
				return new WP_REST_Response(
					[ 'message' => 'Invalid API key format. Expected: goal_<env>_<prefix>_<secret>.' ],
					400
				);
			}
			update_option( Options::KEY_TOKEN, $candidate );
			// New key → reset the verified flag. The SPA fires a test
			// event right after Save; that's what flips it back on.
			( new Options() )->mark_active( false );
		}
		if ( array_key_exists( 'anonymizeIp', $body ) ) {
			update_option( Options::KEY_ANONYMIZE_IP, (bool) $body['anonymizeIp'] );
		}
		if ( isset( $body['sensorFilters'] ) && is_array( $body['sensorFilters'] ) ) {
			// Preserve BOTH `true` (disabled) and `false` (explicitly
			// enabled) values so the operator can override a sensor
			// that ships off by default. Plugin::assemble_services()
			// distinguishes between "missing key" (apply default) and
			// "explicitly false" (operator opted in).
			$clean = [];
			foreach ( $body['sensorFilters'] as $key => $disabled ) {
				if ( is_string( $key ) ) {
					$clean[ sanitize_key( $key ) ] = (bool) $disabled;
				}
			}
			update_option( Options::KEY_SENSOR_FILTERS, $clean );
		}

		return $this->get_settings();
	}

	/**
	 * @return array<int, array{key: string, label: string, enabled: bool}>
	 */
	private function sensor_descriptions( Options $opts ): array {
		$disabled = $opts->sensor_filters();
		$sensors  = Plugin::instance()->sensors()->sensors();
		$out      = [];
		foreach ( $sensors as $key => $_sensor ) {
			$out[] = [
				'key'     => $key,
				'label'   => ucwords( str_replace( [ '-', '_' ], ' ', $key ) ),
				'enabled' => empty( $disabled[ $key ] ),
			];
		}
		return $out;
	}
}
