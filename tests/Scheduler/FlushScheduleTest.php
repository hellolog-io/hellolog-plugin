<?php
/**
 * Tests for {@see HelloLog\Scheduler\FlushSchedule}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Scheduler;

use HelloLog\Scheduler\FlushSchedule;
use PHPUnit\Framework\TestCase;

/**
 * Pure decision logic for the Action Scheduler flush lifecycle — no WP
 * dependencies, so it pins the schedule/unschedule/clamp rules in isolation.
 * This is the logic that prevents the 0.3.1 runaway: an inactive install must
 * resolve to `unschedule`, never `schedule`.
 */
final class FlushScheduleTest extends TestCase {

	public function test_active_and_not_scheduled_schedules(): void {
		$this->assertSame( 'schedule', FlushSchedule::reconcile( true, false ) );
	}

	public function test_active_and_scheduled_is_noop(): void {
		$this->assertSame( 'noop', FlushSchedule::reconcile( true, true ) );
	}

	public function test_inactive_and_scheduled_unschedules(): void {
		$this->assertSame( 'unschedule', FlushSchedule::reconcile( false, true ) );
	}

	public function test_inactive_and_not_scheduled_is_noop(): void {
		$this->assertSame( 'noop', FlushSchedule::reconcile( false, false ) );
	}

	public function test_interval_floors_below_minimum_to_sixty(): void {
		$this->assertSame( 60, FlushSchedule::clamp_interval( 30 ) );
		$this->assertSame( 60, FlushSchedule::clamp_interval( 0 ) );
		$this->assertSame( 60, FlushSchedule::clamp_interval( -5 ) );
	}

	public function test_interval_keeps_values_at_or_above_minimum(): void {
		$this->assertSame( 60, FlushSchedule::clamp_interval( 60 ) );
		$this->assertSame( 120, FlushSchedule::clamp_interval( 120 ) );
		$this->assertSame( 300, FlushSchedule::clamp_interval( 300 ) );
	}
}
