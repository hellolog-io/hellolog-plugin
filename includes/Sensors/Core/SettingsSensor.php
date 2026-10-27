<?php
/**
 * Sensor: WordPress general settings changes and role capability edits.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Events\ChangeSet;
use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\OptionChangePolicy;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Subscribes to {@see updated_option} and {@see added_option} and reports
 * changes to the well-known options. We special-case the high-impact ones
 * (site URL, admin email, search-engine visibility, permalink structure) so
 * they get their own severity-MEDIUM/HIGH codes; everything else in the
 * watchlist rolls up to code 6000. `update_option()` adds an option that does
 * not exist yet, and only `added_option` tells that write: it is logged from
 * nothing (old value null).
 *
 * Each event has the setting as its target and the old-to-new change, built
 * the way the plugin settings sensors build theirs (see
 * {@see OptionChangePolicy::target()} and {@see OptionChangePolicy::changes()}),
 * and every value it carries is masked (a password in a URL, a
 * credential-like key in JSON). The comment word lists are text somebody
 * wrote: they are reported as changed, without their words. Unlike plugin
 * settings, every write to a watched option is logged, whoever made it: a
 * cron job or a visitor's request changing the site address is exactly what
 * has to be on record.
 *
 * The roles of a site live in one option, `{table prefix}user_roles`, and
 * `WP_Roles` writes all of it on every role or capability it adds or removes:
 * a plugin setting up its capabilities writes it many times in one request.
 * So the option is only noted when written (the roles before the first write
 * of the request, per site, and after the last), and one critical event (6005)
 * per site goes out at shutdown, before the request's events are written,
 * telling which roles came or went and which capabilities each role gained or
 * lost; nothing when they ended where they started. A write made later, by
 * another shutdown callback, goes out on its own just before the final flush.
 * The roles a new site of a network is set up with (its roles option is
 * created in the request) are not an edit and are not logged.
 */
final class SettingsSensor extends AbstractSensor {

	private const WATCHED = [
		'blogname',
		'blogdescription',
		'siteurl',
		'home',
		'admin_email',
		'blog_public',
		'permalink_structure',
		'default_role',
		'date_format',
		'time_format',
		'start_of_week',
		'timezone_string',
		'WPLANG',
		'category_base',
		'tag_base',
		'comment_registration',
		'comment_moderation',
		'require_name_email',
		'comments_notify',
		'moderation_notify',
		'users_can_register',
		'posts_per_page',
		'page_for_posts',
		'page_on_front',
		'show_on_front',
		'default_comment_status',
		'default_ping_status',
		'close_comments_for_old_posts',
		'close_comments_days_old',
		'comment_previously_approved',
		'comment_max_links',
		'moderation_keys',
		'disallowed_keys',
		'thread_comments',
		'gmt_offset',
		'site_icon',
		'wp_page_for_privacy_policy',
		'default_category',
		'uploads_use_yearmonth_folders',
	];

	/**
	 * Watched options that hold text somebody wrote (the words that hold a
	 * comment for moderation or throw it out): reported as changed, without
	 * their values.
	 *
	 * @var array<int, string>
	 */
	private const CONTENT = [ 'moderation_keys', 'disallowed_keys' ];

	/**
	 * The roles option as it was before its first write of the request and
	 * after its latest, per site. `old` is null when the option was created
	 * in the request.
	 *
	 * @var array<int, array{option: string, old: ?array<int|string, mixed>, new: array<int|string, mixed>}>
	 */
	private array $roles = [];

	public function key(): string {
		return 'core-settings';
	}

