<?php
/**
 * Machine-readable export of the event catalog.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors;

use HelloLog\Events\EventCatalog;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the registered {@see EventCatalog} into a plain, code-sorted list so
 * other tooling can consume the same definitions the sensors use, instead of
 * keeping a hand-copied table in step with the seeder.
 */
final class CatalogExporter {

	/**
	 * One row per registered code, ascending by code.
	 *
	 * `label` is the definition's message template verbatim; `vars` are the
	 * `{placeholder}` names found in it, in order of first appearance.
	 *
	 * @return list<array{code:int,label:string,severity:string,object:string,event_type:string,vars:list<string>}>
	 */
	public static function export( EventCatalog $catalog ): array {
		$definitions = $catalog->all();
		ksort( $definitions );

		$rows = [];
		foreach ( $definitions as $definition ) {
			$rows[] = [
				'code'       => $definition->code,
				'label'      => $definition->message_template,
				'severity'   => $definition->severity,
				'object'     => $definition->object,
				'event_type' => $definition->event_type,
				'vars'       => self::placeholders( $definition->message_template ),
			];
		}

		return $rows;
	}

	/**
	 * @return list<string>
	 */
	private static function placeholders( string $template ): array {
		if ( ! preg_match_all( '/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $template, $matches ) ) {
			return [];
		}

		return array_values( array_unique( $matches[1] ) );
	}
}
