<?php
/**
 * Sensor: taxonomy term lifecycle.
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
 * Tracks terms (categories, tags, custom taxonomies) — create, edit, delete.
 * Skips automatically-generated taxonomy on user_register and similar, and
 * the taxonomies listed in {@see self::UNTRACKED}.
 *
 * Every event names the term as its `target` by its id. An edit says what
 * changed: core fires `edit_terms` just before it writes a term and
 * `edited_term` once the write is done and the term's cache is cleaned, so
 * the term is read on both and compared field by field (see
 * {@see self::term_fields()}). A save that changed nothing goes out without
 * changes and the per-request rules drop it; two edits of one term in a
 * request are folded into one.
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

	/**
	 * Fields of a term an edit is compared on, in the order they are reported.
	 *
	 * @var array<int, string>
	 */
	private const FIELDS = [ 'name', 'slug', 'parent', 'description' ];

	/**
	 * Fields reported as changed without their text: what somebody wrote.
	 *
	 * @var array<int, string>
	 */
	private const CONTENT_FIELDS = [ 'description' ];

	/**
	 * Fields of the terms about to be written, read just before the write:
	 * blog id => term id => fields. A term id is unique on its own site only.
	 *
	 * @var array<int, array<int, array{name: string, slug: string, parent: string, parent_id: int, description: string}>>
	 */
	private array $before = [];

	public function key(): string {
		return 'core-taxonomy';
	}

	public function boot(): void {
		add_action( 'created_term', [ $this, 'on_created' ], 10, 3 );
		add_action( 'edit_terms', [ $this, 'on_before_edit' ], 10, 2 );
		add_action( 'edited_term', [ $this, 'on_edited' ], 10, 3 );
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
				'target'   => self::term_target( $term_id, (string) $term->name ),
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

	/**
	 * A term is about to be written: note its fields. Core fires this right
	 * before the write in `wp_update_term()`, and in `wp_insert_term()` when
	 * it fixes up the slug of a new term (that note is never compared, and
	 * the next edit replaces it).
	 */
	public function on_before_edit( int $term_id, string $taxonomy ): void {
		if ( ! self::is_tracked( $taxonomy ) ) {
			return;
		}

		// A note of an earlier edit must not stand in for this one.
		$blog = self::blog_id();
		unset( $this->before[ $blog ][ $term_id ] );

		// Reading the term can fail (a filter on it that throws); that must
		// never reach the request that saves the term.
		try {
			$term = get_term( $term_id, $taxonomy );
			if ( is_object( $term ) && ! is_wp_error( $term ) ) {
				$this->before[ $blog ][ $term_id ] = self::term_fields( $term );
			}
		} catch ( Throwable $e ) {
			$this->report_failure(
				$e,
				2121,
				[
					'term_id'  => $term_id,
					'taxonomy' => $taxonomy,
				]
			);
		}
	}

	/**
	 * A term was written: compare it with the fields noted before the write.
	 * Without them there is nothing to compare, so the update goes out with
	 * no changes and the per-request rules drop it.
	 */
	public function on_edited( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! self::is_tracked( $taxonomy ) ) {
			return;
		}

		$blog   = self::blog_id();
		$before = $this->before[ $blog ][ $term_id ] ?? null;
		unset( $this->before[ $blog ][ $term_id ] );

		try {
			$term = get_term( $term_id, $taxonomy );
			if ( ! is_object( $term ) || is_wp_error( $term ) ) {
				return;
			}

			$after = self::term_fields( $term );
			$this->emit(
				2121,
				[
					'target'   => self::term_target( $term_id, $after['name'] ),
					'name'     => $after['name'],
					'taxonomy' => $taxonomy,
					'changes'  => null === $before ? [] : self::term_changes( $before, $after ),
					'metadata' => [
						'term_id'  => $term_id,
						'slug'     => $after['slug'],
						'taxonomy' => $taxonomy,
					],
				]
			);
		} catch ( Throwable $e ) {
			$this->report_failure(
				$e,
				2121,
				[
					'term_id'  => $term_id,
					'taxonomy' => $taxonomy,
				]
			);
		}
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
				'target'   => self::term_target( $term_id, $name ),
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

	/**
	 * The fields of a term an edit is compared on: its name, its slug, the
	 * name of its parent (empty for none), the parent's id (0 for none;
	 * compared, never reported) and its description.
	 *
	 * The parent is named by `$parent_name`, given the parent's term id. By
	 * default the parent term is read from WordPress; a parent that can not
	 * be found is named by its id (`#12`), so it never reads as "no parent".
	 *
	 * @param object                       $term        A term: a `WP_Term`, or any object with its fields.
	 * @param (callable(int): string)|null $parent_name Names the parent term with this id.
	 * @return array{name: string, slug: string, parent: string, parent_id: int, description: string}
	 */
	public static function term_fields( object $term, ?callable $parent_name = null ): array {
		$parent_id = max( 0, (int) ( $term->parent ?? 0 ) );
		$parent    = '';
		if ( $parent_id > 0 ) {
			$taxonomy = (string) ( $term->taxonomy ?? '' );
			$parent   = null === $parent_name ? self::parent_name( $parent_id, $taxonomy ) : (string) $parent_name( $parent_id );
		}

		return [
			'name'        => (string) ( $term->name ?? '' ),
			'slug'        => (string) ( $term->slug ?? '' ),
			'parent'      => $parent,
			'parent_id'   => $parent_id,
			'description' => (string) ( $term->description ?? '' ),
		];
	}

	/**
	 * What changed between two {@see self::term_fields()} snapshots of a
	 * term. A new description is reported as changed, without its text.
	 *
	 * When both snapshots carry the parent's id, the parent is compared by
	 * it: the same parent is no change whatever its name reads, and a move to
	 * another parent with the same name reports both names with their ids
	 * (`Reviews (#4)` to `Reviews (#5)`). Any other move reports the names
	 * alone. Snapshots without the id are compared by the parent's name.
	 *
	 * @param array<string, int|string> $before Fields before the edit.
	 * @param array<string, int|string> $after  Fields after the edit.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function term_changes( array $before, array $after ): array {
		if ( isset( $before['parent_id'], $after['parent_id'] ) ) {
			$old_id   = (int) $before['parent_id'];
			$new_id   = (int) $after['parent_id'];
			$old_name = (string) ( $before['parent'] ?? '' );
			$new_name = (string) ( $after['parent'] ?? '' );

			if ( $old_id === $new_id ) {
				$after['parent'] = $old_name;
			} elseif ( $old_name === $new_name ) {
				$before['parent'] = self::parent_with_id( $old_name, $old_id );
				$after['parent']  = self::parent_with_id( $new_name, $new_id );
			}
		}

		return ChangeSet::diff( $before, $after, self::FIELDS, self::CONTENT_FIELDS );
	}

	/**
	 * The `target` of a term event.
	 *
	 * @param int    $term_id Term id.
	 * @param string $name    Term name.
	 * @return array{type: string, id: string, label: string}
	 */
	public static function term_target( int $term_id, string $name ): array {
		return [
			'type'  => 'term',
			'id'    => (string) $term_id,
			'label' => $name,
		];
	}

	/**
	 * Name of a parent term; its id (`#12`) when it can not be found.
	 *
	 * @param int    $term_id  Parent term id.
	 * @param string $taxonomy Taxonomy of the child; empty for any.
	 */
	private static function parent_name( int $term_id, string $taxonomy ): string {
		$parent = get_term( $term_id, $taxonomy );

		return is_object( $parent ) && ! is_wp_error( $parent ) && isset( $parent->name ) ? (string) $parent->name : '#' . $term_id;
	}

	/**
	 * A parent's name told apart from another parent's of the same name by
	 * its id; no parent stays empty.
	 *
	 * @param string $name      Parent name.
	 * @param int    $parent_id Parent term id; 0 for none.
	 */
	private static function parent_with_id( string $name, int $parent_id ): string {
		return 0 === $parent_id ? $name : $name . ' (#' . $parent_id . ')';
	}

	/**
	 * The current site; 0 outside WordPress.
	 */
	private static function blog_id(): int {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	}
}
