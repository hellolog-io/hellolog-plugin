<?php
/**
 * Stand-in for the main WooCommerce class: what a sensor checks to tell
 * whether the shop is active.
 *
 * Loaded only by tests that run in a separate process: defining it makes
 * `class_exists( 'WooCommerce' )` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	/**
	 * Nothing but its name is used.
	 */
	final class WooCommerce {
	}
}
