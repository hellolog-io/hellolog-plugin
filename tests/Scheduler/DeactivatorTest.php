<?php
/**
 * Tests for {@see HelloLog\Deactivator}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Scheduler;

use HelloLog\Deactivator;
use PHPUnit\Framework\TestCase;

/**
 * Deactivating always cancels the background jobs, whatever happens to
 * helloLOG's own last event first.
 */
final class DeactivatorTest extends TestCase {

	/**
	 * A plugin that never booted in this request has nothing to send: the
	 * jobs are still cancelled.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_jobs_are_cancelled_when_there_is_nothing_to_send(): void {
		require_once __DIR__ . '/wp-stubs.php';
		hellolog_test_reset_wp_stubs();
		$GLOBALS['__hlg_as_scheduled']['hellolog_flush_queue'] = 1;

		Deactivator::deactivate( true );

		$this->assertSame( 4, $GLOBALS['__hlg_as_calls']['unschedule'] );
		$this->assertArrayNotHasKey( 'hellolog_flush_queue', $GLOBALS['__hlg_as_scheduled'] );
	}
}
