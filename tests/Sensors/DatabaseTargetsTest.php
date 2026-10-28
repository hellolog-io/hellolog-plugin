<?php
/**
 * Tests for {@see HelloLog\Sensors\DatabaseTargets}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors;

use HelloLog\Sensors\DatabaseTargets;
use PHPUnit\Framework\TestCase;

/**
 * A table has one target, whichever way a statement writes its name.
 */
final class DatabaseTargetsTest extends TestCase {

	/**
	 * @dataProvider tables
	 *
	 * @param ?string $database The database the statement names, null for none.
	 * @param string  $table    The table alone.
	 * @param ?string $current  The database WordPress works in.
	 * @param string  $expected The id and the label of the target.
	 */
	public function test_a_table_is_named_alone_in_the_database_of_the_site_and_qualified_in_any_other( ?string $database, string $table, ?string $current, string $expected ): void {
		$this->assertSame(
			[
				'type'  => 'table',
				'id'    => $expected,
				'label' => $expected,
			],
			DatabaseTargets::table( $database, $table, $current )
		);
	}

	/**
	 * @return array<string, array{0: ?string, 1: string, 2: ?string, 3: string}>
	 */
	public static function tables(): array {
		return [
			'no database named'                   => [ null, 'wp_shop', 'wordpress', 'wp_shop' ],
			'the database of the site'            => [ 'wordpress', 'wp_shop', 'wordpress', 'wp_shop' ],
			'another database'                    => [ 'otherdb', 'wp_shop', 'wordpress', 'otherdb.wp_shop' ],
			'a database, and the own one unknown' => [ 'wordpress', 'wp_shop', null, 'wordpress.wp_shop' ],
			'a name with a dot in it'             => [ null, 'odd.name', 'wordpress', 'odd.name' ],
		];
	}
}
