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
 * left in the local WP database: the queue table of every site, and the
 * options of the site it runs on. Backend-side events are NOT touched —
 * the operator must purge them through the backend admin API if desired.
 */
final class Uninstall {

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

		foreach ( self::queue_tables() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall teardown of the plugin's own table; {$table} is our own prefixed name, nothing to cache, and the schema change IS the point.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	/**
	 * The queue table of the site, or on a network of every site of every
	 * network: the plugin's files are gone for all of them. Named by each
	 * site's prefix, without switching to it.
	 *
	 * @return array<int, string>
	 */
	private static function queue_tables(): array {
		global $wpdb;

		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return [ $wpdb->prefix . 'hellolog_queue' ];
		}

		$tables = [];
		foreach ( get_sites(
			[
				'fields' => 'ids',
				'number' => 0,
			]
		) as $blog_id ) {
			$tables[] = $wpdb->get_blog_prefix( (int) $blog_id ) . 'hellolog_queue';
		}

		return $tables;
	}

	private static function delete_options(): void {
		foreach ( self::OPTION_KEYS as $option ) {
			delete_option( $option );
		}
	}
}
