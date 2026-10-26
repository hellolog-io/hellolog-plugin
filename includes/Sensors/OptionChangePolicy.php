<?php
/**
 * Decides which option writes are a person saving a setting.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors;

use HelloLog\Events\ActionSchedulerContext;
use HelloLog\Events\ChangeSet;
use HelloLog\Events\RequestSource;

defined( 'ABSPATH' ) || exit;

/**
 * The prefix-based option sensors (`woocommerce_*`, `rank_math*`, `wpseo*`,
 * `termly*`, `lw_*`) see every write under their prefix, and plugins write
 * plenty of bookkeeping there: queues, notices, version stamps, caches. Left
 * alone that reads as "settings changed", twice per save (once in the admin
 * request, once from a background run).
 *
 * A write counts as a setting change only when somebody SAVED it: a signed-in
 * person's POST on an admin screen or admin-ajax, a signed-in write over the
 * REST API, or a WP-CLI command; and the option is not plugin housekeeping.
 * A page view that happens to write an option, or any unattended run (cron,
 * Action Scheduler, also when it runs inside WP-CLI), is bookkeeping.
 * {@see self::should_log()} is the pure rule; the sensors call
 * {@see self::saved_changes()}, which feeds it the live request.
 */
final class OptionChangePolicy {

	/** HTTP methods that only read; a REST call with one of them saves nothing. */
	private const READ_METHODS = [ 'GET', 'HEAD', 'OPTIONS' ];

	/**
	 * Option names that are plugin housekeeping, not a setting somebody chose.
	 *
	 * Two endings are deliberately narrow because real settings share them:
	 * `_notices$` (plural; `woocommerce_demo_store_notice` is a setting, so a
	 * singular `_notice` only counts as `_admin_notice`), and
	 * `_marketplace_suggestions$` unless it follows `_show`
	 * (`woocommerce_show_marketplace_suggestions` is a switch somebody flips).
	 */
	private const INTERNAL = '/(^_|_transient|_queue|_notifications?$|_notices$|_admin_notices?$|_tracks_|_cache|_locks?$|_log$|_bans$|_baseline$|_rev$|_done$|_skipped_|_version$|_db_version|_last_|_timestamp$|_onboarding|_task_list|(?<!_show)_marketplace_suggestions$|_admin_notes?|_dismissed|_flush_rewrite|_schema_version|_usage_tracking)/';

	/**
	 * Whether a write to an option should reach the log.
	 *
	 * By source:
	 * - `admin`, `ajax`: a signed-in person's POST.
	 * - `rest`: a signed-in person's write (any method but GET, HEAD, OPTIONS).
	 * - `cli`: always, with or without `--user`; a command is deliberate.
	 * - everything else (frontend, cron, Action Scheduler, XML-RPC): never.
	 * Unattended work (`$background`) is never logged, whatever the source;
	 * that is how a `wp cron event run` is kept out.
	 *
	 * @param string $option     Option name.
	 * @param string $source     Entry point of the request, see {@see RequestSource}.
	 * @param bool   $has_actor  Whether a signed-in user made the request.
	 * @param string $method     HTTP method of the request. Defaults to a save so a
	 *                           caller that has no method to give gets the plain
	 *                           source-and-actor rule.
	 * @param bool   $background Whether the code runs unattended (cron or a queued job).
	 */
	public static function should_log( string $option, string $source, bool $has_actor, string $method = 'POST', bool $background = false ): bool {
		if ( $background || self::is_internal( $option ) ) {
			return false;
		}

		$method = strtoupper( $method );

		return match ( $source ) {
			RequestSource::ADMIN, RequestSource::AJAX => $has_actor && 'POST' === $method,
			RequestSource::REST                       => $has_actor && '' !== $method && ! in_array( $method, self::READ_METHODS, true ),
			RequestSource::CLI                        => true,
			default                                   => false,
		};
	}

	/**
	 * {@see self::should_log()} for the request being served right now.
	 *
	 * The name check runs first: it is a plain string match and settles most
	 * writes before the request is inspected.
	 *
	 * @param string $option Option name.
	 */
	public static function should_log_current( string $option ): bool {
		if ( self::is_internal( $option ) ) {
			return false;
		}

		$has_actor  = function_exists( 'get_current_user_id' ) && get_current_user_id() > 0;
		$method     = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$background = ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ActionSchedulerContext::is_running();

		return self::should_log( $option, RequestSource::current(), $has_actor, $method, $background );
	}

	/**
	 * What to log for an option write: the old-to-new changes, or an empty
	 * list when the write is not worth an event (not a save, bookkeeping, or
	 * nothing a reader could see changed).
	 *
	 * @param string $option Option name.
	 * @param mixed  $old    Value before the write.
	 * @param mixed  $new    Value after the write.
	 *
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function saved_changes( string $option, $old, $new ): array {
		if ( ! self::should_log_current( $option ) ) {
			return [];
		}

		return self::changes( $option, $old, $new );
	}

	/**
	 * Whether the option name looks like plugin housekeeping.
	 *
	 * @param string $option Option name.
	 */
	public static function is_internal( string $option ): bool {
		return 1 === preg_match( self::INTERNAL, $option );
	}

	/**
	 * The event target of an option: the setting itself.
	 *
	 * @param string $option Option name.
	 *
	 * @return array{type: string, id: string, label: string}
	 */
	public static function target( string $option ): array {
		return [
			'type'  => 'setting',
			'id'    => $option,
			'label' => $option,
		];
	}

	/**
	 * The old-to-new change of an option. A credential-like name is masked on
	 * both sides, see {@see ChangeSet::diff()}.
	 *
	 * @param string $option Option name.
	 * @param mixed  $old    Value before the write.
	 * @param mixed  $new    Value after the write.
	 *
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function changes( string $option, $old, $new ): array {
		return ChangeSet::diff( [ $option => $old ], [ $option => $new ], [ $option ] );
	}
}
