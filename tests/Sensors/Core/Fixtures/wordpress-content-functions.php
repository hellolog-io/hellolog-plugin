<?php
/**
 * Stand-ins for the WordPress classes and functions the post and comment
 * sensors call from their hooks.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` and `class_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Sensors\Core\Fixtures\WpState;

	/**
	 * A post or comment: a bag of read-only fields.
	 */
	class WP_Post {

		/** @param array<string, mixed> $values */
		public function __construct( private array $values ) {
		}

		public function __get( string $name ) {
			return $this->values[ $name ] ?? null;
		}
	}

	class WP_Comment {

		/** @param array<string, mixed> $values */
		public function __construct( private array $values ) {
		}

		public function __get( string $name ) {
			return $this->values[ $name ] ?? null;
		}
	}

	function doing_action( ?string $hook_name = null ): bool {
		return null === $hook_name ? null !== WpState::$doing_action : WpState::$doing_action === $hook_name;
	}

	function get_post_type_object( string $type ): ?object {
		return array_key_exists( $type, WpState::$post_types ) ? (object) [ 'show_ui' => WpState::$post_types[ $type ] ] : null;
	}

	function get_edit_post_link( int $id, string $context = 'display' ): ?string {
		return WpState::$can_edit ? 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit' : null;
	}

	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . $path;
	}

	function get_permalink( WP_Post $post ): string {
		return 'https://example.test/p/' . $post->ID . '/';
	}

	function get_post( int $id ): WP_Post {
		if ( isset( WpState::$posts[ $id ] ) ) {
			return WpState::$posts[ $id ];
		}

		return new WP_Post(
			[
				'ID'         => $id,
				'post_title' => 'Parent post',
				'post_type'  => 'post',
			]
		);
	}

	/**
	 * @param string $object Post type name; core also takes a post object.
	 * @param string $output Only `names` is stubbed.
	 * @return array<int, string>
	 */
	function get_object_taxonomies( $object, string $output = 'names' ): array {
		return WpState::$taxonomies[ (string) $object ] ?? [];
	}

	/**
	 * Like core: false when the post has no terms in the taxonomy.
	 *
	 * @param int    $post     Post id; core also takes a post object.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<int, object>|false
	 */
	function get_the_terms( $post, string $taxonomy ) {
		if ( WpState::$term_reads_throw ) {
			throw new \RuntimeException( 'unreadable' );
		}

		$terms = [];
		foreach ( WpState::$post_terms[ (int) $post ][ $taxonomy ] ?? [] as $tt_id ) {
			if ( isset( WpState::$terms[ $tt_id ] ) ) {
				$terms[] = WpState::$terms[ $tt_id ];
			}
		}

		return [] === $terms ? false : $terms;
	}

	/**
	 * Only the lookup by term taxonomy id is stubbed. Like core, a term of
	 * another taxonomy than the one asked for is not found.
	 *
	 * @param string     $field    `term_taxonomy_id`.
	 * @param int|string $value    Term taxonomy id.
	 * @param string     $taxonomy Taxonomy; empty for any.
	 * @return object|false
	 */
	function get_term_by( string $field, $value, string $taxonomy = '' ) {
		if ( WpState::$term_reads_throw ) {
			throw new \RuntimeException( 'unreadable' );
		}
		$term = 'term_taxonomy_id' === $field ? ( WpState::$terms[ (int) $value ] ?? null ) : null;
		if ( null === $term || ( '' !== $taxonomy && $term->taxonomy !== $taxonomy ) ) {
			return false;
		}

		return $term;
	}

	function is_sticky( int $post_id = 0 ): bool {
		return in_array( $post_id, WpState::$sticky, true );
	}

	/**
	 * @param int $post Post id; core also takes a post object.
	 * @return int|false
	 */
	function get_post_thumbnail_id( $post = null ) {
		return WpState::$thumbnails[ (int) $post ] ?? 0;
	}

	/**
	 * @param int $post Post id; core also takes a post object.
	 * @return string|false
	 */
	function get_page_template_slug( $post = null ) {
		return WpState::$templates[ (int) $post ] ?? '';
	}

	/**
	 * @param int $post Post id; core also takes a post object.
	 * @return array{latest_id: int, count: int}
	 */
	function wp_get_latest_revision_id_and_total_count( $post = 0 ): array {
		$latest = WpState::$revisions[ (int) $post ] ?? 0;

		return [
			'latest_id' => $latest,
			'count'     => $latest > 0 ? 1 : 0,
		];
	}

	/**
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @param bool   $single  Only the single read is stubbed.
	 * @return mixed
	 */
	function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
		if ( WpState::$post_meta_throws ) {
			throw new \RuntimeException( 'unreadable' );
		}

		return WpState::$post_meta[ $post_id ][ $key ] ?? '';
	}

	function get_comment( int $id ): ?WP_Comment {
		return WpState::$comment;
	}

	function get_comment_link( WP_Comment $comment ): string {
		return 'https://example.test/p/1/#comment-' . $comment->comment_ID;
	}
}
