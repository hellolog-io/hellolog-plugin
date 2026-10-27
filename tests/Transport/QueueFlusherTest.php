<?php
/**
 * Tests for {@see HelloLog\Transport\QueueFlusher}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Transport;

use HelloLog\Queue\QueueRepository;
use HelloLog\Tests\Queue\Fixtures\FakeWpdb;
use HelloLog\Tests\Transport\Fixtures\HttpState;
use HelloLog\Transport\ApiClient;
use HelloLog\Transport\PayloadBuilder;
use HelloLog\Transport\QueueFlusher;
use HelloLog\Transport\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * A run sends one batch of the queue and says how many of its events the
 * backend accepted, so a caller that has to empty the queue knows whether
 * more may be waiting. Each test runs in its own process: it replaces the
 * global `$wpdb` and defines the HTTP calls.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class QueueFlusherTest extends TestCase {

	private FakeWpdb $wpdb;

	protected function setUp(): void {
		require_once __DIR__ . '/../Queue/Fixtures/FakeWpdb.php';
		require_once __DIR__ . '/../Queue/Fixtures/wordpress-queue-functions.php';
		require_once __DIR__ . '/../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../Events/Fixtures/wordpress-functions.php';
		require_once __DIR__ . '/Fixtures/HttpState.php';
		require_once __DIR__ . '/Fixtures/wordpress-http-functions.php';

		$this->wpdb      = new FakeWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	public function test_an_empty_queue_sends_nothing_and_counts_nothing(): void {
		$this->assertSame( 0, $this->flusher()->run() );
		$this->assertSame( [], HttpState::$requests );
	}

	public function test_an_accepted_batch_leaves_the_queue_and_is_counted(): void {
		$this->queue( 3 );

		$this->assertSame( 3, $this->flusher()->run() );
		$this->assertCount( 1, HttpState::$requests );
		$this->assertSame( QueueFlusher::BATCH_SIZE, 500 );
		$this->assertContains( 'DELETE FROM wp_hellolog_queue WHERE id IN (1,2,3)', $this->wpdb->statements );
	}

	public function test_a_refused_batch_counts_nothing(): void {
		$this->queue( 3 );
		HttpState::$status = 503;

		$this->assertSame( 0, $this->flusher()->run() );
		$this->assertCount( 1, HttpState::$requests );
		$this->assertNotContains( 'DELETE FROM wp_hellolog_queue WHERE id IN (1,2,3)', $this->wpdb->statements );
	}

	public function test_without_a_key_nothing_is_sent_or_counted(): void {
		$this->queue( 3 );
		$flusher = new QueueFlusher( new QueueRepository(), new PayloadBuilder(), new ApiClient( 'https://example.test/v1', '' ), new RetryPolicy() );

		$this->assertSame( 0, $flusher->run() );
		$this->assertSame( [], HttpState::$requests );
	}

	/**
	 * Put events in the queue, ids from 1.
	 *
	 * @param int $count How many.
	 */
	private function queue( int $count ): void {
		for ( $id = 1; $id <= $count; $id++ ) {
			$this->wpdb->queue_rows[] = (object) [
				'id'         => $id,
				'payload'    => '{"code":2001}',
				'attempts'   => 0,
				'next_try'   => '2026-10-26 12:00:00',
				'status'     => QueueRepository::STATUS_PENDING,
				'last_error' => null,
				'created_at' => '2026-10-26 12:00:00',
			];
		}
	}

	private function flusher(): QueueFlusher {
		return new QueueFlusher(
			new QueueRepository(),
			new PayloadBuilder(),
			new ApiClient( 'https://example.test/v1', 'goal_test_abcd1234_' . str_repeat( 'a', 40 ) ),
			new RetryPolicy()
		);
	}
}
