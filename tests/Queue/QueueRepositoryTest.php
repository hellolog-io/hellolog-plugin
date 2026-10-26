<?php
/**
 * Tests for which queue table {@see HelloLog\Queue\QueueRepository} writes to.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Queue;

use HelloLog\Events\BufferedDispatcher;
use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDefinition;
use HelloLog\Events\SuppressionRules;
use HelloLog\Queue\QueueRepository;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Queue\Fixtures\FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Each test runs in its own process: it replaces the global `$wpdb` and
 * defines `current_time()` and `dbDelta()`.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class QueueRepositoryTest extends TestCase {

	private FakeWpdb $wpdb;

	protected function setUp(): void {
		require_once __DIR__ . '/Fixtures/FakeWpdb.php';
		require_once __DIR__ . '/Fixtures/wordpress-queue-functions.php';

		$this->wpdb = new FakeWpdb();
		// The request is on site 2 when the queue is written.
		$this->wpdb->prefix = 'wp_2_';
		$GLOBALS['wpdb']    = $this->wpdb;
	}

	public function test_an_event_without_a_site_goes_to_the_current_sites_queue(): void {
		( new QueueRepository() )->insert( '{"code":2001}' );

		$this->assertSame( [ 'wp_2_hellolog_queue' ], array_column( $this->wpdb->inserts, 0 ) );
	}

	public function test_an_event_goes_to_the_queue_of_the_site_it_was_dispatched_on(): void {
		$repository = new QueueRepository();
		$repository->insert( '{"code":2001}', 3 );
		$repository->insert( '{"code":2003}', 1 );

		$this->assertSame( [ 'wp_3_hellolog_queue', 'wp_hellolog_queue' ], array_column( $this->wpdb->inserts, 0 ) );
		$this->assertSame( '{"code":2001}', $this->wpdb->inserts[0][1]['payload'] );
		$this->assertSame( QueueRepository::STATUS_PENDING, $this->wpdb->inserts[0][1]['status'] );
	}

	public function test_the_current_sites_table_name_is_unchanged(): void {
		$this->assertSame( 'wp_2_hellolog_queue', ( new QueueRepository() )->table() );
	}

	/**
	 * A site added to a network before the plugin was active on all of it
	 * has no queue table: its first event creates it instead of being lost.
	 */
	public function test_a_missing_queue_table_is_created_before_the_first_event(): void {
		$this->wpdb->tables = [ 'wp_hellolog_queue' ];

		( new QueueRepository() )->insert( '{"code":2001}', 3 );

		$this->assertCount( 1, $this->wpdb->deltas );
		$this->assertStringContainsString( 'CREATE TABLE wp_3_hellolog_queue (', $this->wpdb->deltas[0] );
		$this->assertStringContainsString( 'KEY queue_pick_idx (status, next_try)', $this->wpdb->deltas[0] );
		$this->assertStringContainsString( 'DEFAULT CHARACTER SET utf8mb4', $this->wpdb->deltas[0] );
		$this->assertSame( [ 'wp_3_hellolog_queue' ], array_column( $this->wpdb->inserts, 0 ) );
		$this->assertSame( 1, $this->wpdb->insert_id, 'The event is in the new table.' );
	}

	public function test_an_existing_queue_table_is_left_alone(): void {
		( new QueueRepository() )->insert( '{"code":2001}', 3 );

		$this->assertSame( [], $this->wpdb->deltas );
	}

	/**
	 * Looked up once per site and request, whichever repository writes.
	 */
	public function test_each_sites_table_is_looked_up_once_per_request(): void {
		$this->wpdb->tables = [ 'wp_hellolog_queue' ];

		$first = new QueueRepository();
		$first->insert( '{"code":1}', 3 );
		$first->insert( '{"code":2}', 3 );
		$first->insert( '{"code":3}' );
		( new QueueRepository() )->insert( '{"code":4}', 3 );
		( new QueueRepository() )->insert( '{"code":5}', 2 );

		$this->assertCount( 2, $this->wpdb->queries );
		$this->assertStringStartsWith( 'SHOW TABLES LIKE ', $this->wpdb->queries[0] );
		$this->assertCount( 2, $this->wpdb->deltas );
		$this->assertStringContainsString( 'CREATE TABLE wp_3_hellolog_queue (', $this->wpdb->deltas[0] );
		$this->assertStringContainsString( 'CREATE TABLE wp_2_hellolog_queue (', $this->wpdb->deltas[1] );
		$this->assertSame( 5, $this->wpdb->insert_id );
	}

	public function test_a_failed_insert_throws_with_the_database_error(): void {
		$this->wpdb->fail_inserts = true;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_3_hellolog_queue: Disk full' );

		( new QueueRepository() )->insert( '{"code":2001}', 3 );
	}

	/**
	 * Wired the way the plugin wires it, a failed insert reaches the
	 * `hellolog_dispatch_failed` action instead of vanishing.
	 */
	public function test_a_failed_insert_is_reported_through_the_dispatch_failed_action(): void {
		require_once __DIR__ . '/../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../Events/Fixtures/wordpress-functions.php';
		$this->wpdb->fail_inserts = true;

		$catalog = new EventCatalog();
		$catalog->register( new EventDefinition( 2001, 'post', 'published', 'info', 'Published.' ) );
		$repository = new QueueRepository();
		$dispatcher = new BufferedDispatcher(
			new EventBuilder( $catalog, false, static fn(): string => 'admin' ),
			static fn( string $json, ?int $blog_id = null ): int => $repository->insert( $json, $blog_id ),
			new SuppressionRules()
		);

		$dispatcher->dispatch( 2001 );
		$dispatcher->flush();

		$this->assertCount( 1, WpCalls::$actions );
		[ $hook, $args ] = WpCalls::$actions[0];
		$this->assertSame( 'hellolog_dispatch_failed', $hook );
		$this->assertInstanceOf( \RuntimeException::class, $args[0] );
		$this->assertStringContainsString( 'Disk full', $args[0]->getMessage() );
	}
}
