<?php
/**
 * Tests for the pure parts of {@see HelloLog\Sensors\Core\CommentsSensor}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Events\EventDispatcher;
use HelloLog\Sensors\Core\CommentsSensor;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
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
			// The block editor's notes on a post are written by people, as comments.
			'block editor note' => [ 'note', true ],
			'wrong case note'   => [ 'Note', false ],
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

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_permanent_delete_carries_no_links(): void {
		$calls = $this->run_hook(
			$this->comment_fields(),
			static function ( CommentsSensor $sensor ): void {
				$sensor->on_delete( 15 );
			}
		);

		$this->assertCount( 1, $calls );
		$this->assertSame( 2204, $calls[0][0] );
		$this->assertArrayNotHasKey( 'links', $calls[0][1] );
		$this->assertSame(
			[
				'type'  => 'comment',
				'id'    => '15',
				'label' => 'Parent post',
			],
			$calls[0][1]['target']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_other_comment_events_still_carry_links(): void {
		$calls = $this->run_hook(
			$this->comment_fields(),
			function ( CommentsSensor $sensor ): void {
				$sensor->on_update_data( $this->update_data( [ 'comment_content' => 'Edited' ] ) );
				$sensor->on_edit( 15 );
			}
		);

		$this->assertSame( [ 2205 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				'edit' => 'https://example.test/wp-admin/comment.php?action=editcomment&c=15',
				'view' => 'https://example.test/p/1/#comment-15',
			],
			$calls[0][1]['links']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_internal_notes_stored_as_comments_emit_nothing(): void {
		$calls = $this->run_hook(
			$this->comment_fields( [ 'comment_type' => 'order_note' ] ),
			function ( CommentsSensor $sensor, \WP_Comment $note ): void {
				$sensor->on_insert( 15, $note );
				$sensor->on_status( 'trash', 'approved', $note );
				$sensor->on_update_data( $this->update_data( [ 'comment_content' => 'Edited' ] ) );
				$sensor->on_edit( 15 );
				$sensor->on_delete( 15 );
			}
		);

		$this->assertSame( [], $calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_block_editor_note_is_logged_like_a_comment(): void {
		$calls = $this->run_hook(
			$this->comment_fields( [ 'comment_type' => 'note' ] ),
			function ( CommentsSensor $sensor, \WP_Comment $note ): void {
				$sensor->on_insert( 15, $note );
				$sensor->on_update_data( $this->update_data( [ 'comment_content' => 'Edited' ] ) );
				$sensor->on_edit( 15 );
				$sensor->on_delete( 15 );
			}
		);

		$this->assertSame( [ 2200, 2205, 2204 ], array_column( $calls, 0 ) );
		$this->assertSame( '15', $calls[0][1]['target']['id'] );
	}

	/**
	 * Approving a held comment through the REST API first writes the comment
	 * again (with the request's user agent), which fires `edit_comment`, and
	 * only then changes its status. Only the approval happened.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_approving_a_comment_through_the_rest_api_is_not_also_an_edit(): void {
		$calls = $this->run_hook(
			$this->comment_fields( [ 'comment_approved' => '0' ] ),
			function ( CommentsSensor $sensor ): void {
				$data = $this->update_data(
					[
						'comment_approved' => 0,
						'comment_agent'    => 'curl/8.5.0',
					]
				);
				$this->assertSame( $data, $sensor->on_update_data( $data ), 'The filter must hand the data back unchanged.' );
				$sensor->on_edit( 15 );

				WpState::$comment = new \WP_Comment( $this->comment_fields() );
				$sensor->on_status( 'approved', 'unapproved', WpState::$comment );
			}
		);

		$this->assertSame( [ 2201 ], array_column( $calls, 0 ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_update_of_the_status_or_the_user_agent_alone_is_not_an_edit(): void {
		$calls = $this->run_hook(
			$this->comment_fields(),
			function ( CommentsSensor $sensor ): void {
				$sensor->on_update_data( $this->update_data( [ 'comment_approved' => 0 ] ) );
				$sensor->on_edit( 15 );
				$sensor->on_update_data( $this->update_data( [ 'comment_agent' => 'Another browser' ] ) );
				$sensor->on_edit( 15 );
				$sensor->on_update_data( $this->update_data( [ 'comment_karma' => 3, 'comment_date' => '2026-10-27 08:00:00' ] ) );
				$sensor->on_edit( 15 );
			}
		);

		$this->assertSame( [], $calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_edit_lists_the_visible_fields_it_changed(): void {
		$calls = $this->run_hook(
			$this->comment_fields(),
			function ( CommentsSensor $sensor ): void {
				$sensor->on_update_data(
					$this->update_data(
						[
							'comment_content'      => 'A new text',
							'comment_author'       => 'Robert',
							'comment_author_email' => 'robert@example.com',
							'comment_author_url'   => 'https://example.com/robert',
							'comment_agent'        => 'Another browser',
						]
					)
				);
				$sensor->on_edit( 15 );
			}
		);

		$this->assertSame( [ 2205 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				[
					'field' => 'comment_content',
					'old'   => null,
					'new'   => null,
				],
				[
					'field' => 'comment_author',
					'old'   => 'Bob',
					'new'   => 'Robert',
				],
				[
					'field' => 'comment_author_email',
					'old'   => 'bob@example.com',
					'new'   => 'robert@example.com',
				],
				[
					'field' => 'comment_author_url',
					'old'   => '',
					'new'   => 'https://example.com/robert',
				],
			],
			$calls[0][1]['changes']
		);
	}

	/**
	 * The comparison of one update is used once: a later `edit_comment` for
	 * the same comment with nothing compared before it is not an edit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_edit_is_logged_once_per_update(): void {
		$calls = $this->run_hook(
			$this->comment_fields(),
			function ( CommentsSensor $sensor ): void {
				$sensor->on_update_data( $this->update_data( [ 'comment_content' => 'Edited' ] ) );
				$sensor->on_edit( 15 );
				$sensor->on_edit( 15 );
			}
		);

		$this->assertSame( [ 2205 ], array_column( $calls, 0 ) );
	}

	/**
	 * Since WordPress 5.5 a filter may return an error to stop the update.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_update_data_that_is_not_an_array_is_handed_back_untouched(): void {
		$this->run_hook(
			$this->comment_fields(),
			function ( CommentsSensor $sensor ): void {
				$error = new \stdClass();
				$this->assertSame( $error, $sensor->on_update_data( $error ) );
			}
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_update_is_compared_after_every_other_filter_changed_it(): void {
		require_once __DIR__ . '/../../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-hook-functions.php';
		$this->run_hook( $this->comment_fields(), static fn( CommentsSensor $sensor ) => $sensor->boot() );

		$this->assertSame(
			[
				'priority'      => PHP_INT_MAX,
				'accepted_args' => 1,
			],
			WpCalls::$hooks['wp_update_comment_data'] ?? null
		);
	}

	/**
	 * What `wp_update_comment()` hands the `wp_update_comment_data` filter:
	 * the stored comment merged with the update, unslashed.
	 *
	 * @param array<string, mixed> $over Fields the update changes.
	 * @return array<string, mixed>
	 */
	private function update_data( array $over = [] ): array {
		return array_merge(
			$this->comment_fields(),
			[
				'comment_content'      => 'Nice post',
				'comment_author_email' => 'bob@example.com',
				'comment_author_url'   => '',
				'comment_agent'        => 'Mozilla/5.0',
				'comment_karma'        => 0,
				'comment_date'         => '2026-10-26 10:00:00',
			],
			$over
		);
	}

	/**
	 * Fields of a visitor comment on post 1.
	 *
	 * @param array<string, mixed> $over Fields to override.
	 * @return array<string, mixed>
	 */
	private function comment_fields( array $over = [] ): array {
		return array_merge(
			[
				'comment_ID'           => 15,
				'comment_post_ID'      => 1,
				'comment_author'       => 'Bob',
				'comment_author_email' => 'bob@example.com',
				'comment_author_url'   => '',
				'comment_content'      => 'Nice post',
				'comment_agent'        => 'Mozilla/5.0',
				'comment_type'         => 'comment',
				'comment_approved'     => '1',
			],
			$over
		);
	}

	/**
	 * Run hook calls against a recording dispatcher, with the WordPress
	 * stand-ins loaded. Only call this from a test of its own process.
	 *
	 * @param array<string, mixed> $fields Fields of the comment; `get_comment()` returns it too.
	 * @param callable             $hooks  What to call; receives the sensor and the comment.
	 * @return array<int, array{0: int, 1: array<string, mixed>}> Every dispatch as `[ code, fields ]`.
	 */
	private function run_hook( array $fields, callable $hooks ): array {
		require_once __DIR__ . '/Fixtures/wordpress-content-functions.php';

		$comment          = new \WP_Comment( $fields );
		WpState::$comment = $comment;

		$dispatcher = new class() implements EventDispatcher {

			/** @var array<int, array{0: int, 1: array<string, mixed>}> */
			public array $calls = [];

			public function dispatch( int $code, array $fields = [] ): void {
				$this->calls[] = [ $code, $fields ];
			}
		};

		$hooks( new CommentsSensor( $dispatcher ), $comment );

		return $dispatcher->calls;
	}
}
