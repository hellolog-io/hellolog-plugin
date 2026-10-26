<?php
/**
 * Tests for {@see HelloLog\Sensors\Core\TaxonomySensor::is_tracked()}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Sensors\Core\TaxonomySensor;
use PHPUnit\Framework\TestCase;

/**
 * Menus have their own sensor (a menu is a `nav_menu` term, so tracking the
 * term too logs every menu change twice), and the others are internal
 * bookkeeping rather than something an editor manages.
 */
final class TaxonomySensorTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: bool}> taxonomy, expected.
	 */
	public static function taxonomies(): array {
		return [
			'category'              => [ 'category', true ],
			'tag'                   => [ 'post_tag', true ],
			'custom taxonomy'       => [ 'genre', true ],
			'product category'      => [ 'product_cat', true ],
			'product tag'           => [ 'product_tag', true ],
			'product attribute'     => [ 'pa_color', true ],
			'link category'         => [ 'link_category', true ],
			'menu'                  => [ 'nav_menu', false ],
			'post format'           => [ 'post_format', false ],
			'theme of a template'   => [ 'wp_theme', false ],
			'template part area'    => [ 'wp_template_part_area', false ],
			'wrong case'            => [ 'Nav_Menu', true ],
		];
	}

	/**
	 * @dataProvider taxonomies
	 */
	public function test_is_tracked( string $taxonomy, bool $expected ): void {
		$this->assertSame( $expected, TaxonomySensor::is_tracked( $taxonomy ) );
	}
}
