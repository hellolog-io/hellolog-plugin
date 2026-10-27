<?php
/**
 * Tests for {@see HelloLog\Sensors\Core\PostSnapshot}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Sensors\Core\PostSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Pins what a snapshot of a post holds and how two of them are compared:
 * the compared fields and their order, the password reported as yes/no
 * only, the floating date of a draft, the trash suffix of a slug, the
 * featured image, the template, the sticky flag and the terms.
 */
final class PostSnapshotTest extends TestCase {

	public function test_fields_keep_the_wire_names_in_report_order(): void {
		$this->assertSame(
			[
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
			],
			PostSnapshot::FIELDS
		);
		$this->assertSame( [ 'post_content' ], PostSnapshot::CONTENT_FIELDS );
	}

	public function test_from_values_maps_the_post_fields_and_the_extras(): void {
		$snapshot = PostSnapshot::from_values(
			$this->raw( [ 'post_password' => 'hunter2' ] ),
			[
				'template'          => 'templates/wide.php',
				'featured_image'    => 'Sunset',
				'featured_image_id' => 30,
				'sticky'            => true,
				'terms'             => [ 'category' => [ 5 => 'News' ] ],
			]
		);

		$this->assertSame( 'Old title', $snapshot['post_title'] );
		$this->assertSame( 'draft', $snapshot['post_status'] );
		$this->assertTrue( $snapshot['password_protected'] );
		$this->assertSame( 'templates/wide.php', $snapshot['template'] );
		$this->assertSame( 'Sunset', $snapshot['featured_image'] );
		$this->assertSame( 30, $snapshot['featured_image_id'] );
		$this->assertTrue( $snapshot['sticky'] );
		$this->assertSame( [ 'category' => [ 5 => 'News' ] ], $snapshot['terms'] );
		$this->assertArrayNotHasKey( 'post_password', $snapshot );
		$this->assertStringNotContainsString( 'hunter2', serialize( $snapshot ) );
	}

	public function test_from_values_without_extras_is_a_post_with_none_of_them(): void {
		$snapshot = PostSnapshot::from_values( $this->raw(), [] );

		$this->assertSame( '', $snapshot['template'] );
		$this->assertNull( $snapshot['featured_image'] );
		$this->assertSame( 0, $snapshot['featured_image_id'] );
		$this->assertFalse( $snapshot['sticky'] );
		$this->assertSame( [], $snapshot['terms'] );
		$this->assertFalse( $snapshot['password_protected'] );
	}

	public function test_the_default_template_is_no_template(): void {
		$this->assertSame( '', PostSnapshot::from_values( $this->raw(), [ 'template' => 'default' ] )['template'] );
		$this->assertSame( '', PostSnapshot::from_values( $this->raw(), [ 'template' => false ] )['template'] );
	}

	public function test_a_password_is_reported_as_yes_or_no_and_never_itself(): void {
		$before = PostSnapshot::from_values( $this->raw( [ 'post_password' => '' ] ), [] );
		$after  = PostSnapshot::from_values( $this->raw( [ 'post_password' => 'x-secret' ] ), [] );

		$this->assertSame(
			[
				[
					'field' => 'password_protected',
					'old'   => 'no',
					'new'   => 'yes',
				],
			],
			PostSnapshot::changes( $before, $after )
		);
		$this->assertSame(
			[
				[
					'field' => 'password_protected',
					'old'   => 'yes',
					'new'   => 'no',
				],
			],
			PostSnapshot::changes( $after, $before )
		);
		$this->assertStringNotContainsString( 'x-secret', serialize( [ $before, $after, PostSnapshot::changes( $before, $after ) ] ) );
	}

	public function test_a_new_password_on_a_protected_post_is_no_change(): void {
		$before = PostSnapshot::from_values( $this->raw( [ 'post_password' => 'OLDSECRET' ] ), [] );
		$after  = PostSnapshot::from_values( $this->raw( [ 'post_password' => 'NEWSECRET' ] ), [] );

		$this->assertSame( [], PostSnapshot::changes( $before, $after ) );
	}