	public function boot(): void {
		add_action( 'updated_option', [ $this, 'on_update' ], 10, 3 );
		add_action( 'added_option', [ $this, 'on_add' ], 10, 2 );
		// Before the request's events are written, at priority 1.
		add_action( 'shutdown', [ $this, 'emit_role_changes' ], 0 );
		// Role writes made by later shutdown callbacks, before the final flush
		// at PHP_INT_MAX.
		add_action( 'shutdown', [ $this, 'emit_role_changes' ], PHP_INT_MAX - 1 );
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public function on_update( string $option, $old_value, $value ): void {
		if ( in_array( $option, self::WATCHED, true ) ) {
			$this->log_setting( $option, $old_value, $value );
			return;
		}

		$this->note_roles( $option, true, $old_value, $value );
	}

	/**
	 * An option written for the first time.
	 *
	 * @param mixed $value
	 */
	public function on_add( string $option, $value ): void {
		if ( in_array( $option, self::WATCHED, true ) ) {
			$this->log_setting( $option, null, $value );
			return;
		}

		$this->note_roles( $option, false, null, $value );
	}

	/**
	 * Emit one event per site whose roles changed in this request, on that
	 * site, and forget the notes. Runs at shutdown, twice: what the first run
	 * emitted is not emitted again.
	 */
	public function emit_role_changes(): void {
		$pending     = $this->roles;
		$this->roles = [];

		foreach ( $pending as $blog_id => $note ) {
			try {
				if ( null === $note['old'] ) {
					continue; // Created in this request: the roles a new site starts with.
				}
				$changes = self::roles_changes( $note['old'], $note['new'] );
				if ( [] === $changes ) {
					continue;
				}

				$this->emit_on_site(
					(int) $blog_id,
					6005,
					[
						'target'   => OptionChangePolicy::target( $note['option'] ),
						'changes'  => $changes,
						'metadata' => [ 'option' => $note['option'] ],
					]
				);
			} catch ( Throwable $e ) {
				$this->report_failure( $e, 6005, [ 'option' => $note['option'] ] );
			}
		}
	}

	/**
	 * What changed between two values of the roles option: a role added
	 * (`role:<slug>`, null to `added`) or removed (`present` to null), and
	 * the capabilities of a role (`caps:<slug>`), with the ones it lost as
	 * the old value and the ones it gained as the new, each a sorted,
	 * comma-separated list (null when there are none). A new role's
	 * capabilities are all gained, a removed role's all lost. A capability
	 * stored as denied (`false`) is one the role does not have, as for
	 * `WP_Role::has_cap()`.
	 *
	 * The items are built here, not by {@see ChangeSet::diff()}: role and
	 * capability names are identifiers, and one that reads like a credential
	 * (`license_key_manager`) must not be masked.
	 *
	 * @param array<int|string, mixed> $old The option before.
	 * @param array<int|string, mixed> $new The option after.
	 *
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function roles_changes( array $old, array $new ): array {
		$slugs = [];
		foreach ( array_merge( array_keys( $old ), array_keys( $new ) ) as $slug ) {
			$slugs[ (string) $slug ] = true;
		}

		$changes = [];
		foreach ( array_keys( $slugs ) as $slug ) {
			$slug = (string) $slug;
			$had  = array_key_exists( $slug, $old );
			$has  = array_key_exists( $slug, $new );
			if ( $had !== $has ) {
				$changes[] = [
					'field' => 'role:' . $slug,
					'old'   => $had ? 'present' : null,
					'new'   => $has ? 'added' : null,
				];
			}

			$before = $had ? self::granted( $old[ $slug ] ) : [];
			$after  = $has ? self::granted( $new[ $slug ] ) : [];
			$lost   = array_diff( $before, $after );
			$gained = array_diff( $after, $before );
			if ( [] !== $lost || [] !== $gained ) {
				$changes[] = [
					'field' => 'caps:' . $slug,
					'old'   => self::cap_list( $lost ),
					'new'   => self::cap_list( $gained ),
				];
			}
		}

		return $changes;
	}

	/**
	 * Whether an option is the roles option of the site whose tables start
	 * with `$prefix`.
	 *
	 * @param string $option Option name.
	 * @param string $prefix Table prefix of the site.
	 */
	public static function is_roles_option( string $option, string $prefix ): bool {
		return $prefix . 'user_roles' === $option;
	}

	/**
	 * Report a write to a watched option.
	 *
	 * @param mixed $old_value Value before the write; null when the option did not exist.
	 * @param mixed $value     Value after the write.
	 */
	private function log_setting( string $option, $old_value, $value ): void {
		// Reading the values of a write can fail (an object whose text throws);
		// that must never reach the request that saved the setting.
		try {
			if ( $old_value === $value ) {
				return;
			}

			$content = in_array( $option, self::CONTENT, true );
			$changes = $content
				? ChangeSet::diff( [ $option => $old_value ], [ $option => $value ], [ $option ], [ $option ] )
				: OptionChangePolicy::changes( $option, $old_value, $value );
			if ( [] === $changes ) {
				return; // Nothing a reader could see changed (`1` and `'1'`).
			}

			$fields = [
				'option'   => $option,
				'target'   => OptionChangePolicy::target( $option ),
				'changes'  => $changes,
				'metadata' => [ 'option' => $option ],
			];
			if ( ! $content ) {
				$old = null === $old_value ? null : ChangeSet::redact_text( $this->stringify( $old_value ) );
				$new = ChangeSet::redact_text( $this->stringify( $value ) );

				$fields['old']             = $old ?? '';
				$fields['new']             = $new;
				$fields['state']           = $new;
				$fields['metadata']['old'] = $old;
				$fields['metadata']['new'] = $new;
			}

			$this->emit( $this->code_for( $option ), $fields );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, $this->code_for( $option ), [ 'option' => $option ] );
		}
	}

