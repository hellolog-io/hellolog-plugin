<?php
/**
 * Tests for {@see HelloLog\Events\RequestContext::capture()}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Events;

use HelloLog\Events\RequestContext;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/request-context-stubs.php';

/**
 * Regression: `AppPasswordsSensor::on_authenticate()` fires from inside
 * `wp_validate_application_password()`, i.e. while WP core is still running
 * the `determine_current_user` filter. At that point `$current_user` is not
 * set yet, so calling `wp_get_current_user()` re-enters
 * `_wp_get_current_user()` → `determine_current_user` → the app-password
 * validator → our sensor → `wp_get_current_user()` … an unbounded recursion
 * that ends in "Allowed memory size exhausted" on every Application-Password
 * authenticated REST request (seen live on szerelvenyuzlet.hu, 2026-08-20).
 */
final class RequestContextTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['__hlg_rc_current_user_calls'] = 0;
		$GLOBALS['__hlg_rc_doing_filter']       = [];
		$GLOBALS['__hlg_rc_current_user']       = null;
		$_SERVER['REMOTE_ADDR']                 = '203.0.113.7';
		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_does_not_resolve_current_user_while_determine_current_user_is_running(): void {
		$GLOBALS['__hlg_rc_doing_filter'] = [ 'determine_current_user' ];

		$ctx = RequestContext::capture();

		$this->assertSame( 0, $GLOBALS['__hlg_rc_current_user_calls'], 'wp_get_current_user() must not be called mid-authentication' );
		$this->assertNull( $ctx->user_id );
		$this->assertNull( $ctx->username );
		$this->assertSame( [], $ctx->roles );
		$this->assertSame( '203.0.113.7', $ctx->client_ip, 'the rest of the context is still captured' );
	}

	public function test_resolves_current_user_outside_authentication(): void {
		$GLOBALS['__hlg_rc_current_user'] = (object) [
			'ID'         => 42,
			'user_login' => 'editor',
			'roles'      => [ 'editor' ],
		];

		$ctx = RequestContext::capture();

		$this->assertSame( 1, $GLOBALS['__hlg_rc_current_user_calls'] );
		$this->assertSame( 42, $ctx->user_id );
		$this->assertSame( 'editor', $ctx->username );
		$this->assertSame( [ 'editor' ], $ctx->roles );
	}
}
