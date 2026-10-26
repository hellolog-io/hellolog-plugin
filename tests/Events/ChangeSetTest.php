<?php
/**
 * Tests for {@see HelloLog\Events\ChangeSet}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Events;

use HelloLog\Events\ChangeSet;
use PHPUnit\Framework\TestCase;

/**
 * The helper is pure: snapshots in, an old-to-new list out. The tests pin the
 * output contract (a 0-indexed list of exact `field`/`old`/`new` triples with
 * string-or-null values), the masking rules and the merge rule.
 */
final class ChangeSetTest extends TestCase {

	/**
	 * @param string  $field Field name.
	 * @param ?string $old   Old value.
	 * @param ?string $new   New value.
	 *
	 * @return array{field: string, old: ?string, new: ?string}
	 */
	private static function item( string $field, ?string $old, ?string $new ): array {
		return [
			'field' => $field,
			'old'   => $old,
			'new'   => $new,
		];
	}

	// ---- diff ----------------------------------------------------------

	public function test_identical_snapshots_produce_no_changes(): void {
		$row = [
			'post_title'  => 'Hello',
			'post_status' => 'draft',
		];

		$this->assertSame( [], ChangeSet::diff( $row, $row, [ 'post_title', 'post_status' ] ) );
	}

	public function test_title_change_is_one_exact_item(): void {
		$changes = ChangeSet::diff(
			[
				'post_title'  => 'Hello',
				'post_status' => 'draft',
			],
			[
				'post_title'  => 'Hello world',
				'post_status' => 'draft',
			],
			[ 'post_title', 'post_status' ]
		);

		$this->assertSame( [ self::item( 'post_title', 'Hello', 'Hello world' ) ], $changes );
	}

	public function test_items_follow_the_fields_order_not_the_snapshot_order(): void {
		$changes = ChangeSet::diff(
			[
				'a' => '1',
				'b' => '1',
				'c' => '1',
			],
			[
				'c' => '2',
				'b' => '2',
				'a' => '2',
			],
			[ 'b', 'c', 'a' ]
		);

		$this->assertSame( [ 'b', 'c', 'a' ], array_column( $changes, 'field' ) );
	}

	public function test_only_listed_fields_are_compared(): void {
		$changes = ChangeSet::diff(
			[
				'a' => '1',
				'b' => '1',
			],
			[
				'a' => '2',
				'b' => '2',
			],
			[ 'a' ]
		);

		$this->assertSame( [ self::item( 'a', '1', '2' ) ], $changes );
	}

	public function test_result_is_a_zero_indexed_list_when_leading_fields_are_unchanged(): void {
		$changes = ChangeSet::diff(
			[
				'a' => '1',
				'b' => '1',
				'c' => '1',
			],
			[
				'a' => '1',
				'b' => '2',
				'c' => '2',
			],
			[ 'a', 'b', 'c' ]
		);

		$this->assertSame( [ 0, 1 ], array_keys( $changes ) );
	}

	public function test_missing_keys_count_as_null(): void {
		$added   = ChangeSet::diff( [], [ 'k' => 'v' ], [ 'k' ] );
		$removed = ChangeSet::diff( [ 'k' => 'v' ], [], [ 'k' ] );
		$absent  = ChangeSet::diff( [], [], [ 'k' ] );

		$this->assertSame( [ self::item( 'k', null, 'v' ) ], $added );
		$this->assertSame( [ self::item( 'k', 'v', null ) ], $removed );
		$this->assertSame( [], $absent );
	}

	public function test_an_empty_string_differs_from_a_missing_value(): void {
		$changes = ChangeSet::diff( [], [ 'k' => '' ], [ 'k' ] );

		$this->assertSame( [ self::item( 'k', null, '' ) ], $changes );
	}

	public function test_integer_field_names_become_string_fields(): void {
		$changes = ChangeSet::diff( [ 7 => 'a' ], [ 7 => 'b' ], [ 7 ] );

		$this->assertSame( [ self::item( '7', 'a', 'b' ) ], $changes );
		$this->assertIsString( $changes[0]['field'] );
	}

	public function test_numbers_and_numeric_strings_are_equal_after_stringifying(): void {
		$this->assertSame( [], ChangeSet::diff( [ 'n' => 5 ], [ 'n' => '5' ], [ 'n' ] ) );
		$this->assertSame(
			[ self::item( 'n', '5', '6' ) ],
			ChangeSet::diff( [ 'n' => 5 ], [ 'n' => 6 ], [ 'n' ] )
		);
	}

