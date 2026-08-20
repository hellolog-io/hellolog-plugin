<?php
/**
 * Minimal WordPress function stand-ins for RequestContextTest.
 *
 * @package HelloLog\Tests
 *
 * No WordPress core is loaded by this suite (see tests/bootstrap.php); these
 * cover exactly the surface `RequestContext::capture()` touches. Everything is
 * gated behind `function_exists()` so another test file may safely provide
 * its own version of any of them.
 */

declare(strict_types=1);

$GLOBALS['__hlg_rc_current_user_calls'] = 0;
$GLOBALS['__hlg_rc_doing_filter']       = [];
$GLOBALS['__hlg_rc_current_user']       = null;

if ( ! function_exists( 'wp_get_current_user' ) ) {
	/**
	 * Counts calls and returns the configured user (or an anonymous ID 0 user).
	 */
	function wp_get_current_user() {
		++$GLOBALS['__hlg_rc_current_user_calls'];
		return $GLOBALS['__hlg_rc_current_user'] ?? (object) [
			'ID'         => 0,
			'user_login' => '',
			'roles'      => [],
		];
	}
}

if ( ! function_exists( 'doing_filter' ) ) {
	/**
	 * @param string|null $hook_name
	 */
	function doing_filter( $hook_name = null ): bool {
		$running = $GLOBALS['__hlg_rc_doing_filter'];
		return null === $hook_name ? [] !== $running : in_array( $hook_name, $running, true );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
