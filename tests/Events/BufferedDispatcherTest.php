<?php
/**
 * Tests for {@see HelloLog\Events\BufferedDispatcher}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Events;

use HelloLog\Events\BufferedDispatcher;
use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\SuppressionRules;
use PHPUnit\Framework\TestCase;

/**
 * The dispatcher is fed by a fake builder that hands back whatever the test
 * put in `fields`, so the buffering and flushing can be pinned without a
 * catalog or WordPress.
 */
final class BufferedDispatcherTest extends TestCase {

	/**
	 * What the sink was called with, in order.
	 *
	 * @var array<int, string>
	 */
	private array $written = [];

	protected function setUp(): void {
		$this->written = [];
	}

	/**
	 * A builder that returns `code` plus the `target` of the fields, and null
	 * for code 0 (an "unknown code").
	 */
	private function builder(): EventBuilder {
		return new class( new EventCatalog() ) extends EventBuilder {
			public function build( int $code, array $fields = [] ): ?array {
				if ( 0 === $code ) {
					return null;
				}
				if ( isset( $fields['boom'] ) ) {
					throw new \RuntimeException( 'builder failed' );
				}

				$payload = [ 'code' => $code ];
				if ( isset( $fields['target'] ) ) {
					$payload['target'] = $fields['target'];
				}
				if ( isset( $fields['changes'] ) ) {
					$payload['changes'] = $fields['changes'];
				}
				if ( isset( $fields['message'] ) ) {
					$payload['message'] = $fields['message'];
				}

				return $payload;
			}
		};
	}

