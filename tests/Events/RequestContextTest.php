<?php
/**
 * Tests for {@see HelloLog\Events\RequestContext}'s pure helpers.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Events;

use HelloLog\Events\RequestContext;
use HelloLog\Tests\Events\Fixtures\AuthState;
use PHPUnit\Framework\TestCase;

/**
 * The two helpers `capture()` delegates to are pure and pinned directly.
 * `capture()` itself reads the current user through WordPress, so those
 * tests run in their own process with `Fixtures/wordpress-auth-functions.php`.
 */
final class RequestContextTest extends TestCase {

	public function test_session_fingerprint_never_returns_the_raw_token(): void {
		$token = str_repeat( 'a1', 21 );
		$this->assertSame( hash( 'sha256', $token ), RequestContext::session_fingerprint( $token ) );
		$this->assertNotSame( $token, RequestContext::session_fingerprint( $token ) );
	}

	public function test_session_fingerprint_is_null_without_a_session(): void {
		$this->assertNull( RequestContext::session_fingerprint( '' ) );
	}

	public function test_anonymize_zeroes_the_last_ipv4_octet(): void {
		$this->assertSame( '203.0.113.0', RequestContext::anonymize( '203.0.113.77' ) );
	}

	public function test_anonymize_keeps_the_ipv6_48_prefix(): void {
		$this->assertSame( '2001:db8:85a3::', RequestContext::anonymize( '2001:db8:85a3:8d3:1319:8a2e:370:7348' ) );
	}

	/**
	 * Sensors can fire while WordPress is still working out who the current
	 * user is: an application-password sign-in is reported from inside
	 * `determine_current_user`. Reading the current user there applies that
	 * filter again, which reports the sign-in again, without end.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_current_user_is_not_read_while_it_is_being_determined(): void {
		require_once __DIR__ . '/Fixtures/wordpress-auth-functions.php';
		AuthState::$filters = [ 'determine_current_user' ];

		$ctx = RequestContext::capture();

		$this->assertSame( 0, AuthState::$current_user_calls );
		$this->assertNull( $ctx->user_id );
		$this->assertNull( $ctx->username );
		$this->assertSame( [], $ctx->roles );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_rest_of_the_context_is_still_captured_while_the_user_is_being_determined(): void {
		require_once __DIR__ . '/Fixtures/wordpress-auth-functions.php';
		require_once __DIR__ . '/../Sensors/Fixtures/wordpress-request-functions.php';
		AuthState::$filters     = [ 'determine_current_user' ];
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		$ctx = RequestContext::capture();

		$this->assertSame( '203.0.113.7', $ctx->client_ip );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_current_user_is_read_once_it_is_known(): void {
		require_once __DIR__ . '/Fixtures/wordpress-auth-functions.php';
		AuthState::$current_user = (object) [
			'ID'         => 42,
			'user_login' => 'editor',
			'roles'      => [ 'editor' ],
		];

		$ctx = RequestContext::capture();

		$this->assertSame( 1, AuthState::$current_user_calls );
		$this->assertSame( 42, $ctx->user_id );
		$this->assertSame( 'editor', $ctx->username );
		$this->assertSame( [ 'editor' ], $ctx->roles );
	}

	/**
	 * Another filter running (a sensor's own, say) is no reason to skip the user.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_current_user_is_read_while_another_filter_runs(): void {
		require_once __DIR__ . '/Fixtures/wordpress-auth-functions.php';
		AuthState::$filters      = [ 'the_content' ];
		AuthState::$current_user = (object) [
			'ID'         => 3,
			'user_login' => 'author',
			'roles'      => [ 'author' ],
		];

		$this->assertSame( 3, RequestContext::capture()->user_id );
	}
}