	/**
	 * Note a write to the roles option of the current site: the roles before
	 * it, if it is the first of the request, and the roles after it.
	 *
	 * @param string $option  Option name.
	 * @param bool   $existed Whether the option existed before the write.
	 * @param mixed  $old     Value before the write.
	 * @param mixed  $value   Value after the write.
	 */
	private function note_roles( string $option, bool $existed, $old, $value ): void {
		try {
			$prefix = self::table_prefix();
			if ( null === $prefix || ! self::is_roles_option( $option, $prefix ) ) {
				return;
			}

			$blog_id = self::blog_id();
			if ( ! isset( $this->roles[ $blog_id ] ) ) {
				$this->roles[ $blog_id ] = [
					'option' => $option,
					'old'    => $existed ? self::role_map( $old ) : null,
					'new'    => [],
				];
			}
			$this->roles[ $blog_id ]['new'] = self::role_map( $value );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 6005, [ 'option' => $option ] );
		}
	}

	private function code_for( string $option ): int {
		return match ( $option ) {
			'siteurl', 'home'     => 6001,
			'admin_email'         => 6002,
			'permalink_structure' => 6003,
			'blog_public'         => 6004,
			default               => 6000,
		};
	}

	/**
	 * The text of a value, before masking. Arrays and objects are written out
	 * whole: masking has to read the JSON to find a credential-like key in it,
	 * and the event builder shortens long metadata values afterwards.
	 *
	 * @param mixed $value
	 */
	private function stringify( $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		$encoded = wp_json_encode( $value );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Emit as if on the given site, so the event lands on the site where it
	 * happened; the current site is restored afterwards.
	 *
	 * @param int                  $blog_id Site id; 0 when unknown.
	 * @param int                  $code    Event code.
	 * @param array<string, mixed> $fields  Event fields.
	 */
	private function emit_on_site( int $blog_id, int $code, array $fields ): void {
		$switch = $blog_id > 0 && self::blog_id() !== $blog_id && function_exists( 'switch_to_blog' );
		if ( $switch ) {
			switch_to_blog( $blog_id );
		}

		try {
			$this->emit( $code, $fields );
		} finally {
			if ( $switch ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * The roles option as an array; anything else (a broken value) holds no
	 * roles.
	 *
	 * @param mixed $value Value of the roles option.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function role_map( $value ): array {
		return is_array( $value ) ? $value : [];
	}

	/**
	 * Names of the capabilities a role has: those stored with a true value.
	 *
	 * @param mixed $role A role of the roles option (`name`, `capabilities`).
	 *
	 * @return array<int, string>
	 */
	private static function granted( $role ): array {
		$caps = is_array( $role ) && isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : [];

		$granted = [];
		foreach ( $caps as $cap => $grant ) {
			if ( ! empty( $grant ) ) {
				$granted[] = (string) $cap;
			}
		}

		return $granted;
	}

	/**
	 * Capability names as a change value: sorted, joined by `, `, cut to the
	 * value limit; null for none.
	 *
	 * @param array<int|string, string> $caps Capability names.
	 */
	private static function cap_list( array $caps ): ?string {
		return [] === $caps ? null : ChangeSet::stringify( array_values( $caps ) );
	}

	/**
	 * Table prefix of the current site; null outside WordPress.
	 */
	private static function table_prefix(): ?string {
		global $wpdb;

		return isset( $wpdb->prefix ) && is_string( $wpdb->prefix ) ? $wpdb->prefix : null;
	}

	/**
	 * The current site; 0 outside WordPress.
	 */
	private static function blog_id(): int {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	}
}
