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

	function get_comment( int $id ): ?WP_Comment {
		return WpState::$comment;
	}

	function get_comment_link( WP_Comment $comment ): string {
		return 'https://example.test/p/1/#comment-' . $comment->comment_ID;
	}
}
