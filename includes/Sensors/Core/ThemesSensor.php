<?php
/**
 * Sensor: theme switch / install / update / delete.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Sensors\AbstractSensor;
use WP_Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Theme lifecycle (5100–5104). Theme installs and updates ride on
 * `upgrader_process_complete` too, so the hook is shared with the
 * plugin path but filtered by the `type` argument.
 */
final class ThemesSensor extends AbstractSensor {

	public function key(): string {
		return 'core-themes';
	}

	public function boot(): void {
		add_action( 'switch_theme', [ $this, 'on_switch' ], 10, 3 );
		add_action( 'deleted_theme', [ $this, 'on_deleted' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrader' ], 10, 2 );
		add_action( 'customize_save_after', [ $this, 'on_customizer_save' ], 10, 0 );
	}

	public function on_switch( string $new_name, WP_Theme $new_theme, WP_Theme $old_theme ): void {
		$this->emit(
			5100,
			[
				'name'     => $new_name,
				'metadata' => [
					'old_theme' => (string) $old_theme->get( 'Name' ),
					'new_theme' => $new_name,
				],
			]
		);
	}

	/**
	 * @param mixed $errors
	 */
	public function on_deleted( string $stylesheet, $errors ): void {
		if ( true !== $errors ) {
			return;
		}
		$this->emit(
			5103,
			[
				'name'     => $stylesheet,
				'metadata' => [ 'stylesheet' => $stylesheet ],
			]
		);
	}

	/**
	 * @param mixed                $upgrader
	 * @param array<string, mixed> $hook_extra
	 */
	public function on_upgrader( $upgrader, array $hook_extra ): void {
		if ( 'theme' !== ( $hook_extra['type'] ?? '' ) ) {
			return;
		}
		$action = (string) ( $hook_extra['action'] ?? '' );
		$themes = (array) ( $hook_extra['themes'] ?? [] );
		if ( empty( $themes ) && isset( $hook_extra['theme'] ) ) {
			$themes = [ (string) $hook_extra['theme'] ];
		}
		$code = 'install' === $action ? 5101 : 5102;
		foreach ( $themes as $stylesheet ) {
			$stylesheet = (string) $stylesheet;
			$theme      = wp_get_theme( $stylesheet );
			$name       = self::resolve_theme_name( $theme->get( 'Name' ), $stylesheet );
			$this->emit(
				$code,
				[
					'name'     => $name,
					'version'  => (string) $theme->get( 'Version' ),
					'metadata' => [
						'stylesheet' => $stylesheet,
						'action'     => $action,
					],
				]
			);
		}
	}

	public function on_customizer_save(): void {
		$this->emit( 5104, [] );
	}

	/**
	 * `WP_Theme::get( 'Name' )` returns `false` (not `''`) when the header is
	 * unreadable, so a plain `?:`/`!==''` check isn't enough — fall back to
	 * the stylesheet slug for anything that isn't a non-empty string.
	 */
	public static function resolve_theme_name( mixed $raw_name, string $stylesheet ): string {
		return is_string( $raw_name ) && '' !== $raw_name ? $raw_name : $stylesheet;
	}
}
