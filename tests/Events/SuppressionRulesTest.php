<?php
/**
 * Tests for {@see HelloLog\Events\SuppressionRules}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Events;

use HelloLog\Events\SuppressionRules;
use PHPUnit\Framework\TestCase;

/**
 * The rules work on built payload arrays only, so they run without
 * WordPress. Each test feeds a request's worth of events in dispatch order
 * and checks which ones survive.
 */
final class SuppressionRulesTest extends TestCase {

	/**
	 * A built event reduced to what the rules look at.
	 *
	 * @param int                                                             $code    Event code.
	 * @param ?array{type: string, id: string}                                $target  Target, or null for none.
	 * @param ?array<int, array{field: string, old: ?string, new: ?string}>  $changes Changes, or null for none.
	 * @param array<string, mixed>                                            $extra   Further payload keys.
	 *
	 * @return array<string, mixed>
	 */
	private static function event( int $code, ?array $target, ?array $changes = null, array $extra = [] ): array {
		$payload = [ 'code' => $code ];
		if ( null !== $target ) {
			$payload['target'] = $target;
		}
		if ( null !== $changes ) {
			$payload['changes'] = $changes;
		}

		return $payload + $extra;
	}

	/**
	 * @return array{type: string, id: string}
	 */
	private static function post( int $id ): array {
		return [
			'type' => 'post',
			'id'   => (string) $id,
		];
	}

	/**
	 * @return array{type: string, id: string}
	 */
	private static function user( int $id ): array {
		return [
			'type' => 'user',
			'id'   => (string) $id,
		];
	}

	/**
	 * @return array{type: string, id: string}
	 */
	private static function order( int $id ): array {
		return [
			'type' => 'order',
			'id'   => (string) $id,
		];
	}

	/**
	 * @return array{field: string, old: ?string, new: ?string}
	 */
	private static function change( string $field, ?string $old, ?string $new ): array {
		return [
			'field' => $field,
			'old'   => $old,
			'new'   => $new,
		];
	}

	/**
	 * A registration event (4000) of the given role, with the `role` template
	 * var the builder renders it from.
	 *
	 * @param string $role Role the new user got.
	 *
	 * @return array<string, mixed>
	 */
	private static function registration( int $user, string $role ): array {
		return self::event( 4000, self::user( $user ), null, [ 'vars' => [ 'role' => $role ] ] );
	}

	/**
	 * A role change (4002) of a user.
	 *
	 * @param ?string $old Previous roles as the sensor reports them; '' or null for none.
	 * @param string  $new New role.
	 *
	 * @return array<string, mixed>
	 */
	private static function role_change( int $user, ?string $old, string $new ): array {
		return self::event( 4002, self::user( $user ), [ self::change( 'role', $old, $new ) ] );
	}

	/**
	 * The codes of a result, in order.
	 *
	 * @param array<int, array<string, mixed>> $payloads Payloads.
	 *
	 * @return array<int, int>
	 */
	private static function codes( array $payloads ): array {
		return array_map( static fn( array $p ): int => $p['code'], $payloads );
	}

	// ---- suppress ------------------------------------------------------

