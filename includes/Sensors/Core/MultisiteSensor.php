<?php
/**
 * Sensor: multisite — site lifecycle + super admin grants.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Sensors\AbstractSensor;
use WP_Site;

defined( 'ABSPATH' ) || exit;

/**
 * Network admin events. {@see should_load()} skips this sensor on
 * single-site WordPress installs.
 */
final class MultisiteSensor extends AbstractSensor {

	public function key(): string {
		return 'core-multisite';
	}

	public function should_load(): bool {
		return is_multisite();
	}

	public function boot(): void {
		add_action( 'wp_initialize_site', [ $this, 'on_site_created' ], 10, 1 );
		add_action( 'wp_delete_site', [ $this, 'on_site_deleted' ], 10, 1 );
		add_action( 'archive_blog', [ $this, 'on_archived' ], 10, 1 );
		add_action( 'unarchive_blog', [ $this, 'on_unarchived' ], 10, 1 );
		add_action( 'granted_super_admin', [ $this, 'on_super_admin_granted' ], 10, 1 );
		add_action( 'revoked_super_admin', [ $this, 'on_super_admin_revoked' ], 10, 1 );
	}

	public function on_site_created( WP_Site $site ): void {
		$this->emit(
			4100,
			[
				'target'   => self::site_target( $site ),
				'domain'   => (string) $site->domain,
				'metadata' => [
					'site_id' => (int) $site->blog_id,
					'domain'  => (string) $site->domain,
					'path'    => (string) $site->path,
				],
			]
		);
	}

	public function on_site_deleted( WP_Site $site ): void {
		$this->emit(
			4101,
			[
				'target'   => self::site_target( $site ),
				'domain'   => (string) $site->domain,
				'metadata' => [
					'site_id' => (int) $site->blog_id,
					'domain'  => (string) $site->domain,
				],
			]
		);
	}

	/**
	 * The object an event about a site of the network is about: the site by
	 * its id, which does not change, labelled with where it lives (its domain
	 * and path, without the trailing slash).
	 *
	 * @return array{type: string, id: string, label: string}
	 */
	public static function site_target( WP_Site $site ): array {
		return [
			'type'  => 'site',
			'id'    => (string) (int) $site->blog_id,
			'label' => rtrim( (string) $site->domain . (string) $site->path, '/' ),
		];
	}

	public function on_archived( int $blog_id ): void {
		$this->emit_site_state( $blog_id, 'archived' );
	}

	public function on_unarchived( int $blog_id ): void {
		$this->emit_site_state( $blog_id, 'unarchived' );
	}

	public function on_super_admin_granted( int $user_id ): void {
		$user = get_userdata( $user_id );
		$this->emit(
			4150,
			[
				'username' => $user ? (string) $user->user_login : '#' . $user_id,
				'state'    => 'granted',
				'metadata' => [ 'user_id' => $user_id ],
			]
		);
	}

	public function on_super_admin_revoked( int $user_id ): void {
		$user = get_userdata( $user_id );
		$this->emit(
			4150,
			[
				'username' => $user ? (string) $user->user_login : '#' . $user_id,
				'state'    => 'revoked',
				'metadata' => [ 'user_id' => $user_id ],
			]
		);
	}

	private function emit_site_state( int $blog_id, string $state ): void {
		$site = get_site( $blog_id );
		$this->emit(
			4102,
			[
				'domain'   => $site ? (string) $site->domain : '#' . $blog_id,
				'state'    => $state,
				'metadata' => [
					'site_id' => $blog_id,
					'state'   => $state,
				],
			]
		);
	}
}
