<?php
/**
 * Sensor: comment lifecycle.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Events\ChangeSet;
use HelloLog\Sensors\AbstractSensor;
use WP_Comment;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks comment lifecycle: new, approve / spam / trash transitions,
 * permanent delete, and in-place edits.
 *
 * WordPress also stores internal notes as comments (order notes, scheduler
 * logs, ...); only the types in {@see self::TRACKED_TYPES} are visitor
 * comments, so the rest are left to the sensors that own them.
 *
 * An edit is only logged when a field a reader sees changed, see
 * {@see self::EDIT_FIELDS}. `wp_update_comment()` fires `edit_comment` for
 * every write, also when only the status or the user agent changes: the
 * REST API approves a comment by writing it again with the request's user
 * agent before it changes the status.
 */
final class CommentsSensor extends AbstractSensor {

	/**
	 * Comment types that are comments in the everyday sense. The empty string
	 * is how older comments store the plain type; `note` is a note a person
	 * leaves on a post in the block editor.
	 *
	 * @var array<int, string>
	 */
	private const TRACKED_TYPES = [ '', 'comment', 'pingback', 'trackback', 'review', 'note' ];

	/**
	 * Comment fields a reader sees, compared by an update, in the order they
	 * are reported.
	 *
	 * @var array<int, string>
	 */
	private const EDIT_FIELDS = [ 'comment_content', 'comment_author', 'comment_author_email', 'comment_author_url' ];

	/**
	 * Fields reported as "changed" without their values.
	 *
	 * @var array<int, string>
	 */
	private const CONTENT_FIELDS = [ 'comment_content' ];

	/**
	 * What the update `wp_update_comment()` is writing changes, by comment id.
	 * Set by the `wp_update_comment_data` filter, taken by `edit_comment`.
	 *
	 * @var array<int, array<int, array{field: string, old: ?string, new: ?string}>>
	 */
	private array $pending_changes = [];

	public function key(): string {
		return 'core-comments';
	}

	public function boot(): void {
		add_action( 'wp_insert_comment', [ $this, 'on_insert' ], 10, 2 );
		add_action( 'transition_comment_status', [ $this, 'on_status' ], 10, 3 );
		add_action( 'edit_comment', [ $this, 'on_edit' ], 10, 1 );
		add_action( 'deleted_comment', [ $this, 'on_delete' ], 10, 1 );
		// Last, so the comparison sees what is really written.
		add_filter( 'wp_update_comment_data', [ $this, 'on_update_data' ], PHP_INT_MAX, 1 );
	}

	public function on_insert( int $id, WP_Comment $comment ): void {
		if ( ! self::is_tracked_type( (string) $comment->comment_type ) ) {
			return;
		}
		$this->emit_comment_event( 2200, $comment );
	}

	public function on_status( string $new_status, string $old_status, $comment ): void {
		if ( ! $comment instanceof WP_Comment || ! self::is_tracked_type( (string) $comment->comment_type ) ) {
			return;
		}
		$code = match ( $new_status ) {
			'approved' => 2201,
			'spam'     => 2202,
			'trash'    => 2203,
			default    => 0,
		};
		if ( 0 === $code ) {
			return;
		}
		$this->emit_comment_event( $code, $comment, [ 'old_status' => $old_status ] );
	}