	public function test_a_content_change_carries_no_values(): void {
		$before = PostSnapshot::from_values( $this->raw(), [] );
		$after  = PostSnapshot::from_values( $this->raw( [ 'post_content' => 'Something else entirely' ] ), [] );

		$this->assertSame(
			[
				[
					'field' => 'post_content',
					'old'   => null,
					'new'   => null,
				],
			],
			PostSnapshot::changes( $before, $after )
		);
	}

	public function test_identical_snapshots_have_no_changes(): void {
		$extras = [
			'template'          => 'templates/wide.php',
			'featured_image'    => 'Sunset',
			'featured_image_id' => 30,
			'sticky'            => true,
			'terms'             => [ 'category' => [ 5 => 'News' ] ],
		];

		$this->assertSame(
			[],
			PostSnapshot::changes( PostSnapshot::from_values( $this->raw(), $extras ), PostSnapshot::from_values( $this->raw( [ 'post_modified' => 'later' ] ), $extras ) )
		);
	}

	public function test_changes_follow_the_field_order_with_the_terms_last(): void {
		$before = PostSnapshot::from_values(
			$this->raw(),
			[ 'terms' => [ 'category' => [ 5 => 'News' ] ] ]
		);
		$after  = PostSnapshot::from_values(
			$this->raw(
				[
					'post_title'     => 'New title',
					'post_status'    => 'pending',
					'comment_status' => 'closed',
					'post_password'  => 'pw',
				]
			),
			[
				'template' => 'templates/wide.php',
				'sticky'   => true,
				'terms'    => [ 'category' => [ 6 => 'Reviews' ] ],
			]
		);

		$this->assertSame(
			[ 'post_title', 'post_status', 'comment_status', 'password_protected', 'template', 'sticky', 'category' ],
			array_column( PostSnapshot::changes( $before, $after ), 'field' )
		);
	}

	public function test_the_author_change_is_shown_as_it_is(): void {
		$before = PostSnapshot::from_values( $this->raw(), [] );
		$after  = PostSnapshot::from_values( $this->raw( [ 'post_author' => '3' ] ), [] );

		$this->assertSame(
			[
				[
					'field' => 'post_author',
					'old'   => '1',
					'new'   => '3',
				],
			],
			PostSnapshot::changes( $before, $after )
		);
	}

	public function test_the_date_and_the_menu_order_are_reported(): void {
		$before = PostSnapshot::from_values( $this->raw(), [] );
		$after  = PostSnapshot::from_values(
			$this->raw(
				[
					'post_date'     => '2020-01-01 09:00:00',
					'post_date_gmt' => '2020-01-01 08:00:00',
					'menu_order'    => 3,
				]
			),
			[]
		);

		$this->assertSame(
			[
				[
					'field' => 'post_date',
					'old'   => '2026-10-26 10:00:00',
					'new'   => '2020-01-01 09:00:00',
				],
				[
					'field' => 'menu_order',
					'old'   => '0',
					'new'   => '3',
				],
			],
			PostSnapshot::changes( $before, $after )
		);
	}

	public function test_the_floating_date_of_a_draft_is_no_date(): void {
		$floating = PostSnapshot::from_values( $this->raw( [ 'post_date_gmt' => '0000-00-00 00:00:00' ] ), [] );
		$later    = PostSnapshot::from_values(
			$this->raw(
				[
					'post_date'     => '2026-10-26 10:05:00',
					'post_date_gmt' => '0000-00-00 00:00:00',
				]
			),
			[]
		);
		$set      = PostSnapshot::from_values(
			$this->raw(
				[
					'post_date'     => '2026-12-24 18:00:00',
					'post_date_gmt' => '2026-12-24 17:00:00',
				]
			),
			[]
		);

		$this->assertNull( $floating['post_date'] );
		$this->assertSame( [], PostSnapshot::changes( $floating, $later ) );
		$this->assertSame(
			[
				[
					'field' => 'post_date',
					'old'   => null,
					'new'   => '2026-12-24 18:00:00',
				],
			],
			PostSnapshot::changes( $later, $set )
		);
	}

