<?php
/**
 * Tests for which queue table {@see HelloLog\Queue\QueueRepository} writes to.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Queue;

use HelloLog\Queue\QueueRepository;
use HelloLog\Tests\Queue\Fixtures\FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Each test runs in its own process: it replaces the global `$wpdb` and
 * defines `current_time()`.
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
}
