<?php
/**
 * Sensor: plugin install/activate/deactivate/update/delete and automatic
 * update toggles.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Events\ChangeSet;
use HelloLog\Sensors\AbstractSensor;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Drives the plugin-lifecycle codes (5000–5005). Uses the standard WP
 * hooks; `upgrader_process_complete` covers both fresh install and
 * update because both flow through Plugin_Upgrader.
 *
 * Every event names the plugin as its `target` by its slug (the plugin's
 * directory, or the file name without `.php` for a single-file plugin):
 * plugin files are often longer than a target id may be. The full plugin
 * file is always in `metadata.plugin_file`.
 *
 * An update says which version it came from. Before a package is installed
 * the sensor notes the version of every installed plugin, per site; the new
 * version is read from the plugin's file once the upgrade is complete, since
 * an automatic update leaves core's plugin list of the request as it was.
 * Uploading a plugin over an installed one runs the install, and is told as
 * the update it is. A deletion keeps the name and version read just before
 * the files go.
 *
 * helloLOG's own deactivation is not logged here: helloLOG logs and sends it
 * from its own deactivation hook ({@see \HelloLog\Plugin::deliver_own_deactivation()},
 * with {@see self::deactivation_fields()}), which runs before
 * `deactivated_plugin`; a second event here would be queued after that last
 * send and go out stale when the plugin is turned on again.
 */
final class PluginsSensor extends AbstractSensor {

	/** The option listing the plugins WordPress updates automatically. */
	private const AUTO_UPDATES = 'auto_update_plugins';

	/**
	 * Name and version of every plugin seen installed before a package was
	 * installed in this request: blog id => plugin file => headers. The first
	 * sighting is kept: a bulk update reads the list again after each plugin.
	 *
	 * @var array<int, array<string, array{Name: string, Version: string}>>
	 */
	private array $before = [];

	/**
	 * Name and version of the plugins about to be deleted: blog id => plugin
	 * file => headers.
	 *
	 * @var array<int, array<string, array{Name: string, Version: string}>>
	 */
	private array $deleting = [];

	public function key(): string {
		return 'core-plugins';
	}

	public function boot(): void {
		add_action( 'activated_plugin', [ $this, 'on_activated' ], 10, 2 );
		add_action( 'deactivated_plugin', [ $this, 'on_deactivated' ], 10, 2 );
		add_action( 'delete_plugin', [ $this, 'on_delete' ], 10, 1 );
		add_action( 'deleted_plugin', [ $this, 'on_deleted' ], 10, 2 );
		add_filter( 'upgrader_pre_install', [ $this, 'on_pre_install' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrader' ], 10, 2 );

		// Core writes the list with update_site_option(). On a single site that
		// ends in update_option() (add_option() the first time), which fires the
		// option hooks AND the site-option ones; on a network only the
		// site-option hooks fire. One pair per kind of install, never both.
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			add_action( 'add_site_option_' . self::AUTO_UPDATES, [ $this, 'on_site_option_added' ], 10, 2 );
			add_action( 'update_site_option_' . self::AUTO_UPDATES, [ $this, 'on_site_option_updated' ], 10, 3 );
		} else {
			add_action( 'added_option', [ $this, 'on_option_added' ], 10, 2 );
			add_action( 'updated_option', [ $this, 'on_option_updated' ], 10, 3 );
		}
	}

	public function on_activated( string $plugin, bool $network_wide ): void {
		$name = self::name( $plugin );
		$this->emit(
			5001,
			[
				'target'   => self::plugin_target( $plugin, $name ),
				'name'     => $name,
				'metadata' => [
					'plugin_file'  => $plugin,
					'network_wide' => $network_wide,
				],
			]
		);
	}

	public function on_deactivated( string $plugin, bool $network_wide ): void {
		if ( self::is_own( $plugin ) ) {
			return;
		}
		$this->emit( 5002, self::deactivation_fields( $plugin, $network_wide ) );
	}

	/**
	 * The fields of a plugin's deactivation (5002), its name read from its
	 * file. helloLOG builds its own deactivation with them too.
	 *
	 * @param string $plugin       Plugin file, relative to the plugins directory.
	 * @param bool   $network_wide Whether it was deactivated on every site of the network.
	 * @return array<string, mixed>
	 */
	public static function deactivation_fields( string $plugin, bool $network_wide ): array {
		$name = self::name( $plugin );

		return [
			'target'   => self::plugin_target( $plugin, $name ),
			'name'     => $name,
			'metadata' => [
				'plugin_file'  => $plugin,
				'network_wide' => $network_wide,
			],
		];
	}

	/**
	 * A plugin is about to be deleted: keep its name and version, which can
	 * not be read once its files are gone.
	 */
	public function on_delete( string $plugin ): void {
		try {
			$this->deleting[ self::blog_id() ][ $plugin ] = self::headers( self::plugin_data( $plugin ) );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 5004, [ 'plugin_file' => $plugin ] );
		}
	}

