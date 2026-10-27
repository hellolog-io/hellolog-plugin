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
 * outside WordPress core.
 *
 * This is best-effort: a plugin issuing the DDL is the common path and
 * is covered here. Migration tools running outside the WP request are
 * not (run an ops-side migration audit instead). So is dynamic SQL: a
 * statement assembled in the database (`PREPARE … FROM @variable`) is never
 * seen as text.
 *
 * The sensor fails closed. Spotting is coarse: any statement whose text has
 * the word `CREATE` or `DROP` followed somewhere by the word `TABLE` or
 * `TABLES` (a cheap search for "table" first, then a scan in one pass, with
 * no pattern that could give up on a large input) is logged, unless the
 * precise reading below grants it an exemption. Anything that reading can
 * not settle is logged once, as it is, under the first name after `TABLE`,
 * marked `unparsed`, with no exemption.
 *
 * The precise reading follows MySQL: comments (block comments, `#`, `--`
 * followed by a space or control character) and runs of whitespace count as
 * one space. A comment MySQL runs (one that opens with `/*!` or `/*M!`)
 * depends on the server's version, so a statement with one is never read as
 * certain. The statement kept in the metadata is the collapsed form, so
 * padding can not push anything out of what is kept. Its exemptions:
 *
 *   - a statement that only reads or writes rows (`SELECT`, `INSERT`,
 *     `UPDATE`, `DELETE`, `REPLACE`) or describes a table (`SHOW`, `DESCRIBE`,
 *     `DESC`, `EXPLAIN`), read from its first word after nothing but
 *     whitespace and plain comments: a search for the words, a post about
 *     them, helloLOG queueing one of these events. One statement per query,
 *     as wpdb runs them;
 *   - a temporary table (`CREATE` or `DROP TEMPORARY TABLE`): it never
 *     outlives its connection;
 *   - helloLOG's own queue table (on activation, for a new site, on a site's
 *     first event; on uninstall, with a deleted site), named without a
 *     database or with the current one;
 *   - while WordPress sets a network site up (`wp_initialize_site`) or
 *     deletes it (`wp_uninitialize_site`), that site's tables (its prefix,
 *     `{base prefix}{site id}_`; core is switched to the site), never the main
 *     site's and never another database's: the site's creation and deletion
 *     are logged on their own (4100, 4101).
 *
 * A `DROP TABLE` (or `DROP TABLES`) can name several tables; each table not
 * exempted is logged as its own event.
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
	 * Statements that never create or drop a table, by their first word.
	 *
	 * @var array<int, string>
	 */
	private const ROW_STATEMENTS = [ 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN' ];

	/**
	 * One part of a table name: quoted in backticks or double quotes (a quote
	 * doubled inside), or plain. Possessive, so a long name is read once.
	 */
	private const NAME_PART = '(?:`(?:[^`]++|``)++`|"(?:[^"]++|"")++"|[0-9A-Za-z_$\x80-\xFF]++)';

	/** Whitespace MySQL skips between words: PHP's trim set plus form feed. */
	private const SPACE = " \t\n\r\0\x0B\f";

	public function key(): string {
		return 'core-database';
	}

	public function boot(): void {
		add_filter( 'query', [ $this, 'on_query' ], 10, 1 );
	}

	public function on_query( string $query ): string {
		if ( false === stripos( $query, 'table' ) ) {
			return $query;
		}
		$keyword = self::table_keyword( $query );
		if ( null === $keyword ) {
			return $query;
		}

		$this->report( $query, $keyword );

		return $query;
	}

	/**
	 * Log a statement the coarse search spotted, minus what the precise
	 * reading exempts.
	 *
	 * @param string $query   The statement as run.
	 * @param string $keyword `CREATE` or `DROP`: the first one the search found.
	 */
	private function report( string $query, string $keyword ): void {
		$first = self::first_word( $query );
		if ( null !== $first && in_array( $first, self::ROW_STATEMENTS, true ) ) {
			return; // Rows read or written, never a table.
		}

		[ $statement, $certain ] = self::normalize( $query );
		$head                    = strtoupper( (string) strstr( $statement . ' ', ' ', true ) );
		$code                    = 'DROP' === ( 'CREATE' === $head || 'DROP' === $head ? $head : $keyword ) ? 7101 : 7100;

		$tables = $certain ? self::read_tables( $statement, $head ) : null;
		if ( null === $tables ) {
			// Fail closed: logged as it is, with no exemption.
			$this->emit_table( $code, self::name_after_keyword( $statement ), $statement, true );
			return;
		}

		$site_prefix = self::site_prefix();
		foreach ( $tables as $table ) {
			if ( ! self::is_skipped( $table, $site_prefix ) ) {
				$this->emit_table( $code, $table['name'], $statement, false );
			}
		}
	}

	/**
	 * The tables a statement that reads as certain creates or drops; none
	 * for a temporary table; null when it is not a table statement the
	 * sensor can read to its end.
	 *
	 * @param string $statement The statement as read.
	 * @param string $head      Its first word, upper-cased.
	 * @return array<int, array{name: string, database: ?string, table: string}>|null
	 */
	private static function read_tables( string $statement, string $head ): ?array {
		if ( 'DROP' === $head ) {
			$pattern = '/^DROP\s+(TEMPORARY\s+)?TABLES?\b\s*(?:IF\s+EXISTS\b\s*)?/i';
		} elseif ( 'CREATE' === $head ) {
			$pattern = '/^CREATE\s+(TEMPORARY\s+)?TABLE\b\s*(?:IF\s+NOT\s+EXISTS\b\s*)?/i';
		} else {
			return null;
		}
		if ( 1 !== preg_match( $pattern, $statement, $m ) ) {
			return null;
		}

		$tables = self::table_names( substr( $statement, strlen( $m[0] ) ), 'DROP' === $head );
		if ( null !== $tables && '' !== ( $m[1] ?? '' ) ) {
			return []; // A temporary table: never a stored table.
		}

		return $tables;
	}

	/**
	 * `CREATE` or `DROP`, whichever comes first as a word, when the word
	 * `TABLE` or `TABLES` follows it somewhere; null otherwise. One pass over
	 * the text, without patterns.
	 *
	 * @param string $sql The statement as run.
	 */
	private static function table_keyword( string $sql ): ?string {
		$keyword = null;
		$at      = PHP_INT_MAX;
		foreach ( [ 'CREATE', 'DROP' ] as $word ) {
			$pos = self::find_word( $sql, $word, 0 );
			if ( null !== $pos && $pos < $at ) {
				$keyword = $word;
				$at      = $pos;
			}
		}
		if ( null === $keyword ) {
			return null;
		}

		$pos = stripos( $sql, 'table', $at + strlen( $keyword ) );
		while ( false !== $pos ) {
			$end = $pos + 5;
			if ( isset( $sql[ $end ] ) && 's' === strtolower( $sql[ $end ] ) && ! self::is_name_char( $sql[ $end + 1 ] ?? '' ) ) {
				++$end;
			}
			if ( ! self::is_name_char( $sql[ $pos - 1 ] ?? '' ) && ! self::is_name_char( $sql[ $end ] ?? '' ) ) {
				return $keyword;
			}
			$pos = stripos( $sql, 'table', $pos + 1 );
		}

		return null;
	}

	/**
	 * Where a word first stands on its own (no name character on either
	 * side), ignoring case; null when it does not.
	 *
	 * @param string $sql    The text.
	 * @param string $word   The word.
	 * @param int    $offset Where to start.
	 */
	private static function find_word( string $sql, string $word, int $offset ): ?int {
		$pos = stripos( $sql, $word, $offset );
		while ( false !== $pos ) {
			$before = $pos > 0 ? $sql[ $pos - 1 ] : '';
			$after  = $sql[ $pos + strlen( $word ) ] ?? '';
			if ( ! self::is_name_char( $before ) && ! self::is_name_char( $after ) ) {
				return $pos;
			}
			$pos = stripos( $sql, $word, $pos + 1 );
		}

		return null;
	}

	/**
	 * Whether a character can be part of a word or name: a letter, a digit,
	 * `_`, `$`, or any byte of a multibyte character.
	 *
	 * @param string $char One character; empty at either end of the text.
	 */
	private static function is_name_char( string $char ): bool {
		return '' !== $char && ( ctype_alnum( $char ) || '_' === $char || '$' === $char || ord( $char ) >= 0x80 );
	}

	/**
	 * The first word of a statement, upper-cased, after nothing but
	 * whitespace and plain comments; null when what comes first is not
	 * certain (a comment MySQL runs, one that is never closed).
	 *
	 * @param string $sql The statement as run.
	 */
	private static function first_word( string $sql ): ?string {
		$len = strlen( $sql );
		$i   = 0;
		while ( $i < $len ) {
			$c = $sql[ $i ];
			if ( false !== strpos( self::SPACE, $c ) ) {
				++$i;
			} elseif ( '/' === $c && '*' === ( $sql[ $i + 1 ] ?? '' ) ) {
				if ( self::is_version_comment( $sql, $i ) ) {
					return null;
				}
				$close = strpos( $sql, '*/', $i + 2 );
				if ( false === $close ) {
					return null;
				}
				$i = $close + 2;
			} elseif ( self::is_line_comment( $sql, $i ) ) {
				$line = strpos( $sql, "\n", $i );
				$i    = false === $line ? $len : $line + 1;
			} else {
				$word = strspn( $sql, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', $i );
				return strtoupper( substr( $sql, $i, $word ) );
			}
		}

		return '';
	}

	/**
	 * Whether a block comment at `$i` is one MySQL runs (`/*!`, or MariaDB's
	 * `/*M!`).
	 *
	 * @param string $sql The statement.
	 * @param int    $i   Position of its `/`.
	 */
	private static function is_version_comment( string $sql, int $i ): bool {
		$third = $sql[ $i + 2 ] ?? '';

		return '!' === $third || ( 'M' === $third && '!' === ( $sql[ $i + 3 ] ?? '' ) );
	}

	/**
	 * Whether a comment that runs to the end of the line starts at `$i`: `#`,
	 * or `--` followed by a space or control character (or nothing).
	 *
	 * @param string $sql The statement.
	 * @param int    $i   Position.
	 */
	private static function is_line_comment( string $sql, int $i ): bool {
		if ( '#' === $sql[ $i ] ) {
			return true;
		}
		if ( '-' !== $sql[ $i ] || '-' !== ( $sql[ $i + 1 ] ?? '' ) ) {
			return false;
		}
		if ( ! isset( $sql[ $i + 2 ] ) ) {
			return true;
		}
		$next = ord( $sql[ $i + 2 ] );

		return $next <= 0x20 || 0x7F === $next;
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
	 * The statement as MySQL reads it, and whether that reading is certain.
	 * Outside quotes every comment and run of whitespace is one space (none
	 * at either end), and a comment MySQL runs is read as its content.
	 * Quoted text is kept as it is. Not certain: a comment MySQL runs (what it
	 * does depends on the server's version), or a comment or quote that is
	 * never closed. One pass, without patterns.
	 *
	 * @param string $sql The statement.
	 * @return array{0: string, 1: bool}
	 */
	private static function normalize( string $sql ): array {
		$out     = '';
		$space   = false;
		$certain = true;
		$runs    = 0; // Comments MySQL runs that are open.
		$len     = strlen( $sql );
		$i       = 0;
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
				$end = self::quoted_end( $sql, $i );
				if ( null === $end ) {
					$certain = false;
					$end     = $len;
				}
				$out  .= ( $space && '' !== $out ? ' ' : '' ) . substr( $sql, $i, $end - $i );
				$space = false;
				$i     = $end;
			} elseif ( '/' === $c && '*' === $next ) {
				if ( self::is_version_comment( $sql, $i ) ) {
					$certain = false;
					++$runs;
					$i += 'M' === $sql[ $i + 2 ] ? 4 : 3;
					$i += strspn( $sql, '0123456789', $i );
				} else {
					$close = strpos( $sql, '*/', $i + 2 );
					if ( false === $close ) {
						$certain = false;
						$close   = $len;
					}
					$i = $close + 2;
				}
				$space = true;
			} elseif ( '*' === $c && '/' === $next && $runs > 0 ) {
				--$runs;
				$i    += 2;
				$space = true;
			} elseif ( self::is_line_comment( $sql, $i ) ) {
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

		return [ $out, $certain && 0 === $runs ];
	}

	/**
	 * Where a quoted run that starts at `$start` ends (just past its closing
	 * quote); null when it is never closed. A doubled quote, and outside
	 * backticks a backslash, escapes the next character.
	 *
	 * @param string $sql   The statement.
	 * @param int    $start Position of the opening quote.
	 */
	private static function quoted_end( string $sql, int $start ): ?int {
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

		return null;
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
		while ( true ) {
			$found = preg_match( $pattern, $rest, $m, 0, $offset );
			if ( false === $found ) {
				return null; // The pattern gave up: not read.
			}
			if ( 1 !== $found ) {
				break;
			}
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
		if ( 1 !== preg_match( '/\bTABLES?\b\s*+(?:IF\s++(?:NOT\s++)?EXISTS\b\s*+)?[`"\']?([\w.]++)/i', $statement, $matches ) ) {
			return '';
		}
		return $matches[1];
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
