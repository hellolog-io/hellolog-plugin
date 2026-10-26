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
 * Like the database, it refuses an insert into a table that does not exist.
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
	 * Every query `get_var()` ran.
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
	 * Only `%s`, quoted the way the database reads it back.
	 *
	 * @param string $query   Query with placeholders.
	 * @param mixed  ...$args Values.
	 */
	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$query = (string) preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 );
		}

		return $query;
	}

	/**
	 * Answers `SHOW TABLES LIKE '…'` the way MySQL does: the table name when
	 * it exists, null otherwise.
	 *
	 * @param string $query Query.
	 */
	public function get_var( string $query ): ?string {
		$this->queries[] = $query;
		if ( 1 !== preg_match( "/^SHOW TABLES LIKE '(.*)'$/", $query, $m ) ) {
			return null;
		}
		$name = stripcslashes( stripslashes( $m[1] ) );

		return in_array( $name, $this->tables, true ) ? $name : null;
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}
}
