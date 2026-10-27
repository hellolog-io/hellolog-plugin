<?php
/**
 * Tests for {@see HelloLog\Events\ActionSchedulerContext}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Events;

use HelloLog\Events\ActionSchedulerContext;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use PHPUnit\Framework\TestCase;

/**
 * The context is static state, and `register()` needs `add_action()`, so
 * every test runs in its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ActionSchedulerContextTest extends TestCase {

	/**
	 * The job's own `begin_execute` callbacks already run inside the job, so
	 * the context opens before all of them and closes after everything else.
	 */
	public function test_begin_is_hooked_first_and_both_ends_last_with_the_action_id(): void {
		require_once __DIR__ . '/Fixtures/WpCalls.php';
		require_once __DIR__ . '/Fixtures/wordpress-hook-functions.php';

		ActionSchedulerContext::register();

		$this->assertSame(
			[
				'action_scheduler_begin_execute'    => [
					'priority'      => PHP_INT_MIN,
					'accepted_args' => 1,
				],
				'action_scheduler_after_execute'    => [
					'priority'      => PHP_INT_MAX,
					'accepted_args' => 1,
				],
				'action_scheduler_failed_execution' => [
					'priority'      => PHP_INT_MAX,
					'accepted_args' => 1,
				],
			],
			WpCalls::$hooks
		);
	}

	/**
	 * Action Scheduler announces a failure after the success hook when
	 * marking the job complete throws: one job, two ends. The second one
	 * must not close the job around it.
	 */
	public function test_a_job_that_ends_twice_ends_once(): void {
		ActionSchedulerContext::begin( 1 );
		ActionSchedulerContext::begin( 2 );
		ActionSchedulerContext::end( 2 );
		ActionSchedulerContext::end( 2 );

		$this->assertTrue( ActionSchedulerContext::is_running(), 'The outer job is still running.' );

		ActionSchedulerContext::end( 1 );
		$this->assertFalse( ActionSchedulerContext::is_running() );
	}

	public function test_an_end_for_a_job_that_never_began_is_ignored(): void {
		ActionSchedulerContext::begin( 1 );
		ActionSchedulerContext::end( 99 );

		$this->assertTrue( ActionSchedulerContext::is_running() );
	}

	public function test_a_job_can_run_again_after_it_ended(): void {
		ActionSchedulerContext::begin( '3' );
		ActionSchedulerContext::end( 3 );
		ActionSchedulerContext::begin( 3 );

		$this->assertTrue( ActionSchedulerContext::is_running() );

		ActionSchedulerContext::end( '3' );
		$this->assertFalse( ActionSchedulerContext::is_running() );
	}

	public function test_begin_and_end_without_an_id_still_count(): void {
		ActionSchedulerContext::begin();
		ActionSchedulerContext::begin( 4 );
		ActionSchedulerContext::end( 4 );

		$this->assertTrue( ActionSchedulerContext::is_running() );

		ActionSchedulerContext::end();
		$this->assertFalse( ActionSchedulerContext::is_running() );
	}

	/**
	 * How many jobs run, one inside the other: what a job did can be told
	 * from what was done around it.
	 */
	public function test_the_depth_counts_the_jobs_that_run(): void {
		$this->assertSame( 0, ActionSchedulerContext::depth() );

		ActionSchedulerContext::begin( 1 );
		ActionSchedulerContext::begin( 2 );
		$this->assertSame( 2, ActionSchedulerContext::depth() );

		ActionSchedulerContext::end( 2 );
		ActionSchedulerContext::end( 2 );
		$this->assertSame( 1, ActionSchedulerContext::depth() );

		ActionSchedulerContext::end( 1 );
		$this->assertSame( 0, ActionSchedulerContext::depth() );
	}
}
