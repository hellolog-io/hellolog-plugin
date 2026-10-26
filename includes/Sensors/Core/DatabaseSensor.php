<?php
/**
 * Sensor: custom database table create/drop detection.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Sensors\AbstractSensor;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks `query` so we can spot `CREATE TABLE` / `DROP TABLE` issued
 * outside WordPress core. The filter is cheap — early-exit unless the
 * query starts with one of the keywords we care about.
 *
 * This is best-effort: a plugin issuing the DDL is the common path and
 * is covered here. Migration tools running outside the WP request are
 * not (run an ops-side migration audit instead).
 *
 * helloLOG creating its own queue table (on activation, for a new site, on
 * a site's first event) is set-up, not a change to the database, and is
 * not logged; dropping it is.
 */
final class DatabaseSensor extends AbstractSensor {

	public function key(): string {
		return 'core-database';
	}

	public function boot(): void {
		add_filter( 'query', [ $this, 'on_query' ], 10, 1 );
	}

	public function on_query( string $query ): string {
		$trimmed = ltrim( $query );
		$head    = strtoupper( substr( $trimmed, 0, 12 ) );

		if ( str_starts_with( $head, 'CREATE TABLE' ) ) {
			$name = $this->extract_table( $trimmed, 'CREATE TABLE' );
			if ( self::is_own_queue_table( $name ) ) {
				return $query;
			}
			$this->emit(
				7100,
				[
					'name'     => $name,
					'metadata' => [ 'query' => substr( $trimmed, 0, 256 ) ],
				]
			);
		} elseif ( str_starts_with( $head, 'DROP TABLE' ) ) {
			$this->emit(
				7101,
				[
					'name'     => $this->extract_table( $trimmed, 'DROP TABLE' ),
					'metadata' => [ 'query' => substr( $trimmed, 0, 256 ) ],
				]
			);
		}

		return $query;
	}

	/**
	 * Whether a table is helloLOG's own queue table, on any site of the
	 * network: `{base prefix}hellolog_queue` or `{base prefix}{id}_hellolog_queue`.
	 *
	 * @param string $name Table name, possibly with its database in front.
	 */
	private static function is_own_queue_table( string $name ): bool {
		global $wpdb;

		$base  = is_object( $wpdb ) && isset( $wpdb->base_prefix ) ? (string) $wpdb->base_prefix : '';
		$parts = explode( '.', $name );

		return 1 === preg_match( '/^' . preg_quote( $base, '/' ) . '(?:\d+_)?hellolog_queue$/D', (string) end( $parts ) );
	}

	private function extract_table( string $query, string $prefix ): string {
		$after = trim( substr( $query, strlen( $prefix ) ) );
		// Skip IF NOT EXISTS / IF EXISTS clauses.
		$after = preg_replace( '/^IF\s+(NOT\s+)?EXISTS\s+/i', '', $after ) ?? $after;
		// First word is the table name.
		preg_match( '/^[`"\']?([\w\.]+)[`"\']?/', $after, $matches );
		return $matches[1] ?? '';
	}
}
