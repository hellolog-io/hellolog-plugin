<?php
/**
 * Sensor: the media library.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Events\ChangeSet;
use HelloLog\Events\ObjectType;
use HelloLog\Sensors\AbstractSensor;
use Throwable;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks the files of the media library: an upload (2010), an edit of its
 * title, caption, description or alternative text (2011) and its permanent
 * delete (2012). A file moved to the trash and back (possible only when the
 * site turns `MEDIA_TRASH` on) is logged by {@see ContentSensor}, on the same
 * target (see {@see self::media_target()}), so one file's history stays in
 * one place.
 *
 * Core fires `attachment_updated` with the file before and after a save, so
 * an edit of its texts is compared on the spot. The alternative text is post
 * meta, written apart from the rest: `update_post_meta` fires before the
 * write, while the stored value is still the old one, and `added_post_meta`
 * once a file that had none got one.
 *
 * A plugin or theme zip uploaded through the installer is stored as an
 * attachment while the install runs and deleted again afterwards. It is no
 * file of the library and none of its events is logged, see
 * {@see self::is_upgrader_package()}.
 */
final class MediaSensor extends AbstractSensor {

	/** Meta key of the alternative text of an image. */
	private const ALT_KEY = '_wp_attachment_image_alt';

	/** Meta key of the context core made an attachment in. */
	private const CONTEXT_KEY = '_wp_attachment_context';

	/** The context of a zip the plugin and theme installer uploaded. */
	private const UPGRADER_CONTEXT = 'upgrader';

	/**
	 * Fields of a file an edit is compared on, in the order they are
	 * reported: the name reported => the `WP_Post` field.
	 *
	 * @var array<string, string>
	 */
	private const FIELDS = [
		'title'       => 'post_title',
		'caption'     => 'post_excerpt',
		'description' => 'post_content',
	];

	/**
	 * Fields reported as "changed" without their text: what somebody wrote.
	 *
	 * @var array<int, string>
	 */
	private const CONTENT_FIELDS = [ 'description' ];

	public function key(): string {
		return 'core-media';
	}

	public function boot(): void {
		add_action( 'add_attachment', [ $this, 'on_added' ], 10, 1 );
		add_action( 'attachment_updated', [ $this, 'on_updated' ], 10, 3 );
		add_action( 'update_post_meta', [ $this, 'on_meta_update' ], 10, 4 );
		add_action( 'added_post_meta', [ $this, 'on_meta_added' ], 10, 4 );
		add_action( 'delete_attachment', [ $this, 'on_delete' ], 10, 2 );
	}

