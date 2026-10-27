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
 * are the tables WordPress creates and drops while it sets a network site up
 * or deletes it. Each test runs in its own process: it replaces the global
 * `$wpdb`.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class DatabaseSensorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb'] = (object) [ 'base_prefix' => 'wp_' ];
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
			$this->assertSame( ltrim( $query ), $fields['metadata']['query'] );
		}
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
			'a comment in the list'         => [ 'DROP TABLE wp_hellolog_queue /* old */, wp_users', 7101, 'wp_hellolog_queue' ],
			'a comment before the keyword'  => [ 'DROP /* comment */ TABLE wp_users', 7101, 'wp_users' ],
			'a comment before a queue drop' => [ 'DROP /* x */ TABLE IF EXISTS wp_hellolog_queue', 7101, 'wp_hellolog_queue' ],
			'create or replace'             => [ 'CREATE OR REPLACE TABLE wp_shop ( id BIGINT )', 7100, 'wp_shop' ],
		];
	}

	/**
	 * A statement that starts like a table statement but can not be read to
	 * its end is logged once, as it is, with no exemption: it is never taken
	 * for the queue table alone.
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
		$this->assertSame( $query, $recorder->calls[0][1]['metadata']['query'] );
	}

	/**
	 * Not even while a network site is set up or deleted.
	 */
	public function test_a_statement_that_can_not_be_read_is_logged_in_a_site_action_too(): void {
		require_once __DIR__ . '/Fixtures/wordpress-content-functions.php';
		WpState::$doing_action = 'wp_uninitialize_site';
		$recorder              = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( 'DROP /* x */ TABLE wp_users' );

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
		require_once __DIR__ . '/Fixtures/wordpress-content-functions.php';
		WpState::$doing_action = $action;
		$recorder              = $this->recorder();

		$this->assertSame( $query, ( new DatabaseSensor( $recorder ) )->on_query( $query ) );
		$this->assertSame( [], $recorder->calls );
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
