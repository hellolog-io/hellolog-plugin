<?php
/**
 * AJAX handler for the Settings → Connection "Send test event" button.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Admin;

use HelloLog\Scheduler\VerifySchedule;
use HelloLog\Settings\Options;
use HelloLog\Transport\ApiClient;
use HelloLog\Transport\TokenVerifier;

defined( 'ABSPATH' ) || exit;

/**
 * One-shot diagnostics call from the admin UI. Bypasses the queue: builds a
 * minimal "ping" payload and POSTs it directly through {@see ApiClient}.
 * Operator gets an immediate status + body in the response.
 *
 * A successful round-trip also runs an immediate `GET /verify` — the same
 * call {@see \HelloLog\Scheduler\VerifyScheduleBridge} makes on its daily
 * tick — so a plan upgrade's `api_access` flag lands without waiting for
 * the next recheck. That follow-up call is best-effort and never changes
 * this handler's response: a transport error or non-200 just leaves
 * `Options::KEY_API_ACCESS` untouched, same as the daily job does on a
 * transient failure.
 */
final class TestConnectionHandler {

	public const ACTION = 'hellolog_test_connection';

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'handle' ] );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'error' => 'forbidden' ], 403 );
		}
		check_ajax_referer( self::ACTION, 'nonce' );

		$options = new Options();
		if ( '' === $options->endpoint_url() || '' === $options->token() ) {
			wp_send_json_error( [ 'error' => 'transport not configured' ], 400 );
		}

		$client = new ApiClient( $options->endpoint_url(), $options->token() );
		$batch  = wp_json_encode(
			[
				'batch_id' => 'connection-test-' . substr( md5( (string) microtime( true ) ), 0, 8 ),
				'events'   => [
					[
						'code'        => 9999,
						'occurred_at' => gmdate( 'Y-m-d\TH:i:s.v\Z' ),
						'severity'    => 'info',
						'object'      => 'system',
						'event_type'  => 'connection-test',
						'message'     => 'Test event from hellolog Settings page.',
					],
				],
			]
		);
		if ( ! is_string( $batch ) ) {
			wp_send_json_error( [ 'error' => 'encode failed' ], 500 );
		}

		$result = $client->post_batch( $batch );
		// A successful round-trip is what flips the license from "stored"
		// to "active". Sensors only attach after this, so it's the
		// gatekeeping step.
		$options->mark_active( $result->ok );
		if ( $result->ok ) {
			$this->refresh_api_access( $options );
			wp_send_json_success(
				[
					'status' => $result->status,
					'body'   => $result->body,
				]
			);
		}
		wp_send_json_error(
			[
				'status'    => $result->status,
				'body'      => $result->body,
				'retryable' => $result->retryable,
			],
			$result->status > 0 ? $result->status : 502
		);
	}

	/**
	 * Best-effort `GET /verify` right after a successful test event.
	 * Mirrors {@see \HelloLog\Scheduler\VerifyScheduleBridge::run()}'s
	 * handling of `Options::KEY_API_ACCESS`: only a 200 body is inspected,
	 * and a legacy/empty body (no `api_access` key) leaves the option
	 * untouched rather than forcing a Free-plan upsell.
	 */
	private function refresh_api_access( Options $options ): void {
		$verifier = new TokenVerifier( $options->endpoint_url(), $options->token() );
		if ( 200 !== $verifier->verify() ) {
			return;
		}
		switch ( VerifySchedule::api_access_from_body( $verifier->last_body() ) ) {
			case 'set-true':
				$options->set_api_access( true );
				break;
			case 'set-false':
				$options->set_api_access( false );
				break;
		}
	}
}
