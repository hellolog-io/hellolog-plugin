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
use HelloLog\Events\RequestContext;
use HelloLog\Events\RequestSource;

defined( 'ABSPATH' ) || exit;

/**
 * The prefix-based option sensors (`woocommerce_*`, `rank_math*`, `wpseo*`,
 * `termly*`, `lw_*`) see every write under their prefix, and plugins write
 * plenty of bookkeeping there: queues, notices, version stamps, caches. Left
 * alone that reads as "settings changed", twice per save (once in the admin
 * request, once from a background run).
 *
 * A write counts as a setting change when somebody made it: a signed-in
 * person on an admin screen, admin-ajax or the REST API, or a WP-CLI command;
 * and the option is not plugin housekeeping. Unattended runs (cron, Action
 * Scheduler, also when they run inside WP-CLI) and visitors are not. The HTTP
 * method plays no part: a request can change its own method (`_method`,
 * `X-HTTP-Method-Override`) and plugins save settings from signed-in GET
 * links, so a method rule would be an easy way to slip a change past the log.
 * The method is recorded with the event instead, see
 * {@see self::request_metadata()}. {@see self::should_log()} is the pure rule;
 * the sensors call {@see self::saved_changes()}, which feeds it the live
 * request.
 */
final class OptionChangePolicy {

	/**
	 * Option names that are plugin housekeeping, not a setting somebody chose.
	 *
	 * `last` and `timestamp` count after a hyphen as well as an underscore:
	 * WooCommerce names a daily refresh stamp `woocommerce_admin-wc-helper-last-refresh`.
	 *
	 * Two endings are deliberately narrow because real settings share them:
	 * `_notices$` (plural; `woocommerce_demo_store_notice` is a setting, so a
	 * singular `_notice` only counts as `_admin_notice`), and
	 * `_marketplace_suggestions$` unless it follows `_show`
	 * (`woocommerce_show_marketplace_suggestions` is a switch somebody flips).
	 */
	private const INTERNAL = '/(^_|_transient|_queue|_notifications?$|_notices$|_admin_notices?$|_tracks_|_cache|_locks?$|_log$|_bans$|_baseline$|_rev$|_done$|_skipped_|_version$|_db_version|[_-]last[_-]|[_-]timestamp$|_onboarding|_task_list|(?<!_show)_marketplace_suggestions$|_admin_notes?|_dismissed|_flush_rewrite|_schema_version|_usage_tracking|_table_created$)/';

	/**
	 * Whether a write to an option should reach the log.
	 *
	 * By source:
	 * - `admin`, `ajax`, `rest`: a signed-in person's write.
	 * - `cli`: always, with or without `--user`; a command is deliberate.
	 * - everything else (frontend, cron, Action Scheduler, XML-RPC): never.
	 * Unattended work (`$background`) is never logged, whatever the source;
	 * that is how a `wp cron event run` is kept out.
	 *
	 * @param string $option     Option name.
	 * @param string $source     Entry point of the request, see {@see RequestSource}.
	 * @param bool   $has_actor  Whether a signed-in user made the request.
	 * @param bool   $background Whether the code runs unattended (cron or a queued job).
	 */
	public static function should_log( string $option, string $source, bool $has_actor, bool $background = false ): bool {
		if ( $background || self::is_internal( $option ) ) {
			return false;
		}

		return match ( $source ) {
			RequestSource::ADMIN, RequestSource::AJAX, RequestSource::REST => $has_actor,
			RequestSource::CLI                                             => true,
			default                                                        => false,
		};
	}

	/**
	 * {@see self::should_log()} for the request being served right now.
	 *
	 * The name check runs first: it is a plain string match and settles most
	 * writes before the request is inspected. A write made while WordPress is
	 * still determining the current user has no signed-in person yet, and the
	 * user is not read there, see {@see RequestContext::is_determining_user()}.
	 *
	 * @param string $option Option name.
	 */
	public static function should_log_current( string $option ): bool {
		if ( self::is_internal( $option ) ) {
			return false;
		}

		$has_actor  = ! RequestContext::is_determining_user() && function_exists( 'get_current_user_id' ) && get_current_user_id() > 0;
		$background = ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ActionSchedulerContext::is_running();

		return self::should_log( $option, RequestSource::current(), $has_actor, $background );
	}

	/**
	 * What the event should say about the request itself: its HTTP method,
	 * upper-cased, when it is an HTTP request. A WP-CLI command has none,
	 * although WP-CLI sets `REQUEST_METHOD` itself. A value that is not a
	 * plain method word is left out rather than sent.
	 *
	 * @return array{http_method?: string}
	 */
	public static function request_metadata(): array {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || RequestSource::CLI === RequestSource::current() ) {
			return [];
		}

		$method = self::method_name( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );

		return null === $method ? [] : [ 'http_method' => $method ];
	}

	/**
	 * The upper-cased method when the text is a plain method word (letters
	 * and hyphens, at most 20, nothing before or after it); null otherwise.
	 *
	 * @param string $method What the server reports as the request method.
	 */
	public static function method_name( string $method ): ?string {
		$method = strtoupper( $method );

		return 1 === preg_match( '/^[A-Z][A-Z-]{0,19}\z/D', $method ) ? $method : null;
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
