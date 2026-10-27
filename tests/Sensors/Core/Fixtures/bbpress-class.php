<?php
/**
 * Stand-in for the main bbPress class: what a sensor checks to tell whether
 * the forums are active.
 *
 * Loaded only by tests that run in a separate process: defining it makes
 * `class_exists( 'bbPress' )` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	/**
	 * Nothing but its name is used.
	 */
	final class bbPress {
	}
}
