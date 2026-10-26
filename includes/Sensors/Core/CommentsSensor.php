<?php
/**
 * Sensor: comment lifecycle.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

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
 */
final class CommentsSensor extends AbstractSensor {

	/**
	 * Comment types that are comments in the everyday sense. The empty string
	 * is how older comments store the plain type.
	 *
	 * @var array<int, string>
	 */
	private const TRACKED_TYPES = [ '', 'comment', 'pingback', 'trackback', 'review' ];

	public function key(): string {
		return 'core-comments';
	}

	public function boot(): void {
		add_action( 'wp_insert_comment', [ $this, 'on_insert' ], 10, 2 );
		add_action( 'transition_comment_status', [ $this, 'on_status' ], 10, 3 );
		add_action( 'edit_comment', [ $this, 'on_edit' ], 10, 1 );
		add_action( 'deleted_comment', [ $this, 'on_delete' ], 10, 1 );
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

	public function on_edit( int $id ): void {
		$comment = get_comment( $id );
		if ( $comment instanceof WP_Comment && self::is_tracked_type( (string) $comment->comment_type ) ) {
			$this->emit_comment_event( 2205, $comment );
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
	 * @param array<string, mixed> $extra
	 */
	private function emit_comment_event( int $code, WP_Comment $comment, array $extra = [] ): void {
		$post  = $comment->comment_post_ID ? get_post( (int) $comment->comment_post_ID ) : null;
		$title = $post ? (string) $post->post_title : '#' . $comment->comment_post_ID;
		$id    = (int) $comment->comment_ID;

		$approved = (string) $comment->comment_approved;
		$view     = '1' === $approved ? get_comment_link( $comment ) : null;

		$this->emit(
			$code,
			[
				'post'       => $post
					? [
						'id'   => (int) $post->ID,
						'type' => (string) $post->post_type,
					]
					: null,
				'target'     => self::comment_target( $id, $title ),
				'links'      => self::comment_links(
					admin_url( 'comment.php?action=editcomment&c=' . $id ),
					is_string( $view ) ? $view : null,
					$approved
				),
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
			]
		);
	}
}
