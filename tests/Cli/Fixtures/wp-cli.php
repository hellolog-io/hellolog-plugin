<?php
/**
 * Stand-ins for `WP_CLI` and the option calls the sensor commands make.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `class_exists()` and `function_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	$GLOBALS['hellolog_test_options'] = [];

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

	/**
	 * @param string $option        Option name.
	 * @param mixed  $default_value Answer when the option does not exist.
	 * @return mixed
	 */
	function get_option( string $option, $default_value = false ) {
		return $GLOBALS['hellolog_test_options'][ $option ] ?? $default_value;
	}

	/**
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 */
	function update_option( string $option, $value ): bool {
		$GLOBALS['hellolog_test_options'][ $option ] = $value;

		return true;
	}
}
