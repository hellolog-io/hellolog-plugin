<?php
/**
 * Tests for the parts of {@see HelloLog\Events\BufferedDispatcher} that only
 * run when WordPress is loaded.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Events;

use HelloLog\Events\BufferedDispatcher;
use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\SuppressionRules;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use PHPUnit\Framework\TestCase;

/**
 * Each test runs in its own process: it defines `do_action()` and
 * `wp_json_encode()`, which would otherwise change what every other test of
 * the dispatcher exercises.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class BufferedDispatcherWordPressTest extends TestCase {

	/**
	 * What the sink was called with, in order.
	 *
	 * @var array<int, string>
	 */
	private array $written = [];

	protected function setUp(): void {
		require_once __DIR__ . '/Fixtures/wordpress-functions.php';
		$this->written = [];
	}

	/**
	 * @param ?callable $sink Sink; defaults to one that collects into `$written`.
	 */
	private function dispatcher( ?callable $sink = null ): BufferedDispatcher {
		$builder = new class( new EventCatalog() ) extends EventBuilder {
			public function build( int $code, array $fields = [] ): ?array {
				if ( isset( $fields['boom'] ) ) {
					throw new \RuntimeException( 'builder failed' );
				}

				return [
					'code'    => $code,
					'target'  => $fields['target'] ?? null,
					'message' => 'Árvíztűrő',
				];
			}
		};

		$sink ??= function ( string $json ): void {
			$this->written[] = $json;
		};

		return new BufferedDispatcher( $builder, $sink, SuppressionRules::defaults() );
	}

	/**
	 * @return array{type: string, id: string}
	 */
	private static function post( int $id ): array {
		return [
			'type' => 'post',
			'id'   => (string) $id,
		];
	}

	public function test_it_encodes_with_wp_json_encode_and_its_default_flags(): void {
		$dispatcher = $this->dispatcher();

		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );
		$dispatcher->flush();

		$this->assertSame( [ 0 ], WpCalls::$json_flags );
		// Default flags escape non-ASCII, which is what keeps 4-byte characters
		// safe in the queue table.
		$this->assertStringNotContainsString( 'Árvíztűrő', $this->written[0] );
		$this->assertStringContainsString( '\\u00c1rv', $this->written[0] );
	}

	public function test_a_flush_failure_fires_the_failure_hook_with_code_zero(): void {
		$error      = new \RuntimeException( 'queue is down' );
		$dispatcher = $this->dispatcher(
			static function () use ( $error ): void {
				throw $error;
			}
		);

		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );
		$dispatcher->flush();

		$this->assertSame( [ [ 'hellolog_dispatch_failed', [ $error, 0, [] ] ] ], WpCalls::$actions );
	}

	public function test_a_builder_failure_fires_the_failure_hook_with_code_and_fields(): void {
		$dispatcher = $this->dispatcher();

		$dispatcher->dispatch( 2001, [ 'boom' => true ] );

		$this->assertCount( 1, WpCalls::$actions );
		$this->assertSame( 'hellolog_dispatch_failed', WpCalls::$actions[0][0] );
		$this->assertInstanceOf( \RuntimeException::class, WpCalls::$actions[0][1][0] );
		$this->assertSame( 2001, WpCalls::$actions[0][1][1] );
		$this->assertSame( [ 'boom' => true ], WpCalls::$actions[0][1][2] );
	}

	public function test_a_throwing_failure_hook_callback_does_not_escape_flush(): void {
		WpCalls::$action_throws = true;
		$dispatcher             = $this->dispatcher(
			static function (): void {
				throw new \RuntimeException( 'queue is down' );
			}
		);

		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );
		$dispatcher->flush();

		$this->assertCount( 1, WpCalls::$actions, 'the hook was called, and its failure swallowed' );
	}

	public function test_a_throwing_failure_hook_callback_does_not_escape_dispatch(): void {
		WpCalls::$action_throws = true;
		$dispatcher             = $this->dispatcher();

		$dispatcher->dispatch( 2001, [ 'boom' => true ] );

		$this->assertCount( 1, WpCalls::$actions );
	}
}
