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
use HelloLog\Tests\Queue\Fixtures\NetworkState;
use PHPUnit\Framework\TestCase;

/**
 * Each test runs in its own process: it replaces the global `$wpdb` and
 * defines `current_time()`, `dbDelta()` and the network and option calls.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class QueueRepositoryTest extends TestCase {

	private FakeWpdb $wpdb;

	protected function setUp(): void {
		require_once __DIR__ . '/Fixtures/FakeWpdb.php';
		require_once __DIR__ . '/Fixtures/wordpress-queue-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-network-functions.php';

		$this->wpdb = new FakeWpdb();
		// The request is on site 2 when the queue is written.
		$this->wpdb->prefix         = 'wp_2_';
		$GLOBALS['wpdb']            = $this->wpdb;
		NetworkState::$sites        = [
			1 => 1,
			2 => 1,
			3 => 1,
		];
		NetworkState::$current_blog = 2;
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

	/**
	 * The site is set up the way activation sets it up, its default settings
	 * included (the noisy sensors stay off there too), and the request is
	 * back on its own site afterwards.
	 */
	public function test_a_site_set_up_on_its_first_event_gets_the_default_settings(): void {
		$this->wpdb->tables = [ 'wp_hellolog_queue', 'wp_2_hellolog_queue' ];

		( new QueueRepository() )->insert( '{"code":2001}', 3 );

		$this->assertSame(
			[
				'core-request'      => true,
				'core-failed-login' => true,
			],
			NetworkState::$options[3]['hellolog_sensor_filters'] ?? null
		);
		$this->assertSame( HELLOLOG_VERSION, NetworkState::$options[3]['hellolog_db_version'] ?? null );
		$this->assertSame( 2, NetworkState::$current_blog );
		$this->assertSame( 'wp_2_', $this->wpdb->prefix );
		$this->assertArrayNotHasKey( 2, NetworkState::$options, 'The current site is left alone.' );
	}

	public function test_a_site_set_up_on_its_first_event_keeps_the_settings_it_has(): void {
		$this->wpdb->tables         = [ 'wp_hellolog_queue', 'wp_2_hellolog_queue' ];
		NetworkState::$options[3] = [ 'hellolog_token' => 'goal_live_example' ];

		( new QueueRepository() )->insert( '{"code":2001}', 3 );

		$this->assertSame( 'goal_live_example', NetworkState::$options[3]['hellolog_token'] );
	}

	public function test_the_current_site_is_set_up_without_switching_sites(): void {
		$this->wpdb->tables = [];

		( new QueueRepository() )->insert( '{"code":2001}' );

		$this->assertSame( [ 'wp_2_hellolog_queue' ], $this->wpdb->tables );
		$this->assertArrayHasKey( 'hellolog_sensor_filters', NetworkState::$options[2] );
		$this->assertSame( 0, NetworkState::$switch_count );
	}

	public function test_a_single_site_is_set_up_without_switching(): void {
		NetworkState::$multisite    = false;
		NetworkState::$current_blog = 1;
		$this->wpdb->prefix         = 'wp_';
		$this->wpdb->tables         = [];

		( new QueueRepository() )->insert( '{"code":2001}', 1 );

		$this->assertSame( [ 'wp_hellolog_queue' ], $this->wpdb->tables );
		$this->assertSame( 0, NetworkState::$switch_count );
		$this->assertSame( [], $this->wpdb->errors );
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, mixed>}> Method, arguments.
	 */
	public static function queue_queries(): array {
		return [
			'pick a batch'           => [ 'pick_batch', [ 10 ] ],
			'mark sending'           => [ 'mark_sending', [ [ 1, 2 ] ] ],
			'purge everything'       => [ 'purge', [] ],
			'purge one status'       => [ 'purge', [ QueueRepository::STATUS_DEAD ] ],
			'purge old dead rows'    => [ 'purge_dead_before', [ '2026-10-01 00:00:00' ] ],
			'delete the oldest'      => [ 'delete_oldest', [ 5 ] ],
			'count all'              => [ 'count_all', [] ],
			'requeue dead rows'      => [ 'requeue_dead', [] ],
			'delete some'            => [ 'delete_many', [ [ 1, 2 ] ] ],
			'mark for a retry'       => [ 'mark_retry', [ 1, 2, '2026-10-26 12:05:00', 'timeout' ] ],
			'mark dead'              => [ 'mark_dead', [ 1, 'gone' ] ],
			'count by status'        => [ 'counts_by_status', [] ],
		];
	}

	/**
	 * Draining, counting and pruning on a site that has no queue table yet
	 * set it up first instead of querying a table that is not there.
	 *
	 * @dataProvider queue_queries
	 *
	 * @param string            $method Repository method.
	 * @param array<int, mixed> $args   Its arguments.
	 */
	public function test_every_query_works_on_a_site_without_a_queue_table_yet( string $method, array $args ): void {
		$this->wpdb->tables = [ 'wp_hellolog_queue' ];

		( new QueueRepository() )->$method( ...$args );

		$this->assertSame( [], $this->wpdb->errors );
		$this->assertContains( 'wp_2_hellolog_queue', $this->wpdb->tables );
		$this->assertArrayHasKey( 'hellolog_sensor_filters', NetworkState::$options[2] );
		$this->assertNotSame( [], $this->wpdb->statements, 'The query itself still ran.' );
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
