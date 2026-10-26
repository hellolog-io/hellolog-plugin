<?php
/**
 * Sensor: taxonomy term lifecycle.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Sensors\AbstractSensor;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks terms (categories, tags, custom taxonomies) — create, edit, delete.
 * Skips automatically-generated taxonomy on user_register and similar, and
 * the taxonomies listed in {@see self::UNTRACKED}.
 */
final class TaxonomySensor extends AbstractSensor {

	/**
	 * Taxonomies that are not something an editor manages: menus are reported
	 * by the menus sensor (a menu is a `nav_menu` term, so tracking the term
	 * would log every menu change twice), the others are internal bookkeeping.
	 *
	 * @var array<int, string>
	 */
	private const UNTRACKED = [ 'nav_menu', 'post_format', 'wp_theme', 'wp_template_part_area' ];

	public function key(): string {
		return 'core-taxonomy';
	}

	public function boot(): void {
		add_action( 'created_term', [ $this, 'on_created' ], 10, 3 );
		add_action( 'edit_term', [ $this, 'on_edited' ], 10, 3 );
		add_action( 'pre_delete_term', [ $this, 'on_deleted' ], 10, 2 );
	}

	public function on_created( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! self::is_tracked( $taxonomy ) ) {
			return;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		$this->emit(
			2120,
			[
				'name'     => (string) $term->name,
				'taxonomy' => $taxonomy,
				'metadata' => [
					'term_id'  => $term_id,
					'slug'     => (string) $term->slug,
					'taxonomy' => $taxonomy,
				],
			]
		);
	}

	public function on_edited( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! self::is_tracked( $taxonomy ) ) {
			return;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		$this->emit(
			2121,
			[
				'name'     => (string) $term->name,
				'taxonomy' => $taxonomy,
				'metadata' => [
					'term_id'  => $term_id,
					'slug'     => (string) $term->slug,
					'taxonomy' => $taxonomy,
				],
			]
		);
	}

	public function on_deleted( int $term_id, string $taxonomy ): void {
		if ( ! self::is_tracked( $taxonomy ) ) {
			return;
		}
		$term = get_term( $term_id, $taxonomy );
		$name = ( $term && ! is_wp_error( $term ) ) ? (string) $term->name : '#' . $term_id;
		$this->emit(
			2122,
			[
				'name'     => $name,
				'taxonomy' => $taxonomy,
				'metadata' => [
					'term_id'  => $term_id,
					'taxonomy' => $taxonomy,
				],
			]
		);
	}

	/**
	 * Whether terms of a taxonomy are logged by this sensor.
	 *
	 * @param string $taxonomy Taxonomy name.
	 */
	public static function is_tracked( string $taxonomy ): bool {
		return ! in_array( $taxonomy, self::UNTRACKED, true );
	}
}
