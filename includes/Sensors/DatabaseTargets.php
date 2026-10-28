<?php
/**
 * The targets of the database's objects.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors;

defined( 'ABSPATH' ) || exit;

/**
 * How an event names a database table as its target. It never calls into
 * WordPress.
 */
final class DatabaseTargets {

	/**
	 * A table, named by its own name. A statement may write the database
	 * before it (`wordpress.wp_shop`); the database WordPress works in is
	 * left out then, so one table keeps one history however it is written. A
	 * table of another database keeps it.
	 *
	 * @param ?string $database The database the statement names; null for none.
	 * @param string  $table    The table alone.
	 * @param ?string $current  The database WordPress works in; null when it is not known.
	 * @return array{type: string, id: string, label: string}
	 */
	public static function table( ?string $database, string $table, ?string $current ): array {
		$name = null === $database || $database === $current ? $table : $database . '.' . $table;

		return [
			'type'  => 'table',
			'id'    => $name,
			'label' => $name,
		];
	}
}