	/**
	 * A file was uploaded (or made by core, a cropped site icon say).
	 */
	public function on_added( int $post_id ): void {
		// Reading the file can fail (a filter on its meta that throws); that
		// must never reach the request that stored it.
		try {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post && ! self::is_upgrader_package( $post_id ) ) {
				$this->emit_media( 2010, $post );
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 2010, [ 'post_id' => $post_id ] );
		}
	}

	/**
	 * A file was saved: logged when its title, caption or description
	 * changed. A save that changed none of them (attaching the file to a
	 * post, moving it to the trash) is no edit.
	 *
	 * @param int   $post_id     Attachment id.
	 * @param mixed $post_after  The file as saved.
	 * @param mixed $post_before The file before the save.
	 */
	public function on_updated( int $post_id, $post_after, $post_before ): void {
		if ( ! $post_after instanceof WP_Post || ! $post_before instanceof WP_Post ) {
			return;
		}

		try {
			$changes = self::field_changes( self::fields( $post_before ), self::fields( $post_after ) );
			if ( [] !== $changes && ! self::is_upgrader_package( $post_id ) ) {
				$this->emit_media( 2011, $post_after, $changes );
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 2011, [ 'post_id' => $post_id ] );
		}
	}

	/**
	 * Post meta is about to be overwritten. Core fires this before the
	 * write, so the stored alternative text is still the old one.
	 *
	 * @param mixed $meta_id    Row id; not used.
	 * @param mixed $meta_value The new value.
	 */
	public function on_meta_update( $meta_id, int $object_id, string $meta_key, $meta_value ): void {
		if ( self::ALT_KEY !== $meta_key ) {
			return;
		}

		try {
			$this->alt_changed( $object_id, get_post_meta( $object_id, self::ALT_KEY, true ), $meta_value );
		} catch ( Throwable $e ) {
			$this->report_alt_failure( $e, $object_id );
		}
	}

	/**
	 * Post meta was stored for the first time: a file that had no
	 * alternative text got one.
	 *
	 * @param mixed $meta_id    Row id; not used.
	 * @param mixed $meta_value The value.
	 */
	public function on_meta_added( $meta_id, int $object_id, string $meta_key, $meta_value ): void {
		if ( self::ALT_KEY !== $meta_key ) {
			return;
		}

		try {
			$this->alt_changed( $object_id, null, $meta_value );
		} catch ( Throwable $e ) {
			$this->report_alt_failure( $e, $object_id );
		}
	}

	/**
	 * A file is being deleted for good. Core fires this while the file and
	 * its meta still exist.
	 *
	 * @param int   $post_id Attachment id.
	 * @param mixed $post    The file.
	 */
	public function on_delete( int $post_id, $post = null ): void {
		try {
			if ( ! $post instanceof WP_Post ) {
				$post = get_post( $post_id );
			}
			if ( $post instanceof WP_Post && ! self::is_upgrader_package( $post_id ) ) {
				$this->emit_media( 2012, $post );
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 2012, [ 'post_id' => $post_id ] );
		}
	}

	/**
	 * The change of a file's alternative text; empty when it did not change.
	 *
	 * An empty text is no text: an upload through the REST API that sends an
	 * empty one stores it, and clearing the field stores one too, so both
	 * read as none (null). The text is masked and cut like every reported
	 * value.
	 *
	 * @param ?string $old The text before; null for none.
	 * @param ?string $new The text after; null for none.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function alt_change( ?string $old, ?string $new ): array {
		$old = '' === $old ? null : $old;
		$new = '' === $new ? null : $new;
		if ( $old === $new ) {
			return [];
		}

		return ChangeSet::diff( [ 'alt' => $old ], [ 'alt' => $new ], [ 'alt' ] );
	}

	/**
	 * The changes of an edit of a file: its title, caption and description
	 * (only said to have changed), given the raw `post_title`,
	 * `post_excerpt` and `post_content` before and after the edit.
	 *
	 * @param array<string, mixed> $before The fields before the edit.
	 * @param array<string, mixed> $after  The fields after the edit.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function field_changes( array $before, array $after ): array {
		$old = [];
		$new = [];
		foreach ( self::FIELDS as $name => $field ) {
			$old[ $name ] = $before[ $field ] ?? null;
			$new[ $name ] = $after[ $field ] ?? null;
		}

		return ChangeSet::diff( $old, $new, array_keys( self::FIELDS ), self::CONTENT_FIELDS );
	}

	/**
	 * The object an event about a file of the media library is about, for
	 * every sensor that logs one.
	 *
	 * @param int    $id    Attachment id.
	 * @param string $title Title of the file.
	 * @return array{type: string, id: string, label: string}
	 */
	public static function media_target( int $id, string $title ): array {
		return [
			'type'  => ObjectType::MEDIA,
			'id'    => (string) $id,
			'label' => $title,
		];
	}

	/**
	 * Whether an attachment is a plugin or theme zip uploaded through the
	 * installer: core stores it with the context `upgrader` (before
	 * `add_attachment` fires; it is still there when `delete_attachment`
	 * fires) and deletes it once the install is done, or two hours later.
	 * Browsers name the type of a zip in different ways, so only the context
	 * tells.
	 *
	 * @param int $id Attachment id.
	 */
	public static function is_upgrader_package( int $id ): bool {
		return self::UPGRADER_CONTEXT === get_post_meta( $id, self::CONTEXT_KEY, true );
	}

	/**
	 * Log a change of the alternative text of a file.
	 *
	 * @param int   $post_id Attachment id.
	 * @param mixed $old     The text before; null for none.
	 * @param mixed $new     The text after.
	 */
	private function alt_changed( int $post_id, $old, $new ): void {
		$changes = self::alt_change( self::text( $old ), self::text( $new ) );
		if ( [] === $changes ) {
			return;
		}

		$post = get_post( $post_id );
		if ( $post instanceof WP_Post && 'attachment' === $post->post_type && ! self::is_upgrader_package( $post_id ) ) {
			$this->emit_media( 2011, $post, $changes );
		}
	}

	/**
	 * Report a failure while reading an alternative text change.
	 *
	 * @param Throwable $e       What went wrong.
	 * @param int       $post_id Attachment id.
	 */
	private function report_alt_failure( Throwable $e, int $post_id ): void {
		$this->report_failure(
			$e,
			2011,
			[
				'post_id' => $post_id,
				'field'   => 'alt',
			]
		);
	}

	/**
	 * The compared fields of a file, see {@see self::field_changes()}.
	 *
	 * @return array<string, mixed>
	 */
	private static function fields( WP_Post $post ): array {
		$fields = [];
		foreach ( self::FIELDS as $field ) {
			$fields[ $field ] = $post->$field;
		}

		return $fields;
	}

	/**
	 * A meta value as text: a string as it is, anything else (a value some
	 * plugin stored in another shape) as its loggable text.
	 *
	 * @param mixed $value Meta value.
	 */
	private static function text( $value ): ?string {
		return is_string( $value ) ? $value : ChangeSet::stringify( $value );
	}

	/**
	 * @param array<int, array{field: string, old: ?string, new: ?string}> $changes What changed.
	 */
	private function emit_media( int $code, WP_Post $post, array $changes = [] ): void {
		$id    = (int) $post->ID;
		$title = (string) $post->post_title;

		$fields = [
			'post'     => [
				'id'     => $id,
				'type'   => (string) $post->post_type,
				'status' => (string) $post->post_status,
			],
			'target'   => self::media_target( $id, $title ),
			'title'    => $title,
			'metadata' => [
				'mime_type' => (string) $post->post_mime_type,
			],
		];
		if ( [] !== $changes ) {
			$fields['changes'] = $changes;
		}
		// A deleted file is gone, so there is nothing to open.
		if ( 2012 !== $code ) {
			$fields['links'] = [ 'edit' => self::edit_link( $id ) ];
		}

		$this->emit( $code, $fields );
	}

	/**
	 * The edit screen of a file. Core answers null when nobody who may edit
	 * the file is logged in (a form plugin storing a visitor's upload), so
	 * the link is then built.
	 *
	 * @param int $id Attachment id.
	 */
	private static function edit_link( int $id ): string {
		$edit = get_edit_post_link( $id, 'raw' );

		return is_string( $edit ) && '' !== $edit ? $edit : admin_url( 'post.php?post=' . $id . '&action=edit' );
	}
}
