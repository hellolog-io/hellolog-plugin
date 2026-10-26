<?php
/**
 * Decides which option writes are a person saving a setting.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors;

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
 * A write counts as a setting change only when a signed-in person made it
 * through an interactive entry point and the option is not plugin
 * housekeeping. {@see self::should_log()} is the pure rule; the sensors call
 * {@see self::should_log_current()}, which feeds it the live request.
 */
final class OptionChangePolicy {

	/**
	 * Entry points where a person can be saving a setting. Everything else
	 * (frontend, cron, Action Scheduler, XML-RPC) is background work or a
	 * visitor.
	 */
	private const INTERACTIVE_SOURCES = [
		RequestSource::ADMIN,
		RequestSource::AJAX,
		RequestSource::REST,
		RequestSource::CLI,
	];

	/**
	 * Option names that are plugin housekeeping, not a setting somebody chose.
	 */
	private const INTERNAL = '/(^_|_transient|_queue|_notifications?$|_notices?$|_tracks_|_cache|_lock$|_version$|_db_version|_last_|_timestamp$|_onboarding|_task_list|_marketplace|_admin_notes?|_dismissed|_flush_rewrite|_schema_version|_usage_tracking)/';

	/**
	 * Whether a write to an option should reach the log.
	 *
	 * @param string $option    Option name.
	 * @param string $source    Entry point of the request, see {@see RequestSource}.
	 * @param bool   $has_actor Whether a signed-in user made the request.
	 */
	public static function should_log( string $option, string $source, bool $has_actor ): bool {
		return $has_actor
			&& in_array( $source, self::INTERACTIVE_SOURCES, true )
			&& ! self::is_internal( $option );
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

		$has_actor = function_exists( 'get_current_user_id' ) && get_current_user_id() > 0;

		return self::should_log( $option, RequestSource::current(), $has_actor );
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