	public function test_boolean_change_reads_yes_no(): void {
		$changes = ChangeSet::diff( [ 'on' => true ], [ 'on' => false ], [ 'on' ] );

		$this->assertSame( [ self::item( 'on', 'yes', 'no' ) ], $changes );
	}

	public function test_content_fields_report_only_that_they_changed(): void {
		$changes = ChangeSet::diff(
			[
				'post_title'   => 'T',
				'post_content' => 'old body',
			],
			[
				'post_title'   => 'T',
				'post_content' => 'new body',
			],
			[ 'post_title', 'post_content' ],
			[ 'post_content' ]
		);

		$this->assertSame( [ self::item( 'post_content', null, null ) ], $changes );
	}

	public function test_unchanged_content_fields_are_silent(): void {
		$row = [ 'post_content' => 'same body' ];

		$this->assertSame( [], ChangeSet::diff( $row, $row, [ 'post_content' ], [ 'post_content' ] ) );
	}

	public function test_content_change_past_the_truncation_limit_is_still_detected(): void {
		$old = str_repeat( 'x', 2000 );
		$new = substr( $old, 0, 1999 ) . 'y';

		$changes = ChangeSet::diff(
			[ 'post_content' => $old ],
			[ 'post_content' => $new ],
			[ 'post_content' ],
			[ 'post_content' ]
		);

		$this->assertSame( [ self::item( 'post_content', null, null ) ], $changes );
	}

	public function test_long_values_that_differ_only_after_the_limit_become_a_changed_flag(): void {
		$old = str_repeat( 'x', 2000 );
		$new = substr( $old, 0, 1999 ) . 'y';

		$changes = ChangeSet::diff( [ 'f' => $old ], [ 'f' => $new ], [ 'f' ] );

		// Both sides would be cut to the same text, which reads as "no change"
		// and would be dropped later — so the item carries no values instead.
		$this->assertSame( [ self::item( 'f', null, null ) ], $changes );
	}

	public function test_long_values_that_differ_within_the_limit_keep_their_cut_values(): void {
		$old = str_repeat( 'x', 2000 );
		$new = 'y' . substr( $old, 1 );

		$changes = ChangeSet::diff( [ 'f' => $old ], [ 'f' => $new ], [ 'f' ] );

		$this->assertCount( 1, $changes );
		$this->assertSame( 1024, mb_strlen( $changes[0]['old'], 'UTF-8' ) );
		$this->assertSame( 1024, mb_strlen( $changes[0]['new'], 'UTF-8' ) );
		$this->assertNotSame( $changes[0]['old'], $changes[0]['new'] );
	}

	public function test_a_long_value_change_survives_merge(): void {
		$old = str_repeat( 'x', 2000 );
		$new = substr( $old, 0, 1999 ) . 'y';

		$changes = ChangeSet::diff( [ 'f' => $old ], [ 'f' => $new ], [ 'f' ] );

		$this->assertSame( $changes, ChangeSet::merge( $changes, [] ) );
		$this->assertSame( $changes, ChangeSet::merge( [], $changes ) );
		$this->assertSame( $changes, ChangeSet::merge( $changes, $changes ) );
	}

	public function test_author_fields_keep_their_values(): void {
		$changes = ChangeSet::diff(
			[
				'post_author'    => 1,
				'comment_author' => 'Ann',
			],
			[
				'post_author'    => 2,
				'comment_author' => 'Bob',
			],
			[ 'post_author', 'comment_author' ]
		);

		$this->assertSame(
			[ self::item( 'post_author', '1', '2' ), self::item( 'comment_author', 'Ann', 'Bob' ) ],
			$changes
		);
	}

	public function test_credential_like_names_are_redacted_on_both_sides(): void {
		$changes = ChangeSet::diff(
			[ 'stripe_secret_key' => 'sk_live_old' ],
			[ 'stripe_secret_key' => 'sk_live_new' ],
			[ 'stripe_secret_key' ]
		);

		$this->assertSame( [ self::item( 'stripe_secret_key', '[redacted]', '[redacted]' ) ], $changes );
	}

