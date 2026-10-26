<?php
/**
 * A `$wpdb` that records its inserts.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Queue\Fixtures;

/**
 * Just the part of `wpdb` the queue writes through, on a network whose
 * main site (1) has the base prefix and every other site `wp_<id>_`. Only
 * ever used inside a test that runs in its own process.
 *
 * Like the database, it refuses an insert into a table that does not exist,
 * and a statement on a queue table that does not exist is an error, kept in
 * {@see self::$errors}.
 */
final class FakeWpdb {

	/** Table prefix of the current site. */
	public string $prefix = 'wp_';

	/** Id of the last inserted row. */
	public int $insert_id = 0;

	/** Error of the last failed query. */
	public string $last_error = '';

	/** Whether every insert fails, as on a full disk. */
	public bool $fail_inserts = false;

	/**
	 * Tables that exist.
	 *
	 * @var array<int, string>
	 */
	public array $tables = [ 'wp_hellolog_queue', 'wp_2_hellolog_queue', 'wp_3_hellolog_queue' ];

	/**
	 * Every `insert()` call as `[ table, data ]`, failed ones included.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public array $inserts = [];

	/**
	 * Every table lookup (`SHOW TABLES LIKE`) `get_var()` ran.
	 *
	 * @var array<int, string>
	 */
	public array $queries = [];

	/**
	 * Every statement `dbDelta()` was given.
	 *
	 * @var array<int, string>
	 */
	public array $deltas = [];

	/**
	 * Every statement `query()`, `get_results()`, `update()` and a `get_var()`
	 * other than a table lookup ran, in order.
	 *
	 * @var array<int, string>
	 */
	public array $statements = [];

	/**
	 * What the database would have answered to statements on a queue table
	 * that does not exist.
	 *
	 * @var array<int, string>
	 */
	public array $errors = [];

	public function get_blog_prefix( ?int $blog_id = null ): string {
		if ( null === $blog_id ) {
			return $this->prefix;
		}

		return 1 === $blog_id ? 'wp_' : 'wp_' . $blog_id . '_';
	}

	/**
	 * @param string               $table  Table name.
	 * @param array<string, mixed> $data   Row.
	 * @param mixed                $format Column formats.
	 * @return int|false Rows inserted, or false on an error.
	 */
	public function insert( string $table, array $data, $format = null ) {
		$this->inserts[] = [ $table, $data ];
		if ( $this->fail_inserts ) {
			$this->last_error = 'Disk full';
			return false;
		}
		if ( ! in_array( $table, $this->tables, true ) ) {
			$this->last_error = "Table 'wordpress." . $table . "' doesn't exist";
			return false;
		}
		++$this->insert_id;

		return 1;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * `%s` quoted the way the database reads it back, `%d` as an integer.
	 * Like core, the values may come as one array.
	 *
	 * @param string $query   Query with placeholders.
	 * @param mixed  ...$args Values.
	 */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = array_values( $args[0] );
		}

		return (string) preg_replace_callback(
			'/%[sd]/',
			static function ( array $m ) use ( &$args ): string {
				$arg = array_shift( $args );

				return '%d' === $m[0] ? (string) (int) $arg : "'" . addslashes( (string) $arg ) . "'";
			},
			$query
		);
	}

	/**
	 * Runs a statement: `DROP TABLE IF EXISTS` removes the table.
	 *
	 * @param string $query Statement.
	 */
	public function query( string $query ): int {
		$this->run( $query );
		if ( 1 === preg_match( '/^DROP TABLE IF EXISTS (\S+)$/', $query, $m ) ) {
			$this->tables = array_values( array_diff( $this->tables, [ $m[1] ] ) );
		}

		return 0;
	}

	/**
	 * @param string $query  Query.
	 * @param mixed  $output Row shape.
	 * @return array<int, mixed>
	 */
	public function get_results( string $query, $output = null ): array {
		$this->run( $query );

		return [];
	}

	/**
	 * @param string               $table        Table name.
	 * @param array<string, mixed> $data         Columns to set.
	 * @param array<string, mixed> $where        Row to update.
	 * @param mixed                $format       Column formats.
	 * @param mixed                $where_format Where formats.
	 */
	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int {
		$this->run( 'UPDATE ' . $table );

		return 0;
	}

	/**
	 * Answers `SHOW TABLES LIKE '…'` the way MySQL does: the table name when
	 * it exists, null otherwise.
	 *
	 * @param string $query Query.
	 */
	public function get_var( string $query ): ?string {
		if ( 1 !== preg_match( "/^SHOW TABLES LIKE '(.*)'$/", $query, $m ) ) {
			$this->run( $query );
			return null;
		}
		$this->queries[] = $query;
		$name = stripcslashes( stripslashes( $m[1] ) );

		return in_array( $name, $this->tables, true ) ? $name : null;
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	/**
	 * Keeps the statement, and the error the database would give for a queue
	 * table in it that does not exist.
	 *
	 * @param string $query Statement.
	 */
	private function run( string $query ): void {
		$this->statements[] = $query;
		if ( str_starts_with( $query, 'DROP TABLE IF EXISTS ' ) ) {
			return;
		}
		preg_match_all( '/\bwp_(?:\d+_)?hellolog_queue\b/', $query, $m );
		foreach ( array_unique( $m[0] ) as $table ) {
			if ( ! in_array( $table, $this->tables, true ) ) {
				$this->errors[] = "Table 'wordpress." . $table . "' doesn't exist";
			}
		}
	}
}
