<?php
/**
 * Offers helloLOG's own GitHub releases as WordPress plugin updates.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Wires three WP core update hooks so a new tagged release on
 * `hellolog-io/hellolog-plugin` shows up on the Plugins screen exactly like
 * a WordPress.org-hosted plugin, with no manual zip upload.
 * {@see self::register()} checks the `hellolog_self_update` filter FIRST and
 * adds none of the hooks when it returns false — a managed install can opt
 * fully out of the built-in updater, not just suppress the nag; the
 * Diagnostics tab then points operators at a manual-download link instead
 * (see `BootstrapPayload`).
 *
 * The actual version/asset decision is pure {@see ReleaseInfo::evaluate()};
 * this class owns only the WP-facing side effects: the GitHub API request,
 * the transient cache (successes AND failures, so a GitHub outage or rate
 * limit is retried at most once an hour instead of on every admin load),
 * and translating a {@see ReleaseInfo} into the shapes
 * `pre_set_site_transient_update_plugins` / `plugins_api` expect.
 */
final class GitHubUpdater {

	private const API_URL   = 'https://api.github.com/repos/hellolog-io/hellolog-plugin/releases/latest';
	private const HOMEPAGE  = 'https://github.com/hellolog-io/hellolog-plugin';
	private const SLUG      = 'hellolog';
	private const TRANSIENT = 'hellolog_github_release';
	// 12h for a real response; 1h when the last request failed, so a GitHub
	// outage/rate limit is retried soon without hammering the API on every
	// admin-page load in between.
	private const CACHE_TTL      = 12 * HOUR_IN_SECONDS;
	private const FAIL_CACHE_TTL = HOUR_IN_SECONDS;
	// Cached in place of a real API body after a failed request — distinct
	// from `false` (nothing cached yet) so a failure is itself remembered.
	private const FAILURE_SENTINEL = '__hellolog_fetch_failed__';

	public function register(): void {
		if ( ! apply_filters( 'hellolog_self_update', true ) ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugins_api' ], 10, 3 );
		add_action( 'upgrader_process_complete', [ $this, 'clear_cache' ], 10, 2 );
	}

	/**
	 * @param mixed $transient WP core's update-plugins transient object.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->release_info();
		if ( null === $release ) {
			unset( $transient->response[ $this->basename() ] );
			$transient->no_update[ $this->basename() ] = $this->no_update_entry();
			return $transient;
		}

		$transient->response[ $this->basename() ] = (object) [
			'slug'        => self::SLUG,
			'plugin'      => $this->basename(),
			'new_version' => $release->version(),
			'url'         => self::HOMEPAGE,
			'package'     => $release->package_url(),
		];

		return $transient;
	}

	/**
	 * @param mixed  $result
	 * @param string $action
	 * @param mixed  $args
	 * @return mixed
	 */
	public function plugins_api( $result, $action = '', $args = null ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->release_info();

		return (object) [
			'name'          => 'helloLOG',
			'slug'          => self::SLUG,
			'version'       => null !== $release ? $release->version() : HELLOLOG_VERSION,
			'author'        => '<a href="https://hellolog.io">hellolog.io</a>',
			'homepage'      => self::HOMEPAGE,
			'download_link' => null !== $release ? $release->package_url() : '',
			'sections'      => [
				'changelog' => $this->changelog_html( null !== $release ? $release->changelog_body() : '' ),
			],
		];
	}

	/**
	 * @param mixed                $upgrader   Unused; part of the hook signature.
	 * @param array<string, mixed> $hook_extra
	 */
	public function clear_cache( $upgrader, array $hook_extra ): void {
		if ( 'plugin' !== ( $hook_extra['type'] ?? '' ) || 'update' !== ( $hook_extra['action'] ?? '' ) ) {
			return;
		}
		if ( in_array( $this->basename(), (array) ( $hook_extra['plugins'] ?? [] ), true ) ) {
			delete_site_transient( self::TRANSIENT );
		}
	}

	private function basename(): string {
		return plugin_basename( HELLOLOG_FILE );
	}

	private function no_update_entry(): object {
		return (object) [
			'slug'        => self::SLUG,
			'plugin'      => $this->basename(),
			'new_version' => HELLOLOG_VERSION,
			'url'         => self::HOMEPAGE,
			'package'     => '',
		];
	}

	private function release_info(): ?ReleaseInfo {
		$body = $this->fetch_release_body();
		return null !== $body ? ReleaseInfo::evaluate( HELLOLOG_VERSION, $body ) : null;
	}

	/** Cached GitHub API response body, or null when unavailable. */
	private function fetch_release_body(): ?string {
		$cached = get_site_transient( self::TRANSIENT );
		if ( false !== $cached ) {
			return self::FAILURE_SENTINEL === $cached ? null : (string) $cached;
		}

		$response = wp_remote_get(
			self::API_URL,
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'hellolog-plugin',
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::TRANSIENT, self::FAILURE_SENTINEL, self::FAIL_CACHE_TTL );
			return null;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		set_site_transient( self::TRANSIENT, $body, self::CACHE_TTL );
		return $body;
	}

	/**
	 * Minimal, safe changelog rendering: escape the raw GitHub release body,
	 * then convert line breaks — no markdown parser, `wp_kses_post()` is the
	 * last word on what actually reaches the admin screen.
	 */
	private function changelog_html( string $body ): string {
		if ( '' === $body ) {
			return '';
		}
		return wp_kses_post( nl2br( esc_html( $body ) ) );
	}
}
