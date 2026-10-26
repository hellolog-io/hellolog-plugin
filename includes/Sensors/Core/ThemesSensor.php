<?php
/**
 * Sensor: theme switch / install / update / delete, customizer saves and
 * automatic update toggles.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Sensors\AbstractSensor;
use Throwable;
use WP_Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Theme lifecycle (5100–5105). Theme installs and updates ride on
 * `upgrader_process_complete` too, so the hook is shared with the
 * plugin path but filtered by the `type` argument.
 *
 * Every event names the theme as its `target` by its stylesheet (the theme's
 * directory). An update says which version it came from: before a package is
 * installed the sensor notes the version of every installed theme, per site,
 * and reads the new one afresh once the upgrade is complete. Uploading a
 * theme over an installed one runs the install, and is told as the update it
 * is. A deletion keeps the name and version read just before the files go.
 */
final class ThemesSensor extends AbstractSensor {

	/** The option listing the themes WordPress updates automatically. */
	private const AUTO_UPDATES = 'auto_update_themes';

	/**
	 * Name and version of every theme seen installed before a package was
	 * installed in this request: blog id => stylesheet => headers. The first
	 * sighting is kept: a bulk update reads the list again after each theme.
	 *
	 * @var array<int, array<string, array{Name: string, Version: string}>>
	 */
	private array $before = [];

	/**
	 * Name and version of the themes about to be deleted: blog id =>
	 * stylesheet => headers.
	 *
	 * @var array<int, array<string, array{Name: string, Version: string}>>
	 */
	private array $deleting = [];

	public function key(): string {
		return 'core-themes';
	}

	public function boot(): void {
		add_action( 'switch_theme', [ $this, 'on_switch' ], 10, 3 );
		add_action( 'delete_theme', [ $this, 'on_delete' ], 10, 1 );
		add_action( 'deleted_theme', [ $this, 'on_deleted' ], 10, 2 );
		add_filter( 'upgrader_pre_install', [ $this, 'on_pre_install' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrader' ], 10, 2 );
		add_action( 'customize_save_after', [ $this, 'on_customizer_save' ], 10, 0 );

		// The list is written like the plugins' one; see PluginsSensor::boot().
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			add_action( 'add_site_option_' . self::AUTO_UPDATES, [ $this, 'on_site_option_added' ], 10, 2 );
			add_action( 'update_site_option_' . self::AUTO_UPDATES, [ $this, 'on_site_option_updated' ], 10, 3 );
		} else {
			add_action( 'added_option', [ $this, 'on_option_added' ], 10, 2 );
			add_action( 'updated_option', [ $this, 'on_option_updated' ], 10, 3 );
		}
	}

	public function on_switch( string $new_name, WP_Theme $new_theme, WP_Theme $old_theme ): void {
		$stylesheet = $new_theme->get_stylesheet();
		$this->emit(
			5100,
			[
				'target'   => self::theme_target( $stylesheet, self::resolve_theme_name( $new_name, $stylesheet ) ),
				'name'     => $new_name,
				'metadata' => [
					'old_theme' => (string) $old_theme->get( 'Name' ),
					'new_theme' => $new_name,
				],
			]
		);
	}

	/**
	 * A theme is about to be deleted: keep its name and version, which can
	 * not be read once its files are gone.
	 */
	public function on_delete( string $stylesheet ): void {
		try {
			$this->deleting[ self::blog_id() ][ $stylesheet ] = self::headers( wp_get_theme( $stylesheet ) );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 5103, [ 'stylesheet' => $stylesheet ] );
		}
	}

	/**
	 * @param mixed $deleted Whether the theme's files were removed.
	 */
	public function on_deleted( string $stylesheet, $deleted ): void {
		$blog  = self::blog_id();
		$known = $this->deleting[ $blog ][ $stylesheet ] ?? null;
		unset( $this->deleting[ $blog ][ $stylesheet ] );

		if ( true !== $deleted ) {
			return;
		}

		$name     = self::resolve_theme_name( $known['Name'] ?? '', $stylesheet );
		$metadata = [ 'stylesheet' => $stylesheet ];
		if ( null !== $known && '' !== $known['Version'] ) {
			$metadata['version'] = $known['Version'];
		}
		$this->emit(
			5103,
			[
				'target'   => self::theme_target( $stylesheet, $name ),
				'name'     => $name,
				'metadata' => $metadata,
			]
		);
	}