	/**
	 * Compares the stored comment with what `wp_update_comment()` is about to
	 * write; `edit_comment` logs the result. The comment is read with
	 * `get_comment()`: the database still holds the old values here, and the
	 * old data core passes the filter is slashed, unlike the new data.
	 *
	 * @param mixed $data The comment data to be written (unslashed), or the error a filter returned.
	 * @return mixed The data, unchanged.
	 */
	public function on_update_data( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['comment_ID'] ) ) {
			return $data;
		}

		$id     = (int) $data['comment_ID'];
		$stored = get_comment( $id );
		if ( $stored instanceof WP_Comment && self::is_tracked_type( (string) $stored->comment_type ) ) {
			$before = [];
			foreach ( self::EDIT_FIELDS as $field ) {
				$before[ $field ] = $stored->$field;
			}
			$this->pending_changes[ $id ] = self::edit_changes( $before, $data );
		}

		return $data;
	}

	public function on_edit( int $id ): void {
		$changes = $this->pending_changes[ $id ] ?? [];
		unset( $this->pending_changes[ $id ] );
		if ( [] === $changes ) {
			return; // Only the status, the user agent or nothing a reader sees changed.
		}

		$comment = get_comment( $id );
		if ( $comment instanceof WP_Comment && self::is_tracked_type( (string) $comment->comment_type ) ) {
			$this->emit_comment_event( 2205, $comment, [], $changes );
		}
	}

	public function on_delete( int $id ): void {
		$comment = get_comment( $id );
		if ( $comment instanceof WP_Comment && self::is_tracked_type( (string) $comment->comment_type ) ) {
			$this->emit_comment_event( 2204, $comment );
		}
	}

	/**
	 * Whether comments of a type are logged by this sensor.
	 *
	 * @param string $comment_type The `comment_type` of the comment.
	 */
	public static function is_tracked_type( string $comment_type ): bool {
		return in_array( $comment_type, self::TRACKED_TYPES, true );
	}

	/**
	 * What an update changed of the fields a reader sees, as a change list.
	 * The text of the comment is reported as changed, without its values.
	 *
	 * @param array<string, mixed> $before Comment fields before the update.
	 * @param array<string, mixed> $after  Comment fields after the update.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function edit_changes( array $before, array $after ): array {
		return ChangeSet::diff( $before, $after, self::EDIT_FIELDS, self::CONTENT_FIELDS );
	}

	/**
	 * The object an event about a comment is about; the label is the title of
	 * the post it was left on.
	 *
	 * @param int    $id         Comment id.
	 * @param string $post_title Title of the post the comment belongs to.
	 * @return array{type: string, id: string, label: string}
	 */
	public static function comment_target( int $id, string $post_title ): array {
		return [
			'type'  => 'comment',
			'id'    => (string) $id,
			'label' => $post_title,
		];
	}

	/**
	 * Where to open a comment. Only an approved comment is shown on the site;
	 * a link that could not be built is left out.
	 *
	 * @param ?string $edit     Edit screen URL.
	 * @param ?string $view     Public URL of the comment.
	 * @param string  $approved The `comment_approved` value (`1`, `0`, `spam`, `trash`).
	 * @return array<string, string>
	 */
	public static function comment_links( ?string $edit, ?string $view, string $approved ): array {
		$links = [];
		if ( null !== $edit && '' !== $edit ) {
			$links['edit'] = $edit;
		}
		if ( '1' === $approved && null !== $view && '' !== $view ) {
			$links['view'] = $view;
		}

		return $links;
	}

	/**
	 * @param array<string, mixed>                                         $extra   Extra `metadata` entries.
	 * @param array<int, array{field: string, old: ?string, new: ?string}> $changes What changed, for an edit.
	 */
	private function emit_comment_event( int $code, WP_Comment $comment, array $extra = [], array $changes = [] ): void {
		$post  = $comment->comment_post_ID ? get_post( (int) $comment->comment_post_ID ) : null;
		$title = $post ? (string) $post->post_title : '#' . $comment->comment_post_ID;
		$id    = (int) $comment->comment_ID;

		$fields = [
			'post'       => $post
				? [
					'id'   => (int) $post->ID,
					'type' => (string) $post->post_type,
				]
				: null,
			'target'     => self::comment_target( $id, $title ),
			'post_title' => $title,
			'author'     => (string) $comment->comment_author,
			'metadata'   => array_merge(
				[
					'comment_id'      => $id,
					'comment_author'  => (string) $comment->comment_author,
					'comment_post_id' => (int) $comment->comment_post_ID,
				],
				$extra
			),
		];

		if ( [] !== $changes ) {
			$fields['changes'] = $changes;
		}

		// A permanently deleted comment is gone, so there is nothing to open.
		if ( 2204 !== $code ) {
			$fields['links'] = $this->links( $comment );
		}

		$this->emit( $code, $fields );
	}

	/**
	 * Links to open a comment.
	 *
	 * @return array<string, string>
	 */
	private function links( WP_Comment $comment ): array {
		$approved = (string) $comment->comment_approved;
		$view     = '1' === $approved ? get_comment_link( $comment ) : null;

		// Built here rather than with get_edit_comment_link(), which answers
		// nothing unless the current user may edit comments (visitors cannot).
		return self::comment_links(
			admin_url( 'comment.php?action=editcomment&c=' . (int) $comment->comment_ID ),
			is_string( $view ) ? $view : null,
			$approved
		);
	}
}
