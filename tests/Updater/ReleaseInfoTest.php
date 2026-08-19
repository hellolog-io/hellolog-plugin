<?php
/**
 * Tests for {@see HelloLog\Updater\ReleaseInfo}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Updater;

use HelloLog\Updater\ReleaseInfo;
use PHPUnit\Framework\TestCase;

/**
 * `ReleaseInfo` is pure — no WordPress function calls — so every case here
 * runs against `evaluate()` directly, no WP stubs required (unlike
 * `VerifyScheduleTest`, which needs `wp-stubs.php` because `TokenVerifier`
 * and `VerifyScheduleBridge` genuinely touch WP).
 */
final class ReleaseInfoTest extends TestCase {

	private const CURRENT = '0.4.3';

	/**
	 * @param array<int, array<string, mixed>> $assets
	 */
	private function api_body( string $tag, array $assets, string $body = '' ): string {
		return (string) json_encode(
			[
				'tag_name' => $tag,
				'body'     => $body,
				'assets'   => $assets,
			]
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $assets
	 */
	private function newer_body( array $assets, string $body = '' ): string {
		return $this->api_body( 'v0.5.0', $assets, $body );
	}

	private function matching_asset( string $version, string $url ): array {
		return [
			'name'                 => "hellolog-{$version}.zip",
			'browser_download_url' => $url,
		];
	}

	private function allowed_url( string $version ): string {
		return "https://github.com/hellolog-io/hellolog-plugin/releases/download/v{$version}/hellolog-{$version}.zip";
	}

	// -- newer version: update offered, fields correct -----------------

	public function test_newer_version_returns_release_info_with_correct_fields(): void {
		$url  = $this->allowed_url( '0.5.0' );
		$body = $this->newer_body( [ $this->matching_asset( '0.5.0', $url ) ], 'Fixed a bug.' );

		$release = ReleaseInfo::evaluate( self::CURRENT, $body );

		$this->assertNotNull( $release );
		$this->assertSame( '0.5.0', $release->version() );
		$this->assertSame( $url, $release->package_url() );
		$this->assertSame( 'Fixed a bug.', $release->changelog_body() );
	}

	public function test_current_version_is_compared_without_the_leading_v(): void {
		$url  = $this->allowed_url( '1.0.0' );
		$body = $this->api_body( '1.0.0', [ $this->matching_asset( '1.0.0', $url ) ] );

		$release = ReleaseInfo::evaluate( self::CURRENT, $body );

		$this->assertNotNull( $release );
		$this->assertSame( '1.0.0', $release->version() );
	}

	// -- same/older version: null ---------------------------------------

	public function test_same_version_returns_null(): void {
		$url  = $this->allowed_url( self::CURRENT );
		$body = $this->api_body( 'v' . self::CURRENT, [ $this->matching_asset( self::CURRENT, $url ) ] );

		$this->assertNull( ReleaseInfo::evaluate( self::CURRENT, $body ) );
	}

	public function test_older_version_returns_null(): void {
		$url  = $this->allowed_url( '0.4.0' );
		$body = $this->api_body( 'v0.4.0', [ $this->matching_asset( '0.4.0', $url ) ] );

		$this->assertNull( ReleaseInfo::evaluate( self::CURRENT, $body ) );
	}

	// -- missing asset: null ---------------------------------------------

	public function test_missing_asset_returns_null(): void {
		$body = $this->newer_body( [] );

		$this->assertNull( ReleaseInfo::evaluate( self::CURRENT, $body ) );
	}

	public function test_asset_with_wrong_name_returns_null(): void {
		$body = $this->newer_body(
			[ $this->matching_asset( '0.4.9', $this->allowed_url( '0.4.9' ) ) ]
		);

		$this->assertNull( ReleaseInfo::evaluate( self::CURRENT, $body ) );
	}

	// -- zipball-only release: null ---------------------------------------

	public function test_zipball_only_release_returns_null(): void {
		$decoded = [
			'tag_name'    => 'v0.5.0',
			'body'        => '',
			'assets'      => [],
			// GitHub always includes these on every release, but they are
			// NOT `assets` — the top-level folder inside them is named
			// after the git ref, not `hellolog/`, so they must never be
			// picked up as an install source.
			'zipball_url' => 'https://api.github.com/repos/hellolog-io/hellolog-plugin/zipball/v0.5.0',
			'tarball_url' => 'https://api.github.com/repos/hellolog-io/hellolog-plugin/tarball/v0.5.0',
		];

		$this->assertNull( ReleaseInfo::evaluate( self::CURRENT, (string) json_encode( $decoded ) ) );
	}

	// -- non-github package URL: rejected ---------------------------------

	public function test_non_github_package_url_is_rejected(): void {
		$body = $this->newer_body(
			[ $this->matching_asset( '0.5.0', 'https://evil.example.com/hellolog-0.5.0.zip' ) ]
		);

		$this->assertNull( ReleaseInfo::evaluate( self::CURRENT, $body ) );
	}

	public function test_github_zipball_download_url_is_rejected_even_with_the_right_name(): void {
		// Same host, wrong path — must not be treated as a releases/download
		// asset URL just because it starts with github.com.
		$body = $this->newer_body(
			[ $this->matching_asset( '0.5.0', 'https://github.com/hellolog-io/hellolog-plugin/archive/refs/tags/v0.5.0.zip' ) ]
		);

		$this->assertNull( ReleaseInfo::evaluate( self::CURRENT, $body ) );
	}

	public function test_package_url_allowed_accepts_the_expected_prefix(): void {
		$this->assertTrue( ReleaseInfo::package_url_allowed( $this->allowed_url( '0.5.0' ) ) );
	}

	public function test_package_url_allowed_rejects_other_hosts(): void {
		$this->assertFalse( ReleaseInfo::package_url_allowed( 'https://evil.example.com/hellolog-0.5.0.zip' ) );
	}

	// -- malformed JSON: null ----------------------------------------------

	public function test_malformed_json_returns_null(): void {
		$this->assertNull( ReleaseInfo::evaluate( self::CURRENT, 'not json' ) );
	}

	public function test_empty_body_returns_null(): void {
		$this->assertNull( ReleaseInfo::evaluate( self::CURRENT, '' ) );
	}

	public function test_json_without_tag_name_returns_null(): void {
		$this->assertNull( ReleaseInfo::evaluate( self::CURRENT, (string) json_encode( [ 'assets' => [] ] ) ) );
	}

	public function test_non_object_json_returns_null(): void {
		$this->assertNull( ReleaseInfo::evaluate( self::CURRENT, '"just a string"' ) );
	}
}