	public function test_publish_suppresses_update_same_target_only(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2006, self::post( 1 ), [ self::change( 'post_title', 'A', 'B' ) ] ),
				self::event( 2001, self::post( 1 ) ),
				self::event( 2006, self::post( 2 ), [ self::change( 'post_title', 'C', 'D' ) ] ),
			]
		);

		$this->assertSame( [ 2001, 2006 ], self::codes( $result ) );
		$this->assertSame( '1', $result[0]['target']['id'] );
		$this->assertSame( '2', $result[1]['target']['id'] );
	}

	public function test_registration_chain(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 9300, self::user( 7 ) ),
				self::registration( 7, 'customer' ),
				self::role_change( 7, '', 'customer' ),
				self::event( 4001, self::user( 7 ), [ self::change( 'display_name', 'a', 'b' ) ] ),
			]
		);

		$this->assertSame( [ 9300 ], self::codes( $result ) );
	}

	public function test_a_registration_with_a_role_other_than_customer_survives_the_shop_event(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::role_change( 7, '', 'administrator' ),
				self::registration( 7, 'administrator' ),
				self::event( 9300, self::user( 7 ) ),
			]
		);

		$this->assertSame( [ 4000, 9300 ], self::codes( $result ) );
		$this->assertSame( 'administrator', $result[0]['vars']['role'] );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function registrations_that_are_not_plainly_customers(): array {
		return [
			'editor'            => [ self::registration( 7, 'editor' ) ],
			'wrong case'        => [ self::registration( 7, 'Customer' ) ],
			'padded'            => [ self::registration( 7, 'customer ' ) ],
			'empty role'        => [ self::registration( 7, '' ) ],
			'no vars'           => [ self::event( 4000, self::user( 7 ) ) ],
			'vars without role' => [ self::event( 4000, self::user( 7 ), null, [ 'vars' => [ 'username' => 'bob' ] ] ) ],
			'role not a string' => [ self::event( 4000, self::user( 7 ), null, [ 'vars' => [ 'role' => [ 'customer' ] ] ] ) ],
		];
	}

	/**
	 * @dataProvider registrations_that_are_not_plainly_customers
	 *
	 * @param array<string, mixed> $registration A registration that is not for the customer role.
	 */
	public function test_only_a_customer_registration_yields_to_the_shop_event( array $registration ): void {
		$result = SuppressionRules::defaults()->apply( [ $registration, self::event( 9300, self::user( 7 ) ) ] );

		$this->assertSame( [ 4000, 9300 ], self::codes( $result ) );
	}

	public function test_a_role_change_from_a_previous_role_survives_a_registration(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::registration( 7, 'subscriber' ),
				self::role_change( 7, 'subscriber', 'administrator' ),
			]
		);

		$this->assertSame( [ 4000, 4002 ], self::codes( $result ) );
	}

	public function test_a_role_change_from_a_previous_role_survives_the_shop_event(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 9300, self::user( 7 ) ),
				self::role_change( 7, 'customer', 'administrator' ),
			]
		);

		$this->assertSame( [ 9300, 4002 ], self::codes( $result ) );
	}

	/**
	 * @return array<string, array{0: ?string}>
	 */
	public static function empty_previous_roles(): array {
		return [
			'empty string' => [ '' ],
			'null'         => [ null ],
		];
	}

	/**
	 * @dataProvider empty_previous_roles
	 */
	public function test_the_first_role_assignment_yields_to_the_registration( ?string $old ): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::role_change( 7, $old, 'subscriber' ),
				self::registration( 7, 'subscriber' ),
			]
		);

		$this->assertSame( [ 4000 ], self::codes( $result ) );
	}

	/**
	 * @dataProvider empty_previous_roles
	 */
	public function test_the_first_role_assignment_yields_to_the_shop_event( ?string $old ): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::role_change( 7, $old, 'customer' ),
				self::event( 9300, self::user( 7 ) ),
			]
		);

		$this->assertSame( [ 9300 ], self::codes( $result ) );
	}

	public function test_a_role_change_that_does_not_report_a_role_item_is_not_taken_for_the_first_assignment(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::registration( 7, 'subscriber' ),
				self::event( 4002, self::user( 7 ), [ self::change( 'display_name', '', 'x' ) ] ),
				self::event( 4002, self::user( 7 ) ),
			]
		);

		$this->assertSame( [ 4000, 4002 ], self::codes( $result ) );
	}

	public function test_only_the_role_item_decides_whether_a_role_change_is_the_first_assignment(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::registration( 7, 'subscriber' ),
				self::event(
					4002,
					self::user( 7 ),
					[
						self::change( 'display_name', '', 'x' ),
						self::change( 'role', 'subscriber', 'editor' ),
					]
				),
			]
		);

		$this->assertSame( [ 4000, 4002 ], self::codes( $result ) );
	}

	public function test_presence_is_computed_before_anything_is_dropped(): void {
		// 4001 is suppressed by 4000, which is itself suppressed by 9300: the
		// middle link of the chain is gone, but 4001 must still go.
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 4001, self::user( 7 ), [ self::change( 'display_name', 'a', 'b' ) ] ),
				self::registration( 7, 'customer' ),
				self::event( 9300, self::user( 7 ) ),
			]
		);

		$this->assertSame( [ 9300 ], self::codes( $result ) );
	}

	public function test_a_listed_code_on_another_target_does_not_suppress(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 4002, self::user( 7 ), [ self::change( 'role', 'a', 'b' ) ] ),
				self::event( 4000, self::user( 8 ) ),
			]
		);

		$this->assertSame( [ 4002, 4000 ], self::codes( $result ) );
	}

	public function test_a_code_with_a_different_target_type_does_not_suppress(): void {
		// Same id, different type: user 1 is not post 1.
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2006, self::post( 1 ), [ self::change( 'post_title', 'A', 'B' ) ] ),
				self::event( 4000, self::user( 1 ) ),
			]
		);

		$this->assertSame( [ 2006, 4000 ], self::codes( $result ) );
	}

	public function test_system_note_suppressed_by_status_change(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 9101, self::order( 5 ) ),
				self::event( 9103, self::order( 5 ), null, [ 'metadata' => [ 'note_type' => 'system' ] ] ),
				self::event( 9103, self::order( 5 ), null, [ 'metadata' => [ 'note_type' => 'private' ] ] ),
			]
		);

		$this->assertSame( [ 9101, 9103 ], self::codes( $result ) );
		$this->assertSame( 'private', $result[1]['metadata']['note_type'] );
	}

	public function test_note_without_a_type_is_not_suppressed(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 9101, self::order( 5 ) ),
				self::event( 9103, self::order( 5 ) ),
			]
		);

		$this->assertSame( [ 9101, 9103 ], self::codes( $result ) );
	}

	public function test_system_note_without_a_status_change_stays(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 9103, self::order( 5 ), null, [ 'metadata' => [ 'note_type' => 'system' ] ] ),
			]
		);

		$this->assertSame( [ 9103 ], self::codes( $result ) );
	}

	// ---- merge ---------------------------------------------------------

	public function test_merge_concatenates_changes(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2006, self::post( 1 ), [ self::change( 'post_title', 'A', 'B' ) ] ),
				self::event( 2006, self::post( 1 ), [ self::change( 'post_status', 'draft', 'pending' ) ] ),
			]
		);

		$this->assertCount( 1, $result );
		$this->assertSame(
			[
				self::change( 'post_title', 'A', 'B' ),
				self::change( 'post_status', 'draft', 'pending' ),
			],
			$result[0]['changes']
		);
	}

	public function test_merge_keeps_first_old_and_last_new_of_a_repeated_field(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2006, self::post( 1 ), [ self::change( 'post_title', 'A', 'B' ) ] ),
				self::event( 2006, self::post( 1 ), [ self::change( 'post_title', 'B', 'C' ) ] ),
			]
		);

		$this->assertSame( [ self::change( 'post_title', 'A', 'C' ) ], $result[0]['changes'] );
	}

	public function test_merge_keeps_everything_but_changes_from_the_first_payload(): void {
		$first  = self::event(
			2006,
			self::post( 1 ),
			[ self::change( 'post_title', 'A', 'B' ) ],
			[
				'message'     => 'first',
				'occurred_at' => '2026-10-26T10:00:00.000Z',
				'user'        => [ 'id' => 1 ],
				'links'       => [ 'edit' => 'https://example.com/edit' ],
				'vars'        => [ 'title' => 'A' ],
				'metadata'    => [ 'post_id' => 1 ],
			]
		);
		$second = self::event(
			2006,
			self::post( 1 ),
			[ self::change( 'post_status', 'draft', 'pending' ) ],
			[
				'message'     => 'second',
				'occurred_at' => '2026-10-26T10:00:05.000Z',
				'user'        => [ 'id' => 2 ],
				'links'       => [ 'edit' => 'https://example.com/other' ],
				'vars'        => [ 'title' => 'B' ],
				'metadata'    => [ 'post_id' => 2 ],
			]
		);

		$result = SuppressionRules::defaults()->apply( [ $first, $second ] );

		$expected            = $first;
		$expected['changes'] = [
			self::change( 'post_title', 'A', 'B' ),
			self::change( 'post_status', 'draft', 'pending' ),
		];
		$this->assertSame( [ $expected ], $result );
	}

	public function test_merge_does_not_cross_targets_or_codes(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 4001, self::user( 7 ), [ self::change( 'display_name', 'a', 'b' ) ] ),
				self::event( 4001, self::user( 8 ), [ self::change( 'display_name', 'c', 'd' ) ] ),
				self::event( 4002, self::user( 7 ), [ self::change( 'role', 'x', 'y' ) ] ),
			]
		);

		$this->assertSame( [ 4001, 4001, 4002 ], self::codes( $result ) );
	}

	public function test_merged_payload_sits_at_the_first_position(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2006, self::post( 1 ), [ self::change( 'post_title', 'A', 'B' ) ] ),
				self::event( 2003, self::post( 3 ) ),
				self::event( 2006, self::post( 1 ), [ self::change( 'post_status', 'draft', 'pending' ) ] ),
			]
		);

		$this->assertSame( [ 2006, 2003 ], self::codes( $result ) );
		$this->assertCount( 2, $result[0]['changes'] );
	}

	public function test_merge_clamps_changes_to_fifty(): void {
		$first  = [];
		$second = [];
		for ( $i = 0; $i < 30; $i++ ) {
			$first[]  = self::change( 'a_' . $i, 'old', 'new' );
			$second[] = self::change( 'b_' . $i, 'old', 'new' );
		}

		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2006, self::post( 1 ), $first ),
				self::event( 2006, self::post( 1 ), $second ),
			]
		);

		$this->assertCount( 1, $result );
		$this->assertCount( 50, $result[0]['changes'] );
		$this->assertSame( range( 0, 49 ), array_keys( $result[0]['changes'] ) );
		$this->assertSame( 'a_0', $result[0]['changes'][0]['field'] );
		$this->assertSame( 'b_19', $result[0]['changes'][49]['field'] );
	}

	public function test_merge_clamps_long_fields_and_values(): void {
		$long = str_repeat( 'é', 2000 );

		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 4002, self::user( 7 ), [ self::change( str_repeat( 'f', 100 ), 'x', $long ) ] ),
				self::event( 4002, self::user( 7 ), [ self::change( 'role', $long, 'y' ) ] ),
			]
		);

		$changes = $result[0]['changes'];
		$this->assertSame( 64, mb_strlen( $changes[0]['field'], 'UTF-8' ) );
		$this->assertSame( str_repeat( 'f', 63 ) . '…', $changes[0]['field'] );
		$this->assertSame( 1024, mb_strlen( $changes[0]['new'], 'UTF-8' ) );
		$this->assertSame( str_repeat( 'é', 1023 ) . '…', $changes[0]['new'] );
		$this->assertSame( 1024, mb_strlen( $changes[1]['old'], 'UTF-8' ) );
		$this->assertSame( 'x', $changes[0]['old'] );
		$this->assertSame( 'y', $changes[1]['new'] );
	}

	public function test_merge_that_ends_up_empty_drops_the_changes_key(): void {
		// 4002 merges but is not in drop_empty: the payload stays, minus an
		// empty `changes` container.
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 4002, self::user( 7 ), [ self::change( 'role', 'a', 'b' ) ] ),
				self::event( 4002, self::user( 7 ), [ self::change( 'role', 'b', 'a' ) ] ),
			]
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 4002, $result[0]['code'] );
		$this->assertArrayNotHasKey( 'changes', $result[0] );
	}

	// ---- drop_empty ----------------------------------------------------

	public function test_drop_empty_after_merge(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2006, self::post( 1 ), [] ),
			]
		);

		$this->assertSame( [], $result );
	}

	public function test_drop_empty_when_changes_are_missing(): void {
		$result = SuppressionRules::defaults()->apply( [ self::event( 2006, self::post( 1 ) ) ] );

		$this->assertSame( [], $result );
	}

	public function test_drop_empty_when_a_merge_cancels_itself_out(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2006, self::post( 1 ), [ self::change( 'post_title', 'A', 'B' ) ] ),
				self::event( 2006, self::post( 1 ), [ self::change( 'post_title', 'B', 'A' ) ] ),
			]
		);

		$this->assertSame( [], $result );
	}

	public function test_empty_changes_survive_for_a_code_not_in_drop_empty(): void {
		$result = SuppressionRules::defaults()->apply( [ self::event( 4002, self::user( 7 ), [] ) ] );

		$this->assertSame( [ 4002 ], self::codes( $result ) );
	}

	// ---- duplicates ----------------------------------------------------

	public function test_exact_duplicates_removed(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 9101, self::order( 5 ), null, [ 'message' => 'Status changed' ] ),
				self::event( 9101, self::order( 5 ), null, [ 'message' => 'Status changed' ] ),
				self::event( 9101, self::order( 5 ), null, [ 'message' => 'Status changed again' ] ),
				self::event( 9101, self::order( 6 ), null, [ 'message' => 'Status changed' ] ),
			]
		);

		$this->assertSame( [ 9101, 9101, 9101 ], self::codes( $result ) );
		$this->assertSame( 'Status changed', $result[0]['message'] );
		$this->assertSame( 'Status changed again', $result[1]['message'] );
		$this->assertSame( '6', $result[2]['target']['id'] );
	}

	public function test_duplicates_with_different_changes_are_kept(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 9101, self::order( 5 ), [ self::change( 'status', 'a', 'b' ) ] ),
				self::event( 9101, self::order( 5 ), [ self::change( 'status', 'b', 'c' ) ] ),
			]
		);

		$this->assertSame( [ 9101, 9101 ], self::codes( $result ) );
	}

	public function test_events_that_differ_only_in_metadata_both_survive(): void {
		// Two private notes on one order in one request: only the note id tells
		// them apart.
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 9103, self::order( 5 ), null, [ 'metadata' => [ 'note_type' => 'private', 'note_id' => 11 ] ] ),
				self::event( 9103, self::order( 5 ), null, [ 'metadata' => [ 'note_type' => 'private', 'note_id' => 12 ] ] ),
			]
		);

		$this->assertSame( [ 9103, 9103 ], self::codes( $result ) );
		$this->assertSame( 11, $result[0]['metadata']['note_id'] );
		$this->assertSame( 12, $result[1]['metadata']['note_id'] );
	}

	/**
	 * @return array<string, array{0: string, 1: mixed, 2: mixed}>
	 */
	public static function differing_keys(): array {
		return [
			'vars'     => [ 'vars', [ 'note' => 'one' ], [ 'note' => 'two' ] ],
			'user'     => [ 'user', [ 'id' => 1 ], [ 'id' => 2 ] ],
			'post'     => [ 'post', [ 'id' => 1 ], [ 'id' => 2 ] ],
			'links'    => [ 'links', [ 'edit' => 'https://example.com/1' ], [ 'edit' => 'https://example.com/2' ] ],
			'severity' => [ 'severity', 'info', 'warning' ],
			'source'   => [ 'source', 'admin', 'rest' ],
		];
	}

	/**
	 * @dataProvider differing_keys
	 *
	 * @param string $key   Payload key that differs.
	 * @param mixed  $one   Value of the first event.
	 * @param mixed  $two   Value of the second event.
	 */
	public function test_events_that_differ_in_any_other_key_both_survive( string $key, $one, $two ): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 9101, self::order( 5 ), null, [ $key => $one ] ),
				self::event( 9101, self::order( 5 ), null, [ $key => $two ] ),
			]
		);

		$this->assertSame( [ 9101, 9101 ], self::codes( $result ) );
	}

	public function test_identical_events_with_different_timestamps_and_client_ids_dedupe(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 9101, self::order( 5 ), null, [ 'occurred_at' => '2026-10-26T10:00:00.000Z', 'client_event_id' => 'a', 'metadata' => [ 'x' => 1 ] ] ),
				self::event( 9101, self::order( 5 ), null, [ 'occurred_at' => '2026-10-26T10:00:01.250Z', 'client_event_id' => 'b', 'metadata' => [ 'x' => 1 ] ] ),
			]
		);

		$this->assertCount( 1, $result );
		$this->assertSame( '2026-10-26T10:00:00.000Z', $result[0]['occurred_at'] );
	}

	public function test_dedupe_ignores_key_order_at_every_depth(): void {
		$first  = [
			'code'     => 9101,
			'target'   => [ 'type' => 'order', 'id' => '5' ],
			'metadata' => [ 'a' => 1, 'b' => [ 'x' => 1, 'y' => 2 ] ],
		];
		$second = [
			'metadata' => [ 'b' => [ 'y' => 2, 'x' => 1 ], 'a' => 1 ],
			'target'   => [ 'id' => '5', 'type' => 'order' ],
			'code'     => 9101,
		];

		$this->assertCount( 1, SuppressionRules::defaults()->apply( [ $first, $second ] ) );
	}

	public function test_dedupe_keeps_the_order_of_list_values(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 9101, self::order( 5 ), null, [ 'metadata' => [ 'items' => [ 'a', 'b' ] ] ] ),
				self::event( 9101, self::order( 5 ), null, [ 'metadata' => [ 'items' => [ 'b', 'a' ] ] ] ),
			]
		);

		$this->assertCount( 2, $result );
	}

	public function test_merge_runs_before_dedupe(): void {
		// Title A to B, back to A, and to B again: the first and last are exact
		// duplicates, but the whole sequence nets out to A to B.
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2006, self::post( 1 ), [ self::change( 'post_title', 'A', 'B' ) ] ),
				self::event( 2006, self::post( 1 ), [ self::change( 'post_title', 'B', 'A' ) ] ),
				self::event( 2006, self::post( 1 ), [ self::change( 'post_title', 'A', 'B' ) ] ),
			]
		);

		$this->assertCount( 1, $result );
		$this->assertSame( [ self::change( 'post_title', 'A', 'B' ) ], $result[0]['changes'] );
	}

	public function test_a_throwing_condition_leaves_only_that_payload_unsuppressed(): void {
		$rules = new SuppressionRules(
			[
				9103 => [ 9101 ],
				9104 => [ 9101 ],
			],
			[],
			[],
			[
				9103 => static function (): bool {
					throw new \RuntimeException( 'bad condition' );
				},
				9104 => static fn(): bool => true,
			]
		);

		$result = $rules->apply(
			[
				self::event( 9101, self::order( 5 ) ),
				self::event( 9103, self::order( 5 ) ),
				self::event( 9104, self::order( 5 ) ),
			]
		);

		// 9103 stays (its test failed), 9104 is still suppressed.
		$this->assertSame( [ 9101, 9103 ], self::codes( $result ) );
	}

	// ---- no target -----------------------------------------------------

	public function test_no_target_never_suppressed(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2006, null, [ self::change( 'post_title', 'A', 'B' ) ] ),
				self::event( 2001, null ),
				self::event( 2006, null, [ self::change( 'post_status', 'draft', 'pending' ) ] ),
			]
		);

		// Neither suppressed by 2001 nor merged into one.
		$this->assertSame( [ 2006, 2001, 2006 ], self::codes( $result ) );
	}

	public function test_no_target_exact_duplicates_are_still_removed(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 1001, null, null, [ 'message' => 'Logged in' ] ),
				self::event( 1001, null, null, [ 'message' => 'Logged in' ] ),
			]
		);

		$this->assertSame( [ 1001 ], self::codes( $result ) );
	}

	public function test_a_targetless_event_neither_suppresses_nor_is_suppressed_by_a_targeted_one(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2001, null ),
				self::event( 2006, self::post( 1 ), [ self::change( 'post_title', 'A', 'B' ) ] ),
			]
		);

		$this->assertSame( [ 2001, 2006 ], self::codes( $result ) );
	}

	public function test_no_target_empty_update_is_kept(): void {
		// Without a target a payload takes part in nothing but exact-duplicate
		// removal, so the empty-update rule does not reach it either.
		$result = SuppressionRules::defaults()->apply( [ self::event( 2006, null, [] ) ] );

		$this->assertSame( [ 2006 ], self::codes( $result ) );
	}

	public function test_a_malformed_target_counts_as_no_target(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2001, [ 'type' => 'post' ] ),
				self::event( 2006, [ 'type' => 'post' ], [ self::change( 'post_title', 'A', 'B' ) ] ),
			]
		);

		$this->assertSame( [ 2001, 2006 ], self::codes( $result ) );
	}

	// ---- construction, defaults, with ---------------------------------

	public function test_empty_rules_keep_everything_but_exact_duplicates(): void {
		$result = ( new SuppressionRules() )->apply(
			[
				self::event( 2006, self::post( 1 ), [] ),
				self::event( 2001, self::post( 1 ) ),
				self::event( 2006, self::post( 1 ), [ self::change( 'post_title', 'A', 'B' ) ] ),
			]
		);

		$this->assertSame( [ 2006, 2001, 2006 ], self::codes( $result ) );
	}

	public function test_apply_on_nothing_returns_nothing(): void {
		$this->assertSame( [], SuppressionRules::defaults()->apply( [] ) );
	}

	public function test_apply_returns_a_list(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2001, self::post( 1 ) ),
				self::event( 2006, self::post( 1 ), [ self::change( 'post_title', 'A', 'B' ) ] ),
				self::event( 2003, self::post( 2 ) ),
			]
		);

		$this->assertSame( [ 0, 1 ], array_keys( $result ) );
		$this->assertSame( [ 2001, 2003 ], self::codes( $result ) );
	}

	public function test_condition_decides_whether_a_suppression_applies(): void {
		$rules = new SuppressionRules(
			[ 9103 => [ 9101 ] ],
			[],
			[],
			[ 9103 => static fn( array $p ): bool => 'system' === ( $p['metadata']['note_type'] ?? '' ) ]
		);

		$result = $rules->apply(
			[
				self::event( 9101, self::order( 5 ) ),
				self::event( 9103, self::order( 5 ), null, [ 'metadata' => [ 'note_type' => 'system' ] ] ),
			]
		);

		$this->assertSame( [ 9101 ], self::codes( $result ) );
	}

	public function test_with_unions_every_list(): void {
		$base  = new SuppressionRules( [ 2006 => [ 2001 ] ], [ 2006 ], [ 2006 ] );
		$other = new SuppressionRules( [ 2006 => [ 2002 ], 5000 => [ 5001 ] ], [ 4001 ], [ 4001 ] );

		$rules = $base->with( $other );

		// 2006 is suppressed by either listed code.
		$this->assertSame( [ 2001 ], self::codes( $rules->apply( [ self::event( 2006, self::post( 1 ), [ self::change( 'a', 'b', 'c' ) ] ), self::event( 2001, self::post( 1 ) ) ] ) ) );
		$this->assertSame( [ 2002 ], self::codes( $rules->apply( [ self::event( 2006, self::post( 1 ), [ self::change( 'a', 'b', 'c' ) ] ), self::event( 2002, self::post( 1 ) ) ] ) ) );
		// The new key works.
		$this->assertSame( [ 5001 ], self::codes( $rules->apply( [ self::event( 5000, self::post( 1 ) ), self::event( 5001, self::post( 1 ) ) ] ) ) );
		// Merge and drop_empty lists are unioned too.
		$merged = $rules->apply(
			[
				self::event( 4001, self::user( 1 ), [ self::change( 'a', '1', '2' ) ] ),
				self::event( 4001, self::user( 1 ), [ self::change( 'b', '1', '2' ) ] ),
			]
		);
		$this->assertCount( 1, $merged );
		$this->assertCount( 2, $merged[0]['changes'] );
		$this->assertSame( [], $rules->apply( [ self::event( 4001, self::user( 1 ), [] ) ] ) );
	}

	public function test_with_does_not_change_either_operand(): void {
		$base  = new SuppressionRules();
		$other = new SuppressionRules( [ 2006 => [ 2001 ] ] );

		$base->with( $other );

		$events = [ self::event( 2006, self::post( 1 ), [ self::change( 'a', 'b', 'c' ) ] ), self::event( 2001, self::post( 1 ) ) ];
		$this->assertSame( [ 2006, 2001 ], self::codes( $base->apply( $events ) ) );
		$this->assertSame( [ 2001 ], self::codes( $other->apply( $events ) ) );
	}

	public function test_with_requires_both_conditions_for_the_same_code(): void {
		$base  = new SuppressionRules(
			[ 9103 => [ 9101 ] ],
			[],
			[],
			[ 9103 => static fn( array $p ): bool => 'system' === ( $p['metadata']['note_type'] ?? '' ) ]
		);
		$other = new SuppressionRules(
			[],
			[],
			[],
			[ 9103 => static fn( array $p ): bool => ! empty( $p['metadata']['automated'] ) ]
		);

		$rules = $base->with( $other );

		$automated = self::event( 9103, self::order( 5 ), null, [ 'metadata' => [ 'note_type' => 'system', 'automated' => true ] ] );
		$manual    = self::event( 9103, self::order( 5 ), null, [ 'metadata' => [ 'note_type' => 'system' ] ] );
		$status    = self::event( 9101, self::order( 5 ) );

		$this->assertSame( [ 9101 ], self::codes( $rules->apply( [ $status, $automated ] ) ) );
		$this->assertSame( [ 9101, 9103 ], self::codes( $rules->apply( [ $status, $manual ] ) ) );
	}

	public function test_defaults_contain_the_initial_table(): void {
		$rules = SuppressionRules::defaults();

		foreach ( [ 2000, 2001, 2002, 2003, 2004, 2005, 2007, 2008, 2009, 2013 ] as $code ) {
			$result = $rules->apply(
				[
					self::event( 2006, self::post( 1 ), [ self::change( 'a', 'b', 'c' ) ] ),
					self::event( $code, self::post( 1 ) ),
				]
			);
			$this->assertSame( [ $code ], self::codes( $result ), "2006 must yield to $code" );
		}

		$this->assertSame( [ 2001 ], self::codes( $rules->apply( [ self::event( 2000, self::post( 1 ) ), self::event( 2001, self::post( 1 ) ) ] ) ) );
		$this->assertSame( [ 4000 ], self::codes( $rules->apply( [ self::event( 4000, self::user( 1 ) ), self::event( 4001, self::user( 1 ), [ self::change( 'a', 'b', 'c' ) ] ) ] ) ) );
		$this->assertSame( [ 4000 ], self::codes( $rules->apply( [ self::registration( 1, 'subscriber' ), self::role_change( 1, '', 'subscriber' ) ] ) ) );
		$this->assertSame( [ 9300 ], self::codes( $rules->apply( [ self::event( 9300, self::user( 1 ) ), self::registration( 1, 'customer' ) ] ) ) );
	}
}
