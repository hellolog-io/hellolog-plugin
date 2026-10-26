<?php
/**
 * Classifies where the current request comes from.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Names the entry point of a request (admin screen, REST call, cron run,
 * Action Scheduler job, WP-CLI…). Stamped on every event so a background job
 * is never mistaken for something a person did.
 */
final class RequestSource {

	public const ADMIN            = 'admin';
	public const FRONTEND         = 'frontend';
	public const REST             = 'rest';
	public const AJAX             = 'ajax';
	public const CRON             = 'cron';
	public const ACTION_SCHEDULER = 'action-scheduler';
	public const CLI              = 'cli';
	public const XMLRPC           = 'xmlrpc';

	/**
	 * Pure classification of an environment snapshot.
	 *
	 * Precedence, strongest first: cli, action-scheduler, cron, xmlrpc, rest,
	 * ajax, admin, frontend. A cron run or a REST call can load admin code,
	 * and an Action Scheduler job usually runs inside a cron or loopback
	 * request, so the more specific entry point has to win.
	 *
	 * @param array<string, bool> $env Flags: cli, action_scheduler, cron,
	 *                                 xmlrpc, rest, ajax, admin. Missing keys
	 *                                 count as false.
	 */
	public static function detect( array $env ): string {
		if ( ! empty( $env['cli'] ) ) {
			return self::CLI;
		}
		if ( ! empty( $env['action_scheduler'] ) ) {
			return self::ACTION_SCHEDULER;
		}
		if ( ! empty( $env['cron'] ) ) {
			return self::CRON;
		}
		if ( ! empty( $env['xmlrpc'] ) ) {
			return self::XMLRPC;
		}
		if ( ! empty( $env['rest'] ) ) {
			return self::REST;
		}
		if ( ! empty( $env['ajax'] ) ) {
			return self::AJAX;
		}
		if ( ! empty( $env['admin'] ) ) {
			return self::ADMIN;
		}

		return self::FRONTEND;
	}

	/**
	 * Whether the source is unattended work with no person behind it.
	 */
	public static function is_background( string $source ): bool {
		return self::CRON === $source || self::ACTION_SCHEDULER === $source;
	}

	/**
	 * Classify the live request. Every WordPress call is guarded so this
	 * returns `frontend` instead of fataling when run outside WordPress.
	 */
	public static function current(): string {
		if ( function_exists( 'wp_is_serving_rest_request' ) ) {
			$rest = wp_is_serving_rest_request();
		} else {
			$rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
		}

		return self::detect(
			[
				'cli'              => defined( 'WP_CLI' ) && WP_CLI,
				'action_scheduler' => ActionSchedulerContext::is_running(),
				'cron'             => function_exists( 'wp_doing_cron' ) && wp_doing_cron(),
				'xmlrpc'           => defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST,
				'rest'             => (bool) $rest,
				'ajax'             => function_exists( 'wp_doing_ajax' ) && wp_doing_ajax(),
				'admin'            => function_exists( 'is_admin' ) && is_admin(),
			]
		);
	}
}
