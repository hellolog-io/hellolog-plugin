<?php
/**
 * Tests for {@see HelloLog\Sensors\Core\ThemesSensor::resolve_theme_name()}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Sensors\Core\ThemesSensor;
use PHPUnit\Framework\TestCase;

/**
 * `WP_Theme::get( 'Name' )` returns `false` — not `''` — when the header is
 * unreadable. This pins the stylesheet-slug fallback for every falsy shape
 * that call can return, not just the empty string.
 */
final class ThemesSensorTest extends TestCase {

	public function test_false_name_falls_back_to_stylesheet(): void {
		$this->assertSame( 'twentytwentyfive', ThemesSensor::resolve_theme_name( false, 'twentytwentyfive' ) );
	}

	public function test_empty_string_name_falls_back_to_stylesheet(): void {
		$this->assertSame( 'twentytwentyfive', ThemesSensor::resolve_theme_name( '', 'twentytwentyfive' ) );
	}

	public function test_valid_name_is_kept_as_is(): void {
		$this->assertSame( 'Twenty Twenty-Five', ThemesSensor::resolve_theme_name( 'Twenty Twenty-Five', 'twentytwentyfive' ) );
	}
}
