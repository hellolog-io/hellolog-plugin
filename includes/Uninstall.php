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
 * left in the local WP database. Backend-side events are NOT touched —
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
		self::drop_queue_table();
		self::delete_options();
	}

	private static function drop_queue_table(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'hellolog_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall teardown of the plugin's own table; {$table} is our own prefixed name, nothing to cache, and the schema change IS the point.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	private static function delete_options(): void {
		foreach ( self::OPTION_KEYS as $option ) {
			delete_option( $option );
		}
	}
}
