<?php
/**
 * Tests for {@see HelloLog\Sensors\Core\RoleList}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Sensors\Core\RoleList;
use PHPUnit\Framework\TestCase;

final class RoleListTest extends TestCase {

	/**
	 * @dataProvider role_sets
	 *
	 * @param array<int, string> $roles    Role slugs.
	 * @param string             $expected The text of them.
	 */
	public function test_roles_are_each_once_sorted_and_joined( array $roles, string $expected ): void {
		$this->assertSame( $expected, RoleList::joined( $roles ) );
	}

	/**
	 * @return array<string, array{0: array<int, string>, 1: string}>
	 */
	public static function role_sets(): array {
		return [
			'none'          => [ [], '' ],
			'one'           => [ [ 'editor' ], 'editor' ],
			'several'       => [ [ 'subscriber', 'author' ], 'author, subscriber' ],
			'a repeated one' => [ [ 'editor', 'editor' ], 'editor' ],
			'keyed'         => [ [ 3 => 'editor', 5 => 'author' ], 'author, editor' ],
		];
	}

	public function test_no_role_is_null(): void {
		$this->assertNull( RoleList::joined_or_null( [] ) );
		$this->assertNull( RoleList::joined_or_null( [ '' ] ) );
	}

	public function test_some_roles_are_their_text(): void {
		$this->assertSame( 'author, subscriber', RoleList::joined_or_null( [ 'subscriber', 'author' ] ) );
	}

	public function test_a_change_from_roles_names_both_sides(): void {
		$this->assertSame(
			[
				'old_role' => 'author, subscriber',
				'new_role' => 'editor',
				'changes'  => [
					[
						'field' => 'role',
						'old'   => 'author, subscriber',
						'new'   => 'editor',
					],
				],
			],
			RoleList::change_fields( 'author, subscriber', 'editor' )
		);
	}

	public function test_a_change_from_no_role_is_null_on_the_old_side_and_keeps_the_template_var(): void {
		$this->assertSame(
			[
				'old_role' => null,
				'new_role' => 'editor',
				'changes'  => [
					[
						'field' => 'role',
						'old'   => null,
						'new'   => 'editor',
					],
				],
				'vars'     => [ 'old_role' => '' ],
			],
			RoleList::change_fields( null, 'editor' )
		);
	}
}
