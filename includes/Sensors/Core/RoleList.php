<?php
/**
 * How a role change names the roles a user had and has.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A role change (4002) tells the user's roles before and after as one text
 * each: every role once, sorted, joined by `, `. A user can have several
 * roles, and none: no role before reads as an addition, so that side is null
 * rather than an empty text.
 */
final class RoleList {

	/**
	 * Role slugs as the `role` change reports them: each once, sorted, joined
	 * by `, `. Empty for no role.
	 *
	 * @param array<int, string> $roles Role slugs.
	 */
	public static function joined( array $roles ): string {
		$roles = array_values( array_unique( $roles ) );
		sort( $roles, SORT_STRING );

		return implode( ', ', $roles );
	}

	/**
	 * {@see self::joined()}, or null for no role.
	 *
	 * @param array<int, string> $roles Role slugs.
	 */
	public static function joined_or_null( array $roles ): ?string {
		$list = self::joined( $roles );

		return '' === $list ? null : $list;
	}

	/**
	 * The fields of a role change: the roles before and after as template
	 * values and as the `role` change.
	 *
	 * A null is no template value, and `{old_role}` would stay in the message
	 * as it stands. So a change from no role passes the empty text the
	 * template has always been rendered with, as a var of its own.
	 *
	 * @param ?string $old Roles before as {@see self::joined()} gives them; null for none.
	 * @param string  $new Roles after.
	 * @return array{old_role: ?string, new_role: string, changes: array<int, array{field: string, old: ?string, new: string}>, vars?: array{old_role: string}}
	 */
	public static function change_fields( ?string $old, string $new ): array {
		$fields = [
			'old_role' => $old,
			'new_role' => $new,
			'changes'  => [
				[
					'field' => 'role',
					'old'   => $old,
					'new'   => $new,
				],
			],
		];
		if ( null === $old ) {
			$fields['vars'] = [ 'old_role' => '' ];
		}

		return $fields;
	}
}