	/**
	 * A package is about to be installed: note the installed themes and their
	 * versions, so the upgrade can say which version it came from. A filter,
	 * so it hands back what it was given.
	 *
	 * @param mixed $response   Whether the install goes on; returned untouched.
	 * @param mixed $hook_extra What is being installed.
	 * @return mixed
	 */
	public function on_pre_install( $response, $hook_extra = [] ) {
		if ( ! is_array( $hook_extra ) || ! self::is_theme_job( $hook_extra ) ) {
			return $response;
		}

		// Reading the installed themes can fail; the install must go on.
		try {
			$this->remember_installed();
		} catch ( Throwable $e ) {
			$action = self::action( $hook_extra );
			$this->report_failure( $e, 'install' === $action ? 5101 : 5102, [ 'action' => $action ] );
		}

		return $response;
	}

	/**
	 * @param mixed $upgrader   The upgrader that ran: a Theme_Upgrader for a theme job.
	 * @param mixed $hook_extra What was installed.
	 */
	public function on_upgrader( $upgrader, $hook_extra = [] ): void {
		if ( ! is_array( $hook_extra ) || 'theme' !== ( $hook_extra['type'] ?? '' ) ) {
			return;
		}
		$action = self::action( $hook_extra );

		try {
			$stylesheets = self::installed_stylesheets( $hook_extra, null );
			if ( [] === $stylesheets && is_object( $upgrader ) && method_exists( $upgrader, 'theme_info' ) ) {
				$info        = $upgrader->theme_info();
				$stylesheets = self::installed_stylesheets( $hook_extra, $info instanceof WP_Theme ? $info->get_stylesheet() : null );
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 'install' === $action ? 5101 : 5102, [ 'action' => $action ] );
			return;
		}

		$before = $this->before[ self::blog_id() ] ?? [];
		foreach ( $stylesheets as $stylesheet ) {
			// An install over a theme that was already there replaced it.
			$code = 'install' === $action && ! isset( $before[ $stylesheet ] ) ? 5101 : 5102;
			try {
				$this->emit_upgrade( $code, $stylesheet, $action, $before[ $stylesheet ] ?? null );
			} catch ( Throwable $e ) {
				$this->report_failure( $e, $code, [ 'stylesheet' => $stylesheet ] );
			}
		}
	}

