<?php
/**
 * Owns the lifecycle of the daily token-recheck Action Scheduler job.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Scheduler;

use HelloLog\Settings\Options;
use HelloLog\Transport\TokenVerifier;

defined( 'ABSPATH' ) || exit;

/**
 * Companion to {@see ActionSchedulerBridge}: same shape, a different
 * question. The flush job asks "is there anything to send?"; this one asks
 * "does the backend still accept this token?" — catching a suspended or
 * revoked token between `wp hellolog test` / Save-Settings calls, before the
 * queue silently fills with events the backend will reject on flush.
 *
 *  - the recurring action exists for as long as a token is CONFIGURED
 *    (valid shape), independent of the verified flag — see
 *    {@see VerifySchedule::reconcile()} for why keying on the verified flag
 *    instead would be a trap;
 *  - the `HOOK` callback is bound unconditionally so an orphaned action left
 *    by an older build self-heals (unschedules itself) instead of ticking
 *    forever with no handler, mirroring `ActionSchedulerBridge::maybe_run()`.
 */
final class VerifyScheduleBridge {

	private const HOOK  = 'hellolog_verify_token';
	private const GROUP = 'hellolog';
	/** One day, in seconds — matches `ActionSchedulerBridge`'s literal-constant style. */
	private const INTERVAL = 86400;

	public function __construct(
		private Options $options,
		private TokenVerifier $verifier
	) {
	}

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'init', [ $this, 'reconcile' ] );
	}

	/**
	 * Bring the schedule in line with the current configuration state. Runs
	 * on every `init`, so clearing the token unschedules within one request.
	 */
	public function reconcile(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		$configured = $this->options->is_configured();
		$scheduled  = as_has_scheduled_action( self::HOOK, [], self::GROUP );

		switch ( VerifySchedule::reconcile( $configured, $scheduled ) ) {
			case 'schedule':
				as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::HOOK, [], self::GROUP );
				break;
			case 'unschedule':
				as_unschedule_all_actions( self::HOOK, [], self::GROUP );
				break;
		}
	}

	/**
	 * The `HOOK` callback. Self-heals an orphaned action when no token is
	 * configured, otherwise re-checks the token and applies the outcome.
	 */
	public function run(): void {
		if ( ! $this->options->is_configured() ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::HOOK, [], self::GROUP );
			}
			return;
		}

		$status = $this->verifier->verify();

		switch ( VerifySchedule::decide( $status ) ) {
			case 'set':
				$this->options->mark_active( true );
				break;
			case 'clear':
				$this->options->mark_active( false );
				break;
		}

		if ( 200 === $status ) {
			$this->apply_api_access( $this->verifier->last_body() );
		}
	}

	/**
	 * Apply the `Options::KEY_API_ACCESS` action decided from a 200 body.
	 */
	private function apply_api_access( string $raw_body ): void {
		switch ( VerifySchedule::api_access_from_body( $raw_body ) ) {
			case 'set-true':
				$this->options->set_api_access( true );
				break;
			case 'set-false':
				$this->options->set_api_access( false );
				break;
		}
	}
}
