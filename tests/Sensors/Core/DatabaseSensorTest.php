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
use PHPUnit\Framework\TestCase;

/**
 * The plugin creates its own queue table on activation, for a new site and
 * on a site's first event; that is set-up, not somebody changing the
 * database. Each test runs in its own process: it replaces the global `$wpdb`.
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
	 * Dropping the queue table is never set-up: it stays on record.
	 */
	public function test_dropping_the_plugins_own_queue_table_is_still_logged(): void {
		$recorder = $this->recorder();
		( new DatabaseSensor( $recorder ) )->on_query( 'DROP TABLE IF EXISTS `wp_3_hellolog_queue`' );

		$this->assertSame( [ 7101 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( 'wp_3_hellolog_queue', $recorder->calls[0][1]['name'] );
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
