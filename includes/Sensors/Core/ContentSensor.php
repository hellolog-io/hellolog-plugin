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
use Throwable;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks the post lifecycle: creation, publish / unpublish, scheduling,
 * review, private, trash, restore, permanent delete, and ordinary updates.
 *
 * A save is told in one event. Core fires `pre_post_update` before it writes
 * an existing post, then `transition_post_status` and `post_updated`; the REST
 * API (the block editor) writes the featured image, sticky flag, template and
 * terms only after that, and `wp_after_insert_post` comes last, once core
 * saved the revision. So the post is read on `pre_post_update` (see
 * {@see PostSnapshot}), its status change is noted, and the event is built on
 * `wp_after_insert_post` from that snapshot and the post as it ends up: the
 * status event when the status change is one, carrying the other changes,
 * otherwise an update (2006). A save that never gets to `wp_after_insert_post`
 * (a REST save whose terms failed) is logged at shutdown. A status change
 * outside a save this sensor saw start (a scheduled post going live, a post
 * inserted) has nothing to compare and is logged as it happens.
 *
 * Terms and the sticky flag set on their own, outside a save, are an update
 * of the post: its terms named by the taxonomy, or `sticky`.
 *
 * Which post types are logged is decided by {@see self::is_tracked_type()}:
 * internal types without an admin screen are left out, and so are the
 * events another sensor reports itself (see {@see PostTypeOwnership}); the
 * site editor's types are always logged (see {@see self::SITE_EDITOR_TYPES}). Every event names the post it is
 * about as its target and links to its edit screen.
 *
 * Of a file of the media library only its trash and restore are logged here
 * (the rest is {@see MediaSensor}'s), on the file's media target. Core moves
 * an attachment to the trash without firing `transition_post_status`, so its
 * trash is told by `trashed_post`.
 */
final class ContentSensor extends AbstractSensor {

	/**
	 * The post types of the site editor, by the names the editor gives them.
	 * Templates, template parts and global styles have no admin screen of
	 * their own, yet they decide how the whole site looks: these types are
	 * always logged, and their messages name them by these labels.
	 *
	 * @var array<string, string>
	 */
	public const SITE_EDITOR_TYPES = [
		'wp_template'      => 'Template',
		'wp_template_part' => 'Template part',
		'wp_global_styles' => 'Global styles',
		'wp_navigation'    => 'Navigation menu',
		'wp_block'         => 'Pattern',
	];

	/**
	 * Old statuses of a save that brings a post into being: an insert (`new`)
	 * and the first save of an editor placeholder (`auto-draft`). Every field
	 * is filled in by such a save, so it carries no changes.
	 *
	 * @var array<int, string>
	 */
	private const CREATION_STATUSES = [ 'new', 'auto-draft' ];

	/**
	 * Snapshots of the posts whose save is open, taken on `pre_post_update`:
	 * `site:post id` => {@see PostSnapshot}. A post id is unique on its own
	 * site only.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $before = [];

	/**
	 * The status change of an open save: `site:post id` => the status before
	 * the save and extra `metadata` for its event.
	 *
	 * @var array<string, array{old: string, extra: array<string, mixed>}>
	 */
	private array $pending = [];

	/**
	 * Term relationships `wp_set_object_terms()` added in the call that is
	 * running: `site:object id:taxonomy` => term taxonomy ids. Taken by the
	 * `set_object_terms` that ends the call.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $added = [];

	/**
	 * Posts `wp_untrash_post()` is restoring right now: `site:post id` => the
	 * status the post had when it was trashed. Set on `untrash_post`, taken by
	 * the status change that follows it.
	 *
	 * @var array<string, string>
	 */
	private array $untrashing = [];

	/**
	 * Posts whose restore by `wp_untrash_post()` the status change has taken,
	 * so the `untrashed_post` it fires next does not log it again.
	 *
	 * @var array<string, true>
	 */
	private array $restored = [];

	public function key(): string {
		return 'core-content';
	}

	public function boot(): void {
		add_action( 'pre_post_update', [ $this, 'on_before_update' ], 10, 1 );
		add_action( 'transition_post_status', [ $this, 'on_status' ], 10, 3 );
		// After core saved the revision of the save, at priority 9.
		add_action( 'wp_after_insert_post', [ $this, 'on_after_insert' ], 10, 1 );
		add_action( 'added_term_relationship', [ $this, 'on_term_added' ], 10, 3 );
		add_action( 'set_object_terms', [ $this, 'on_terms_set' ], 10, 6 );
		add_action( 'post_stuck', [ $this, 'on_stuck' ], 10, 1 );
		add_action( 'post_unstuck', [ $this, 'on_unstuck' ], 10, 1 );
		add_action( 'before_delete_post', [ $this, 'on_delete' ], 10, 2 );
		add_action( 'untrash_post', [ $this, 'on_untrash' ], 10, 2 );
		add_action( 'untrashed_post', [ $this, 'on_untrashed' ], 10, 2 );
		add_action( 'trashed_post', [ $this, 'on_trashed' ], 10, 1 );
		// Before the request's events are written, at priority 1.
		add_action( 'shutdown', [ $this, 'flush_open_saves' ], 0 );
	}

	/**
	 * An existing post is about to be saved: read it as it still is. A save
	 * of the same post that is already open (a plugin saving it again from
	 * the hooks of the first save) keeps the first snapshot, so one event
	 * tells both saves.
	 */
	public function on_before_update( int $post_id ): void {
		$key = self::state_key( $post_id );
		if ( isset( $this->before[ $key ] ) ) {
			return;
		}

		// Reading the post's terms and meta can fail (a filter on them that
		// throws); that must never reach the request that saves the post.
		try {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post && $this->should_track( $post, 2006 ) ) {
				$this->before[ $key ] = $this->snapshot( $post );
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 2006, [ 'post_id' => $post_id ] );
		}
	}

	/**
	 * A restore is logged by the status change out of the trash: the block
	 * editor restores a post with a plain status update, and
	 * `wp_untrash_post()` changes the status too. The status the post had
	 * before the trash is only known when `wp_untrash_post()` restores it.
	 *
	 * Inside a save whose start this sensor saw, the status change is only
	 * noted: the save's event is built once the save is done, see
	 * {@see self::on_after_insert()}. Any other status change is logged here.
	 */
	public function on_status( string $new_status, string $old_status, WP_Post $post ): void {
		$code  = self::status_event_code( $new_status, $old_status );
		$key   = self::state_key( (int) $post->ID );
		$extra = [];
		if ( 2004 === $code && array_key_exists( $key, $this->untrashing ) ) {
			$extra['previous_status'] = $this->untrashing[ $key ];
			$this->restored[ $key ]   = true;
			unset( $this->untrashing[ $key ] );
		}

		if ( isset( $this->before[ $key ] ) ) {
			$this->pending[ $key ] = [
				'old'   => $this->pending[ $key ]['old'] ?? $old_status,
				'extra' => array_merge( $this->pending[ $key ]['extra'] ?? [], $extra ),
			];
			return;
		}

		if ( 0 !== $code && $this->should_track( $post, $code ) ) {
			$this->emit_post_event( $code, $post, $extra );
		}
	}

	/**
	 * A save is done, its terms and meta included: log it, if this sensor saw
	 * it start.
	 */
	public function on_after_insert( int $post_id ): void {
		$key = self::state_key( $post_id );
		if ( ! isset( $this->before[ $key ] ) ) {
			return;
		}

		$before  = $this->before[ $key ];
		$pending = $this->pending[ $key ] ?? null;
		unset( $this->before[ $key ], $this->pending[ $key ] );

		$this->log_save( $post_id, $before, $pending );
	}

	/**
	 * Log the saves that never got to `wp_after_insert_post`, each on the
	 * site it happened on. Runs at shutdown, once.
	 */
	public function flush_open_saves(): void {
		$open          = $this->before;
		$pending       = $this->pending;
		$this->before  = [];
		$this->pending = [];

		foreach ( $open as $key => $before ) {
			$parts   = explode( ':', (string) $key, 2 );
			$blog_id = (int) $parts[0];
			$post_id = (int) ( $parts[1] ?? 0 );

			try {
				$this->on_site( $blog_id, fn() => $this->log_save( $post_id, $before, $pending[ $key ] ?? null ) );
			} catch ( Throwable $e ) {
				$this->report_failure( $e, 2006, [ 'post_id' => $post_id ] );
			}
		}
	}

	/**
	 * `wp_set_object_terms()` gave an object a term it did not have. Noted
	 * for the `set_object_terms` that ends the call: when appending, core
	 * passes it no old terms, so these tell what the call changed.
	 *
	 * @param int    $object_id Object id.
	 * @param mixed  $tt_id     Term taxonomy id.
	 * @param string $taxonomy  Taxonomy name.
	 */
	public function on_term_added( int $object_id, $tt_id, string $taxonomy ): void {
		if ( TaxonomySensor::is_tracked( $taxonomy ) ) {
			$this->added[ self::state_key( $object_id ) . ':' . $taxonomy ][] = (int) $tt_id;
		}
	}

	/**
	 * Terms of an object were set. Outside a save whose start this sensor saw
	 * (that save's event compares the terms itself), a change of the terms of
	 * a post it logs is an update of the post, named by the taxonomy.
	 *
	 * When appending, core passes no old terms (and every term given, the
	 * ones the object had included): the old terms are the post's terms now,
	 * less the relationships this call added, and a call that added none
	 * changed nothing.
	 *
	 * @param int    $object_id  Object id.
	 * @param mixed  $terms      The terms as given; not used.
	 * @param mixed  $tt_ids     Term taxonomy ids of every term given, those the object already had included.
	 * @param string $taxonomy   Taxonomy name.
	 * @param mixed  $append     Whether the terms were added to the object's terms.
	 * @param mixed  $old_tt_ids Term taxonomy ids the object had before; empty when appending.
	 */
	public function on_terms_set( int $object_id, $terms, $tt_ids, string $taxonomy, $append = false, $old_tt_ids = [] ): void {
		$key   = self::state_key( $object_id );
		$added = self::ids( $this->added[ $key . ':' . $taxonomy ] ?? [] );
		unset( $this->added[ $key . ':' . $taxonomy ] );

		if ( ! TaxonomySensor::is_tracked( $taxonomy ) || isset( $this->before[ $key ] ) ) {
			return;
		}

		$old = self::ids( $old_tt_ids );
		$new = self::ids( $tt_ids );
		if ( $append ? [] === $added : $old === $new ) {
			return;
		}

		// Reading the post and the terms can fail (a filter on them that
		// throws); that must never reach the request that set the terms.
		try {
			$post = $this->loggable_post( $object_id );
			if ( null === $post || ! in_array( $taxonomy, get_object_taxonomies( (string) $post->post_type ), true ) ) {
				return;
			}

			if ( $append ) {
				$after = self::post_terms( $object_id, $taxonomy );
				if ( null === $after ) {
					return;
				}
				$after += self::term_names( array_values( array_diff( $added, array_keys( $after ) ) ), $taxonomy );
				$before = array_diff_key( $after, array_flip( $added ) );
			} else {
				$before = self::term_names( $old, $taxonomy );
				$after  = self::term_names( $new, $taxonomy );
			}

			$changes = PostSnapshot::term_changes( [ $taxonomy => $before ], [ $taxonomy => $after ] );
			if ( [] !== $changes ) {
				$this->emit_update( $post, $changes );
			}
		} catch ( Throwable $e ) {
			$this->report_failure(
				$e,
				2006,
				[
					'post_id'  => $object_id,
					'taxonomy' => $taxonomy,
				]
			);
		}
	}

	/**
	 * A post was made sticky.
	 */
	public function on_stuck( int $post_id ): void {
		$this->sticky_changed( $post_id, true );
	}

	/**
	 * A post is no longer sticky.
	 */
	public function on_unstuck( int $post_id ): void {
		$this->sticky_changed( $post_id, false );
	}

	/**
	 * `wp_untrash_post()` is about to restore a post; the status change that
	 * follows logs it.
	 *
	 * @param int    $post_id         Post id.
	 * @param string $previous_status Status of the post when it was trashed.
	 */
	public function on_untrash( int $post_id, string $previous_status = '' ): void {
		$this->untrashing[ self::state_key( $post_id ) ] = $previous_status;
	}

	/**
	 * Logs a restore that no status change has logged, which only happens
	 * when something fires `untrashed_post` on its own.
	 *
	 * @param int    $post_id         Post id.
	 * @param string $previous_status Status of the post when it was trashed.
	 */
	public function on_untrashed( int $post_id, string $previous_status ): void {
		$key = self::state_key( $post_id );
		if ( isset( $this->restored[ $key ] ) ) {
			unset( $this->restored[ $key ] );
			return;
		}

		$post = get_post( $post_id );
		if ( $post instanceof WP_Post && $this->should_track( $post, 2004 ) ) {
			$this->emit_post_event( 2004, $post, [ 'previous_status' => $previous_status ] );
		}
	}

	/**
	 * A file of the media library was moved to the trash. Core changes the
	 * status of an attachment without firing `transition_post_status`, so
	 * this is the only sign of it; any other post's trash was logged by its
	 * status change.
	 */
	public function on_trashed( int $post_id ): void {
		try {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post && 'attachment' === $post->post_type && $this->should_track( $post, 2003 ) ) {
				$this->emit_post_event( 2003, $post );
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 2003, [ 'post_id' => $post_id ] );
		}
	}

	public function on_delete( int $post_id, WP_Post $post ): void {
		// Core's only caller of `wp_delete_auto_drafts()` is this cron action.
		$cleanup = function_exists( 'doing_action' ) && doing_action( 'wp_scheduled_auto_draft_delete' );
		if ( ! $this->should_track( $post, 2005 ) || ! self::is_loggable_delete( (string) $post->post_status, $cleanup ) ) {
			return;
		}
		// `before_delete_post` only fires for permanent deletes — trash uses
		// `wp_trash_post` which we already handle via `transition_post_status`.
		$this->emit_post_event( 2005, $post );
	}

	/**
	 * The event a status change is, or 0 when it is none. The first rule that
	 * applies decides.
	 *
	 * A post that already existed and is sent to `auto-draft`, from any state
	 * (published or trashed included), is "reverted": WordPress deletes stale
	 * auto-drafts on its own, so the move is what has to be on record; a new
	 * placeholder (`new` to `auto-draft`) is not an event. Moving to trash is
	 * "trashed" from every state, and moving out of the trash, to any other
	 * state, is "restored" (the block editor restores a post with a plain
	 * status update). A post that goes live is "published" even when it was
	 * never saved as a draft first, otherwise a direct publish would be
	 * logged as a plain creation. Going from published to anything else is
	 * "unpublished". Otherwise a post sent to `future` is "scheduled", to
	 * `pending` "submitted for review" and to `private` "made private", from
	 * any state, the editor's placeholder (`auto-draft`) and a direct insert
	 * (`new`) included. A post that comes into being as a draft, from either
	 * of those two, is "created".
	 * Everything else (including no change) is not an event of its own.
	 *
	 * @param string $new New post status.
	 * @param string $old Previous post status.
	 */
	public static function status_event_code( string $new, string $old ): int {
		if ( $new === $old ) {
			return 0;
		}
		if ( 'auto-draft' === $new ) {
			return 'new' === $old ? 0 : 2013;
		}
		if ( 'trash' === $new ) {
			return 2003;
		}
		if ( 'trash' === $old ) {
			return 2004;
		}
		if ( 'publish' === $new ) {
			return 2001;
		}
		if ( 'publish' === $old ) {
			return 2002;
		}

		$code = [
			'future'  => 2007,
			'pending' => 2008,
			'private' => 2009,
		][ $new ] ?? 0;
		if ( 0 !== $code ) {
			return $code;
		}

		return 'draft' === $new && in_array( $old, self::CREATION_STATUSES, true ) ? 2000 : 0;
	}

	/**
	 * Whether an event about a post of a type is logged by this sensor.
	 *
	 * The site editor's types always are. Internal types without an admin
	 * screen never are. An event the type's owner reports in its own words
	 * is left to it, see {@see PostTypeOwnership}.
	 *
	 * @param string $post_type    Post type name.
	 * @param bool   $show_ui      Whether the type has an admin screen.
	 * @param int    $code         Code of the event.
	 * @param bool   $owner_active Whether the plugin that owns the type is active, see {@see PostTypeOwnership::owner_active()}.
	 */
	public static function is_tracked_type( string $post_type, bool $show_ui, int $code, bool $owner_active ): bool {
		if ( isset( self::SITE_EDITOR_TYPES[ $post_type ] ) ) {
			return true;
		}

		return $show_ui && ! PostTypeOwnership::is_owned_elsewhere( $post_type, $code, $owner_active );
	}

	/**
	 * How a message names a post type: the site editor's types by the
	 * editor's labels, any other by its name made readable (`wc_booking` is
	 * `Wc booking`).
	 *
	 * @param string $post_type Post type name.
	 */
	public static function type_label( string $post_type ): string {
		return self::SITE_EDITOR_TYPES[ $post_type ] ?? ucfirst( str_replace( [ '_', '-' ], ' ', $post_type ) );
	}

	/**
	 * Whether the permanent delete of a post is worth an event.
	 *
	 * Only the housekeeping WordPress runs by itself is skipped: its cron
	 * job that clears out stale `auto-draft` placeholders. Any other delete
	 * of an `auto-draft` post is logged, otherwise setting a post to that
	 * status first would be a way to delete it unseen.
	 *
	 * @param string $status                 Status of the post being deleted.
	 * @param bool   $in_auto_draft_cleanup Whether WordPress's own auto-draft cleanup is running.
	 */
	public static function is_loggable_delete( string $status, bool $in_auto_draft_cleanup ): bool {
		return ! ( 'auto-draft' === $status && $in_auto_draft_cleanup );
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
	 * Whether an event about this post is logged, see {@see self::is_tracked_type()}.
	 * A plugin or theme zip the installer keeps as an attachment is no file
	 * of the media library and never logged, see {@see MediaSensor::is_upgrader_package()}.
	 *
	 * @param WP_Post $post Post the event is about.
	 * @param int     $code Code of the event.
	 */
	private function should_track( WP_Post $post, int $code ): bool {
		$type        = (string) $post->post_type;
		$type_object = get_post_type_object( $type );

		$tracked = self::is_tracked_type(
			$type,
			(bool) ( $type_object->show_ui ?? false ),
			$code,
			PostTypeOwnership::owner_active( $type )
		);

		return $tracked && ( 'attachment' !== $type || ! $this->is_upgrader_package( (int) $post->ID, $code ) );
	}

	/**
	 * Whether an attachment is a zip the plugin and theme installer keeps.
	 * When that can not be read the failure is reported and the attachment
	 * taken for a file of the library: its event is logged.
	 *
	 * @param int $post_id Attachment id.
	 * @param int $code    Code of the event.
	 */
	private function is_upgrader_package( int $post_id, int $code ): bool {
		try {
			return MediaSensor::is_upgrader_package( $post_id );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, $code, [ 'post_id' => $post_id ] );

			return false;
		}
	}

	/**
	 * Log a save whose start this sensor saw: compare the post as it was
	 * before the save with the post as it is now. A status change that is
	 * an event is logged as that event, carrying the other changes; any
	 * other save is an update, which names the status among its changes when
	 * the status changed. A save that brought the post into being carries no
	 * changes. When the reads fail, the event still goes out, without
	 * changes.
	 *
	 * @param array<string, mixed>                                   $before  Snapshot taken before the save.
	 * @param array{old: string, extra: array<string, mixed>}|null $pending The status change of the save, if any.
	 */
	private function log_save( int $post_id, array $before, ?array $pending ): void {
		try {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post ) {
				return;
			}

			$old   = (string) ( $pending['old'] ?? $before['post_status'] ?? '' );
			$code  = self::status_event_code( (string) $post->post_status, $old );
			$event = 0 === $code ? 2006 : $code;
			if ( ! $this->should_track( $post, $event ) ) {
				return;
			}

			$changes = [];
			if ( ! in_array( $old, self::CREATION_STATUSES, true ) ) {
				try {
					$changes = PostSnapshot::changes( $before, $this->snapshot( $post ) );
				} catch ( Throwable $e ) {
					$this->report_failure( $e, $event, [ 'post_id' => $post_id ] );
				}
			}
			if ( 2006 !== $event ) {
				$changes = self::status_event_changes( $changes, $before );
			}

			$extra = $pending['extra'] ?? [];
			if ( 2006 === $event ) {
				$extra['changed'] = array_column( $changes, 'field' );
			}

			$links = [];
			if ( in_array( 'post_content', array_column( $changes, 'field' ), true ) ) {
				try {
					$links = self::revision_link( $post_id );
				} catch ( Throwable $e ) {
					$this->report_failure( $e, $event, [ 'post_id' => $post_id ] );
				}
			}

			$this->emit_post_event( $event, $post, $extra, $changes, $links );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 2006, [ 'post_id' => $post_id ] );
		}
	}

	/**
	 * What a status event carries of a save's changes: not the status, which
	 * the event itself says, and not the date of a draft that had none (a
	 * floating date, see {@see PostSnapshot::from_values()}): publishing,
	 * scheduling or making it private gives it one as part of the status
	 * change.
	 *
	 * @param array<int, array{field: string, old: ?string, new: ?string}> $changes The save's changes.
	 * @param array<string, mixed>                                         $before  Snapshot taken before the save.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	private static function status_event_changes( array $changes, array $before ): array {
		$floating = null === ( $before['post_date'] ?? null );

		return array_values(
			array_filter(
				$changes,
				static fn( array $item ): bool => 'post_status' !== $item['field'] && ! ( $floating && 'post_date' === $item['field'] )
			)
		);
	}

	/**
	 * Log the sticky flag of a post set on its own, outside a save whose
	 * start this sensor saw (that save's event compares the flag itself).
	 *
	 * @param int  $post_id Post id.
	 * @param bool $sticky  Whether the post is sticky now.
	 */
	private function sticky_changed( int $post_id, bool $sticky ): void {
		if ( isset( $this->before[ self::state_key( $post_id ) ] ) ) {
			return;
		}

		try {
			$post = $this->loggable_post( $post_id );
			if ( null !== $post ) {
				$this->emit_update(
					$post,
					[
						[
							'field' => 'sticky',
							'old'   => ChangeSet::stringify( ! $sticky ),
							'new'   => ChangeSet::stringify( $sticky ),
						],
					]
				);
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 2006, [ 'post_id' => $post_id ] );
		}
	}

	/**
	 * The post with this id, when an update of it is logged: a post of a
	 * tracked type that is no editor placeholder (an `auto-draft` has
	 * nothing anybody changed yet).
	 *
	 * @param int $post_id Post id.
	 */
	private function loggable_post( int $post_id ): ?WP_Post {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'auto-draft' === $post->post_status || ! $this->should_track( $post, 2006 ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Log an update of a post made outside a save.
	 *
	 * @param WP_Post                                                      $post    The post.
	 * @param array<int, array{field: string, old: ?string, new: ?string}> $changes What changed.
	 */
	private function emit_update( WP_Post $post, array $changes ): void {
		$this->emit_post_event( 2006, $post, [ 'changed' => array_column( $changes, 'field' ) ], $changes );
	}

	/**
	 * @param array<string, mixed>                                         $extra   Extra `metadata` entries.
	 * @param array<int, array{field: string, old: ?string, new: ?string}> $changes What changed.
	 * @param array<string, string>                                        $links   Links beside the edit and view links.
	 */
	private function emit_post_event( int $code, WP_Post $post, array $extra = [], array $changes = [], array $links = [] ): void {
		$id = (int) $post->ID;

		$fields = [
			'post'      => [
				'id'     => $id,
				'type'   => (string) $post->post_type,
				'status' => (string) $post->post_status,
			],
			'target'    => 'attachment' === $post->post_type
				? MediaSensor::media_target( $id, (string) $post->post_title )
				: self::post_target( $id, (string) $post->post_title ),
			'title'     => (string) $post->post_title,
			'post_type' => self::type_label( (string) $post->post_type ),
			'metadata'  => array_merge(
				[
					'slug'   => (string) $post->post_name,
					'author' => (int) $post->post_author,
				],
				$extra
			),
		];
		if ( 2006 === $code || [] !== $changes ) {
			$fields['changes'] = $changes;
		}
		if ( 2007 === $code ) {
			$fields['date'] = (string) $post->post_date;
		}

		// A permanently deleted post is gone, so there is nothing to open.
		if ( 2005 !== $code ) {
			$fields['links'] = array_merge( $this->links( $post ), $links );
		}

		$this->emit( $code, $fields );
	}

	/**
	 * A {@see PostSnapshot} of a post as it is stored now: its fields, its
	 * page template, featured image and sticky flag, and its terms in every
	 * tracked taxonomy of its type.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot( WP_Post $post ): array {
		$raw = [];
		foreach ( PostSnapshot::POST_FIELDS as $field ) {
			$raw[ $field ] = $post->$field;
		}

		$id       = (int) $post->ID;
		$image_id = (int) get_post_thumbnail_id( $id );
		$image    = null;
		if ( $image_id > 0 ) {
			$file  = get_post( $image_id );
			$title = $file instanceof WP_Post ? (string) $file->post_title : '';
			$image = '' !== $title ? $title : '#' . $image_id;
		}

		return PostSnapshot::from_values(
			$raw,
			[
				'template'          => get_page_template_slug( $id ),
				'featured_image'    => $image,
				'featured_image_id' => $image_id,
				'sticky'            => is_sticky( $id ),
				'terms'             => self::terms( $post ),
			]
		);
	}

	/**
	 * The terms of a post in every tracked taxonomy of its type: taxonomy
	 * name => term taxonomy id => term name. A taxonomy whose terms could not
	 * be read is left out (see {@see self::post_terms()}).
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function terms( WP_Post $post ): array {
		$terms = [];
		foreach ( get_object_taxonomies( (string) $post->post_type ) as $taxonomy ) {
			if ( ! is_string( $taxonomy ) || ! TaxonomySensor::is_tracked( $taxonomy ) ) {
				continue;
			}

			$found = self::post_terms( (int) $post->ID, $taxonomy );
			if ( null !== $found ) {
				$terms[ $taxonomy ] = $found;
			}
		}

		return $terms;
	}

	/**
	 * The terms a post has in one taxonomy: term taxonomy id => term name;
	 * null when they could not be read.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<int, string>|null
	 */
	private static function post_terms( int $post_id, string $taxonomy ): ?array {
		// False is a post without terms there; anything else but a list (an
		// error) is terms that could not be read.
		$found = get_the_terms( $post_id, $taxonomy );
		if ( false !== $found && ! is_array( $found ) ) {
			return null;
		}

		$terms = [];
		foreach ( is_array( $found ) ? $found : [] as $term ) {
			if ( is_object( $term ) && isset( $term->term_taxonomy_id ) ) {
				$terms[ (int) $term->term_taxonomy_id ] = (string) ( $term->name ?? '' );
			}
		}

		return $terms;
	}

	/**
	 * The names of terms given by their term taxonomy id (what core passes to
	 * `set_object_terms`; a term id is something else). A term that can not
	 * be found is named by its id (`#12`).
	 *
	 * @param array<int, int> $tt_ids   Term taxonomy ids.
	 * @param string          $taxonomy Taxonomy name.
	 * @return array<int, string> Term taxonomy id => name.
	 */
	private static function term_names( array $tt_ids, string $taxonomy ): array {
		$names = [];
		foreach ( $tt_ids as $tt_id ) {
			$term            = get_term_by( 'term_taxonomy_id', $tt_id, $taxonomy );
			$names[ $tt_id ] = is_object( $term ) && isset( $term->name ) ? (string) $term->name : '#' . $tt_id;
		}

		return $names;
	}

	/**
	 * Term taxonomy ids as core passes them (ints or numeric strings), each
	 * once, sorted.
	 *
	 * @param mixed $ids Ids.
	 * @return array<int, int>
	 */
	private static function ids( $ids ): array {
		$clean = [];
		foreach ( (array) $ids as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$clean[ (int) $id ] = (int) $id;
			}
		}
		sort( $clean );

		return $clean;
	}

	/**
	 * A link to the revision core saved for the post's text, if it saved
	 * one: by `wp_after_insert_post` the latest revision is that of the save.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, string>
	 */
	private static function revision_link( int $post_id ): array {
		$latest = wp_get_latest_revision_id_and_total_count( $post_id );
		$id     = is_array( $latest ) ? (int) ( $latest['latest_id'] ?? 0 ) : 0;

		return $id > 0 ? [ 'revision' => admin_url( 'revision.php?revision=' . $id ) ] : [];
	}

	/**
	 * Run something on a site of the network, and come back.
	 *
	 * @param int      $blog_id Site id; 0 for the current one.
	 * @param callable $work    What to run.
	 */
	private function on_site( int $blog_id, callable $work ): void {
		$switch = $blog_id > 0 && self::blog_id() !== $blog_id && function_exists( 'switch_to_blog' );
		if ( $switch ) {
			switch_to_blog( $blog_id );
		}

		try {
			$work();
		} finally {
			if ( $switch ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * The key of a post's per-request state: `site:post id`.
	 *
	 * @param int $post_id Post id.
	 */
	private static function state_key( int $post_id ): string {
		return self::blog_id() . ':' . $post_id;
	}

	/**
	 * The current site; 0 outside WordPress.
	 */
	private static function blog_id(): int {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	}

	/**
	 * Links to open a post.
	 *
	 * @return array<string, string>
	 */
	private function links( WP_Post $post ): array {
		$id = (int) $post->ID;

		// Core answers null for the edit link when nobody who may edit the post is
		// logged in (a scheduled post going live in cron, a visitor), so build it.
		$edit = get_edit_post_link( $id, 'raw' );
		if ( ! is_string( $edit ) || '' === $edit ) {
			$edit = admin_url( 'post.php?post=' . $id . '&action=edit' );
		}
		$view = 'publish' === $post->post_status ? get_permalink( $post ) : null;

		return self::post_links( $edit, is_string( $view ) ? $view : null, (string) $post->post_status );
	}
}