	public function test_an_unchanged_credential_is_not_reported(): void {
		$row = [ 'api_token' => 'same' ];

		$this->assertSame( [], ChangeSet::diff( $row, $row, [ 'api_token' ] ) );
	}

	public function test_a_credential_set_for_the_first_time_is_redacted_not_null(): void {
		$changes = ChangeSet::diff( [], [ 'smtp_password_x_key' => 'abc' ], [ 'smtp_password_x_key' ] );

		$this->assertSame( [ self::item( 'smtp_password_x_key', '[redacted]', '[redacted]' ) ], $changes );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function password_field_provider(): array {
		return [
			'password'  => [ 'password' ],
			'user_pass' => [ 'user_pass' ],
			'uppercase' => [ 'Password' ],
		];
	}

	/**
	 * @dataProvider password_field_provider
	 *
	 * @param string $field Field name.
	 */
	public function test_password_fields_carry_no_values_at_all( string $field ): void {
		$changes = ChangeSet::diff( [ $field => 'hash-1' ], [ $field => 'hash-2' ], [ $field ] );

		$this->assertSame( [ self::item( $field, null, null ) ], $changes );
	}

	public function test_a_duplicate_field_name_is_reported_once(): void {
		$changes = ChangeSet::diff( [ 'a' => '1' ], [ 'a' => '2' ], [ 'a', 'a' ] );

		$this->assertCount( 1, $changes );
	}

	// ---- stringify -----------------------------------------------------

	public function test_stringify_scalars(): void {
		$this->assertNull( ChangeSet::stringify( null ) );
		$this->assertSame( 'yes', ChangeSet::stringify( true ) );
		$this->assertSame( 'no', ChangeSet::stringify( false ) );
		$this->assertSame( '42', ChangeSet::stringify( 42 ) );
		$this->assertSame( '0', ChangeSet::stringify( 0 ) );
		$this->assertSame( '1.5', ChangeSet::stringify( 1.5 ) );
		$this->assertSame( 'text', ChangeSet::stringify( 'text' ) );
		$this->assertSame( '', ChangeSet::stringify( '' ) );
	}

	public function test_stringify_non_finite_floats(): void {
		$this->assertSame( 'NAN', ChangeSet::stringify( NAN ) );
		$this->assertSame( 'INF', ChangeSet::stringify( INF ) );
		$this->assertSame( '-INF', ChangeSet::stringify( -INF ) );
	}

	public function test_list_arrays_are_sorted_and_joined(): void {
		$this->assertSame( 'a, b', ChangeSet::stringify( [ 'b', 'a' ] ) );
		$this->assertSame( '', ChangeSet::stringify( [] ) );
		$this->assertSame( 'no, yes', ChangeSet::stringify( [ true, false ] ) );
	}

	public function test_reordered_lists_are_not_a_change(): void {
		$this->assertSame( [], ChangeSet::diff( [ 'roles' => [ 'b', 'a' ] ], [ 'roles' => [ 'a', 'b' ] ], [ 'roles' ] ) );
	}

	public function test_a_changed_list_is_reported_as_sorted_text(): void {
		$changes = ChangeSet::diff(
			[ 'roles' => [ 'editor' ] ],
			[ 'roles' => [ 'editor', 'author' ] ],
			[ 'roles' ]
		);

		$this->assertSame( [ self::item( 'roles', 'editor', 'author, editor' ) ], $changes );
	}

	public function test_assoc_arrays_become_json_with_sorted_keys(): void {
		$this->assertSame(
			'{"a":1,"b":2}',
			ChangeSet::stringify(
				[
					'b' => 2,
					'a' => 1,
				]
			)
		);
	}

	public function test_nested_assoc_keys_are_sorted_too(): void {
		$one = [
			'z' => [
				'y' => 1,
				'x' => 2,
			],
			'a' => 'é/',
		];
		$two = [
			'a' => 'é/',
			'z' => [
				'x' => 2,
				'y' => 1,
			],
		];

		$this->assertSame( '{"a":"é/","z":{"x":2,"y":1}}', ChangeSet::stringify( $one ) );
		$this->assertSame( ChangeSet::stringify( $one ), ChangeSet::stringify( $two ) );
	}

	public function test_a_key_order_difference_is_not_a_change(): void {
		$before = [
			'opt' => [
				'b' => 1,
				'a' => 2,
			],
		];
		$after  = [
			'opt' => [
				'a' => 2,
				'b' => 1,
			],
		];

		$this->assertSame( [], ChangeSet::diff( $before, $after, [ 'opt' ] ) );
	}

	public function test_long_strings_are_cut_to_1024_characters_ending_with_an_ellipsis(): void {
		$out = ChangeSet::stringify( str_repeat( 'a', 2000 ) );

		$this->assertSame( 1024, mb_strlen( $out, 'UTF-8' ) );
		$this->assertSame( '…', mb_substr( $out, -1, null, 'UTF-8' ) );
		$this->assertSame( str_repeat( 'a', 1023 ), mb_substr( $out, 0, 1023, 'UTF-8' ) );
	}

	public function test_truncation_counts_characters_not_bytes(): void {
		$out = ChangeSet::stringify( str_repeat( 'é', 1500 ) );

		$this->assertSame( 1024, mb_strlen( $out, 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $out, 'UTF-8' ) );
		$this->assertSame( str_repeat( 'é', 1023 ) . '…', $out );
	}

	public function test_a_value_of_exactly_1024_characters_is_kept_whole(): void {
		$exact = str_repeat( 'b', 1024 );

		$this->assertSame( $exact, ChangeSet::stringify( $exact ) );
		$this->assertSame( 1024, mb_strlen( ChangeSet::stringify( $exact . 'c' ), 'UTF-8' ) );
	}

	public function test_long_json_is_truncated_as_well(): void {
		$out = ChangeSet::stringify( [ 'k' => str_repeat( 'a', 3000 ) ] );

		$this->assertSame( 1024, mb_strlen( $out, 'UTF-8' ) );
		$this->assertSame( '…', mb_substr( $out, -1, null, 'UTF-8' ) );
	}

	public function test_invalid_utf8_inside_an_array_does_not_break_stringify(): void {
		$out = ChangeSet::stringify( [ 'k' => "bad\xB1" ] );

		$this->assertIsString( $out );
		$this->assertTrue( mb_check_encoding( $out, 'UTF-8' ) );
	}

	public function test_a_self_referencing_array_is_cut_off_instead_of_recursing_forever(): void {
		$a         = [ 'k' => 'v' ];
		$a['self'] = &$a;

		$out = ChangeSet::stringify( $a );

		$this->assertIsString( $out );
		$this->assertLessThanOrEqual( 1024, mb_strlen( $out, 'UTF-8' ) );
		$this->assertStringContainsString( '…', $out );
		$this->assertSame( [ 'k', 'self' ], array_keys( $a ), 'the input must not be modified' );
	}

	public function test_a_self_referencing_list_is_cut_off_too(): void {
		$list   = [ 'a' ];
		$list[] = &$list;

		$out = ChangeSet::stringify( $list );

		$this->assertIsString( $out );
		$this->assertStringContainsString( '…', $out );
	}

	public function test_very_deep_nesting_is_cut_to_an_ellipsis(): void {
		$deep = 'leaf';
		for ( $i = 0; $i < 30; $i++ ) {
			$deep = [ 'a' => $deep ];
		}

		$out = ChangeSet::stringify( $deep );

		$this->assertStringContainsString( '"…"', $out );
		$this->assertStringNotContainsString( 'leaf', $out );
	}

	public function test_ordinary_nesting_is_not_cut(): void {
		$deep = 1;
		for ( $i = 0; $i < 5; $i++ ) {
			$deep = [ 'a' => $deep ];
		}

		$this->assertSame( '{"a":{"a":{"a":{"a":{"a":1}}}}}', ChangeSet::stringify( $deep ) );
	}

	public function test_invalid_utf8_in_a_string_is_replaced(): void {
		$out = ChangeSet::stringify( "bad\xB1end" );

		$this->assertSame( "bad\u{FFFD}end", $out );
		$this->assertTrue( mb_check_encoding( $out, 'UTF-8' ) );
	}

	public function test_valid_multibyte_strings_pass_through_untouched(): void {
		$this->assertSame( 'árvíztűrő tükörfúrógép', ChangeSet::stringify( 'árvíztűrő tükörfúrógép' ) );
	}

	public function test_invalid_utf8_never_reaches_a_reported_value(): void {
		$changes = ChangeSet::diff(
			[ 'a' => "x\xB1" ],
			[
				'a'    => "y\xB1",
				'list' => [ "z\xB1" ],
			],
			[ 'a', 'list' ]
		);

		$this->assertCount( 2, $changes );
		foreach ( $changes as $change ) {
			foreach ( [ 'old', 'new' ] as $side ) {
				if ( null !== $change[ $side ] ) {
					$this->assertTrue( mb_check_encoding( $change[ $side ], 'UTF-8' ), $change['field'] . ' ' . $side );
				}
			}
		}
	}

	// ---- merge ---------------------------------------------------------

	public function test_merge_keeps_the_first_old_and_the_last_new(): void {
		$merged = ChangeSet::merge(
			[ self::item( 'status', 'draft', 'pending' ) ],
			[ self::item( 'status', 'pending', 'publish' ) ]
		);

		$this->assertSame( [ self::item( 'status', 'draft', 'publish' ) ], $merged );
	}

	public function test_merge_drops_a_field_that_returned_to_its_original_value(): void {
		$merged = ChangeSet::merge(
			[ self::item( 'status', 'draft', 'pending' ) ],
			[ self::item( 'status', 'pending', 'draft' ) ]
		);

		$this->assertSame( [], $merged );
	}

	public function test_merge_drops_a_value_that_was_added_and_removed_again(): void {
		$merged = ChangeSet::merge(
			[ self::item( 'opt', null, 'x' ) ],
			[ self::item( 'opt', 'x', null ) ]
		);

		$this->assertSame( [], $merged );
	}

	public function test_merge_concatenates_distinct_fields_in_order(): void {
		$merged = ChangeSet::merge(
			[ self::item( 'a', '1', '2' ), self::item( 'b', '1', '2' ) ],
			[ self::item( 'c', '1', '2' ) ]
		);

		$this->assertSame( [ 'a', 'b', 'c' ], array_column( $merged, 'field' ) );
		$this->assertSame( [ 0, 1, 2 ], array_keys( $merged ) );
	}

	public function test_merge_keeps_the_position_of_the_first_appearance(): void {
		$merged = ChangeSet::merge(
			[ self::item( 'a', '1', '2' ), self::item( 'b', '1', '2' ) ],
			[ self::item( 'a', '2', '3' ) ]
		);

		$this->assertSame(
			[ self::item( 'a', '1', '3' ), self::item( 'b', '1', '2' ) ],
			$merged
		);
	}

	public function test_merge_with_an_empty_side_returns_the_other_side(): void {
		$items = [ self::item( 'a', '1', '2' ) ];

		$this->assertSame( $items, ChangeSet::merge( $items, [] ) );
		$this->assertSame( $items, ChangeSet::merge( [], $items ) );
		$this->assertSame( [], ChangeSet::merge( [], [] ) );
	}

	public function test_merge_result_is_a_list_after_dropping_items_in_the_middle(): void {
		$merged = ChangeSet::merge(
			[ self::item( 'a', '1', '2' ), self::item( 'b', 'x', 'y' ), self::item( 'c', '1', '2' ) ],
			[ self::item( 'b', 'y', 'x' ) ]
		);

		$this->assertSame( [ 0, 1 ], array_keys( $merged ) );
		$this->assertSame( [ 'a', 'c' ], array_column( $merged, 'field' ) );
	}

	public function test_merge_keeps_the_changed_flag_of_content_fields(): void {
		$flag = self::item( 'post_content', null, null );

		$this->assertSame( [ $flag ], ChangeSet::merge( [ $flag ], [] ) );
		$this->assertSame( [ $flag ], ChangeSet::merge( [ $flag ], [ $flag ] ) );
	}

	public function test_merge_keeps_the_changed_flag_of_redacted_fields(): void {
		$flag = self::item( 'api_key', '[redacted]', '[redacted]' );

		$this->assertSame( [ $flag ], ChangeSet::merge( [ $flag ], [ $flag ] ) );
	}

	public function test_merge_drops_a_no_op_item_with_equal_values(): void {
		$merged = ChangeSet::merge( [ self::item( 'a', 'same', 'same' ) ], [] );

		$this->assertSame( [], $merged );
	}

	public function test_merge_output_has_exactly_the_three_keys(): void {
		$merged = ChangeSet::merge(
			[ self::item( 'a', '1', '2' ) ],
			[ self::item( 'a', '2', '3' ) ]
		);

		$this->assertSame( [ 'field', 'old', 'new' ], array_keys( $merged[0] ) );
	}
}
