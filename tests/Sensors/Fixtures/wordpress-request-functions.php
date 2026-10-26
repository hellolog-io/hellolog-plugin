<?php
/**
 * Stand-ins for the WordPress calls that tell who is making the current
 * request, through which entry point and with which method.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Sensors\Fixtures\RequestState;

	function get_current_user_id(): int {
		++RequestState::$user_id_reads;

		return RequestState::$user_id;
	}

	function is_admin(): bool {
		return RequestState::$admin;
	}

	function wp_doing_ajax(): bool {
		return RequestState::$ajax;
	}

	function wp_doing_cron(): bool {
		return RequestState::$cron;
	}

	/**
	 * @param string $value A string; core also takes arrays, the policy never passes one.
	 */
	function wp_unslash( $value ): string {
		return stripslashes( (string) $value );
	}

	/**
	 * @param string $value A string; the policy only reads the request method.
	 */
	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}
