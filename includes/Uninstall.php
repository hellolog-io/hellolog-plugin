<?php
/**
 * Uninstall routine — drops the queue table and the plugin's options.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog;

defined( 'ABSPATH' ) || exit;

/**
 * Called from the top-level uninstall.php. Removes every trace the plugin
 * left in the local WP database: on every site (of every network), the
 * queue table and every `hellolog_*` option and transient, the token
 * included. Backend-side events are NOT touched —
 * the operator must purge them through the backend admin API if desired.
 */
final class Uninstall {

	/**
	 * Option name patterns (SQL LIKE, before escaping) of what the plugin
	 * keeps on a site: its options, its transients, and on a single site its
	 * site transients (stored with the options there).
	 *
	 * @var array<int, string>
	 */
	private const OPTION_PREFIXES = [
		'hellolog_',
		'_transient_hellolog_',
		'_transient_timeout_hellolog_',
		'_site_transient_hellolog_',
		'_site_transient_timeout_hellolog_',
	];

	private const OPTION_KEYS = [
		'hellolog_endpoint_url',
		'hellolog_token',
		'hellolog_anonymize_ip',
		'hellolog_sensor_filters',
		'hellolog_db_version',
	];

	public static function uninstall(): void {
		self::drop_queue_tables();
		self::delete_options();
	}

	private static function drop_queue_tables(): void {
		global $wpdb;

		foreach ( self::site_prefixes() as $prefix ) {
			$table = $prefix . 'hellolog_queue';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall teardown of the plugin's own table; {$table} is our own prefixed name, nothing to cache, and the schema change IS the point.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	/**
	 * Remove the plugin's options and transients from every site, through
	 * each site's options table. The current site's settings also go through
	 * the options API first, so its option cache forgets them.
	 */
	private static function delete_options(): void {
		global $wpdb;

		foreach ( self::OPTION_KEYS as $option ) {
			delete_option( $option );
		}

		$likes = array_map( static fn( string $prefix ): string => $wpdb->esc_like( $prefix ) . '%', self::OPTION_PREFIXES );
		$where = implode( ' OR ', array_fill( 0, count( $likes ), 'option_name LIKE %s' ) );
		foreach ( self::site_prefixes() as $prefix ) {
			$table = $prefix . 'options';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- uninstall teardown; {$table} is a site's own options table and {$where} holds exactly one %s per LIKE value passed.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$where}", $likes ) );
		}
	}

	/**
	 * The table prefix of the site, or on a network of every site of every
	 * network: the plugin's files are gone for all of them. No site is
	 * switched to.
	 *
	 * @return array<int, string>
	 */
	private static function site_prefixes(): array {
		global $wpdb;

		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return [ $wpdb->prefix ];
		}

		$prefixes = [];
		foreach ( get_sites(
			[
				'fields' => 'ids',
				'number' => 0,
			]
		) as $blog_id ) {
			$prefixes[] = $wpdb->get_blog_prefix( (int) $blog_id );
		}

		return $prefixes;
	}
}