	/**
	 * @param int                                 $max   Auto-flush threshold.
	 * @param ?callable                           $sink  Sink; defaults to one that collects into `$written`.
	 * @param SuppressionRules|callable|null      $rules Rules or a provider of them; defaults to the initial table.
	 */
	private function dispatcher( int $max = 200, ?callable $sink = null, $rules = null ): BufferedDispatcher {
		$sink ??= function ( string $json ): void {
			$this->written[] = $json;
		};

		return new BufferedDispatcher( $this->builder(), $sink, $rules ?? SuppressionRules::defaults(), $max );
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

	/**
	 * The decoded payloads written so far.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function decoded(): array {
		return array_map(
			static fn( string $json ): array => json_decode( $json, true, 512, JSON_THROW_ON_ERROR ),
			$this->written
		);
	}

	public function test_it_is_an_event_dispatcher(): void {
		$this->assertInstanceOf( EventDispatcher::class, $this->dispatcher() );
	}

	public function test_nothing_written_before_flush(): void {
		$dispatcher = $this->dispatcher();

		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );
		$dispatcher->dispatch( 2003, [ 'target' => self::post( 2 ) ] );

		$this->assertSame( [], $this->written );
	}

	public function test_flush_writes_each_survivor_as_json(): void {
		$dispatcher = $this->dispatcher();

		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );
		$dispatcher->dispatch( 2003, [ 'target' => self::post( 2 ) ] );
		$dispatcher->flush();

		$this->assertCount( 2, $this->written );
		$this->assertSame(
			[
				[
					'code'   => 2001,
					'target' => self::post( 1 ),
				],
				[
					'code'   => 2003,
					'target' => self::post( 2 ),
				],
			],
			$this->decoded()
		);
	}

	public function test_flush_applies_rules(): void {
		$dispatcher = $this->dispatcher();

		$dispatcher->dispatch(
			2006,
			[
				'target'  => self::post( 1 ),
				'changes' => [
					[
						'field' => 'post_title',
						'old'   => 'A',
						'new'   => 'B',
					],
				],
			]
		);
		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );
		$dispatcher->dispatch( 2006, [ 'target' => self::post( 2 ) ] );
		$dispatcher->flush();

		$this->assertSame( [ 2001 ], array_column( $this->decoded(), 'code' ) );
	}

	public function test_flush_clears_the_buffer(): void {
		$dispatcher = $this->dispatcher();

		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );
		$dispatcher->flush();
		$dispatcher->flush();

		$this->assertCount( 1, $this->written );
	}

	public function test_flush_on_an_empty_buffer_writes_nothing(): void {
		$this->dispatcher()->flush();

		$this->assertSame( [], $this->written );
	}

	public function test_unknown_code_is_dropped(): void {
		$dispatcher = $this->dispatcher();

		$dispatcher->dispatch( 0, [ 'target' => self::post( 1 ) ] );
		$dispatcher->flush();

		$this->assertSame( [], $this->written );
	}

	public function test_auto_flush_at_max(): void {
		$dispatcher = $this->dispatcher( 3 );

		for ( $i = 1; $i <= 7; $i++ ) {
			$dispatcher->dispatch( 2001, [ 'target' => self::post( $i ) ] );
		}

		// Two automatic flushes of three each; the seventh is still buffered.
		$this->assertCount( 6, $this->written );

		$dispatcher->flush();
		$this->assertCount( 7, $this->written );
	}

	public function test_the_buffer_is_empty_right_after_an_automatic_flush(): void {
		$dispatcher = $this->dispatcher( 2 );

		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );
		$dispatcher->dispatch( 2001, [ 'target' => self::post( 2 ) ] );
		$this->assertCount( 2, $this->written );

		$dispatcher->flush();
		$this->assertCount( 2, $this->written );
	}

	public function test_flush_survives_throwing_sink(): void {
		$calls      = 0;
		$dispatcher = $this->dispatcher(
			200,
			static function () use ( &$calls ): void {
				++$calls;
				throw new \RuntimeException( 'queue is down' );
			}
		);

		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );
		$dispatcher->dispatch( 2003, [ 'target' => self::post( 2 ) ] );

		$dispatcher->flush();
		$this->assertSame( 2, $calls );

		// The buffer is gone, so a second flush does not retry the same rows.
		$dispatcher->flush();
		$this->assertSame( 2, $calls );
	}

	public function test_a_failing_write_does_not_lose_the_following_events(): void {
		$attempt    = 0;
		$dispatcher = $this->dispatcher(
			200,
			function ( string $json ) use ( &$attempt ): void {
				if ( 1 === ++$attempt ) {
					throw new \RuntimeException( 'one insert failed' );
				}
				$this->written[] = $json;
			}
		);

		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );
		$dispatcher->dispatch( 2003, [ 'target' => self::post( 2 ) ] );
		$dispatcher->dispatch( 2004, [ 'target' => self::post( 3 ) ] );
		$dispatcher->flush();

		$this->assertSame( [ 2003, 2004 ], array_column( $this->decoded(), 'code' ) );
	}

	public function test_an_auto_flush_with_a_throwing_sink_does_not_escape_dispatch(): void {
		$dispatcher = $this->dispatcher(
			1,
			static function (): void {
				throw new \RuntimeException( 'queue is down' );
			}
		);

		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );

		$this->addToAssertionCount( 1 );
	}

	public function test_a_throwing_builder_does_not_escape_dispatch(): void {
		$dispatcher = $this->dispatcher();

		$dispatcher->dispatch( 2001, [ 'boom' => true ] );
		$dispatcher->dispatch( 2003, [ 'target' => self::post( 2 ) ] );
		$dispatcher->flush();

		$this->assertSame( [ 2003 ], array_column( $this->decoded(), 'code' ) );
	}

	public function test_flush_writes_the_raw_events_when_the_rules_cannot_be_applied(): void {
		$dispatcher = $this->dispatcher();

		// An int where the change list wants strings: folding the two fails.
		$bad = static fn( string $new ): array => [
			'target'  => [
				'type' => 'user',
				'id'   => '7',
			],
			'changes' => [
				[
					'field' => 'role',
					'old'   => 5,
					'new'   => $new,
				],
			],
		];
		$dispatcher->dispatch( 4002, $bad( 'a' ) );
		$dispatcher->dispatch( 4002, $bad( 'b' ) );
		$dispatcher->flush();

		// Better a duplicate than a lost audit event.
		$this->assertSame( [ 4002, 4002 ], array_column( $this->decoded(), 'code' ) );
	}

	// ---- lazy rules ----------------------------------------------------

	public function test_rules_provider_is_resolved_at_the_first_flush_only(): void {
		$resolved   = 0;
		$dispatcher = $this->dispatcher(
			200,
			null,
			static function () use ( &$resolved ): SuppressionRules {
				++$resolved;

				return SuppressionRules::defaults();
			}
		);
		$this->assertSame( 0, $resolved, 'not at construction' );

		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );
		$this->assertSame( 0, $resolved, 'not at dispatch' );

		$dispatcher->flush();
		$this->assertSame( 1, $resolved );

		$dispatcher->dispatch( 2001, [ 'target' => self::post( 2 ) ] );
		$dispatcher->flush();
		$this->assertSame( 1, $resolved, 'resolved once' );
	}

	public function test_rules_added_after_construction_take_effect(): void {
		$current    = new SuppressionRules();
		$dispatcher = $this->dispatcher( 200, null, static function () use ( &$current ): SuppressionRules {
			return $current;
		} );

		// What a loader does after the dispatcher was built.
		$current = new SuppressionRules( [ 5000 => [ 5001 ] ] );

		$dispatcher->dispatch( 5000, [ 'target' => self::post( 1 ) ] );
		$dispatcher->dispatch( 5001, [ 'target' => self::post( 1 ) ] );
		$dispatcher->flush();

		$this->assertSame( [ 5001 ], array_column( $this->decoded(), 'code' ) );
	}

	public function test_a_failing_rules_provider_falls_back_to_the_initial_table(): void {
		$dispatcher = $this->dispatcher(
			200,
			null,
			static function (): SuppressionRules {
				throw new \RuntimeException( 'a filter callback failed' );
			}
		);

		$dispatcher->dispatch(
			2006,
			[
				'target'  => self::post( 1 ),
				'changes' => [
					[
						'field' => 'post_title',
						'old'   => 'A',
						'new'   => 'B',
					],
				],
			]
		);
		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );
		$dispatcher->flush();

		$this->assertSame( [ 2001 ], array_column( $this->decoded(), 'code' ) );
	}

	public function test_a_provider_that_returns_something_else_falls_back_to_the_initial_table(): void {
		$dispatcher = $this->dispatcher( 200, null, static fn() => null );

		$dispatcher->dispatch( 2006, [ 'target' => self::post( 1 ) ] );
		$dispatcher->flush();

		// 2006 without changes is dropped by the initial table.
		$this->assertSame( [], $this->written );
	}

	// ---- final flush ---------------------------------------------------

	public function test_events_after_the_final_flush_are_written_through(): void {
		$dispatcher = $this->dispatcher();

		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );
		$dispatcher->flush_final();
		$this->assertSame( [ 2001 ], array_column( $this->decoded(), 'code' ) );

		$dispatcher->dispatch( 2003, [ 'target' => self::post( 2 ) ] );
		$this->assertSame( [ 2001, 2003 ], array_column( $this->decoded(), 'code' ), 'written at once, no further flush needed' );
	}

	public function test_written_through_events_still_go_through_the_rules(): void {
		$dispatcher = $this->dispatcher();
		$dispatcher->flush_final();

		// An empty "post updated" is dropped by the rules even on its own.
		$dispatcher->dispatch( 2006, [ 'target' => self::post( 1 ) ] );
		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );

		$this->assertSame( [ 2001 ], array_column( $this->decoded(), 'code' ) );
	}

	public function test_a_plain_flush_does_not_start_writing_through(): void {
		$dispatcher = $this->dispatcher();

		$dispatcher->flush();
		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );

		$this->assertSame( [], $this->written );
	}

	public function test_a_throwing_sink_does_not_escape_a_written_through_dispatch(): void {
		$dispatcher = $this->dispatcher(
			200,
			static function (): void {
				throw new \RuntimeException( 'queue is down' );
			}
		);
		$dispatcher->flush_final();

		$dispatcher->dispatch( 2001, [ 'target' => self::post( 1 ) ] );

		$this->addToAssertionCount( 1 );
	}

	public function test_an_unencodable_payload_is_skipped(): void {
		$dispatcher = $this->dispatcher();

		$dispatcher->dispatch(
			2001,
			[
				'target'  => self::post( 1 ),
				'message' => "broken \xB1\x31 text",
			]
		);
		$dispatcher->dispatch( 2003, [ 'target' => self::post( 2 ) ] );
		$dispatcher->flush();

		$this->assertSame( [ 2003 ], array_column( $this->decoded(), 'code' ) );
	}

	public function test_without_wordpress_json_keeps_unicode_and_slashes_readable(): void {
		$dispatcher = $this->dispatcher();

		$dispatcher->dispatch(
			2001,
			[
				'target'  => self::post( 1 ),
				'message' => 'Árvíztűrő tükörfúrógép https://example.com/a',
			]
		);
		$dispatcher->flush();

		$this->assertStringContainsString( 'Árvíztűrő tükörfúrógép https://example.com/a', $this->written[0] );
	}
}