	/**
	 * Customizer changes were published. The theme they belong to is the
	 * active one by now: publishing a preview of another theme switches to it
	 * first.
	 */
	public function on_customizer_save(): void {
		try {
			$theme      = wp_get_theme();
			$stylesheet = $theme->get_stylesheet();
			$this->emit(
				5104,
				[
					'target'   => self::theme_target( $stylesheet, self::resolve_theme_name( $theme->get( 'Name' ), $stylesheet ) ),
					'metadata' => [ 'stylesheet' => $stylesheet ],
				]
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 5104 );
		}
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public function on_option_updated( string $option, $old_value, $value ): void {
		if ( self::AUTO_UPDATES === $option ) {
			$this->log_auto_updates( $old_value, $value );
		}
	}

	/**
	 * @param mixed $value
	 */
	public function on_option_added( string $option, $value ): void {
		if ( self::AUTO_UPDATES === $option ) {
			$this->log_auto_updates( [], $value );
		}
	}

	/**
	 * @param mixed $value
	 * @param mixed $old_value
	 */
	public function on_site_option_updated( string $option, $value, $old_value ): void {
		if ( self::AUTO_UPDATES === $option ) {
			$this->log_auto_updates( $old_value, $value );
		}
	}

	/**
	 * @param mixed $value
	 */
	public function on_site_option_added( string $option, $value ): void {
		if ( self::AUTO_UPDATES === $option ) {
			$this->log_auto_updates( [], $value );
		}
	}

	/**
	 * The stylesheets an upgrader job installed: its `themes` list (bulk
	 * update), else its `theme` (single update), else the theme the upgrader
	 * found in the package it installed (the stylesheet of
	 * `Theme_Upgrader::theme_info()`; an install names no theme). The same
	 * rule as {@see PluginsSensor::installed_files()}, on the theme keys.
	 *
	 * @param array<string, mixed> $hook_extra The job as `upgrader_process_complete` describes it.
	 * @param string|null          $theme_info Stylesheet of the installed package, if known.
	 * @return array<int, string>
	 */
	public static function installed_stylesheets( array $hook_extra, ?string $theme_info ): array {
		return PluginsSensor::installed_files(
			[
				'plugins' => $hook_extra['themes'] ?? [],
				'plugin'  => $hook_extra['theme'] ?? null,
			],
			$theme_info
		);
	}

	/**
	 * The `target` of a theme event.
	 *
	 * @return array{type: string, id: string, label: string}
	 */
	public static function theme_target( string $stylesheet, string $name ): array {
		return [
			'type'  => 'theme',
			'id'    => $stylesheet,
			'label' => $name,
		];
	}

	/**
	 * `WP_Theme::get( 'Name' )` returns `false` (not `''`) when the header is
	 * unreadable, so a plain `?:`/`!==''` check isn't enough — fall back to
	 * the stylesheet slug for anything that isn't a non-empty string.
	 */
	public static function resolve_theme_name( mixed $raw_name, string $stylesheet ): string {
		return is_string( $raw_name ) && '' !== $raw_name ? $raw_name : $stylesheet;
	}

	/**
	 * Emit the install or update of one theme of a completed upgrade.
	 *
	 * @param int                                       $code       5101 or 5102.
	 * @param string                                    $stylesheet Theme directory.
	 * @param string                                    $action     The job's action (`install`, `update`).
	 * @param array{Name: string, Version: string}|null $before     The theme before the job, if it was installed.
	 */
	private function emit_upgrade( int $code, string $stylesheet, string $action, ?array $before ): void {
		$after = self::headers( self::fresh_theme( $stylesheet ) );
		$name  = self::resolve_theme_name( '' !== $after['Name'] ? $after['Name'] : ( $before['Name'] ?? '' ), $stylesheet );

		$metadata = [
			'stylesheet' => $stylesheet,
			'action'     => $action,
		];
		if ( '' !== $after['Version'] ) {
			$metadata['version'] = $after['Version'];
		}

		$fields = [
			'target'   => self::theme_target( $stylesheet, $name ),
			'name'     => $name,
			'version'  => $after['Version'],
			'metadata' => $metadata,
		];

		$changes = null === $before ? [] : PluginsSensor::version_changes( [ $stylesheet => $before ], [ $stylesheet => $after ] );
		if ( isset( $changes[ $stylesheet ] ) ) {
			$fields['changes'] = [ $changes[ $stylesheet ] ];
		}

		$this->emit( $code, $fields );
	}

	/**
	 * One 5105 per theme whose automatic updates were turned on or off. A
	 * theme that is no longer installed was only dropped from the list when
	 * it was saved; nobody turned its updates off.
	 *
	 * @param mixed $old The list before the write.
	 * @param mixed $new The list after the write.
	 */
	private function log_auto_updates( $old, $new ): void {
		// Reading the themes can fail; that must never reach the request that
		// saved the list.
		try {
			foreach ( PluginsSensor::auto_update_toggles( $old, $new ) as $toggle ) {
				$stylesheet = $toggle['entry'];
				$theme      = wp_get_theme( $stylesheet );
				if ( 'disabled' === $toggle['state'] && ! $theme->exists() ) {
					continue;
				}

				$name = self::resolve_theme_name( $theme->get( 'Name' ), $stylesheet );
				$this->emit(
					5105,
					[
						'target'   => self::theme_target( $stylesheet, $name ),
						'state'    => $toggle['state'],
						'name'     => $name,
						'metadata' => [ 'stylesheet' => $stylesheet ],
					]
				);
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 5105, [ 'option' => self::AUTO_UPDATES ] );
		}
	}

	/**
	 * Note every installed theme not seen yet on this site.
	 */
	private function remember_installed(): void {
		$blog = self::blog_id();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$stylesheet = (string) $stylesheet;
			if ( $theme instanceof WP_Theme && ! isset( $this->before[ $blog ][ $stylesheet ] ) ) {
				$this->before[ $blog ][ $stylesheet ] = self::headers( $theme );
			}
		}
	}

	/**
	 * The theme as its files are now. Core keeps the headers of every theme
	 * it read for the rest of the request (the snapshot reads them all), and
	 * a single update clears them only when it also clears the update cache,
	 * which an automatic update does not.
	 */
	private static function fresh_theme( string $stylesheet ): WP_Theme {
		wp_get_theme( $stylesheet )->cache_delete();

		return wp_get_theme( $stylesheet );
	}

	/**
	 * Whether an install step is part of a theme job. A bulk update names
	 * only the `theme` at this step, without a `type`.
	 *
	 * @param array<string, mixed> $hook_extra The job.
	 */
	private static function is_theme_job( array $hook_extra ): bool {
		return 'theme' === ( $hook_extra['type'] ?? null ) || isset( $hook_extra['theme'] );
	}

	/**
	 * @param array<string, mixed> $hook_extra The job.
	 */
	private static function action( array $hook_extra ): string {
		return is_string( $hook_extra['action'] ?? null ) ? $hook_extra['action'] : '';
	}

	/**
	 * Name and version of a theme; empty for a header it does not have.
	 *
	 * @return array{Name: string, Version: string}
	 */
	private static function headers( WP_Theme $theme ): array {
		$name    = $theme->get( 'Name' );
		$version = $theme->get( 'Version' );

		return [
			'Name'    => is_string( $name ) ? $name : '',
			'Version' => is_string( $version ) ? $version : '',
		];
	}

	private static function blog_id(): int {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	}
}