	/**
	 * WordPress adds `__trashed` to the slug of a post in the trash and takes
	 * it off on restore: that is its own bookkeeping, not a new slug.
	 */
	public function test_the_trash_suffix_of_a_slug_is_no_change(): void {
		$live    = PostSnapshot::from_values( $this->raw( [ 'post_status' => 'publish' ] ), [] );
		$trashed = PostSnapshot::from_values(
			$this->raw(
				[
					'post_status' => 'trash',
					'post_name'   => 'old-title__trashed',
				]
			),
			[]
		);

		$this->assertSame( 'old-title', $trashed['post_name'] );
		$this->assertSame( [ 'post_status' ], array_column( PostSnapshot::changes( $live, $trashed ), 'field' ) );
		$this->assertSame( [ 'post_status' ], array_column( PostSnapshot::changes( $trashed, $live ), 'field' ) );

		// Outside the trash a slug ending that way is what somebody chose.
		$chosen = PostSnapshot::from_values( $this->raw( [ 'post_name' => 'old-title__trashed' ] ), [] );
		$this->assertSame( 'old-title__trashed', $chosen['post_name'] );
	}

	public function test_a_restore_onto_a_taken_slug_reports_the_new_slug(): void {
		$trashed  = PostSnapshot::from_values(
			$this->raw(
				[
					'post_status' => 'trash',
					'post_name'   => 'old-title__trashed',
				]
			),
			[]
		);
		$restored = PostSnapshot::from_values( $this->raw( [ 'post_name' => 'old-title-2' ] ), [] );

		$this->assertSame(
			[
				'field' => 'post_name',
				'old'   => 'old-title',
				'new'   => 'old-title-2',
			],
			PostSnapshot::changes( $trashed, $restored )[0]
		);
	}

	public function test_template_and_sticky_changes_read_as_text(): void {
		$before = PostSnapshot::from_values( $this->raw(), [] );
		$after  = PostSnapshot::from_values(
			$this->raw(),
			[
				'template' => 'templates/wide.php',
				'sticky'   => true,
			]
		);

		$this->assertSame(
			[
				[
					'field' => 'template',
					'old'   => '',
					'new'   => 'templates/wide.php',
				],
				[
					'field' => 'sticky',
					'old'   => 'no',
					'new'   => 'yes',
				],
			],
			PostSnapshot::changes( $before, $after )
		);
	}

	public function test_a_featured_image_is_named_by_its_title(): void {
		$none   = PostSnapshot::from_values( $this->raw(), [] );
		$sunset = PostSnapshot::from_values(
			$this->raw(),
			[
				'featured_image'    => 'Sunset',
				'featured_image_id' => 30,
			]
		);
		$beach  = PostSnapshot::from_values(
			$this->raw(),
			[
				'featured_image'    => 'Beach',
				'featured_image_id' => 31,
			]
		);

		$this->assertSame(
			[
				[
					'field' => 'featured_image',
					'old'   => null,
					'new'   => 'Sunset',
				],
			],
			PostSnapshot::changes( $none, $sunset )
		);
		$this->assertSame(
			[
				[
					'field' => 'featured_image',
					'old'   => 'Sunset',
					'new'   => 'Beach',
				],
			],
			PostSnapshot::changes( $sunset, $beach )
		);
		$this->assertSame(
			[
				[
					'field' => 'featured_image',
					'old'   => 'Beach',
					'new'   => null,
				],
			],
			PostSnapshot::changes( $beach, $none )
		);
	}

	/**
	 * The image is compared by its id: a renamed image is no change of the
	 * post, and another image of the same title is.
	 */
	public function test_a_featured_image_is_compared_by_its_id(): void {
		$sunset  = PostSnapshot::from_values(
			$this->raw(),
			[
				'featured_image'    => 'Sunset',
				'featured_image_id' => 30,
			]
		);
		$renamed = PostSnapshot::from_values(
			$this->raw(),
			[
				'featured_image'    => 'Dusk',
				'featured_image_id' => 30,
			]
		);
		$twin    = PostSnapshot::from_values(
			$this->raw(),
			[
				'featured_image'    => 'Sunset',
				'featured_image_id' => 44,
			]
		);

		$this->assertSame( [], PostSnapshot::changes( $sunset, $renamed ) );
		$this->assertSame(
			[
				[
					'field' => 'featured_image',
					'old'   => 'Sunset (#30)',
					'new'   => 'Sunset (#44)',
				],
			],
			PostSnapshot::changes( $sunset, $twin )
		);
	}

