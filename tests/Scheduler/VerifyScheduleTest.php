<?php
/**
 * Tests for the daily token-recheck feature: {@see HelloLog\Scheduler\VerifySchedule},
 * {@see HelloLog\Transport\TokenVerifier} and {@see HelloLog\Scheduler\VerifyScheduleBridge}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Scheduler;

use HelloLog\Scheduler\VerifySchedule;
use HelloLog\Scheduler\VerifyScheduleBridge;
use HelloLog\Settings\Options;
use HelloLog\Transport\TokenVerifier;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wp-stubs.php';

/**
 * `VerifySchedule` itself is pure (no WP dependencies), tested the same way
 * as `FlushScheduleTest`. `TokenVerifier`/`VerifyScheduleBridge` do touch WP
 * (`wp_remote_get`, options, Action Scheduler), so those tests run against
 * the lightweight stand-ins in `wp-stubs.php` — in particular a genuinely
 * working `pre_http_request` filter, so the request that reaches "the wire"
 * is asserted directly rather than assumed.
 */
final class VerifyScheduleTest extends TestCase {

	private const HOOK = 'hellolog_verify_token';

	private string $token;

	protected function setUp(): void {
		hellolog_test_reset_wp_stubs();
		// Matches Options::is_valid_token()'s goal_<env>_<8>_<40> shape.
		$this->token = 'goal_live_' . str_repeat( 'a', 8 ) . '_' . str_repeat( 'b', 40 );
	}

	// -- VerifySchedule::reconcile() — pure, mirrors FlushScheduleTest -----

	public function test_configured_and_not_scheduled_schedules(): void {
		$this->assertSame( 'schedule', VerifySchedule::reconcile( true, false ) );
	}

	public function test_configured_and_scheduled_is_noop(): void {
		$this->assertSame( 'noop', VerifySchedule::reconcile( true, true ) );
	}

	public function test_unconfigured_and_scheduled_unschedules(): void {
		$this->assertSame( 'unschedule', VerifySchedule::reconcile( false, true ) );
	}

	public function test_unconfigured_and_not_scheduled_is_noop(): void {
		$this->assertSame( 'noop', VerifySchedule::reconcile( false, false ) );
	}

	// -- VerifySchedule::decide() — pure -----------------------------------

	public function test_200_sets_the_flag(): void {
		$this->assertSame( 'set', VerifySchedule::decide( 200 ) );
	}

	public function test_401_clears_the_flag(): void {
		$this->assertSame( 'clear', VerifySchedule::decide( 401 ) );
	}

	public function test_403_clears_the_flag(): void {
		$this->assertSame( 'clear', VerifySchedule::decide( 403 ) );
	}

	public function test_wp_error_status_zero_leaves_flag_unchanged(): void {
		$this->assertSame( 'unchanged', VerifySchedule::decide( 0 ) );
	}

	public function test_server_error_leaves_flag_unchanged(): void {
		$this->assertSame( 'unchanged', VerifySchedule::decide( 500 ) );
	}

	public function test_other_statuses_leave_flag_unchanged(): void {
		$this->assertSame( 'unchanged', VerifySchedule::decide( 204 ) );
		$this->assertSame( 'unchanged', VerifySchedule::decide( 429 ) );
		$this->assertSame( 'unchanged', VerifySchedule::decide( 404 ) );
	}

	// -- TokenVerifier: request shape + status mapping ----------------------

