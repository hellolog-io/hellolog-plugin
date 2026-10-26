<?php
/**
 * Sensor: posts, pages, and custom post types.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Events\ChangeSet;
use HelloLog\Sensors\AbstractSensor;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks the post lifecycle: creation, publish / unpublish, trash, restore,
 * permanent delete, and ordinary updates. We follow {@see transition_post_status}
 * for state changes and {@see post_updated} for in-place content edits.
 *
 * Which post types are logged is decided by {@see self::is_tracked_type()}:
 * the types another sensor reports itself, and internal types without an
 * admin screen, are left out. Every event names the post it is about as its
 * target and links to its edit screen.
 */
final class ContentSensor extends AbstractSensor {

	/**
	 * Post types that never produce a post event here: either another sensor
	 * reports them in its own words (store products, orders and coupons,
	 * attachments) or they are internal records nobody edits (revisions,
	 * menu items, customizer drafts, embed caches, privacy requests).
	 *
	 * @var array<int, string>
	 */
	public const OWNED_ELSEWHERE = [
		'product',
		'product_variation',
		'shop_order',
		'shop_order_placehold',
		'shop_order_refund',
		'shop_coupon',
		'revision',
		'nav_menu_item',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'attachment',
	];

	/**
	 * Post fields compared by an in-place update, in the order they are reported.
	 *
	 * @var array<int, string>
	 */
	private const DIFF_FIELDS = [ 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_parent', 'post_author', 'comment_status', 'ping_status' ];

	/**
	 * Fields reported as "changed" without their values.
	 *
	 * @var array<int, string>
	 */
	private const CONTENT_FIELDS = [ 'post_content' ];

	public function key(): string {
		return 'core-content';
	}

	public function boot(): void {
		add_action( 'transition_post_status', [ $this, 'on_status' ], 10, 3 );
		add_action( 'post_updated', [ $this, 'on_updated' ], 10, 3 );
		add_action( 'before_delete_post', [ $this, 'on_delete' ], 10, 2 );
		add_action( 'untrashed_post', [ $this, 'on_untrashed' ], 10, 2 );
	}

	public function on_status( string $new_status, string $old_status, WP_Post $post ): void {
		if ( ! $this->should_track( $post ) ) {
			return;
		}

		$code = self::status_event_code( $new_status, $old_status );
		if ( 0 !== $code ) {
			$this->emit_post_event( $code, $post );
		}
	}

	public function on_untrashed( int $post_id, string $previous_status ): void {
		$post = get_post( $post_id );
		if ( $post instanceof WP_Post && $this->should_track( $post ) ) {
			$this->emit_post_event( 2004, $post, [ 'previous_status' => $previous_status ] );
		}
	}

	public function on_delete( int $post_id, WP_Post $post ): void {
		if ( ! $this->should_track( $post ) ) {
			return;
		}
		// `before_delete_post` only fires for permanent deletes — trash uses
		// `wp_trash_post` which we already handle via `transition_post_status`.
		$this->emit_post_event( 2005, $post );
	}

	public function on_updated( int $post_id, WP_Post $after, WP_Post $before ): void {
		if ( ! $this->should_track( $after ) ) {
			return;
		}
		if ( $after->post_status !== $before->post_status ) {
			return; // status changes are handled by `transition_post_status`
		}
		if ( $after->post_modified === $before->post_modified ) {
			return; // no real change
		}
		$changes = self::field_changes( $this->snapshot( $before ), $this->snapshot( $after ) );

		// An update that changed none of the listed fields still goes out, with
		// no changes, so the per-request buffer can drop it.
		$this->emit_post_event(
			2006,
			$after,
			[
				'changed' => array_column( $changes, 'field' ),
			],
			$changes
		);
	}

	/**
	 * The event a status change is, or 0 when it is none.
	 *
	 * A post that goes live is "published" even when it was never saved as a
	 * draft first (`auto-draft` or `new`), otherwise a direct publish would be
	 * logged as a plain creation. A post that leaves `auto-draft` for any
	 * other visible state is "created". Moving to trash is "trashed" from
	 * every state, and going from published to anything else but trash is
	 * "unpublished". Everything else (including no change) is not an event
	 * of its own.
	 *
	 * @param string $new New post status.
	 * @param string $old Previous post status.
	 */
	public static function status_event_code( string $new, string $old ): int {
		if ( $new === $old ) {
			return 0;
		}
		if ( 'trash' === $new ) {
			return 2003;
		}
		if ( 'publish' === $new ) {
			return 2001;
		}
		if ( 'auto-draft' === $old && in_array( $new, [ 'draft', 'pending', 'future', 'private' ], true ) ) {
			return 2000;
		}
		if ( 'publish' === $old ) {
			return 2002;
		}

		return 0;
	}

	/**
	 * Whether posts of a type are logged by this sensor.
	 *
	 * @param string $post_type Post type name.
	 * @param bool   $show_ui   Whether the type has an admin screen; internal types without one are not logged.
	 */
	public static function is_tracked_type( string $post_type, bool $show_ui ): bool {
		if ( in_array( $post_type, self::OWNED_ELSEWHERE, true ) ) {
			return false;
		}

		return $show_ui;
	}

	/**
	 * The object an event about a post is about.
	 *
	 * @param int    $id    Post id.
	 * @param string $title Post title.
	 * @return array{type: string, id: string, label: string}
	 */
	public static function post_target( int $id, string $title ): array {
		return [
			'type'  => 'post',
			'id'    => (string) $id,
			'label' => $title,
		];
	}

	/**
	 * Where to open a post. The public page exists only while the post is
	 * published; a link core could not give is left out.
	 *
	 * @param ?string $edit   Edit screen URL.
	 * @param ?string $view   Public URL.
	 * @param string  $status Post status.
	 * @return array<string, string>
	 */
	public static function post_links( ?string $edit, ?string $view, string $status ): array {
		$links = [];
		if ( null !== $edit && '' !== $edit ) {
			$links['edit'] = $edit;
		}
		if ( 'publish' === $status && null !== $view && '' !== $view ) {
			$links['view'] = $view;
		}

		return $links;
	}

	/**
	 * What changed between two post snapshots, as a change list.
	 *
	 * @param array<string, mixed> $before Post fields before the update.
	 * @param array<string, mixed> $after  Post fields after the update.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function field_changes( array $before, array $after ): array {
		return ChangeSet::diff( $before, $after, self::DIFF_FIELDS, self::CONTENT_FIELDS );
	}

	private function should_track( WP_Post $post ): bool {
		$type_object = get_post_type_object( $post->post_type );

		return self::is_tracked_type( (string) $post->post_type, (bool) ( $type_object->show_ui ?? false ) );
	}

	/**
	 * @param array<string, mixed>                                         $extra   Extra `metadata` entries.
	 * @param array<int, array{field: string, old: ?string, new: ?string}> $changes What changed, for an update.
	 */
	private function emit_post_event( int $code, WP_Post $post, array $extra = [], array $changes = [] ): void {
		$id = (int) $post->ID;

		// Core answers null for the edit link when nobody who may edit the post is
		// logged in (a scheduled post going live in cron, a visitor), so build it.
		$edit = get_edit_post_link( $id, 'raw' );
		if ( ! is_string( $edit ) || '' === $edit ) {
			$edit = admin_url( 'post.php?post=' . $id . '&action=edit' );
		}
		$view = 'publish' === $post->post_status ? get_permalink( $post ) : null;

		$fields = [
			'post'      => [
				'id'     => $id,
				'type'   => (string) $post->post_type,
				'status' => (string) $post->post_status,
			],
			'target'    => self::post_target( $id, (string) $post->post_title ),
			'links'     => self::post_links( $edit, is_string( $view ) ? $view : null, (string) $post->post_status ),
			'title'     => (string) $post->post_title,
			'post_type' => ucfirst( str_replace( [ '_', '-' ], ' ', (string) $post->post_type ) ),
			'metadata'  => array_merge(
				[
					'slug'   => (string) $post->post_name,
					'author' => (int) $post->post_author,
				],
				$extra
			),
		];
		if ( 2006 === $code ) {
			$fields['changes'] = $changes;
		}

		$this->emit( $code, $fields );
	}

	/**
	 * The compared fields of a post, by name.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot( WP_Post $post ): array {
		$values = [];
		foreach ( self::DIFF_FIELDS as $field ) {
			$values[ $field ] = $post->$field;
		}

		return $values;
	}
}
