<?php
/**
 * Tests for {@see HelloLog\Sensors\Core\DatabaseSensor}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Events\EventDispatcher;
use HelloLog\Sensors\Core\DatabaseSensor;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use PHPUnit\Framework\TestCase;

/**
 * The plugin creates its own queue table on activation, for a new site and
 * on a site's first event, and drops it on uninstall and with a deleted
 * site; that is housekeeping, not somebody changing the database. Neither
 * are the tables WordPress creates and drops for a network site it sets up
 * or deletes. Each test runs in its own process: it replaces the global
 * `$wpdb`, of a database named `wordpress`.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DatabaseSensorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb'] = (object) [
			'base_prefix' => 'wp_',
			'dbname'      => 'wordpress',
		];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function own_queue_tables(): array {
		return [
			'main site'           => [ "CREATE TABLE wp_hellolog_queue (\n id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT )" ],
			'another site'        => [ 'CREATE TABLE wp_3_hellolog_queue ( id BIGINT )' ],
			'quoted, if missing'  => [ '  create table if not exists `wp_12_hellolog_queue` ( id BIGINT )' ],
		];
	}

	/**
	 * @dataProvider own_queue_tables
	 *
	 * @param string $query The statement.
	 */
	public function test_creating_the_plugins_own_queue_table_is_not_logged( string $query ): void {
		$recorder = $this->recorder();

		$this->assertSame( $query, ( new DatabaseSensor( $recorder ) )->on_query( $query ) );
		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function other_tables(): array {
		return [
			'a core table of a new site' => [ 'CREATE TABLE wp_4_posts ( ID BIGINT )', 'wp_4_posts' ],
			'a name ending the same way' => [ 'CREATE TABLE wp_myhellolog_queue ( id BIGINT )', 'wp_myhellolog_queue' ],
			'a name starting the same'   => [ 'CREATE TABLE wp_hellolog_queue_copy ( id BIGINT )', 'wp_hellolog_queue_copy' ],
			'another prefix'             => [ 'CREATE TABLE other_hellolog_queue ( id BIGINT )', 'other_hellolog_queue' ],
		];
	}

	/**
	 * @dataProvider other_tables
	 *
	 * @param string $query The statement.
	 * @param string $table The table it creates.
	 */
	public function test_creating_any_other_table_is_logged( string $query, string $table ): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( $query );

		$this->assertSame( [ 7100 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( $table, $recorder->calls[0][1]['name'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function own_queue_table_drops(): array {
		return [
			'main site'    => [ 'DROP TABLE wp_hellolog_queue' ],
			'another site' => [ 'DROP TABLE IF EXISTS `wp_3_hellolog_queue`' ],
			'lower case'   => [ '  drop table if exists wp_12_hellolog_queue' ],
		];
	}

	/**
	 * Dropping the queue table is the plugin's own housekeeping (uninstall, a
	 * deleted site), the same as creating it.
	 *
	 * @dataProvider own_queue_table_drops
	 *
	 * @param string $query The statement.
	 */
	public function test_dropping_the_plugins_own_queue_table_is_not_logged( string $query ): void {
		$recorder = $this->recorder();

		$this->assertSame( $query, ( new DatabaseSensor( $recorder ) )->on_query( $query ) );
		$this->assertSame( [], $recorder->calls );
	}

	public function test_dropping_any_other_table_is_logged(): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( 'DROP TABLE IF EXISTS `wp_hellolog_queue_copy`' );

		$this->assertSame( [ 7101 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 'wp_hellolog_queue_copy', $recorder->calls[0][1]['name'] );
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function table_lists(): array {
		return [
			'queue table and a core table'      => [ 'DROP TABLE wp_hellolog_queue, wp_users', [ 'wp_users' ] ],
			'if exists, core table second'      => [ 'DROP TABLE IF EXISTS wp_hellolog_queue, wp_options', [ 'wp_options' ] ],
			'core table first'                  => [ 'DROP TABLE wp_users,wp_3_hellolog_queue', [ 'wp_users' ] ],
			'two other tables'                  => [ 'DROP TABLE wp_shop_orders, wp_shop_items', [ 'wp_shop_orders', 'wp_shop_items' ] ],
			'quoted, qualified, cascade, ;'     => [ 'DROP TABLE `wordpress`.`wp_hellolog_queue` , `wordpress`.`wp_users` CASCADE;', [ 'wordpress.wp_users' ] ],
			'restrict'                          => [ 'drop table if exists wp_a, wp_b restrict', [ 'wp_a', 'wp_b' ] ],
			'a comma inside a quoted name'      => [ 'DROP TABLE `wp_hellolog_queue`, `odd,name`', [ 'odd,name' ] ],
			'a dot inside a quoted name'        => [ 'DROP TABLE `old.wp_hellolog_queue`', [ 'old.wp_hellolog_queue' ] ],
			'a name of digits'                  => [ 'DROP TABLE `2024`', [ '2024' ] ],
			'the plural keyword'                => [ 'DROP TABLES wp_a, wp_b', [ 'wp_a', 'wp_b' ] ],
			'plural, if exists, queue table'    => [ 'drop tables if exists wp_hellolog_queue, wp_users', [ 'wp_users' ] ],
			'line breaks between the keywords'  => [ "DROP\n\tTABLE\n  wp_users", [ 'wp_users' ] ],
			'a comment in the list'             => [ 'DROP TABLE wp_hellolog_queue /* old */, wp_users', [ 'wp_users' ] ],
			'a comment between the keywords'    => [ 'DROP /* comment */ TABLE wp_users', [ 'wp_users' ] ],
			'comments for spaces'               => [ 'DROP/**/TABLE/**/wp_hellolog_queue,/**/wp_users', [ 'wp_users' ] ],
			'a comment inside a quoted name'    => [ 'DROP TABLE `odd/*x*/name`', [ 'odd/*x*/name' ] ],
			'another database\'s queue table'   => [ 'DROP TABLE otherdb.wp_hellolog_queue', [ 'otherdb.wp_hellolog_queue' ] ],
		];
	}

	/**
	 * A statement can drop several tables: every one that is not the queue
	 * table is logged, each as its own event, whatever its place in the list.
	 *
	 * @dataProvider table_lists
	 *
	 * @param string             $query  The statement.
	 * @param array<int, string> $tables The tables that must be logged, in order.
	 */
	public function test_every_dropped_table_but_the_queue_table_is_logged( string $query, array $tables ): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( $query );

		$this->assertSame( array_fill( 0, count( $tables ), 7101 ), array_column( $recorder->calls, 0 ) );
		$this->assertSame( $tables, array_column( array_column( $recorder->calls, 1 ), 'name' ) );
		foreach ( $recorder->calls as [ , $fields ] ) {
			$this->assertArrayNotHasKey( 'unparsed', $fields['metadata'] );
		}
	}

	/**
	 * The statement in the metadata is the one that ran, its comments and
	 * runs of whitespace each read as one space.
	 */
	public function test_the_statement_is_kept_with_comments_and_whitespace_collapsed(): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( "DROP\n\tTABLE /* old */ `wp a`,\n  wp_users" );

		$this->assertSame( 'DROP TABLE `wp a`, wp_users', $recorder->calls[0][1]['metadata']['query'] );
	}

	/**
	 * Comments and whitespace before the keyword, of any kind, do not hide
	 * the statement; neither does a comment MySQL runs (`/*!`).
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function hidden_beginnings(): array {
		return [
			'a block comment'      => [ '/* note */ DROP TABLE wp_users' ],
			'a dash comment'       => [ "-- note\nDROP TABLE wp_users" ],
			'a hash comment'       => [ "# note\r\nDROP TABLE wp_users" ],
			'form feed, tabs'      => [ "\f\v\t DROP TABLE wp_users" ],
			'comments in a row'    => [ "/*a*//*b*/\n-- c\n#d\n  DROP TABLE wp_users" ],
			'a comment MySQL runs' => [ '/*!40101 DROP TABLE wp_users */' ],
			'a MariaDB one'        => [ '/*M!100100 DROP TABLE wp_users */;' ],
		];
	}

	/**
	 * @dataProvider hidden_beginnings
	 *
	 * @param string $query The statement.
	 */
	public function test_comments_and_whitespace_before_the_keyword_do_not_hide_a_drop( string $query ): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( $query );

		$this->assertSame( [ 7101 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 'wp_users', $recorder->calls[0][1]['name'] );
	}

	/**
	 * Whatever the sensor can not read is logged, never let through: inputs
	 * sized to make a pattern give up (a long run of comments, a huge comment)
	 * are read without one.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function oversized_beginnings(): array {
		return [
			'4000 hash comments'    => [ str_repeat( "#\n", 4000 ) . 'DROP TABLE wp_users' ],
			'4000 empty comments'   => [ str_repeat( '/**/', 4000 ) . 'DROP TABLE wp_users' ],
			'a 1 MB block comment'  => [ '/* ' . str_repeat( 'x', 1000000 ) . ' */ DROP TABLE wp_users' ],
			'4000 dash comments'    => [ str_repeat( "-- x\n", 4000 ) . 'DROP TABLE wp_users' ],
		];
	}

	/**
	 * @dataProvider oversized_beginnings
	 *
	 * @param string $query The statement.
	 */
	public function test_oversized_comments_before_the_keyword_do_not_hide_a_drop( string $query ): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( $query );

		$this->assertSame( [ 7101 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 'wp_users', $recorder->calls[0][1]['name'] );
	}

	/**
	 * MySQL reads `--` followed by any space or control character as a
	 * comment, as the sensor does.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function dash_comments(): array {
		return [
			'tab'       => [ "--\tnote\nDROP TABLE wp_users" ],
			'control'   => [ "--\x01note\nDROP TABLE wp_users" ],
			'delete'    => [ "--\x7Fnote\nDROP TABLE wp_users" ],
			'line feed' => [ "--\nDROP TABLE wp_users" ],
		];
	}

	/**
	 * @dataProvider dash_comments
	 *
	 * @param string $query The statement.
	 */
	public function test_dash_comments_ending_in_any_control_character_are_read( string $query ): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( $query );

		$this->assertSame( [ 7101 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 'wp_users', $recorder->calls[0][1]['name'] );
		$this->assertArrayNotHasKey( 'unparsed', $recorder->calls[0][1]['metadata'] );
	}

	/**
	 * What a comment MySQL runs does depends on the server's version, so it
	 * is never read as certain: the statement is logged, marked unparsed,
	 * and no exemption applies, not even a temporary table or the queue.
	 *
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	public static function version_comments(): array {
		return [
			'the whole statement'      => [ '/*!50000 DROP TABLE wp_users*/', 7101, 'wp_users' ],
			'temporary only for some'  => [ 'CREATE /*!99999 TEMPORARY */ TABLE wp_shop ( id BIGINT )', 7100, 'wp_shop' ],
			'after the queue table'    => [ 'DROP TABLE wp_hellolog_queue /*!99999 , wp_users */', 7101, 'wp_hellolog_queue' ],
			'the keyword alone'        => [ '/*!50000DROP*/ TABLE wp_users', 7101, 'wp_users' ],
			'TABLE alone'              => [ 'DROP /*!50000TABLE*/ wp_users', 7101, 'wp_users' ],
			'CREATE alone'             => [ '/*!50000CREATE*/ TABLE wp_shop (id int)', 7100, 'wp_shop' ],
			'no space after a version' => [ '/*!40101DROP TABLE wp_users*/', 7101, 'wp_users' ],
			'a MariaDB one, no space'  => [ '/*M!100100DROP*/ TABLE wp_users', 7101, 'wp_users' ],
		];
	}

	/**
	 * @dataProvider version_comments
	 *
	 * @param string $query The statement.
	 * @param int    $code  The event it must leave.
	 * @param string $name  The table it is logged under.
	 */
	public function test_a_comment_mysql_runs_is_logged_without_exemption( string $query, int $code, string $name ): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( $query );

		$this->assertSame( [ $code ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( $name, $recorder->calls[0][1]['name'] );
		$this->assertSame( 'yes', $recorder->calls[0][1]['metadata']['unparsed'] );
	}

	/**
	 * On a single-byte connection (latin1, cp1250) MySQL reads byte 0xA0, a
	 * no-break space there, as a space: it hides nothing either. Logged as
	 * not read, since on other connections it is no space.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function no_break_spaces(): array {
		return [
			'between the keywords' => [ "DROP\xA0TABLE wp_users" ],
			'before the statement' => [ "\xA0DROP TABLE wp_users" ],
		];
	}

	/**
	 * @dataProvider no_break_spaces
	 *
	 * @param string $query The statement.
	 */
	public function test_a_no_break_space_does_not_hide_a_drop( string $query ): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( $query );

		$this->assertSame( [ 7101 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 'wp_users', $recorder->calls[0][1]['name'] );
		$this->assertSame( 'yes', $recorder->calls[0][1]['metadata']['unparsed'] );
	}

	/**
	 * Rows read or written are not tables: a search for the words, a post
	 * or comment about them, and helloLOG writing one of its own events (an
	 * event about a table holds its statement) leave no event.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function statements_about_tables(): array {
		return [
			'a plain select'          => [ 'SELECT * FROM wp_users' ],
			'a huge select'           => [ 'SELECT ' . str_repeat( 'x', 1000000 ) . ' FROM wp_users' ],
			'a search'                => [ "SELECT ID FROM wp_posts WHERE post_content LIKE '%drop table%'" ],
			'a post about it'         => [ "INSERT INTO wp_posts (post_content) VALUES ('How to create a table')" ],
			'an update about it'      => [ "/* note */ UPDATE wp_posts SET post_content = 'DROP TABLE x' WHERE ID = 1" ],
			'helloLOG\'s own event'   => [ "INSERT INTO `wp_hellolog_queue` (`payload`) VALUES ('{\"metadata\":{\"query\":\"CREATE TABLE wp_shop (id INT)\"}}')" ],
			'a replace'               => [ "REPLACE INTO wp_options VALUES ('x', 'drop tables')" ],
			'a delete'                => [ "DELETE FROM wp_comments WHERE comment_content = 'create table'" ],
			'how a table was created' => [ 'SHOW CREATE TABLE wp_posts' ],
			'a description'           => [ "DESCRIBE wp_posts 'create table'" ],
		];
	}

	/**
	 * @dataProvider statements_about_tables
	 *
	 * @param string $query The statement.
	 */
	public function test_statements_that_only_mention_tables_are_not_logged( string $query ): void {
		$recorder = $this->recorder();

		$this->assertSame( $query, ( new DatabaseSensor( $recorder ) )->on_query( $query ) );
		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * Anything else that holds the words (a statement prepared to run later,
	 * a variable holding one) is logged, unparsed.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function other_statements_with_the_words(): array {
		return [
			'a prepared statement' => [ "PREPARE s FROM 'DROP TABLE wp_users'", 7101 ],
			'a variable'           => [ "SET @s = 'CREATE TABLE wp_x (id INT)'", 7100 ],
			'a comment first'      => [ "/* x */ PREPARE s FROM 'DROP TABLE wp_users'", 7101 ],
		];
	}

	/**
	 * @dataProvider other_statements_with_the_words
	 *
	 * @param string $query The statement.
	 * @param int    $code  The event it must leave.
	 */
	public function test_other_statements_holding_the_words_are_logged( string $query, int $code ): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( $query );

		$this->assertSame( [ $code ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 'yes', $recorder->calls[0][1]['metadata']['unparsed'] );
	}

	/**
	 * Large values (page builder layouts, block content, cached JSON) are
	 * written all the time, full of words that only contain the keywords
	 * (`_tablet`, `created_at`, `dropdown`). Every statement runs through the
	 * sensor, so the check stays linear in its length on every supported PHP
	 * version.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function large_statements(): array {
		$mb      = 1024 * 1024;
		$layout  = substr( str_repeat( '{"padding_tablet":"10px","margin_tablet":"0"},', intdiv( $mb, 44 ) ), 0, $mb );
		$records = substr( str_repeat( '{"created_at":"2026-01-01","widget":"dropdown"},', intdiv( $mb, 46 ) ), 0, $mb );

		return [
			'a row written'            => [ "UPDATE wp_postmeta SET meta_value = 'create " . $layout . "' WHERE meta_id = 1" ],
			'a variable set'           => [ "SET @layout = 'create " . $layout . "'" ],
			'words inside other words' => [ "SET @records = '" . $records . " table'" ],
		];
	}

	/**
	 * @dataProvider large_statements
	 *
	 * @param string $query A statement of about 1 MB that creates or drops nothing.
	 */
	public function test_a_large_statement_is_checked_quickly( string $query ): void {
		$recorder = $this->recorder();
		$sensor   = new DatabaseSensor( $recorder );

		$start = hrtime( true );
		$sensor->on_query( $query );
		$elapsed = ( hrtime( true ) - $start ) / 1e6;

		$this->assertSame( [], $recorder->calls );
		$this->assertLessThan( 50, $elapsed, 'milliseconds for one statement' );
	}

	public function test_a_huge_quoted_table_name_is_still_logged(): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( 'DROP TABLE wp_hellolog_queue, `' . str_repeat( 'a', 1000000 ) . '`' );

		$this->assertSame( [ 7101 ], array_column( $recorder->calls, 0 ) );
	}

	/**
	 * Padding can not push a table out of what is kept: every table has its
	 * own event, named.
	 */
	public function test_padding_between_the_tables_hides_none_of_them(): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( 'DROP/**/TABLE wp_hellolog_queue,' . str_repeat( ' ', 300 ) . 'wp_users' );

		$this->assertSame( [ 7101 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 'wp_users', $recorder->calls[0][1]['name'] );
		$this->assertSame( 'DROP TABLE wp_hellolog_queue, wp_users', $recorder->calls[0][1]['metadata']['query'] );
	}

	public function test_the_queue_table_of_this_database_named_with_it_is_not_logged(): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( 'DROP TABLE `wordpress`.`wp_hellolog_queue`, wordpress.wp_2_hellolog_queue' );

		$this->assertSame( [], $recorder->calls );
	}

	public function test_dropping_only_queue_tables_is_not_logged(): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( 'DROP TABLE IF EXISTS `wp_hellolog_queue`, wp_2_hellolog_queue;' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	public static function unreadable_statements(): array {
		return [
			'a missing comma'        => [ 'DROP TABLE wp_hellolog_queue wp_users', 7101, 'wp_hellolog_queue' ],
			'an unknown last word'   => [ 'DROP TABLE wp_hellolog_queue, wp_users PURGE', 7101, 'wp_hellolog_queue' ],
			'create or replace'      => [ 'CREATE OR REPLACE TABLE wp_shop ( id BIGINT )', 7100, 'wp_shop' ],
		];
	}

	/**
	 * A statement that starts like a table statement but can not be read to
	 * its end is logged once, as it is (comments and whitespace collapsed,
	 * marked as not read), with no exemption: it is never taken for the queue
	 * table alone.
	 *
	 * @dataProvider unreadable_statements
	 *
	 * @param string $query The statement.
	 * @param int    $code  The event it must leave.
	 * @param string $name  The table it is logged under: the first one it names.
	 */
	public function test_a_statement_that_can_not_be_read_is_logged_as_it_is( string $query, int $code, string $name ): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( $query );

		$this->assertSame( [ $code ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( $name, $recorder->calls[0][1]['name'] );
		$this->assertSame( str_replace( '/**/', ' ', $query ), $recorder->calls[0][1]['metadata']['query'] );
		$this->assertSame( 'yes', $recorder->calls[0][1]['metadata']['unparsed'] );
	}

	/**
	 * Padding can not push the rest of an unreadable statement out of what is
	 * kept of it.
	 */
	public function test_padding_in_an_unreadable_statement_is_collapsed(): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( 'DROP TABLE wp_hellolog_queue,' . str_repeat( ' ', 300 ) . 'wp_users PURGE' );

		$this->assertSame( 'DROP TABLE wp_hellolog_queue, wp_users PURGE', $recorder->calls[0][1]['metadata']['query'] );
		$this->assertSame( 'yes', $recorder->calls[0][1]['metadata']['unparsed'] );
	}

	/**
	 * Not even while a network site is set up or deleted.
	 */
	public function test_a_statement_that_can_not_be_read_is_logged_in_a_site_action_too(): void {
		$this->in_site_action( 'wp_uninitialize_site', 4 );
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( 'DROP TABLE wp_4_posts wp_users' );

		$this->assertSame( [ 7101 ], array_column( $recorder->calls, 0 ) );
	}

	public function test_a_qualified_quoted_table_is_named_by_database_and_table(): void {
		$recorder = $this->recorder();
		$sensor   = new DatabaseSensor( $recorder );

		$sensor->on_query( 'CREATE TABLE `wordpress`.`wp_3_hellolog_queue` ( id BIGINT )' );
		$sensor->on_query( "CREATE TABLE IF NOT EXISTS `wordpress`.`wp_shop`(\n id BIGINT )" );

		$this->assertSame( [ 7100 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 'wordpress.wp_shop', $recorder->calls[0][1]['name'] );
	}

	/**
	 * Statements that only look alike leave no event: a temporary table never
	 * outlives its connection (creating one changes no stored table, dropping
	 * one never drops a stored table), and a tablespace, an index or a view
	 * is not a table.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function not_table_statements(): array {
		return [
			'temporary drop'    => [ 'DROP TEMPORARY TABLE IF EXISTS wp_users' ],
			'temporary drops'   => [ 'drop temporary tables wp_tmp_a, wp_tmp_b' ],
			'temporary create'  => [ 'CREATE TEMPORARY TABLE wp_tmp ( id BIGINT )' ],
			'tablespace create' => [ "CREATE TABLESPACE ts1 ADD DATAFILE 'ts1.ibd'" ],
			'tablespace drop'   => [ 'DROP TABLESPACE ts1' ],
			'index create'      => [ 'CREATE INDEX wp_table_idx ON wp_shop ( id )' ],
			'view drop'         => [ 'DROP VIEW wp_shop_view' ],
			'a select'          => [ 'SELECT * FROM wp_users' ],
		];
	}

	/**
	 * @dataProvider not_table_statements
	 *
	 * @param string $query The statement.
	 */
	public function test_statements_that_only_look_alike_are_not_logged( string $query ): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( $query );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function site_lifecycle_statements(): array {
		return [
			'a table of a new site'            => [ 'wp_initialize_site', 'CREATE TABLE wp_4_posts ( ID BIGINT )' ],
			'a plugin table of a new site'     => [ 'wp_initialize_site', 'CREATE TABLE IF NOT EXISTS wp_4_shop_orders ( id BIGINT )' ],
			'a table of a deleted site'        => [ 'wp_uninitialize_site', 'DROP TABLE IF EXISTS `wp_4_posts`' ],
			'a plugin table of a deleted site' => [ 'wp_uninitialize_site', 'DROP TABLE IF EXISTS `wp_4_shop_orders`' ],
		];
	}

	/**
	 * Setting up a network site creates its tables and deleting one drops
	 * them; the site's creation and deletion are logged on their own, one
	 * event each, not one per table.
	 *
	 * @dataProvider site_lifecycle_statements
	 *
	 * @param string $action The site action WordPress is running.
	 * @param string $query  The statement.
	 */
	public function test_tables_of_a_site_being_set_up_or_deleted_are_not_logged( string $action, string $query ): void {
		$this->in_site_action( $action, 4 );
		$recorder = $this->recorder();

		$this->assertSame( $query, ( new DatabaseSensor( $recorder ) )->on_query( $query ) );
		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * Only that site's tables: anything else dropped or created while a site
	 * is set up or deleted is logged, the tables of the main site above all.
	 */
	public function test_other_tables_in_a_site_action_are_logged(): void {
		$this->in_site_action( 'wp_uninitialize_site', 4 );
		$recorder = $this->recorder();
		$sensor   = new DatabaseSensor( $recorder );

		$sensor->on_query( 'DROP TABLE IF EXISTS wp_4_posts, wp_users, wp_40_posts, otherdb.wp_4_options' );

		$this->assertSame( [ 'wp_users', 'wp_40_posts', 'otherdb.wp_4_options' ], array_column( array_column( $recorder->calls, 1 ), 'name' ) );
	}

	public function test_the_main_sites_tables_are_never_skipped(): void {
		$this->in_site_action( 'wp_initialize_site', 1 );
		$recorder = $this->recorder();
		$sensor   = new DatabaseSensor( $recorder );

		$sensor->on_query( 'CREATE TABLE wp_posts ( ID BIGINT )' );
		$sensor->on_query( 'DROP TABLE wp_1_posts' );

		$this->assertSame( [ 7100, 7101 ], array_column( $recorder->calls, 0 ) );
	}

	/**
	 * Inside any other action a table is still a change to the database.
	 */
	public function test_tables_created_and_dropped_in_other_actions_are_logged(): void {
		require_once __DIR__ . '/Fixtures/wordpress-content-functions.php';
		WpState::$doing_action = 'admin_init';
		$recorder              = $this->recorder();
		$sensor                = new DatabaseSensor( $recorder );

		$sensor->on_query( 'CREATE TABLE wp_shop_orders ( id BIGINT )' );
		$sensor->on_query( 'DROP TABLE wp_shop_orders' );

		$this->assertSame( [ 7100, 7101 ], array_column( $recorder->calls, 0 ) );
	}

	/**
	 * WordPress running a site action, on that site (core switches to the
	 * site it sets up or deletes).
	 *
	 * @param string $action  The site action.
	 * @param int    $blog_id The site.
	 */
	private function in_site_action( string $action, int $blog_id ): void {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-content-functions.php';
		WpState::$doing_action = $action;
		WpState::$blog_id      = $blog_id;
	}

	/**
	 * A dispatcher that keeps what it was given.
	 */
	private function recorder(): EventDispatcher {
		return new class() implements EventDispatcher {

			/** @var array<int, array{0: int, 1: array<string, mixed>}> */
			public array $calls = [];

			public function dispatch( int $code, array $fields = [] ): void {
				$this->calls[] = [ $code, $fields ];
			}
		};
	}
}
