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
