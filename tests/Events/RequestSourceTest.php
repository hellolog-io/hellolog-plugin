<?php
/**
 * Tests for {@see HelloLog\Events\RequestSource} and
 * {@see HelloLog\Events\ActionSchedulerContext}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Events;

use HelloLog\Events\ActionSchedulerContext;
use HelloLog\Events\RequestSource;
use PHPUnit\Framework\TestCase;

/**
 * `detect()` is pure and pinned through its precedence table; `current()`
 * only has to survive a WordPress-free process; the Action Scheduler context
 * is a plain depth counter.
 */
final class RequestSourceTest extends TestCase {

	protected function setUp(): void {
		// The counter is static state — start every test from depth 0.
		while ( ActionSchedulerContext::is_running() ) {
			ActionSchedulerContext::end();
		}
	}

	/**
	 * @return array<string, array{0: array<string, bool>, 1: string}>
	 */
	public static function precedence_provider(): array {
		return [
			'nothing set is a front-end request' => [ [], 'frontend' ],
			'all flags false'                    => [
				[
					'cli'              => false,
					'action_scheduler' => false,
					'cron'             => false,
					'xmlrpc'           => false,
					'rest'             => false,
					'ajax'             => false,
					'admin'            => false,
				],
				'frontend',
			],
			'admin alone'                        => [ [ 'admin' => true ], 'admin' ],
			'admin and ajax is ajax'             => [ [ 'admin' => true, 'ajax' => true ], 'ajax' ],
			'admin and rest is rest'             => [ [ 'admin' => true, 'rest' => true ], 'rest' ],
			'rest beats ajax'                    => [ [ 'rest' => true, 'ajax' => true ], 'rest' ],
			'xmlrpc beats rest'                  => [ [ 'xmlrpc' => true, 'rest' => true ], 'xmlrpc' ],
			'cron and admin is cron'             => [ [ 'cron' => true, 'admin' => true ], 'cron' ],
			'cron beats xmlrpc'                  => [ [ 'cron' => true, 'xmlrpc' => true ], 'cron' ],
			'action scheduler beats cron'        => [ [ 'action_scheduler' => true, 'cron' => true ], 'action-scheduler' ],
			'cli beats cron'                     => [ [ 'cli' => true, 'cron' => true ], 'cli' ],
			'cli beats action scheduler'         => [ [ 'cli' => true, 'action_scheduler' => true ], 'cli' ],
			'cli beats everything'               => [
				[
					'cli'              => true,
					'action_scheduler' => true,
					'cron'             => true,
					'xmlrpc'           => true,
					'rest'             => true,
					'ajax'             => true,
					'admin'            => true,
				],
				'cli',
			],
		];
	}

	/**
	 * @dataProvider precedence_provider
	 *
	 * @param array<string, bool> $env      Environment flags.
	 * @param string              $expected Expected source.
	 */
	public function test_precedence( array $env, string $expected ): void {
		$this->assertSame( $expected, RequestSource::detect( $env ) );
	}

	public function test_is_background(): void {
		$this->assertTrue( RequestSource::is_background( 'cron' ) );
		$this->assertTrue( RequestSource::is_background( 'action-scheduler' ) );

		foreach ( [ 'admin', 'frontend', 'rest', 'ajax', 'cli', 'xmlrpc', '' ] as $source ) {
			$this->assertFalse( RequestSource::is_background( $source ), $source );
		}
	}

	public function test_context_depth_counter(): void {
		$this->assertFalse( ActionSchedulerContext::is_running() );

		ActionSchedulerContext::begin();
		ActionSchedulerContext::begin();
		ActionSchedulerContext::end();
		$this->assertTrue( ActionSchedulerContext::is_running() );

		ActionSchedulerContext::end();
		$this->assertFalse( ActionSchedulerContext::is_running() );

		// An unmatched end() must not push the depth below zero…
		ActionSchedulerContext::end();
		$this->assertFalse( ActionSchedulerContext::is_running() );

		// …so a single begin() afterwards is enough to be running again.
		ActionSchedulerContext::begin();
		$this->assertTrue( ActionSchedulerContext::is_running() );
	}

	public function test_current_without_wordpress_is_a_frontend_request(): void {
		$this->assertSame( 'frontend', RequestSource::current() );
	}

	public function test_current_reports_a_running_action_scheduler_job(): void {
		ActionSchedulerContext::begin();
		$this->assertSame( 'action-scheduler', RequestSource::current() );

		ActionSchedulerContext::end();
		$this->assertSame( 'frontend', RequestSource::current() );
	}
}
