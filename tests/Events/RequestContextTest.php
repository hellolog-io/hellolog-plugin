<?php
/**
 * Tests for {@see HelloLog\Events\RequestContext}'s pure helpers.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Events;

use HelloLog\Events\RequestContext;
use PHPUnit\Framework\TestCase;

/**
 * `capture()` itself reads WP globals; the two helpers it delegates to are
 * pure, so they are pinned here.
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
}
