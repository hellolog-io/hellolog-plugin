<?php
/**
 * Stand-ins for the WordPress functions the queue calls besides `$wpdb`,
 * `dbDelta()` included (it lives in wp-admin/includes/upgrade.php).
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

	/**
	 * @param string   $type Format; the queue only asks for `mysql`.
	 * @param bool|int $gmt  Whether to give UTC; the queue always does.
	 */
	function current_time( string $type, $gmt = 0 ): string {
		return '2026-10-26 12:00:00';
	}

	/**
	 * Records the statement and, for a `CREATE TABLE`, makes the table exist.
	 *
	 * @param string|array<int, string> $queries Statements.
	 * @param bool                      $execute Whether to run them.
	 * @return array<string, string>
	 */
	function dbDelta( $queries = '', $execute = true ): array {
		global $wpdb;

		foreach ( (array) $queries as $query ) {
			$wpdb->deltas[] = $query;
			if ( 1 === preg_match( '/CREATE TABLE (\S+)/', $query, $m ) && ! in_array( $m[1], $wpdb->tables, true ) ) {
				$wpdb->tables[] = $m[1];
			}
		}

		return [];
	}
}
