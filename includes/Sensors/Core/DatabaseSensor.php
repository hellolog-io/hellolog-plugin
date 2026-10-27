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
 * query starts with one of the keywords we care about, or with a comment.
 *
 * This is best-effort: a plugin issuing the DDL is the common path and
 * is covered here. Migration tools running outside the WP request are
 * not (run an ops-side migration audit instead).
 *
 * A statement is read the way MySQL reads it: comments (block comments,
 * `-- `, `#`) and runs of whitespace of any kind (form feed included) count
 * as one space, before the keyword too, and a comment MySQL runs (one that
 * opens with `/*!` or `/*M!`) counts as its content. The statement kept in
 * the metadata is this form of it, so padding can not push anything out of
 * what is kept.
 *
 * A `DROP TABLE` (or `DROP TABLES`) can name several tables; each one is
 * logged as its own event. A temporary table never outlives its connection,
 * so creating or dropping one changes no stored table and is not logged.
 * The sensor fails closed: a statement that starts with `CREATE` or `DROP`
 * and has the word `TABLE` or `TABLES` in it, but can not be read to its end,
 * is logged once, as it is, under the first name after that word, marked
 * `unparsed`, and none of the exemptions below apply to it.
 *
 * helloLOG creating or dropping its own queue table (on activation, for a
 * new site, on a site's first event; on uninstall, with a deleted site) is
 * housekeeping, not a change to the database, and is not logged: a queue
 * table named without a database or with the current one is skipped, and a
 * statement is skipped only when every table it names is skipped. Neither
 * are the tables of a network site WordPress sets up (`wp_initialize_site`)
 * or deletes (`wp_uninitialize_site`): its creation and deletion are logged
 * on their own (4100, 4101). Only that site's tables are skipped there (its
 * prefix, `{base prefix}{site id}_`; core is switched to the site), never the
 * main site's, and never a table of another database.
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

	/** Whitespace MySQL skips between words: PHP's trim set plus form feed. */
	private const SPACE = " \t\n\r\0\x0B\f";

	/**
	 * What may come before the keyword: whitespace, comments, and the opening
	 * of a comment MySQL runs.
	 */
	private const LEADING = '~^(?:\s+|/\*(?!M?!).*?\*/|/\*M?!\d*|(?:#|--(?=\s|$))[^\n]*)+~s';

	public function key(): string {
		return 'core-database';
	}

	public function boot(): void {
		add_filter( 'query', [ $this, 'on_query' ], 10, 1 );
	}

	public function on_query( string $query ): string {
		$trimmed = ltrim( $query, self::SPACE );
		$first   = strtoupper( substr( $trimmed, 0, 1 ) );
		if ( 'C' === $first || 'D' === $first ) {
			$start = $trimmed;
		} elseif ( '/' === $first || '-' === $first || '#' === $first ) {
			$start = (string) preg_replace( self::LEADING, '', $trimmed );
		} else {
			return $query;
		}
		$head = strtoupper( substr( $start, 0, 4 ) );
		if ( 'CREA' !== $head && 'DROP' !== $head ) {
			return $query;
		}

		$statement = self::normalize( $trimmed );
		$is_drop   = 'DROP' === strtoupper( substr( $statement, 0, 4 ) );
		$pattern   = $is_drop
			? '/^DROP\s+(TEMPORARY\s+)?TABLES?\b\s*(?:IF\s+EXISTS\b\s*)?/i'
			: '/^CREATE\s+(TEMPORARY\s+)?TABLE\b\s*(?:IF\s+NOT\s+EXISTS\b\s*)?/i';
		if ( 1 === preg_match( $pattern, $statement, $m ) ) {
			if ( '' !== ( $m[1] ?? '' ) ) {
				return $query; // A temporary table: never a stored table.
			}
			$tables = self::table_names( substr( $statement, strlen( $m[0] ) ), $is_drop );
		} elseif ( 1 === preg_match( '/\bTABLES?\b/i', $statement ) ) {
			$tables = null; // Reads like a table statement, but not one this sensor can read.
		} else {
			return $query; // An index, a view, a tablespace…
		}

		$code = $is_drop ? 7101 : 7100;
		if ( null === $tables ) {
			// Fail closed: logged as it is, with no exemption.
			$this->emit_table( $code, self::name_after_keyword( $statement ), $statement, true );
			return $query;
		}

		$site_prefix = self::site_prefix();
		foreach ( $tables as $table ) {
			if ( ! self::is_skipped( $table, $site_prefix ) ) {
				$this->emit_table( $code, $table['name'], $statement, false );
			}
		}

		return $query;
	}

	/**
	 * Emit the creation or drop of one table.
	 *
	 * @param int    $code      7100 or 7101.
	 * @param string $name      Table name as logged.
	 * @param string $statement The statement as read, shortened in the metadata.
	 * @param bool   $unparsed  Whether the statement could not be read.
	 */
	private function emit_table( int $code, string $name, string $statement, bool $unparsed ): void {
		$metadata = [ 'query' => substr( $statement, 0, 256 ) ];
		if ( $unparsed ) {
			$metadata['unparsed'] = 'yes';
		}

		$this->emit(
			$code,
			[
				'name'     => $name,
				'metadata' => $metadata,
			]
		);
	}

	/**
	 * The statement as MySQL reads it: outside quotes, every comment and run
	 * of whitespace is one space (none at either end), and a comment MySQL
	 * runs is its content. Quoted text is kept as it is.
	 *
	 * @param string $sql The statement.
	 */
	private static function normalize( string $sql ): string {
		$out   = '';
		$space = false;
		$runs  = 0; // Comments MySQL runs that are open.
		$len   = strlen( $sql );
		$i     = 0;
		while ( $i < $len ) {
			$plain = strcspn( $sql, "`\"'/*#-" . self::SPACE, $i );
			if ( $plain > 0 ) {
				$out  .= ( $space && '' !== $out ? ' ' : '' ) . substr( $sql, $i, $plain );
				$space = false;
				$i    += $plain;
				continue;
			}

			$c    = $sql[ $i ];
			$next = $sql[ $i + 1 ] ?? '';
			if ( '`' === $c || '"' === $c || "'" === $c ) {
				$end   = self::quoted_end( $sql, $i );
				$out  .= ( $space && '' !== $out ? ' ' : '' ) . substr( $sql, $i, $end - $i );
				$space = false;
				$i     = $end;
			} elseif ( '/' === $c && '*' === $next ) {
				if ( 1 === preg_match( '~\G/\*M?!\d*~', $sql, $m, 0, $i ) ) {
					++$runs;
					$i += strlen( $m[0] );
				} else {
					$close = strpos( $sql, '*/', $i + 2 );
					$i     = false === $close ? $len : $close + 2;
				}
				$space = true;
			} elseif ( '*' === $c && '/' === $next && $runs > 0 ) {
				--$runs;
				$i    += 2;
				$space = true;
			} elseif ( '#' === $c || ( '-' === $c && '-' === $next && ( $i + 2 >= $len || false !== strpos( self::SPACE, $sql[ $i + 2 ] ) ) ) ) {
				$line  = strpos( $sql, "\n", $i );
				$i     = false === $line ? $len : $line + 1;
				$space = true;
			} elseif ( false !== strpos( self::SPACE, $c ) ) {
				$space = true;
				++$i;
			} else {
				$out  .= ( $space && '' !== $out ? ' ' : '' ) . $c;
				$space = false;
				++$i;
			}
		}

		return $out;
	}

	/**
	 * Where a quoted run that starts at `$start` ends (just past its closing
	 * quote; the end of the text when it is not closed). A doubled quote, and
	 * outside backticks a backslash, escapes the next character.
	 *
	 * @param string $sql   The statement.
	 * @param int    $start Position of the opening quote.
	 */
	private static function quoted_end( string $sql, int $start ): int {
		$quote = $sql[ $start ];
		$len   = strlen( $sql );
		for ( $i = $start + 1; $i < $len; $i++ ) {
			if ( '\\' === $sql[ $i ] && '`' !== $quote ) {
				++$i;
			} elseif ( $quote === $sql[ $i ] ) {
				$after = $sql[ $i + 1 ] ?? '';
				if ( $after !== $quote ) {
					return $i + 1;
				}
				++$i;
			}
		}

		return $len;
	}

	/**
	 * The tables a statement names after its keywords: each with the name as
	 * logged (`table` or `database.table`, without quotes), its database (null
	 * when not named) and the table alone. A `CREATE TABLE` names one; a
	 * `DROP TABLE` a comma-separated list, which may end in `RESTRICT` or
	 * `CASCADE` and a `;`. Null when the list can not be read to its end, or
	 * names no table.
	 *
	 * @param string $rest    The statement after its keywords (and `IF [NOT] EXISTS`).
	 * @param bool   $is_list Whether it is a list to read to its end.
	 * @return array<int, array{name: string, database: ?string, table: string}>|null
	 */
	private static function table_names( string $rest, bool $is_list ): ?array {
		$pattern = '/\G\s*(' . self::NAME_PART . ')(?:\s*\.\s*(' . self::NAME_PART . '))?\s*/';
		$tables  = [];
		$offset  = 0;
		while ( 1 === preg_match( $pattern, $rest, $m, 0, $offset ) ) {
			$first = self::unquote( $m[1] );
			if ( isset( $m[2] ) && '' !== $m[2] ) {
				$table    = self::unquote( $m[2] );
				$tables[] = [
					'name'     => $first . '.' . $table,
					'database' => $first,
					'table'    => $table,
				];
			} else {
				$tables[] = [
					'name'     => $first,
					'database' => null,
					'table'    => $first,
				];
			}
			$offset += strlen( $m[0] );

			if ( ! $is_list || ',' !== substr( $rest, $offset, 1 ) ) {
				break;
			}
			++$offset;
		}

		if ( [] === $tables ) {
			return null;
		}
		if ( $is_list && 1 !== preg_match( '/^(?:(?:RESTRICT|CASCADE)\b)?\s*;?\s*$/i', substr( $rest, $offset ) ) ) {
			return null;
		}

		return $tables;
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
	 * For a statement that can not be read: the first name after its first
	 * `TABLE` or `TABLES` (and `IF [NOT] EXISTS`), quotes dropped; empty when
	 * there is none.
	 *
	 * @param string $statement The statement as read.
	 */
	private static function name_after_keyword( string $statement ): string {
		preg_match( '/\bTABLES?\b\s*(?:IF\s+(?:NOT\s+)?EXISTS\b\s*)?[`"\']?([\w\.]+)/i', $statement, $matches );
		return $matches[1] ?? '';
	}

	/**
	 * Whether a table is left out: a queue table of helloLOG, or a table of
	 * the network site WordPress is setting up or deleting; either only when
	 * named without a database or with the current one.
	 *
	 * @param array{name: string, database: ?string, table: string} $table       The table.
	 * @param string|null                                             $site_prefix Prefix of the site being set up or deleted, if any.
	 */
	private static function is_skipped( array $table, ?string $site_prefix ): bool {
		if ( null !== $table['database'] && self::current_database() !== $table['database'] ) {
			return false;
		}

		return self::is_own_queue_table( $table['table'] )
			|| ( null !== $site_prefix && str_starts_with( $table['table'], $site_prefix ) );
	}

	/**
	 * The table prefix of the network site WordPress is setting up or
	 * deleting right now, `{base prefix}{site id}_`; null outside those
	 * actions and for the main site.
	 */
	private static function site_prefix(): ?string {
		if ( ! function_exists( 'doing_action' ) || ! function_exists( 'get_current_blog_id' ) ) {
			return null;
		}
		$in_action = false;
		foreach ( self::SITE_ACTIONS as $action ) {
			$in_action = $in_action || doing_action( $action );
		}
		if ( ! $in_action ) {
			return null;
		}

		$blog_id = (int) get_current_blog_id();
		$main    = function_exists( 'get_main_site_id' ) ? (int) get_main_site_id() : 1;
		if ( $blog_id === $main ) {
			return null;
		}

		return self::base_prefix() . $blog_id . '_';
	}

	/**
	 * Whether a table is helloLOG's own queue table, on any site of the
	 * network: `{base prefix}hellolog_queue` or `{base prefix}{id}_hellolog_queue`.
	 *
	 * @param string $table Table name, without its database.
	 */
	private static function is_own_queue_table( string $table ): bool {
		return 1 === preg_match( '/^' . preg_quote( self::base_prefix(), '/' ) . '(?:\d+_)?hellolog_queue$/D', $table );
	}

	private static function base_prefix(): string {
		global $wpdb;

		return is_object( $wpdb ) && isset( $wpdb->base_prefix ) ? (string) $wpdb->base_prefix : '';
	}

	/**
	 * The database WordPress works in; null when it is not known.
	 */
	private static function current_database(): ?string {
		global $wpdb;

		if ( is_object( $wpdb ) && isset( $wpdb->dbname ) && is_string( $wpdb->dbname ) ) {
			return $wpdb->dbname;
		}

		return defined( 'DB_NAME' ) ? (string) DB_NAME : null;
	}
}
