<?php
/**
 * What a post update compares: a snapshot of the post and the diff of two.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Events\ChangeSet;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helper behind the changes of a post event. It never calls into
 * WordPress: {@see ContentSensor} reads the post and hands the raw values to
 * {@see self::from_values()}, and compares two snapshots with
 * {@see self::changes()}.
 *
 * A snapshot holds the fields of {@see self::FIELDS} by name, plus two
 * entries that are compared but never reported as fields of their own: the
 * id of the featured image (`featured_image_id`) and the post's terms
 * (`terms`: taxonomy name => term taxonomy id => term name).
 */
final class PostSnapshot {

	/**
	 * Fields of a post an update is compared on, in the order they are
	 * reported. The term changes follow them, one per taxonomy.
	 *
	 * @var array<int, string>
	 */
	public const FIELDS = [
		'post_title',
		'post_content',
		'post_excerpt',
		'post_name',
		'post_status',
		'post_parent',
		'post_author',
		'post_date',
		'menu_order',
		'comment_status',
		'ping_status',
		'password_protected',
		'template',
		'featured_image',
		'sticky',
	];

	/**
	 * Fields reported as "changed" without their values.
	 *
	 * @var array<int, string>
	 */
	public const CONTENT_FIELDS = [ 'post_content' ];

	/**
	 * The raw `WP_Post` fields {@see self::from_values()} reads. The password
	 * is among them only to tell whether there is one: it never gets into a
	 * snapshot.
	 *
	 * @var array<int, string>
	 */
	public const POST_FIELDS = [
		'post_title',
		'post_content',
		'post_excerpt',
		'post_name',
		'post_status',
		'post_parent',
		'post_author',
		'post_date',
		'post_date_gmt',
		'menu_order',
		'comment_status',
		'ping_status',
		'post_password',
	];

	/**
	 * Whether a password protects the post, reported as `yes`/`no`. Compared
	 * outside {@see ChangeSet::diff()}, whose masking would take the name for
	 * a credential and hide the yes or no.
	 */
	private const PROTECTED_FIELD = 'password_protected';

	/**
	 * The featured image: compared by its id, reported by its title.
	 */
	private const IMAGE_FIELD = 'featured_image';

	/**
	 * The `post_date_gmt` of a draft that has no date of its own: WordPress
	 * gives such a post the time of every save, which is no date anybody set.
	 */
	private const FLOATING_DATE = '0000-00-00 00:00:00';

	/**
	 * What WordPress appends to the slug of a post in the trash, and takes
	 * off again when the post is restored.
	 */
	private const TRASH_SUFFIX = '__trashed';

	/**
	 * A snapshot of a post.
	 *
	 * `$post` holds the raw fields of {@see self::POST_FIELDS}. Of the
	 * password only whether there is one is kept. A draft without a date of
	 * its own (a floating date) has no date, and the slug of a post in the
	 * trash is read without the suffix WordPress gave it there.
	 *
	 * `$extra` may hold `template` (the page template file; `default`, empty
	 * or false for none), `featured_image` (the image's title; null for
	 * none), `featured_image_id`, `sticky` (bool) and `terms` (taxonomy name
	 * => term taxonomy id => term name; a taxonomy that could not be read is
	 * left out). Anything missing reads as none.
	 *
	 * @param array<string, mixed> $post  Raw post fields.
	 * @param array<string, mixed> $extra What is stored beside the post row.
	 * @return array<string, mixed>
	 */
	public static function from_values( array $post, array $extra ): array {
		$status = (string) ( $post['post_status'] ?? '' );
		$slug   = (string) ( $post['post_name'] ?? '' );
		if ( 'trash' === $status && str_ends_with( $slug, self::TRASH_SUFFIX ) ) {
			$slug = substr( $slug, 0, -strlen( self::TRASH_SUFFIX ) );
		}

		// A floating date is no date anybody set; one set on the draft is.
		$date = $post['post_date'] ?? null;
		if ( self::FLOATING_DATE === (string) ( $post['post_date_gmt'] ?? '' ) ) {
			$date = null;
		}

		$template = $extra['template'] ?? '';
		$template = is_string( $template ) && 'default' !== $template ? $template : '';

		$image = $extra['featured_image'] ?? null;

		return [
			'post_title'          => $post['post_title'] ?? null,
			'post_content'        => $post['post_content'] ?? null,
			'post_excerpt'        => $post['post_excerpt'] ?? null,
			'post_name'           => $slug,
			'post_status'         => $status,
			'post_parent'         => $post['post_parent'] ?? null,
			'post_author'         => $post['post_author'] ?? null,
			'post_date'           => $date,
			'menu_order'          => $post['menu_order'] ?? null,
			'comment_status'      => $post['comment_status'] ?? null,
			'ping_status'         => $post['ping_status'] ?? null,
			self::PROTECTED_FIELD => '' !== (string) ( $post['post_password'] ?? '' ),
			'template'            => $template,
			self::IMAGE_FIELD     => is_scalar( $image ) ? (string) $image : null,
			'featured_image_id'   => max( 0, (int) ( $extra['featured_image_id'] ?? 0 ) ),
			'sticky'              => (bool) ( $extra['sticky'] ?? false ),
			'terms'               => self::terms_of( $extra['terms'] ?? [] ),
		];
	}

