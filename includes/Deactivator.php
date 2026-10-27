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
 * Runs on plugin deactivation. First helloLOG's own deactivation is logged
 * and sent, while the plugin still runs, see
 * {@see Plugin::deliver_own_deactivation()}; then the recurring jobs are
 * cancelled and WordPress unhooks the listeners. The queue table and options
 * stay so a temporary deactivation does not lose pending events.
 */
final class Deactivator {

	private const ACTION_GROUP = 'hellolog';
	private const HOOKS        = [
		'hellolog_flush_queue',
		'hellolog_file_integrity_scan',
		'hellolog_prune_as',
		'hellolog_verify_token',
	];

	/**
	 * Deactivation hook.
	 *
	 * @param bool $network_wide Whether the plugin is deactivated for the whole network.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		// Deactivating goes on, whatever happens to the last event.
		try {
			Plugin::instance()->deliver_own_deactivation( $network_wide );
		} catch ( \Throwable $e ) {
			self::report( $e );
		}

		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		foreach ( self::HOOKS as $hook ) {
			as_unschedule_all_actions( $hook, [], self::ACTION_GROUP );
		}
	}

	/**
	 * Report a failure to send the deactivation the way the dispatcher
	 * reports its own. A listener that throws is swallowed too.
	 *
	 * @param \Throwable $e What went wrong.
	 */
	private static function report( \Throwable $e ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		try {
			/** This action is documented in includes/Events/BufferedDispatcher.php */
			do_action( 'hellolog_dispatch_failed', $e, 5002, [] );
		} catch ( \Throwable $callback_error ) {
			return;
		}
	}
}
