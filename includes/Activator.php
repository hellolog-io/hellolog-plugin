<?php
/**
 * Activation routine — creates the local outgoing queue table.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation. Creates `{$wpdb->prefix}hellolog_queue` and seeds
 * default options. The queue is the ONLY local table this plugin owns —
 * long-term event storage lives on the external backend.
 *
 * On a network every site has a queue of its own: network activation sets up
 * every site of the network, and a site added while the plugin is
 * network-active is set up when it is created. A site that still has no
 * table gets it from {@see Queue\QueueRepository} before its first event.
 */
final class Activator {

	private const TABLE_BASENAME = 'hellolog_queue';

	/**
	 * Sensors that are noisy on a typical site (REST polling, XML-RPC,
	 * 404 monitor) and not useful enough to justify their volume by
	 * default. Operators can toggle them back on from Filters tab.
	 */
	private const DEFAULT_DISABLED_SENSORS = [
		'core-request'      => true,
		// Failed-login attempts are noisy on internet-exposed sites
		// (bot dictionary attacks fire dozens per minute). Off by
		// default; operators flip it on for incident investigation.
		'core-failed-login' => true,
	];

	private const DEFAULT_OPTS = [
		'hellolog_token'          => '',
		'hellolog_anonymize_ip'   => false,
		'hellolog_sensor_filters' => self::DEFAULT_DISABLED_SENSORS,
	];

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether the plugin is activated for the whole network.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			self::activate_site();
			return;
		}

		$sites = get_sites(
			[
				'fields'     => 'ids',
				'number'     => 0,
				'network_id' => get_current_network_id(),
			]
		);
		foreach ( $sites as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			self::activate_site();
			restore_current_blog();
		}
	}

	/**
	 * Set up a site created on a network the plugin is network-active on.
	 * Hooked to `wp_initialize_site`, after core has created the site's own
	 * tables.
	 *
	 * @param mixed $site The new site (`WP_Site`).
	 */
	public static function initialize_site( $site ): void {
		if ( ! $site instanceof \WP_Site || ! self::is_network_active( (int) $site->network_id ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		self::activate_site();
		restore_current_blog();
	}

	/**
	 * Create the queue table and seed the defaults of the current site.
	 */
	private static function activate_site(): void {
		self::create_queue_table();
		self::seed_default_options();
		self::store_db_version();
	}

	/**
	 * Whether the plugin is active on every site of a network.
	 *
	 * @param int $network_id Network.
	 */
	private static function is_network_active( int $network_id ): bool {
		if ( ! is_multisite() || ! defined( 'HELLOLOG_FILE' ) ) {
			return false;
		}
		$active = get_network_option( $network_id, 'active_sitewide_plugins', [] );

		return is_array( $active ) && isset( $active[ plugin_basename( HELLOLOG_FILE ) ] );
	}

	/**
	 * Create a queue table: the current site's, or the one named.
	 *
	 * @param ?string $table Table name; null for the current site's.
	 */
	public static function create_queue_table( ?string $table = null ): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table ??= self::table_name();
		$collate = $wpdb->get_charset_collate();

		// The schema is intentionally narrow: one JSON payload per row plus
		// the bookkeeping columns the dispatcher needs to retry and drain.
		$sql = "CREATE TABLE {$table} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			payload    LONGTEXT NOT NULL,
			attempts   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			next_try   DATETIME NOT NULL,
			status     VARCHAR(16) NOT NULL DEFAULT 'pending',
			last_error VARCHAR(512) NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY queue_pick_idx (status, next_try)
		) {$collate};";

		dbDelta( $sql );
	}

	private static function seed_default_options(): void {
		foreach ( self::DEFAULT_OPTS as $option => $default ) {
			if ( false === get_option( $option, false ) ) {
				add_option( $option, $default, '', false );
			}
		}
	}

	private static function store_db_version(): void {
		update_option( 'hellolog_db_version', HELLOLOG_VERSION, false );
	}

	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_BASENAME;
	}
}