	public function test_verify_sends_bearer_and_site_domain_headers_to_the_verify_path(): void {
		$seen = [];
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$seen ) {
				$seen['url']  = $url;
				$seen['args'] = $args;
				return [
					'response' => [ 'code' => 200 ],
					'body'     => '{}',
				];
			}
		);

		( new TokenVerifier( Options::ENDPOINT_URL, $this->token ) )->verify();

		$this->assertSame( Options::ENDPOINT_URL . '/verify', $seen['url'] );
		$this->assertSame( 'Bearer ' . $this->token, $seen['args']['headers']['Authorization'] );
		$this->assertSame( 'verify-test.example', $seen['args']['headers']['X-Site-Domain'] );
	}

	public function test_verify_returns_the_http_status_code(): void {
		add_filter( 'pre_http_request', fn() => [ 'response' => [ 'code' => 200 ] ] );
		$this->assertSame( 200, ( new TokenVerifier( Options::ENDPOINT_URL, $this->token ) )->verify() );
	}

	public function test_verify_returns_zero_on_wp_error(): void {
		add_filter( 'pre_http_request', fn() => new \WP_Error( 'http_request_failed', 'timed out' ) );
		$this->assertSame( 0, ( new TokenVerifier( Options::ENDPOINT_URL, $this->token ) )->verify() );
	}

	public function test_verify_skips_the_request_when_not_configured(): void {
		$called = false;
		add_filter(
			'pre_http_request',
			function () use ( &$called ) {
				$called = true;
				return [ 'response' => [ 'code' => 200 ] ];
			}
		);

		$this->assertSame( 0, ( new TokenVerifier( '', '' ) )->verify() );
		$this->assertFalse( $called );
	}

	// -- VerifyScheduleBridge::run() — the full 200/401/403/error mapping --

	private function options_with_stored_token( bool $verified ): Options {
		$GLOBALS['__hlg_options'][ Options::KEY_TOKEN ]          = $this->token;
		$GLOBALS['__hlg_options'][ Options::KEY_TOKEN_VERIFIED ] = $verified ? 1 : 0;
		return new Options();
	}

	private function bridge_with_response( Options $options, $response ): VerifyScheduleBridge {
		add_filter( 'pre_http_request', fn() => $response );
		$verifier = new TokenVerifier( Options::ENDPOINT_URL, $options->token() );
		return new VerifyScheduleBridge( $options, $verifier );
	}

	public function test_run_sets_the_flag_on_200(): void {
		$options = $this->options_with_stored_token( false );
		$this->bridge_with_response( $options, [ 'response' => [ 'code' => 200 ] ] )->run();
		$this->assertSame( 1, get_option( Options::KEY_TOKEN_VERIFIED ) );
	}

	public function test_run_clears_the_flag_on_401(): void {
		$options = $this->options_with_stored_token( true );
		$this->bridge_with_response( $options, [ 'response' => [ 'code' => 401 ] ] )->run();
		$this->assertSame( 0, get_option( Options::KEY_TOKEN_VERIFIED ) );
	}

	public function test_run_clears_the_flag_on_403(): void {
		$options = $this->options_with_stored_token( true );
		$this->bridge_with_response( $options, [ 'response' => [ 'code' => 403 ] ] )->run();
		$this->assertSame( 0, get_option( Options::KEY_TOKEN_VERIFIED ) );
	}

	public function test_run_leaves_the_flag_unchanged_on_wp_error(): void {
		$options = $this->options_with_stored_token( true );
		$this->bridge_with_response( $options, new \WP_Error( 'http_request_failed', 'timed out' ) )->run();
		$this->assertSame( 1, get_option( Options::KEY_TOKEN_VERIFIED ) );
	}

	public function test_run_leaves_the_flag_unchanged_on_500(): void {
		$options = $this->options_with_stored_token( true );
		$this->bridge_with_response( $options, [ 'response' => [ 'code' => 500 ] ] )->run();
		$this->assertSame( 1, get_option( Options::KEY_TOKEN_VERIFIED ) );
	}

	public function test_run_unschedules_itself_when_no_token_is_configured(): void {
		$options  = new Options();
		$verifier = new TokenVerifier( Options::ENDPOINT_URL, '' );
		$GLOBALS['__hlg_as_scheduled'][ self::HOOK ] = 1;

		( new VerifyScheduleBridge( $options, $verifier ) )->run();

		$this->assertSame( 1, $GLOBALS['__hlg_as_calls']['unschedule'] );
		$this->assertArrayNotHasKey( Options::KEY_TOKEN_VERIFIED, $GLOBALS['__hlg_options'] );
	}

	// -- VerifyScheduleBridge::reconcile() — schedule lifecycle + idempotency

	public function test_reconcile_schedules_once_when_configured_and_not_yet_scheduled(): void {
		$options  = $this->options_with_stored_token( false );
		$verifier = new TokenVerifier( Options::ENDPOINT_URL, $options->token() );

		( new VerifyScheduleBridge( $options, $verifier ) )->reconcile();

		$this->assertSame( 1, $GLOBALS['__hlg_as_calls']['schedule'] );
	}

	public function test_reconcile_is_idempotent_when_already_scheduled(): void {
		$options  = $this->options_with_stored_token( false );
		$verifier = new TokenVerifier( Options::ENDPOINT_URL, $options->token() );
		$GLOBALS['__hlg_as_scheduled'][ self::HOOK ] = 1;

		$bridge = new VerifyScheduleBridge( $options, $verifier );
		$bridge->reconcile();
		$bridge->reconcile();

		$this->assertSame( 0, $GLOBALS['__hlg_as_calls']['schedule'] );
	}

	public function test_reconcile_unschedules_when_token_cleared(): void {
		$options  = new Options();
		$verifier = new TokenVerifier( Options::ENDPOINT_URL, '' );
		$GLOBALS['__hlg_as_scheduled'][ self::HOOK ] = 1;

		( new VerifyScheduleBridge( $options, $verifier ) )->reconcile();

		$this->assertSame( 1, $GLOBALS['__hlg_as_calls']['unschedule'] );
		$this->assertArrayNotHasKey( self::HOOK, $GLOBALS['__hlg_as_scheduled'] );
	}
}
