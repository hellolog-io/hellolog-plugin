<?php
/**
 * Stand-ins for the WordPress calls that tell who is making the current
 * request and through which entry point.
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
}