	/**
	 * What changed between two snapshots, as a change list: the fields of
	 * {@see self::FIELDS} in their order, then the terms
	 * ({@see self::term_changes()}).
	 *
	 * @param array<string, mixed> $before Snapshot before the save.
	 * @param array<string, mixed> $after  Snapshot after the save.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function changes( array $before, array $after ): array {
		$changes = [];
		foreach ( self::FIELDS as $field ) {
			if ( self::PROTECTED_FIELD === $field ) {
				$item = self::protected_change( $before, $after );
			} elseif ( self::IMAGE_FIELD === $field ) {
				$item = self::image_change( $before, $after );
			} else {
				$item = ChangeSet::diff( $before, $after, [ $field ], self::CONTENT_FIELDS )[0] ?? null;
			}

			if ( null !== $item ) {
				$changes[] = $item;
			}
		}

		return array_merge(
			$changes,
			self::term_changes( self::terms_of( $before['terms'] ?? [] ), self::terms_of( $after['terms'] ?? [] ) )
		);
	}

	/**
	 * What changed in the terms of a post: one item per taxonomy whose terms
	 * differ, named by the taxonomy (`category`, `post_tag`), with the term
	 * names before and after, sorted and joined by `, ` (empty for none).
	 *
	 * Terms are compared by their term taxonomy id, so their order is no
	 * change, and neither is a renamed term. Two different sets that read the
	 * same (another term of the same name) name every term with its id
	 * (`News (#5)`). A taxonomy that is not on both sides is not compared:
	 * it could not be read on one of them. The names are no secret whatever
	 * the taxonomy is called, so they are never masked as a credential.
	 *
	 * @param array<string, array<int, string>> $before Taxonomy => term taxonomy id => name, before.
	 * @param array<string, array<int, string>> $after  The same, after.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function term_changes( array $before, array $after ): array {
		$changes = [];
		foreach ( $after as $taxonomy => $new ) {
			if ( ! isset( $before[ $taxonomy ] ) ) {
				continue;
			}
			$old = $before[ $taxonomy ];

			$old_ids = array_keys( $old );
			$new_ids = array_keys( $new );
			sort( $old_ids );
			sort( $new_ids );
			if ( $old_ids === $new_ids ) {
				continue;
			}

			$old_text = self::names( $old, false );
			$new_text = self::names( $new, false );
			if ( $old_text === $new_text ) {
				$old_text = self::names( $old, true );
				$new_text = self::names( $new, true );
			}

			$changes[] = [
				'field' => (string) $taxonomy,
				'old'   => ChangeSet::truncate( $old_text ),
				'new'   => ChangeSet::truncate( $new_text ),
			];
		}

		return $changes;
	}

	/**
	 * Whether a password protects the post, as a `yes`/`no` change.
	 *
	 * @param array<string, mixed> $before Snapshot before the save.
	 * @param array<string, mixed> $after  Snapshot after the save.
	 * @return array{field: string, old: ?string, new: ?string}|null
	 */
	private static function protected_change( array $before, array $after ): ?array {
		$was = ! empty( $before[ self::PROTECTED_FIELD ] );
		$is  = ! empty( $after[ self::PROTECTED_FIELD ] );
		if ( $was === $is ) {
			return null;
		}

		return [
			'field' => self::PROTECTED_FIELD,
			'old'   => ChangeSet::stringify( $was ),
			'new'   => ChangeSet::stringify( $is ),
		];
	}

	/**
	 * The featured image change: compared by the image's id, so a renamed
	 * image is no change, and reported by its title; another image of the
	 * same title is told apart by its id (`Sunset (#30)`).
	 *
	 * @param array<string, mixed> $before Snapshot before the save.
	 * @param array<string, mixed> $after  Snapshot after the save.
	 * @return array{field: string, old: ?string, new: ?string}|null
	 */
	private static function image_change( array $before, array $after ): ?array {
		$old_id = (int) ( $before['featured_image_id'] ?? 0 );
		$new_id = (int) ( $after['featured_image_id'] ?? 0 );
		if ( $old_id === $new_id ) {
			return null;
		}

		$old = 0 === $old_id ? null : (string) ( $before[ self::IMAGE_FIELD ] ?? '' );
		$new = 0 === $new_id ? null : (string) ( $after[ self::IMAGE_FIELD ] ?? '' );
		if ( $old === $new ) {
			$old .= ' (#' . $old_id . ')';
			$new .= ' (#' . $new_id . ')';
		}

		return [
			'field' => self::IMAGE_FIELD,
			'old'   => ChangeSet::stringify( $old ),
			'new'   => ChangeSet::stringify( $new ),
		];
	}

	/**
	 * The term names of one taxonomy as one text: sorted, joined by `, `,
	 * each with its term taxonomy id when `$with_ids`.
	 *
	 * @param array<int, string> $terms    Term taxonomy id => name.
	 * @param bool               $with_ids Whether to add the ids.
	 */
	private static function names( array $terms, bool $with_ids ): string {
		$names = [];
		foreach ( $terms as $id => $name ) {
			$names[] = ChangeSet::redact_text( $name ) . ( $with_ids ? ' (#' . $id . ')' : '' );
		}
		sort( $names, SORT_STRING );

		return implode( ', ', $names );
	}

	/**
	 * The terms of a snapshot in their one shape: taxonomy name => term
	 * taxonomy id => term name. Anything else is left out.
	 *
	 * @param mixed $terms Terms as given.
	 * @return array<string, array<int, string>>
	 */
	private static function terms_of( $terms ): array {
		if ( ! is_array( $terms ) ) {
			return [];
		}

		$clean = [];
		foreach ( $terms as $taxonomy => $list ) {
			if ( ! is_string( $taxonomy ) || '' === $taxonomy || ! is_array( $list ) ) {
				continue;
			}
			$clean[ $taxonomy ] = [];
			foreach ( $list as $id => $name ) {
				if ( is_scalar( $name ) ) {
					$clean[ $taxonomy ][ (int) $id ] = (string) $name;
				}
			}
		}

		return $clean;
	}
}
