<?php
/**
 * Stand-ins for the WordPress calls that read the current user, resolving it
 * the way core does: through `determine_current_user` the first time.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Events\Fixtures\AuthState;

	/**
	 * @param string|null $hook_name Filter or action name; null asks whether any is running.
	 */
	function doing_filter( $hook_name = null ): bool {
		return null === $hook_name ? [] !== AuthState::$filters : in_array( $hook_name, AuthState::$filters, true );
	}

	/**
	 * Like core: while the current user is unknown, every call applies
	 * `determine_current_user` again, and its callbacks can reach back here.
	 * A nesting deeper than {@see AuthState::MAX_NESTING} throws instead of
	 * running out of memory.
	 */
	function wp_get_current_user(): object {
		++AuthState::$current_user_calls;
		if ( null !== AuthState::$current_user ) {
			return AuthState::$current_user;
		}

		++AuthState::$nesting;
		AuthState::$max_nesting = max( AuthState::$max_nesting, AuthState::$nesting );
		if ( AuthState::$nesting > AuthState::MAX_NESTING ) {
			--AuthState::$nesting;
			throw new \RuntimeException( 'determine_current_user recursed' );
		}

		AuthState::$filters[] = 'determine_current_user';
		try {
			if ( null !== AuthState::$on_determine ) {
				( AuthState::$on_determine )();
			}
		} finally {
			array_pop( AuthState::$filters );
			--AuthState::$nesting;
		}

		AuthState::$current_user = AuthState::$determined ?? (object) [
			'ID'         => 0,
			'user_login' => '',
			'roles'      => [],
		];

		return AuthState::$current_user;
	}
}
