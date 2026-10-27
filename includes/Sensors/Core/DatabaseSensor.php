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
 * A `DROP TABLE` can name several tables; each one is logged as its own
 * event. A list that can not be read to its end (a comment in it, say) is
 * logged once, as it is. A temporary table never outlives its connection
 * and is not logged.
 *
 * helloLOG creating or dropping its own queue table (on activation, for a
 * new site, on a site's first event; on uninstall, with a deleted site) is
 * housekeeping, not a change to the database, and is not logged: a statement
 * is skipped only when every table it names is a queue table, otherwise the
 * other tables are logged. Neither are the tables created and dropped while
 * WordPress sets a network site up (`wp_initialize_site`) or deletes it
 * (`wp_uninitialize_site`): the site's creation and deletion are logged on
 * their own (4100, 4101). That includes the tables other plugins create or
 * drop for the site in those actions.
 */
final class DatabaseSensor extends AbstractSensor {

	/**
	 * The actions in which WordPress creates or drops the tables of a site
	 * of the network.
	 *
	 * @var array<int, string>
	 */
	private const SITE_ACTIONS = [ 'wp_initialize_site', 'wp_uninitialize_site' ];

	/**
	 * One part of a table name: quoted in backticks or double quotes (a quote
	 * doubled inside), or plain.
	 */
	private const NAME_PART = '(?:`(?:[^`]|``)+`|"(?:[^"]|"")+"|[0-9A-Za-z_$\x80-\xFF]+)';

	public function key(): string {
		return 'core-database';
	}

	public function boot(): void {
		add_filter( 'query', [ $this, 'on_query' ], 10, 1 );
	}

	public function on_query( string $query ): string {
		$trimmed = ltrim( $query );
		$head    = strtoupper( substr( $trimmed, 0, 4 ) );
		if ( 'CREA' !== $head && 'DROP' !== $head ) {
			return $query;
		}

		if ( 1 === preg_match( '/^CREATE\s+TABLE\b\s*(?:IF\s+NOT\s+EXISTS\b\s*)?/i', $trimmed, $m ) ) {
			$code  = 7100;
			$names = self::table_names( substr( $trimmed, strlen( $m[0] ) ), false );
		} elseif ( 1 === preg_match( '/^DROP\s+TABLE\b\s*(?:IF\s+EXISTS\b\s*)?/i', $trimmed, $m ) ) {
			$code  = 7101;
			$names = self::table_names( substr( $trimmed, strlen( $m[0] ) ), true );
		} else {
			return $query;
		}

		if ( self::in_site_action() ) {
			return $query;
		}

		if ( null === $names ) {
			// Not read to its end: logged as it is, never taken for a queue table.
			$names = [ self::first_word( substr( $trimmed, strlen( $m[0] ) ) ) ];
		} else {
			// A numeric name became an integer key: back to text.
			$names = array_map( 'strval', array_keys( array_filter( $names, static fn( string $table ): bool => ! self::is_own_queue_table( $table ) ) ) );
		}

		foreach ( $names as $name ) {
			$this->emit(
				$code,
				[
					'name'     => $name,
					'metadata' => [ 'query' => substr( $trimmed, 0, 256 ) ],
				]
			);
		}

		return $query;
	}

	/**
	 * The tables a statement names after its keywords: the name as logged
	 * (`table` or `database.table`, without quotes) => the table alone. A
	 * `CREATE TABLE` names one; a `DROP TABLE` a comma-separated list, which
	 * may end in `RESTRICT` or `CASCADE` and a `;`. Null when the list can
	 * not be read to its end, or names no table.
	 *
	 * @param string $rest    The statement after its keywords (and `IF [NOT] EXISTS`).
	 * @param bool   $is_list Whether it is a list to read to its end.
	 * @return array<string, string>|null
	 */
	private static function table_names( string $rest, bool $is_list ): ?array {
		$pattern = '/\G\s*(' . self::NAME_PART . ')(?:\s*\.\s*(' . self::NAME_PART . '))?\s*/';
		$names   = [];
		$offset  = 0;
		while ( 1 === preg_match( $pattern, $rest, $m, 0, $offset ) ) {
			$first = self::unquote( $m[1] );
			if ( isset( $m[2] ) && '' !== $m[2] ) {
				$table                          = self::unquote( $m[2] );
				$names[ $first . '.' . $table ] = $table;
			} else {
				$names[ $first ] = $first;
			}
			$offset += strlen( $m[0] );

			if ( ! $is_list || ',' !== substr( $rest, $offset, 1 ) ) {
				break;
			}
			++$offset;
		}

		if ( [] === $names ) {
			return null;
		}
		if ( $is_list && 1 !== preg_match( '/^(?:(?:RESTRICT|CASCADE)\b)?\s*;?\s*$/i', substr( $rest, $offset ) ) ) {
			return null;
		}

		return $names;
	}

	/**
	 * A name part without its quotes; a doubled quote inside reads as one.
	 *
	 * @param string $part Name part as written.
	 */
	private static function unquote( string $part ): string {
		$quote = $part[0];
		if ( '`' !== $quote && '"' !== $quote ) {
			return $part;
		}

		return str_replace( $quote . $quote, $quote, substr( $part, 1, -1 ) );
	}

	/**
	 * The first word after the keywords, quotes dropped, for a statement
	 * whose list could not be read.
	 *
	 * @param string $rest The statement after its keywords.
	 */
	private static function first_word( string $rest ): string {
		preg_match( '/^[`"\']?([\w\.]+)[`"\']?/', ltrim( $rest ), $matches );
		return $matches[1] ?? '';
	}

	/**
	 * Whether WordPress is setting a site of the network up or deleting it.
	 */
	private static function in_site_action(): bool {
		if ( ! function_exists( 'doing_action' ) ) {
			return false;
		}
		foreach ( self::SITE_ACTIONS as $action ) {
			if ( doing_action( $action ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a table is helloLOG's own queue table, on any site of the
	 * network: `{base prefix}hellolog_queue` or `{base prefix}{id}_hellolog_queue`.
	 *
	 * @param string $table Table name, without its database.
	 */
	private static function is_own_queue_table( string $table ): bool {
		global $wpdb;

		$base = is_object( $wpdb ) && isset( $wpdb->base_prefix ) ? (string) $wpdb->base_prefix : '';

		return 1 === preg_match( '/^' . preg_quote( $base, '/' ) . '(?:\d+_)?hellolog_queue$/D', $table );
	}
}
