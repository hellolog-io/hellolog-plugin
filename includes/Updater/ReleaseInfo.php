<?php
/**
 * Parses a GitHub "latest release" API response into an update decision.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Pure value object — no WordPress function calls, so {@see self::evaluate()}
 * is exercised directly by PHPUnit with no WP stubs (unlike
 * {@see GitHubUpdater}, which owns every WP-facing side effect: the HTTP
 * request, the transient cache, and translating the result into the shapes
 * `pre_set_site_transient_update_plugins` / `plugins_api` expect).
 *
 * {@see self::evaluate()} folds four independent checks into one
 * null-or-value result on purpose: a malformed body, a release with no
 * matching `hellolog-<version>.zip` asset (this NEVER falls back to
 * GitHub's auto-generated zipball/tarball — their top-level folder name
 * breaks the "one folder per plugin" install), a `browser_download_url`
 * outside the expected GitHub releases path, or a release that isn't
 * actually newer than the running version all mean the same thing to a
 * caller: "nothing to offer".
 */
final class ReleaseInfo {

	/**
	 * The only host/path a `package` URL is ever allowed to come from. The
	 * GitHub API response is otherwise-untrusted input, and WordPress
	 * downloads + unzips whatever this URL points to as the running site's
	 * own code.
	 */
	private const PACKAGE_URL_PREFIX = 'https://github.com/hellolog-io/hellolog-plugin/releases/download/';

	public function __construct(
		private string $version,
		private string $package_url,
		private string $changelog_body
	) {
	}

	public function version(): string {
		return $this->version;
	}

	public function package_url(): string {
		return $this->package_url;
	}

	public function changelog_body(): string {
		return $this->changelog_body;
	}

	/**
	 * @param string $current_version Currently running plugin version.
	 * @param string $api_body        Raw JSON body of a GitHub
	 *                                 `GET /repos/.../releases/latest` call.
	 */
	public static function evaluate( string $current_version, string $api_body ): ?self {
		$decoded = json_decode( $api_body, true );
		if ( ! is_array( $decoded ) || ! is_string( $decoded['tag_name'] ?? null ) ) {
			return null;
		}

		$version = ltrim( $decoded['tag_name'], 'v' );
		if ( ! version_compare( $current_version, $version, '<' ) ) {
			return null;
		}

		$package_url = self::find_zip_asset( $decoded, $version );
		if ( null === $package_url || ! self::package_url_allowed( $package_url ) ) {
			return null;
		}

		$body = is_string( $decoded['body'] ?? null ) ? $decoded['body'] : '';
		return new self( $version, $package_url, $body );
	}

	public static function package_url_allowed( string $url ): bool {
		return str_starts_with( $url, self::PACKAGE_URL_PREFIX );
	}

	/**
	 * Only the versioned asset (`hellolog-<version>.zip`) counts — GitHub's
	 * auto-generated Source code (zip)/(tar.gz) links point at a zipball
	 * whose top-level folder is named after the git ref, not `hellolog/`,
	 * which would install the plugin under the wrong directory.
	 *
	 * @param array<string, mixed> $decoded
	 */
	private static function find_zip_asset( array $decoded, string $version ): ?string {
		if ( ! is_array( $decoded['assets'] ?? null ) ) {
			return null;
		}

		$expected_name = "hellolog-{$version}.zip";
		foreach ( $decoded['assets'] as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			if ( ( $asset['name'] ?? null ) === $expected_name && is_string( $asset['browser_download_url'] ?? null ) ) {
				return $asset['browser_download_url'];
			}
		}
		return null;
	}
}
