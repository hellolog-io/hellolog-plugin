<?php
/**
 * Stand-in for core's `wp_strip_all_tags()`, which the order sensor uses when
 * it exists.
 *
 * Loaded only by tests that run in a separate process: defining it makes
 * `function_exists( 'wp_strip_all_tags' )` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	/**
	 * Like core's: whole `script` and `style` blocks go, then every tag, then
	 * the whitespace around the text.
	 *
	 * @param string $text Text with markup.
	 */
	function wp_strip_all_tags( string $text ): string {
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );

		return trim( strip_tags( $text ) );
	}
}
