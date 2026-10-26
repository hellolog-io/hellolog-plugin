<?php
/**
 * Tests for the pure parts of {@see HelloLog\Sensors\Core\CommentsSensor}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Sensors\Core\CommentsSensor;
use PHPUnit\Framework\TestCase;

/**
 * Pins which comment types are visitor comments worth logging (order notes,
 * Action Scheduler logs and other internal notes are stored as comments too),
 * and the shape of the target and links a comment event carries.
 */
final class CommentsSensorTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: bool}> comment type, expected.
	 */
	public static function comment_types(): array {
		return [
			'legacy empty type' => [ '', true ],
			'comment'           => [ 'comment', true ],
			'pingback'          => [ 'pingback', true ],
			'trackback'         => [ 'trackback', true ],
			'review'            => [ 'review', true ],
			'order note'        => [ 'order_note', false ],
			'scheduler log'     => [ 'action_log', false ],
			'webhook delivery'  => [ 'webhook_delivery', false ],
			'note'              => [ 'note', false ],
			'wrong case'        => [ 'Comment', false ],
			'padded'            => [ ' comment', false ],
		];
	}

	/**
	 * @dataProvider comment_types
	 */
	public function test_is_tracked_type( string $comment_type, bool $expected ): void {
		$this->assertSame( $expected, CommentsSensor::is_tracked_type( $comment_type ) );
	}

	public function test_comment_target_has_a_string_id_and_the_post_title_as_label(): void {
		$this->assertSame(
			[
				'type'  => 'comment',
				'id'    => '15',
				'label' => 'Hello world',
			],
			CommentsSensor::comment_target( 15, 'Hello world' )
		);
	}

	public function test_comment_links_has_edit_and_view_for_an_approved_comment(): void {
		$this->assertSame(
			[
				'edit' => 'https://example.test/wp-admin/comment.php?action=editcomment&c=15',
				'view' => 'https://example.test/hello/#comment-15',
			],
			CommentsSensor::comment_links(
				'https://example.test/wp-admin/comment.php?action=editcomment&c=15',
				'https://example.test/hello/#comment-15',
				'1'
			)
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unapproved_states(): array {
		return [
			'held'  => [ '0' ],
			'spam'  => [ 'spam' ],
			'trash' => [ 'trash' ],
		];
	}

	/**
	 * @dataProvider unapproved_states
	 */
	public function test_comment_links_has_no_view_unless_approved( string $approved ): void {
		$this->assertSame(
			[ 'edit' => 'https://example.test/wp-admin/comment.php?action=editcomment&c=15' ],
			CommentsSensor::comment_links(
				'https://example.test/wp-admin/comment.php?action=editcomment&c=15',
				'https://example.test/hello/#comment-15',
				$approved
			)
		);
	}

	public function test_comment_links_leaves_out_links_core_could_not_give(): void {
		$this->assertSame( [], CommentsSensor::comment_links( '', null, '1' ) );
	}
}