	/**
	 * @param mixed $deleted Whether the plugin's files were removed.
	 */
	public function on_deleted( string $plugin, $deleted ): void {
		$blog  = self::blog_id();
		$known = $this->deleting[ $blog ][ $plugin ] ?? null;
		unset( $this->deleting[ $blog ][ $plugin ] );

		if ( true !== $deleted ) {
			return;
		}

		$name     = null !== $known && '' !== $known['Name'] ? $known['Name'] : $plugin;
		$metadata = [ 'plugin_file' => $plugin ];
		if ( null !== $known && '' !== $known['Version'] ) {
			$metadata['version'] = $known['Version'];
		}
		$this->emit(
			5004,
			[
				'target'   => self::plugin_target( $plugin, $name ),
				'name'     => $name,
				'metadata' => $metadata,
			]
		);
	}

	/**
	 * A package is about to be installed: note the installed plugins and
	 * their versions, so the upgrade can say which version it came from. A
	 * filter, so it hands back what it was given.
	 *
	 * @param mixed $response   Whether the install goes on; returned untouched.
	 * @param mixed $hook_extra What is being installed.
	 * @return mixed
	 */
	public function on_pre_install( $response, $hook_extra = [] ) {
		if ( ! is_array( $hook_extra ) || ! self::is_plugin_job( $hook_extra ) ) {
			return $response;
		}

		// Reading the installed plugins can fail; the install must go on.
		try {
			$this->remember_installed();
		} catch ( Throwable $e ) {
			$action = self::action( $hook_extra );
			$this->report_failure( $e, 'install' === $action ? 5000 : 5003, [ 'action' => $action ] );
		}

		return $response;
	}

