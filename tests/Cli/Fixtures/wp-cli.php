<?php
/**
 * Stand-in for the `WP_CLI` class the commands report through. Load the
 * WordPress stand-ins (`Support/wordpress-functions.php`) with it.
 *
 * Loaded only by tests that run in a separate process: defining it makes
 * `class_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	/**
	 * Like WP-CLI, an error ends the command; here it throws instead of exiting.
	 */
	class WP_CLI {

		/** @var array<int, string> */
		public static array $successes = [];

		/**
		 * @param string $message Message.
		 * @throws \LogicException Always.
		 */
		public static function error( string $message ): void {
			throw new \LogicException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test stand-in.
		}

		public static function success( string $message ): void {
			self::$successes[] = $message;
		}
	}
}
