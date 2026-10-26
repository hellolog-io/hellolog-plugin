<?php
/**
 * Tests for {@see HelloLog\Queue\QueueRetention}'s pure sizing logic.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Queue;

use HelloLog\Queue\QueueRetention;
use PHPUnit\Framework\TestCase;

final class QueueRetentionTest extends TestCase {

	public function test_no_overflow_at_or_below_the_cap(): void {
		$this->assertSame( 0, QueueRetention::overflow( 0, 100 ) );
		$this->assertSame( 0, QueueRetention::overflow( 100, 100 ) );
	}

	public function test_overflow_is_the_excess_over_the_cap(): void {
		$this->assertSame( 1, QueueRetention::overflow( 101, 100 ) );
		$this->assertSame( 4900, QueueRetention::overflow( 5000, 100 ) );
	}

	public function test_cap_is_floored(): void {
		$this->assertSame( QueueRetention::MIN_ROWS, QueueRetention::clamp_max_rows( 5 ) );
		$this->assertSame( 80000, QueueRetention::clamp_max_rows( 80000 ) );
	}

	public function test_dead_retention_is_floored_at_one_day(): void {
		$this->assertSame( 1, QueueRetention::clamp_days( 0 ) );
		$this->assertSame( 14, QueueRetention::clamp_days( 14 ) );
	}
}