	/**
	 * @param mixed $upgrader   The upgrader that ran: a Plugin_Upgrader for a plugin job.
	 * @param mixed $hook_extra What was installed.
	 */
	public function on_upgrader( $upgrader, $hook_extra = [] ): void {
		if ( ! is_array( $hook_extra ) || 'plugin' !== ( $hook_extra['type'] ?? '' ) ) {
			return;
		}
		$action = self::action( $hook_extra );

		try {
			$files = self::installed_files( $hook_extra, null );
			if ( [] === $files && is_object( $upgrader ) && method_exists( $upgrader, 'plugin_info' ) ) {
				$info  = $upgrader->plugin_info();
				$files = self::installed_files( $hook_extra, is_string( $info ) ? $info : null );
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 'install' === $action ? 5000 : 5003, [ 'action' => $action ] );
			return;
		}

		$before = $this->before[ self::blog_id() ] ?? [];
		foreach ( $files as $file ) {
			// An install over a plugin that was already there replaced it.
			$code = 'install' === $action && ! isset( $before[ $file ] ) ? 5000 : 5003;
			try {
				$this->emit_upgrade( $code, $file, $action, $before[ $file ] ?? null );
			} catch ( Throwable $e ) {
				$this->report_failure( $e, $code, [ 'plugin_file' => $file ] );
			}
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
	 * The plugin files an upgrader job installed: its `plugins` list (bulk
	 * update), else its `plugin` (single update), else the plugin the
	 * upgrader found in the package it installed (`Plugin_Upgrader::plugin_info()`;
	 * an install names no plugin). Only non-empty names, each once.
	 *
	 * @param array<string, mixed> $hook_extra  The job as `upgrader_process_complete` describes it.
	 * @param string|null          $plugin_info Plugin file of the installed package, if known.
	 * @return array<int, string>
	 */
	public static function installed_files( array $hook_extra, ?string $plugin_info ): array {
		$files = self::names( $hook_extra['plugins'] ?? [] );
		if ( [] === $files ) {
			$files = self::names( [ $hook_extra['plugin'] ?? null ] );
		}
		if ( [] === $files ) {
			$files = self::names( [ $plugin_info ] );
		}

		return $files;
	}

	/**
	 * Old-to-new version of each plugin in `$after` that is in `$before` too
	 * and whose version differs: plugin file => change. Both maps are shaped
	 * like `get_plugins()`. The theme sensor uses it too, with stylesheets for
	 * keys.
	 *
	 * @param array<int|string, mixed> $before Headers before, by plugin file.
	 * @param array<int|string, mixed> $after  Headers after, by plugin file.
	 * @return array<string, array{field: string, old: ?string, new: ?string}>
	 */
	public static function version_changes( array $before, array $after ): array {
		$changes = [];
		foreach ( $after as $file => $headers ) {
			if ( ! isset( $before[ $file ] ) ) {
				continue;
			}

			$diff = ChangeSet::diff(
				[ 'version' => self::header( $before[ $file ], 'Version' ) ],
				[ 'version' => self::header( $headers, 'Version' ) ],
				[ 'version' ]
			);
			if ( [] !== $diff ) {
				$changes[ (string) $file ] = $diff[0];
			}
		}

		return $changes;
	}

	/**
	 * What a write to an automatic-updates list turned on and off: first the
	 * entries turned on, in the order of the new list, then the ones turned
	 * off, in the order of the old one. Only non-empty strings count, and a
	 * value that is not a list counts as an empty one. The theme sensor uses
	 * it too.
	 *
	 * @param mixed $old The list before the write.
	 * @param mixed $new The list after the write.
	 * @return array<int, array{entry: string, state: string}>
	 */
	public static function auto_update_toggles( $old, $new ): array {
		$before  = self::names( $old );
		$after   = self::names( $new );
		$toggles = [];
		foreach ( $after as $entry ) {
			if ( ! in_array( $entry, $before, true ) ) {
				$toggles[] = [
					'entry' => $entry,
					'state' => 'enabled',
				];
			}
		}
		foreach ( $before as $entry ) {
			if ( ! in_array( $entry, $after, true ) ) {
				$toggles[] = [
					'entry' => $entry,
					'state' => 'disabled',
				];
			}
		}

		return $toggles;
	}

	/**
	 * The slug a plugin is known by: its directory, or for a single-file
	 * plugin the file name without `.php`.
	 */
	public static function plugin_slug( string $plugin_file ): string {
		$directory = strstr( $plugin_file, '/', true );
		if ( false !== $directory && '' !== $directory ) {
			return $directory;
		}

		return str_ends_with( strtolower( $plugin_file ), '.php' ) ? substr( $plugin_file, 0, -4 ) : $plugin_file;
	}

	/**
	 * The `target` of a plugin event.
	 *
	 * @return array{type: string, id: string, label: string}
	 */
	public static function plugin_target( string $plugin_file, string $name ): array {
		return [
			'type'  => 'plugin',
			'id'    => self::plugin_slug( $plugin_file ),
			'label' => $name,
		];
	}

	/**
	 * Emit the install or update of one plugin of a completed upgrade.
	 *
	 * @param int                                       $code   5000 or 5003.
	 * @param string                                    $file   Plugin file.
	 * @param string                                    $action The job's action (`install`, `update`).
	 * @param array{Name: string, Version: string}|null $before The plugin before the job, if it was installed.
	 */
	private function emit_upgrade( int $code, string $file, string $action, ?array $before ): void {
		$after = self::headers( self::plugin_data( $file ) );
		$name  = '' !== $after['Name'] ? $after['Name'] : ( $before['Name'] ?? '' );
		$name  = '' !== $name ? $name : $file;

		$metadata = [
			'plugin_file' => $file,
			'action'      => $action,
		];
		if ( '' !== $after['Version'] ) {
			$metadata['version'] = $after['Version'];
		}

		$fields = [
			'target'   => self::plugin_target( $file, $name ),
			'name'     => $name,
			'version'  => $after['Version'],
			'metadata' => $metadata,
		];

		$changes = null === $before ? [] : self::version_changes( [ $file => $before ], [ $file => $after ] );
		if ( isset( $changes[ $file ] ) ) {
			$fields['changes'] = [ $changes[ $file ] ];
		}

		$this->emit( $code, $fields );
	}

	/**
	 * One 5005 per plugin whose automatic updates were turned on or off. A
	 * plugin that is no longer installed was only dropped from the list when
	 * it was saved; nobody turned its updates off.
	 *
	 * @param mixed $old The list before the write.
	 * @param mixed $new The list after the write.
	 */
	private function log_auto_updates( $old, $new ): void {
		// Reading the plugins can fail; that must never reach the request
		// that saved the list.
		try {
			$toggles = self::auto_update_toggles( $old, $new );
			if ( [] === $toggles ) {
				return;
			}

			$installed = $this->installed_plugins();
			foreach ( $toggles as $toggle ) {
				$file = $toggle['entry'];
				if ( 'disabled' === $toggle['state'] && ! isset( $installed[ $file ] ) ) {
					continue;
				}

				$name = self::header( $installed[ $file ] ?? null, 'Name' );
				$name = '' !== $name ? $name : $file;
				$this->emit(
					5005,
					[
						'target'   => self::plugin_target( $file, $name ),
						'state'    => $toggle['state'],
						'name'     => $name,
						'metadata' => [ 'plugin_file' => $file ],
					]
				);
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 5005, [ 'option' => self::AUTO_UPDATES ] );
		}
	}

	/**
	 * Note every installed plugin not seen yet on this site.
	 */
	private function remember_installed(): void {
		$blog = self::blog_id();
		foreach ( $this->installed_plugins() as $file => $headers ) {
			$file = (string) $file;
			if ( ! isset( $this->before[ $blog ][ $file ] ) ) {
				$this->before[ $blog ][ $file ] = self::headers( $headers );
			}
		}
	}

	/**
	 * Whether an install step is part of a plugin job. A bulk update names
	 * only the `plugin` at this step, without a `type`.
	 *
	 * @param array<string, mixed> $hook_extra The job.
	 */
	private static function is_plugin_job( array $hook_extra ): bool {
		return 'plugin' === ( $hook_extra['type'] ?? null ) || isset( $hook_extra['plugin'] );
	}

	/**
	 * @param array<string, mixed> $hook_extra The job.
	 */
	private static function action( array $hook_extra ): string {
		return is_string( $hook_extra['action'] ?? null ) ? $hook_extra['action'] : '';
	}

	/**
	 * The non-empty strings of a list, each once, in order; none for a value
	 * that is not a list.
	 *
	 * @param mixed $list Any value.
	 * @return array<int, string>
	 */
	private static function names( $list ): array {
		if ( ! is_array( $list ) ) {
			return [];
		}

		$names = [];
		foreach ( $list as $item ) {
			if ( is_string( $item ) && '' !== $item && ! in_array( $item, $names, true ) ) {
				$names[] = $item;
			}
		}

		return $names;
	}

	/**
	 * Name and version out of `get_plugins()`-shaped headers.
	 *
	 * @param mixed $headers Headers of one plugin.
	 * @return array{Name: string, Version: string}
	 */
	private static function headers( $headers ): array {
		return [
			'Name'    => self::header( $headers, 'Name' ),
			'Version' => self::header( $headers, 'Version' ),
		];
	}

	/**
	 * One header as text; empty when it is missing or not a plain value.
	 *
	 * @param mixed  $headers Headers of one plugin.
	 * @param string $key     Header name.
	 */
	private static function header( $headers, string $key ): string {
		$value = is_array( $headers ) ? ( $headers[ $key ] ?? null ) : null;

		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function blog_id(): int {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	}

	private static function name( string $plugin_file ): string {
		$name = self::header( self::plugin_data( $plugin_file ), 'Name' );
		return '' !== $name ? $name : $plugin_file;
	}

	/**
	 * Whether a plugin file is helloLOG itself.
	 *
	 * @param string $plugin_file Plugin file, relative to the plugins directory.
	 */
	private static function is_own( string $plugin_file ): bool {
		return defined( 'HELLOLOG_FILE' ) && function_exists( 'plugin_basename' ) && plugin_basename( HELLOLOG_FILE ) === $plugin_file;
	}

	/**
	 * Every installed plugin, as core lists them for the request.
	 *
	 * @return array<int|string, mixed>
	 */
	private function installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return (array) get_plugins();
	}

	/**
	 * The plugin's headers as its file has them now.
	 *
	 * @return array<string, string>
	 */
	private static function plugin_data( string $plugin_file ): array {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! file_exists( $path ) ) {
			return [];
		}
		return get_plugin_data( $path, false, false );
	}
}
