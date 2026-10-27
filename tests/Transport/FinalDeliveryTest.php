<?php
/**
 * Tests for {@see HelloLog\Transport\FinalDelivery}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Transport;

use HelloLog\Events\BufferedDispatcher;
use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\RequestContext;
use HelloLog\Events\RequestSource;
use HelloLog\Events\SuppressionRules;
use HelloLog\Scheduler\FlushLock;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use HelloLog\Transport\FinalDelivery;
use HelloLog\Transport\QueueFlusher;
use PHPUnit\Framework\TestCase;

/**
 * When no background job will send the queue again (helloLOG is being
 * deactivated), the last event is queued with everything the request
 * collected and sent within the request: under the flush lock, batch after
 * batch until the queue is empty or the time is up, and once more at the
 * very end of the request.
 *
 * Each test runs in its own process: it replaces the global `$wpdb` (the
 * lock) and defines the hook calls.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FinalDeliveryTest extends TestCase {

	/**
	 * What happened, in order: events queued, the lock taken and let go,
	 * batches sent.
	 *
	 * @var array<int, string>
	 */
	public static array $log = [];

	/** Whether another run holds the flush lock. */
	public static bool $lock_held = false;

	/**
	 * The values every lock request was prepared with (name, seconds to wait).
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public static array $lock_requests = [];

	protected function setUp(): void {
		require_once __DIR__ . '/../Sensors/Core/Fixtures/wordpress-user-functions.php';
		require_once __DIR__ . '/../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../Events/Fixtures/wordpress-functions.php';

		$GLOBALS['wpdb'] = new class() {

			/** @var string */
			public $prefix = 'wp_';

			/**
			 * @param string $query   Query.
			 * @param mixed  ...$args Values.
			 */
			public function prepare( string $query, ...$args ): string {
				if ( str_contains( $query, 'GET_LOCK' ) ) {
					FinalDeliveryTest::$lock_requests[] = $args;
				}

				return $query . ' ' . implode( ',', $args );
			}

			public function get_var( string $query ): string {
				FinalDeliveryTest::$log[] = str_contains( $query, 'GET_LOCK' ) ? 'lock' : $query;

				return FinalDeliveryTest::$lock_held ? '0' : '1';
			}

			public function query( string $query ): int {
				FinalDeliveryTest::$log[] = str_contains( $query, 'RELEASE_LOCK' ) ? 'unlock' : $query;

				return 1;
			}
		};
	}

	public function test_the_event_and_the_requests_events_are_queued_before_the_queue_is_sent(): void {
		$dispatcher = $this->buffered();
		$dispatcher->dispatch( 5001, [ 'name' => 'Akismet' ] );

		( new FinalDelivery( $dispatcher, $this->batches( [ 2 ] ), new FlushLock() ) )->deliver( 5002, [ 'name' => 'helloLOG' ] );

		$this->assertSame( [ 'queued 5001', 'queued 5002', 'lock', 'sent', 'unlock' ], self::$log );
	}

	/**
	 * Whatever the rest of the request queues (plugins deactivated after
	 * helloLOG in the same action) is sent after the final flush.
	 */
	public function test_the_queue_is_sent_once_more_at_the_very_end_of_the_request(): void {
		$delivery = new FinalDelivery( $this->buffered(), $this->batches( [] ), new FlushLock() );
		$delivery->deliver( 5002, [ 'name' => 'helloLOG' ] );

		$this->assertCount( 1, WpState::$callbacks['shutdown'] );
		$this->assertSame( [ $delivery, 'send' ], WpState::$callbacks['shutdown'][0]['callback'] );
		$this->assertSame( PHP_INT_MAX, WpState::$callbacks['shutdown'][0]['priority'] );
	}

	public function test_a_dispatcher_that_writes_at_once_is_not_flushed(): void {
		$dispatcher = new class() implements EventDispatcher {

			public function dispatch( int $code, array $fields = [] ): void {
				FinalDeliveryTest::$log[] = 'queued ' . $code;
			}
		};

		( new FinalDelivery( $dispatcher, $this->batches( [ 1 ] ), new FlushLock() ) )->deliver( 5002, [] );

		$this->assertSame( [ 'queued 5002', 'lock', 'sent', 'unlock' ], self::$log );
	}

	public function test_full_batches_are_sent_until_the_queue_is_empty(): void {
		$full = QueueFlusher::BATCH_SIZE;

		( new FinalDelivery( $this->buffered(), $this->batches( [ $full, $full, 12, $full ] ), new FlushLock() ) )->send();

		$this->assertSame( [ 'lock', 'sent', 'sent', 'sent', 'unlock' ], self::$log );
	}

	public function test_sending_stops_once_the_time_is_up(): void {
		$full  = QueueFlusher::BATCH_SIZE;
		$times = [ 100.0, 101.5, 103.0, 104.5 ];
		$clock = static function () use ( &$times ): float {
			return array_shift( $times );
		};

		( new FinalDelivery( $this->buffered(), $this->batches( [ $full, $full, $full, $full ] ), new FlushLock(), 2.0, $clock ) )->send();

		$this->assertSame( [ 'lock', 'sent', 'sent', 'unlock' ], self::$log );
	}

	public function test_the_time_allowed_is_two_seconds(): void {
		$this->assertSame( 2.0, FinalDelivery::BUDGET );
	}

	/**
	 * The background job may be sending right now: the last send waits a
	 * moment for it instead of giving up at once.
	 */
	public function test_the_lock_is_waited_for_at_most_a_second(): void {
		( new FinalDelivery( $this->buffered(), $this->batches( [ 1 ] ), new FlushLock( FinalDelivery::LOCK_WAIT ) ) )->send();
		( new FlushLock() )->acquire();

		$this->assertSame( 1, FinalDelivery::LOCK_WAIT );
		$this->assertSame( [ [ 'wp_hellolog_flush', 1 ], [ 'wp_hellolog_flush', 0 ] ], self::$lock_requests, 'The job itself still never waits.' );
	}

	/**
	 * The time spent waiting for the lock counts against the budget.
	 */
	public function test_waiting_for_the_lock_counts_against_the_time(): void {
		$full  = QueueFlusher::BATCH_SIZE;
		$times = [ 100.0, 101.5, 102.5 ];
		$clock = static function () use ( &$times ): float {
			FinalDeliveryTest::$log[] = 'clock';
			return array_shift( $times );
		};

		( new FinalDelivery( $this->buffered(), $this->batches( [ $full, $full, $full ] ), new FlushLock(), 2.0, $clock ) )->send();

		// The time starts before the lock is asked for: 1.5 s in after the
		// first batch, 2.5 s in after the second.
		$this->assertSame( [ 'clock', 'lock', 'sent', 'clock', 'sent', 'clock', 'unlock' ], self::$log );
	}

	/**
	 * Each site of a network has its own queue and its own key: a delivery
	 * only ever sends the queue of the site it was made on, switching back
	 * to it when the request is on another site, and leaves the request on
	 * the site it was on.
	 */
	public function test_the_queue_of_the_site_it_was_made_on_is_sent(): void {
		WpState::$blog_id = 1;
		$sites            = [];
		$delivery         = new FinalDelivery(
			$this->buffered(),
			static function () use ( &$sites ): int {
				$sites[] = get_current_blog_id();
				return 1;
			},
			new FlushLock()
		);

		switch_to_blog( 2 );
		$delivery->send();

		$this->assertSame( [ 1 ], $sites );
		$this->assertSame( 2, get_current_blog_id() );
		$this->assertTrue( $delivery->serves_current_site() === false );
		restore_current_blog();
		$this->assertTrue( $delivery->serves_current_site() );
	}

	public function test_the_end_of_request_send_is_registered_once(): void {
		$delivery = new FinalDelivery( $this->buffered(), $this->batches( [] ), new FlushLock() );
		$delivery->deliver( 6600, [] );
		$delivery->deliver( 6600, [] );

		$this->assertCount( 1, WpState::$callbacks['shutdown'] );
	}

	public function test_nothing_is_sent_while_another_run_holds_the_lock(): void {
		self::$lock_held = true;

		( new FinalDelivery( $this->buffered(), $this->batches( [ 3 ] ), new FlushLock() ) )->send();

		$this->assertSame( [ 'lock' ], self::$log );
	}

	public function test_a_failed_send_is_reported_never_thrown_and_the_lock_let_go(): void {
		$failing = static function (): int {
			throw new \RuntimeException( 'backend unreachable' );
		};

		( new FinalDelivery( $this->buffered(), $failing, new FlushLock() ) )->send();

		$this->assertSame( [ 'lock', 'unlock' ], self::$log );
		$this->assertCount( 1, WpCalls::$actions );
		[ $hook, $args ] = WpCalls::$actions[0];
		$this->assertSame( 'hellolog_dispatch_failed', $hook );
		$this->assertSame( 'backend unreachable', $args[0]->getMessage() );
		$this->assertSame( 0, $args[1] );
		$this->assertSame( [], $args[2] );
	}

	/**
	 * A batch sender that answers the given counts in turn (0 once they run
	 * out) and notes each batch.
	 *
	 * @param array<int, int> $answers Events accepted per batch.
	 * @return callable(): int
	 */
	private function batches( array $answers ): callable {
		return static function () use ( &$answers ): int {
			FinalDeliveryTest::$log[] = 'sent';

			return (int) array_shift( $answers );
		};
	}

	/**
	 * The production dispatcher, writing to a queue that notes each event.
	 */
	private function buffered(): BufferedDispatcher {
		$catalog = new EventCatalog();
		CatalogSeeder::seed( $catalog );
		$builder = new class( $catalog ) extends EventBuilder {

			public function __construct( EventCatalog $catalog ) {
				parent::__construct( $catalog, false, static fn(): string => RequestSource::ADMIN );
			}

			protected function current_context(): RequestContext {
				return new RequestContext( 1, 'admin', [ 'administrator' ], null, null, null );
			}
		};

		return new BufferedDispatcher(
			$builder,
			static function ( string $json ): void {
				FinalDeliveryTest::$log[] = 'queued ' . json_decode( $json, true )['code'];
			},
			SuppressionRules::defaults()
		);
	}
}
