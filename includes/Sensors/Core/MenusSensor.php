<?php
/**
 * Sensor: navigation menus.
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
 * Tracks the three menu lifecycle hooks; item-level changes (add / remove
 * /reorder) ride on the underlying nav_menu_item post type which we skip
 * in ContentSensor — that noise belongs here, batched per menu save.
 *
 * Every event names the menu as its `target` by its id, with the name stored
 * for it (the input of a save is slashed). A save says when the menu was
 * renamed: the menu, a `nav_menu` term, is read on `edit_terms`, which core
 * fires right before it writes the term, and compared on
 * `wp_update_nav_menu`. The sensor reads it itself because the taxonomy
 * sensor leaves menus alone, and either sensor can be turned off. A save of
 * the menu's items alone is logged too, without changes; the menu screen
 * saves the menu and then its items, and the per-request rules fold the two.
 * Core tells a deletion by the id of a menu that is already gone, so its
 * name is taken from the term core passes to `delete_term`.
 */
final class MenusSensor extends AbstractSensor {

	/** The taxonomy of menus. */
	private const TAXONOMY = 'nav_menu';

	/**
	 * Names of the menus about to be written, read just before the write:
	 * blog id => menu id => name. A menu id is unique on its own site only.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $before = [];

	/**
	 * Names of the menus just deleted: blog id => menu id => name.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $deleted = [];

	public function key(): string {
		return 'core-menus';
	}

	public function boot(): void {
		add_action( 'edit_terms', [ $this, 'on_before_edit' ], 10, 2 );
		add_action( 'delete_term', [ $this, 'on_term_deleted' ], 10, 4 );
		add_action( 'wp_create_nav_menu', [ $this, 'on_create' ], 10, 2 );
		add_action( 'wp_update_nav_menu', [ $this, 'on_update' ], 10, 2 );
		add_action( 'wp_delete_nav_menu', [ $this, 'on_delete' ], 10, 1 );
	}

	/**
	 * @param array<string, mixed> $menu_data
	 */
	public function on_create( int $menu_id, array $menu_data ): void {
		// Reading the menu can fail (a filter on it that throws); that must
		// never reach the request that saves the menu.
		try {
			$name = self::menu_name( $menu_id, $menu_data );
			$this->emit(
				2400,
				[
					'target'   => self::menu_target( $menu_id, $name ),
					'name'     => $name,
					'metadata' => [ 'menu_id' => $menu_id ],
				]
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 2400, [ 'menu_id' => $menu_id ] );
		}
	}

	/**
	 * A term is about to be written: note the name of a menu.
	 */
	public function on_before_edit( int $term_id, string $taxonomy ): void {
		if ( self::TAXONOMY !== $taxonomy ) {
			return;
		}

		// A note of an earlier save must not stand in for this one.
		$blog = self::blog_id();
		unset( $this->before[ $blog ][ $term_id ] );

		try {
			$menu = get_term( $term_id, self::TAXONOMY );
			if ( is_object( $menu ) && ! is_wp_error( $menu ) ) {
				$this->before[ $blog ][ $term_id ] = (string) ( $menu->name ?? '' );
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 2401, [ 'menu_id' => $term_id ] );
		}
	}

	/**
	 * A menu was saved, or its items were (core passes the menu id alone
	 * then). The name noted before the write, if any, is compared with the
	 * stored one.
	 *
	 * @param array<string, mixed> $menu_data
	 */
	public function on_update( int $menu_id, array $menu_data = [] ): void {
		$blog   = self::blog_id();
		$before = $this->before[ $blog ][ $menu_id ] ?? null;
		unset( $this->before[ $blog ][ $menu_id ] );

		try {
			$name = self::menu_name( $menu_id, $menu_data );
			$this->emit(
				2401,
				[
					'target'   => self::menu_target( $menu_id, $name ),
					'name'     => $name,
					'changes'  => null === $before ? [] : ChangeSet::diff( [ 'name' => $before ], [ 'name' => $name ], [ 'name' ] ),
					'metadata' => [ 'menu_id' => $menu_id ],
				]
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 2401, [ 'menu_id' => $menu_id ] );
		}
	}

	/**
	 * A term was deleted: keep the name of a menu for the deletion event,
	 * which only has its id.
	 *
	 * @param mixed $tt_id        Term taxonomy id; not used.
	 * @param mixed $deleted_term The term as it was before it was deleted.
	 */
	public function on_term_deleted( int $term_id, $tt_id, string $taxonomy, $deleted_term ): void {
		if ( self::TAXONOMY !== $taxonomy || ! is_object( $deleted_term ) ) {
			return;
		}

		try {
			if ( isset( $deleted_term->name ) ) {
				$this->deleted[ self::blog_id() ][ $term_id ] = (string) $deleted_term->name;
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 2402, [ 'menu_id' => $term_id ] );
		}
	}

	/**
	 * @param mixed $term Id of the deleted menu; the term itself when code other than core fires the hook.
	 */
	public function on_delete( $term ): void {
		$menu_id = is_object( $term ) ? (int) ( $term->term_id ?? 0 ) : (int) $term;
		$blog    = self::blog_id();
		$name    = $this->deleted[ $blog ][ $menu_id ] ?? '';
		unset( $this->deleted[ $blog ][ $menu_id ] );

		if ( is_object( $term ) && isset( $term->name ) ) {
			$name = (string) $term->name;
		}
		if ( '' === $name ) {
			$name = '#' . $menu_id;
		}

		$this->emit(
			2402,
			[
				'target'   => self::menu_target( $menu_id, $name ),
				'name'     => $name,
				'metadata' => [ 'menu_id' => $menu_id ],
			]
		);
	}

	/**
	 * The `target` of a menu event.
	 *
	 * @param int    $menu_id Menu (term) id.
	 * @param string $name    Menu name.
	 * @return array{type: string, id: string, label: string}
	 */
	public static function menu_target( int $menu_id, string $name ): array {
		return [
			'type'  => 'menu',
			'id'    => (string) $menu_id,
			'label' => $name,
		];
	}

	/**
	 * The name stored for a menu; the name in the input of the save when the
	 * menu can not be found.
	 *
	 * @param int                  $menu_id   Menu (term) id.
	 * @param array<string, mixed> $menu_data Input of the save; may be empty.
	 */
	private static function menu_name( int $menu_id, array $menu_data ): string {
		$menu = get_term( $menu_id, self::TAXONOMY );
		if ( is_object( $menu ) && ! is_wp_error( $menu ) && isset( $menu->name ) ) {
			return (string) $menu->name;
		}

		return (string) ( $menu_data['menu-name'] ?? '' );
	}

	/**
	 * The current site; 0 outside WordPress.
	 */
	private static function blog_id(): int {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	}
}
