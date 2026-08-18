<?php
/**
 * Deactivation routine — unschedule background work.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin deactivation. Cancels recurring jobs and lets WordPress
 * unhook the listeners. The queue table and options stay so a temporary
 * deactivation does not lose pending events.
 */
final class Deactivator {

	private const ACTION_GROUP = 'hellolog';
	private const HOOKS        = [
		'hellolog_flush_queue',
		'hellolog_file_integrity_scan',
		'hellolog_prune_as',
	];

	public static function deactivate(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		foreach ( self::HOOKS as $hook ) {
			as_unschedule_all_actions( $hook, [], self::ACTION_GROUP );
		}
	}
}