	public function test_a_term_change_is_named_by_the_taxonomy_and_lists_the_term_names(): void {
		$before = PostSnapshot::from_values( $this->raw(), [ 'terms' => [ 'category' => [ 5 => 'News' ] ] ] );
		$after  = PostSnapshot::from_values(
			$this->raw(),
			[
				'terms' => [
					'category' => [
						7 => 'Reviews',
						5 => 'News',
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'field' => 'category',
					'old'   => 'News',
					'new'   => 'News, Reviews',
				],
			],
			PostSnapshot::changes( $before, $after )
		);
	}

	public function test_terms_are_compared_by_id_not_by_name_or_order(): void {
		$news     = PostSnapshot::from_values(
			$this->raw(),
			[
				'terms' => [
					'post_tag' => [
						8 => 'b',
						9 => 'a',
					],
				],
			]
		);
		$reorder  = PostSnapshot::from_values(
			$this->raw(),
			[
				'terms' => [
					'post_tag' => [
						9 => 'a',
						8 => 'b',
					],
				],
			]
		);
		$same_old = PostSnapshot::from_values( $this->raw(), [ 'terms' => [ 'category' => [ 5 => 'News' ] ] ] );
		$same_new = PostSnapshot::from_values( $this->raw(), [ 'terms' => [ 'category' => [ 12 => 'News' ] ] ] );

		$this->assertSame( [], PostSnapshot::changes( $news, $reorder ) );
		$this->assertSame(
			[
				[
					'field' => 'category',
					'old'   => 'News (#5)',
					'new'   => 'News (#12)',
				],
			],
			PostSnapshot::changes( $same_old, $same_new )
		);
	}

	public function test_a_taxonomy_read_on_one_side_only_is_not_compared(): void {
		$before = PostSnapshot::from_values( $this->raw(), [ 'terms' => [ 'category' => [ 5 => 'News' ] ] ] );
		$after  = PostSnapshot::from_values( $this->raw(), [ 'terms' => [] ] );

		$this->assertSame( [], PostSnapshot::changes( $before, $after ) );
	}

	public function test_term_changes_report_an_emptied_and_a_first_taxonomy(): void {
		$this->assertSame(
			[
				[
					'field' => 'category',
					'old'   => 'News',
					'new'   => '',
				],
				[
					'field' => 'post_tag',
					'old'   => '',
					'new'   => 'a',
				],
			],
			PostSnapshot::term_changes(
				[
					'category' => [ 5 => 'News' ],
					'post_tag' => [],
				],
				[
					'category' => [],
					'post_tag' => [ 9 => 'a' ],
				]
			)
		);
	}

	/**
	 * A taxonomy name that looks like a credential is still just a
	 * taxonomy: its term names are no secret.
	 */
	public function test_a_taxonomy_named_like_a_credential_is_not_masked(): void {
		$this->assertSame(
			[
				[
					'field' => 'license_type',
					'old'   => '',
					'new'   => 'GPL',
				],
			],
			PostSnapshot::term_changes( [ 'license_type' => [] ], [ 'license_type' => [ 3 => 'GPL' ] ] )
		);
	}

	/**
	 * @param array<string, mixed> $over Fields to change.
	 * @return array<string, mixed> The raw fields of a post, like core's `WP_Post`.
	 */
	private function raw( array $over = [] ): array {
		return array_merge(
			[
				'post_title'     => 'Old title',
				'post_content'   => 'Old body',
				'post_excerpt'   => '',
				'post_name'      => 'old-title',
				'post_status'    => 'draft',
				'post_parent'    => 0,
				'post_author'    => '1',
				'post_date'      => '2026-10-26 10:00:00',
				'post_date_gmt'  => '2026-10-26 09:00:00',
				'menu_order'     => 0,
				'comment_status' => 'open',
				'ping_status'    => 'open',
				'post_password'  => '',
				'post_modified'  => '2026-10-26 11:00:00',
			],
			$over
		);
	}
}
