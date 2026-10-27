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
	 * @param ?string $new New role.
	 *
	 * @return array<string, mixed>
	 */
	private static function role_change( int $user, ?string $old, ?string $new ): array {
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

		$this->assertSame( [ 4002, 4000, 9300 ], self::codes( $result ) );
		$this->assertSame( 'administrator', $result[1]['vars']['role'] );
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

		$this->assertSame( [ 4000, 4002, 4002 ], self::codes( $result ) );
	}

	/**
	 * Multisite adds a user to a site by registering them with the default
	 * role, stripping it, and then granting the real role: that second grant
	 * has no previous role either, and must not hide behind the registration.
	 */
	public function test_a_privileged_grant_after_a_default_role_registration_survives(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::role_change( 7, '', 'subscriber' ),
				self::registration( 7, 'subscriber' ),
				self::role_change( 7, '', 'administrator' ),
			]
		);

		$this->assertSame( [ 4000, 4002 ], self::codes( $result ) );
		$this->assertSame( 'subscriber', $result[0]['vars']['role'] );
		$this->assertSame( 'administrator', $result[1]['changes'][0]['new'] );
	}

	/**
	 * @return array<string, array{0: ?string}> The new role of a first assignment.
	 */
	public static function roles_that_are_not_a_plain_default(): array {
		return [
			'editor'        => [ 'editor' ],
			'author'        => [ 'author' ],
			'administrator' => [ 'administrator' ],
			'shop manager'  => [ 'shop_manager' ],
			'custom role'   => [ 'wholesale_buyer' ],
			'wrong case'    => [ 'Subscriber' ],
			'padded'        => [ 'customer ' ],
			'no role'       => [ '' ],
			'null'          => [ null ],
		];
	}

	/**
	 * @dataProvider roles_that_are_not_a_plain_default
	 */
	public function test_a_first_assignment_of_any_other_role_survives_the_registration( ?string $new ): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::role_change( 7, '', $new ),
				self::registration( 7, 'subscriber' ),
			]
		);

		$this->assertSame( [ 4002, 4000 ], self::codes( $result ) );
	}

	/**
	 * @dataProvider roles_that_are_not_a_plain_default
	 */
	public function test_a_first_assignment_of_any_other_role_survives_the_shop_event( ?string $new ): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::role_change( 7, null, $new ),
				self::event( 9300, self::user( 7 ) ),
			]
		);

		$this->assertSame( [ 4002, 9300 ], self::codes( $result ) );
	}

	public function test_both_plain_defaults_are_first_assignments_that_yield(): void {
		foreach ( [ 'customer', 'subscriber' ] as $role ) {
			$result = SuppressionRules::defaults()->apply(
				[
					self::role_change( 7, '', $role ),
					self::registration( 7, $role ),
				]
			);

			$this->assertSame( [ 4000 ], self::codes( $result ), "the first $role must yield to the registration" );
		}
	}

	/**
	 * A merge would keep the message and vars of the first role change and
	 * understate the final privilege, so every role change stays its own event.
	 */
	public function test_role_changes_of_a_user_are_never_folded(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::role_change( 7, 'subscriber', 'editor' ),
				self::role_change( 7, 'editor', 'administrator' ),
			]
		);

		$this->assertSame( [ 4002, 4002 ], self::codes( $result ) );
		$this->assertSame( 'editor', $result[0]['changes'][0]['new'] );
		$this->assertSame( 'administrator', $result[1]['changes'][0]['new'] );
	}

	public function test_a_role_change_that_goes_back_is_still_logged_twice(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::role_change( 7, 'subscriber', 'administrator' ),
				self::role_change( 7, 'administrator', 'subscriber' ),
			]
		);

		$this->assertSame( [ 4002, 4002 ], self::codes( $result ) );
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

	public function test_status_note_suppressed_by_status_change(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 9101, self::order( 5 ) ),
				self::event( 9103, self::order( 5 ), null, [ 'metadata' => [ 'note_type' => 'status' ] ] ),
				self::event( 9103, self::order( 5 ), null, [ 'metadata' => [ 'note_type' => 'private' ] ] ),
			]
		);

		$this->assertSame( [ 9101, 9103 ], self::codes( $result ) );
		$this->assertSame( 'private', $result[1]['metadata']['note_type'] );
	}

	/**
	 * What the shop notes about payments, stock or errors is information of
	 * its own, and a note meant for the customer is a message to them.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function notes_next_to_a_status_change_that_stay(): array {
		return [
			'system'   => [ 'system' ],
			'customer' => [ 'customer' ],
			'private'  => [ 'private' ],
		];
	}

	/**
	 * @dataProvider notes_next_to_a_status_change_that_stay
	 */
	public function test_only_a_status_note_is_suppressed_by_a_status_change( string $note_type ): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 9101, self::order( 5 ) ),
				self::event( 9103, self::order( 5 ), null, [ 'metadata' => [ 'note_type' => $note_type ] ] ),
			]
		);

		$this->assertSame( [ 9101, 9103 ], self::codes( $result ) );
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

	public function test_status_note_without_a_status_change_stays(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 9103, self::order( 5 ), null, [ 'metadata' => [ 'note_type' => 'status' ] ] ),
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

	/**
	 * A post update (2006) the way the post sensor sends it: the names of the
	 * changed fields in `metadata.changed`.
	 *
	 * @param array<int, array{field: string, old: ?string, new: ?string}> $changes Changes.
	 *
	 * @return array<string, mixed>
	 */
	private static function post_update( array $changes ): array {
		return self::event(
			2006,
			self::post( 1 ),
			$changes,
			[
				'metadata' => [
					'slug'    => 'hello',
					'changed' => array_column( $changes, 'field' ),
				],
			]
		);
	}

	public function test_a_merged_update_lists_the_fields_of_its_merged_changes(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::post_update( [ self::change( 'post_title', 'A', 'B' ) ] ),
				self::post_update( [ self::change( 'post_excerpt', 'x', 'y' ) ] ),
			]
		);

		$this->assertCount( 1, $result );
		$this->assertSame(
			[
				'slug'    => 'hello',
				'changed' => [ 'post_title', 'post_excerpt' ],
			],
			$result[0]['metadata']
		);
	}

	public function test_a_merged_update_no_longer_lists_a_field_that_went_back(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::post_update( [ self::change( 'post_title', 'A', 'B' ), self::change( 'post_excerpt', 'x', 'y' ) ] ),
				self::post_update( [ self::change( 'post_title', 'B', 'A' ) ] ),
			]
		);

		$this->assertSame( [ self::change( 'post_excerpt', 'x', 'y' ) ], $result[0]['changes'] );
		$this->assertSame( [ 'post_excerpt' ], $result[0]['metadata']['changed'] );
	}

	public function test_a_merge_adds_no_changed_list_where_there_was_none(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2006, self::post( 1 ), [ self::change( 'post_title', 'A', 'B' ) ], [ 'metadata' => [ 'slug' => 'hello' ] ] ),
				self::event( 2006, self::post( 1 ), [ self::change( 'post_excerpt', 'x', 'y' ) ], [ 'metadata' => [ 'slug' => 'hello' ] ] ),
			]
		);

		$this->assertSame( [ 'slug' => 'hello' ], $result[0]['metadata'] );
	}

	public function test_merge_does_not_cross_targets_or_codes(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 4001, self::user( 7 ), [ self::change( 'display_name', 'a', 'b' ) ] ),
				self::event( 4001, self::user( 8 ), [ self::change( 'display_name', 'c', 'd' ) ] ),
				self::event( 2006, self::user( 7 ), [ self::change( 'post_title', 'x', 'y' ) ] ),
			]
		);

		$this->assertSame( [ 4001, 4001, 2006 ], self::codes( $result ) );
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
				self::event( 4001, self::user( 7 ), [ self::change( str_repeat( 'f', 100 ), 'x', $long ) ] ),
				self::event( 4001, self::user( 7 ), [ self::change( 'display_name', $long, 'y' ) ] ),
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
		// A merge code that is not in drop_empty: the payload stays, minus an
		// empty `changes` container.
		$result = ( new SuppressionRules( [], [ 5000 ], [] ) )->apply(
			[
				self::event( 5000, self::user( 7 ), [ self::change( 'role', 'a', 'b' ) ] ),
				self::event( 5000, self::user( 7 ), [ self::change( 'role', 'b', 'a' ) ] ),
			]
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 5000, $result[0]['code'] );
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

	/**
	 * A setting saved three times in one request (a settings batch call, say).
	 *
	 * @param string $old Value before.
	 * @param string $new Value after.
	 *
	 * @return array<string, mixed>
	 */
	private static function currency( string $old, string $new ): array {
		return self::event(
			9400,
			[
				'type' => 'setting',
				'id'   => 'woocommerce_currency',
			],
			[ self::change( 'woocommerce_currency', $old, $new ) ],
			[ 'message' => 'WooCommerce setting woocommerce_currency changed.' ]
		);
	}

	public function test_a_setting_that_goes_back_and_forth_keeps_every_step_in_order(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::currency( 'USD', 'EUR' ),
				self::currency( 'EUR', 'USD' ),
				self::currency( 'USD', 'EUR' ),
			]
		);

		// Dropping the third as a copy of the first would leave USD as the
		// final state on record, while the shop ends up on EUR.
		$this->assertSame(
			[ [ 'USD', 'EUR' ], [ 'EUR', 'USD' ], [ 'USD', 'EUR' ] ],
			array_map( static fn( array $p ): array => [ $p['changes'][0]['old'], $p['changes'][0]['new'] ], $result )
		);
	}

	public function test_a_role_that_goes_back_and_forth_keeps_every_step_in_order(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::role_change( 7, 'subscriber', 'administrator' ),
				self::role_change( 7, 'administrator', 'subscriber' ),
				self::role_change( 7, 'subscriber', 'administrator' ),
			]
		);

		$this->assertSame( [ 4002, 4002, 4002 ], self::codes( $result ) );
		$this->assertSame( [ 'administrator', 'subscriber', 'administrator' ], array_map( static fn( array $p ): string => $p['changes'][0]['new'], $result ) );
	}

	public function test_a_duplicate_is_dropped_only_right_after_its_twin_on_the_same_target(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::currency( 'USD', 'EUR' ),
				self::currency( 'USD', 'EUR' ),
				self::currency( 'EUR', 'GBP' ),
				self::currency( 'USD', 'EUR' ),
			]
		);

		$this->assertSame(
			[ [ 'USD', 'EUR' ], [ 'EUR', 'GBP' ], [ 'USD', 'EUR' ] ],
			array_map( static fn( array $p ): array => [ $p['changes'][0]['old'], $p['changes'][0]['new'] ], $result )
		);
	}

	public function test_events_on_other_targets_in_between_do_not_keep_a_duplicate(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 9101, self::order( 5 ), null, [ 'message' => 'Status changed' ] ),
				self::event( 9101, self::order( 6 ), null, [ 'message' => 'Status changed' ] ),
				self::event( 9101, self::order( 5 ), null, [ 'message' => 'Status changed' ] ),
			]
		);

		$this->assertSame( [ '5', '6' ], array_map( static fn( array $p ): string => $p['target']['id'], $result ) );
	}

	public function test_a_targetless_duplicate_is_dropped_only_right_after_its_twin_of_the_same_code(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 1001, null, null, [ 'message' => 'Logged in' ] ),
				self::event( 1002, null, null, [ 'message' => 'Logged out' ] ),
				self::event( 1001, null, null, [ 'message' => 'Logged in' ] ),
				self::event( 1001, null, null, [ 'message' => 'Logged in as bob' ] ),
				self::event( 1001, null, null, [ 'message' => 'Logged in' ] ),
			]
		);

		$this->assertSame(
			[ 'Logged in', 'Logged out', 'Logged in as bob', 'Logged in' ],
			array_column( $result, 'message' )
		);
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

	// ---- fold on suppress -----------------------------------------------

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function fold_codes(): array {
		$cases = [];
		foreach ( [ 2001, 2002, 2004, 2007, 2008, 2009, 2013 ] as $code ) {
			$cases[ (string) $code ] = [ $code ];
		}

		return $cases;
	}

	/**
	 * The classic editor sets the sticky flag after the save that changed the
	 * status: the update it makes is not dropped under the status event, its
	 * changes go into it.
	 *
	 * @dataProvider fold_codes
	 */
	public function test_an_update_folds_its_changes_into_the_status_event_that_suppresses_it( int $code ): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( $code, self::post( 1 ), [ self::change( 'post_title', 'A', 'B' ) ] ),
				self::event( 2006, self::post( 1 ), [ self::change( 'sticky', 'no', 'yes' ) ], [ 'metadata' => [ 'changed' => [ 'sticky' ] ] ] ),
			]
		);

		$this->assertSame( [ $code ], self::codes( $result ) );
		$this->assertSame(
			[
				self::change( 'post_title', 'A', 'B' ),
				self::change( 'sticky', 'no', 'yes' ),
			],
			$result[0]['changes']
		);
		$this->assertArrayNotHasKey( 'metadata', $result[0] );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function drop_codes(): array {
		return [
			'created' => [ 2000 ],
			'trashed' => [ 2003 ],
			'deleted' => [ 2005 ],
		];
	}

	/**
	 * A creation tells no changes, and a trashed or deleted post's last update
	 * says nothing that matters: those still drop the update.
	 *
	 * @dataProvider drop_codes
	 */
	public function test_an_update_under_a_creation_trash_or_delete_is_dropped( int $code ): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2006, self::post( 1 ), [ self::change( 'sticky', 'yes', 'no' ) ] ),
				self::event( $code, self::post( 1 ) ),
			]
		);

		$this->assertSame( [ $code ], self::codes( $result ) );
		$this->assertArrayNotHasKey( 'changes', $result[0] );
	}

	public function test_an_update_folds_when_a_status_event_is_there_beside_a_drop_code(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2001, self::post( 1 ) ),
				self::event( 2006, self::post( 1 ), [ self::change( 'sticky', 'no', 'yes' ) ] ),
				self::event( 2003, self::post( 1 ) ),
			]
		);

		$this->assertSame( [ 2001, 2003 ], self::codes( $result ) );
		$this->assertSame( [ self::change( 'sticky', 'no', 'yes' ) ], $result[0]['changes'] );
	}

	public function test_a_folded_change_keeps_the_earliest_old_and_the_latest_new(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2006, self::post( 1 ), [ self::change( 'post_excerpt', 'x', 'y' ) ] ),
				self::event( 2001, self::post( 1 ), [ self::change( 'post_title', 'A', 'B' ) ] ),
				self::event( 2006, self::post( 1 ), [ self::change( 'post_title', 'B', 'C' ), self::change( 'sticky', 'no', 'yes' ) ] ),
			]
		);

		$this->assertSame( [ 2001 ], self::codes( $result ) );
		$this->assertSame(
			[
				self::change( 'post_excerpt', 'x', 'y' ),
				self::change( 'post_title', 'A', 'C' ),
				self::change( 'sticky', 'no', 'yes' ),
			],
			$result[0]['changes']
		);
	}

	public function test_a_fold_that_goes_back_leaves_no_changes(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2001, self::post( 1 ) ),
				self::event( 2006, self::post( 1 ), [ self::change( 'sticky', 'no', 'yes' ) ] ),
				self::event( 2006, self::post( 1 ), [ self::change( 'sticky', 'yes', 'no' ) ] ),
			]
		);

		$this->assertSame( [ 2001 ], self::codes( $result ) );
		$this->assertArrayNotHasKey( 'changes', $result[0] );
	}

	public function test_a_fold_updates_a_changed_list_only_where_there_is_one(): void {
		$with    = self::event(
			2001,
			self::post( 1 ),
			[ self::change( 'post_title', 'A', 'B' ) ],
			[
				'metadata' => [
					'slug'    => 'hello',
					'changed' => [ 'post_title' ],
				],
			]
		);
		$without = self::event( 2001, self::post( 2 ), null, [ 'metadata' => [ 'slug' => 'world' ] ] );

		$result = SuppressionRules::defaults()->apply(
			[
				$with,
				$without,
				self::event( 2006, self::post( 1 ), [ self::change( 'sticky', 'no', 'yes' ) ] ),
				self::event( 2006, self::post( 2 ), [ self::change( 'sticky', 'no', 'yes' ) ] ),
			]
		);

		$this->assertSame( [ 2001, 2001 ], self::codes( $result ) );
		$this->assertSame( [ 'post_title', 'sticky' ], $result[0]['metadata']['changed'] );
		$this->assertSame( [ 'slug' => 'world' ], $result[1]['metadata'] );
		$this->assertSame( [ self::change( 'sticky', 'no', 'yes' ) ], $result[1]['changes'] );
	}

	/**
	 * An update before any status event goes into the first one after it;
	 * any other into the last status event before it.
	 */
	public function test_an_update_folds_into_the_last_status_event_before_it_else_the_first_after(): void {
		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2006, self::post( 1 ), [ self::change( 'a', '1', '2' ) ] ),
				self::event( 2008, self::post( 1 ) ),
				self::event( 2006, self::post( 1 ), [ self::change( 'b', '1', '2' ) ] ),
				self::event( 2001, self::post( 1 ) ),
				self::event( 2006, self::post( 1 ), [ self::change( 'c', '1', '2' ) ] ),
			]
		);

		$this->assertSame( [ 2008, 2001 ], self::codes( $result ) );
		$this->assertSame( [ 'a', 'b' ], array_column( $result[0]['changes'], 'field' ) );
		$this->assertSame( [ 'c' ], array_column( $result[1]['changes'], 'field' ) );
	}

	public function test_a_fold_stays_on_its_site_and_its_target(): void {
		$on     = static fn( int $site, array $payload ): array => $payload + [ SuppressionRules::BLOG_KEY => $site ];
		$sticky = [ self::change( 'sticky', 'no', 'yes' ) ];

		$result = SuppressionRules::defaults()->apply(
			[
				$on( 1, self::event( 2001, self::post( 42 ) ) ),
				$on( 2, self::event( 2006, self::post( 42 ), $sticky ) ),
				$on( 1, self::event( 2006, self::post( 43 ), $sticky ) ),
				$on( 1, self::event( 2006, self::post( 42 ), $sticky ) ),
			]
		);

		$this->assertSame( [ 2001, 2006, 2006 ], self::codes( $result ) );
		$this->assertSame( $sticky, $result[0]['changes'] );
		$this->assertSame( [ 1, 2, 1 ], array_column( $result, SuppressionRules::BLOG_KEY ) );
		$this->assertSame( [ '42', '42', '43' ], array_map( static fn( array $p ): string => $p['target']['id'], $result ) );
	}

	public function test_a_fold_needs_a_status_event_that_survives(): void {
		$rules = new SuppressionRules(
			[
				2006 => [ 2001 ],
				2001 => [ 5000 ],
			],
			[],
			[],
			[],
			[ 2006 => [ 2001 ] ]
		);

		$result = $rules->apply(
			[
				self::event( 2001, self::post( 1 ) ),
				self::event( 5000, self::post( 1 ) ),
				self::event( 2006, self::post( 1 ), [ self::change( 'sticky', 'no', 'yes' ) ] ),
			]
		);

		$this->assertSame( [ 5000 ], self::codes( $result ) );
		$this->assertArrayNotHasKey( 'changes', $result[0] );
	}

	public function test_a_fold_clamps_changes_to_fifty(): void {
		$own    = [];
		$folded = [];
		for ( $i = 0; $i < 30; $i++ ) {
			$own[]    = self::change( 'own_' . $i, 'a', 'b' );
			$folded[] = self::change( 'folded_' . $i, 'a', 'b' );
		}

		$result = SuppressionRules::defaults()->apply(
			[
				self::event( 2001, self::post( 1 ), $own ),
				self::event( 2006, self::post( 1 ), $folded ),
			]
		);

		$this->assertCount( 50, $result[0]['changes'] );
		$this->assertSame( 'folded_19', $result[0]['changes'][49]['field'] );
	}

	public function test_with_unions_the_fold_lists(): void {
		$base  = new SuppressionRules( [ 2006 => [ 2001, 2002 ] ], [], [], [], [ 2006 => [ 2001 ] ] );
		$other = new SuppressionRules( [], [], [], [], [ 2006 => [ 2002 ] ] );
		$rules = $base->with( $other );

		foreach ( [ 2001, 2002 ] as $code ) {
			$result = $rules->apply(
				[
					self::event( $code, self::post( 1 ) ),
					self::event( 2006, self::post( 1 ), [ self::change( 'sticky', 'no', 'yes' ) ] ),
				]
			);
			$this->assertSame( [ $code ], self::codes( $result ) );
			$this->assertSame( [ self::change( 'sticky', 'no', 'yes' ) ], $result[0]['changes'] ?? null, (string) $code );
		}

		// Neither operand changes.
		$plain = $base->apply( [ self::event( 2002, self::post( 1 ) ), self::event( 2006, self::post( 1 ), [ self::change( 'sticky', 'no', 'yes' ) ] ) ] );
		$this->assertArrayNotHasKey( 'changes', $plain[0] );
	}

	// ---- sites of a network --------------------------------------------

	public function test_events_of_one_site_never_thin_out_those_of_another(): void {
		$on    = static fn( int $site, array $payload ): array => $payload + [ SuppressionRules::BLOG_KEY => $site ];
		$title = [ self::change( 'post_title', 'A', 'B' ) ];

		$result = SuppressionRules::defaults()->apply(
			[
				$on( 1, self::event( 2001, self::post( 42 ) ) ),
				$on( 2, self::event( 2006, self::post( 42 ), $title ) ),
				$on( 3, self::event( 2006, self::post( 42 ), $title ) ),
				$on( 2, self::event( 1001, null, null, [ 'message' => 'Logged in' ] ) ),
				$on( 3, self::event( 1001, null, null, [ 'message' => 'Logged in' ] ) ),
				$on( 1, self::event( 2006, self::post( 42 ), $title ) ),
			]
		);

		$this->assertSame( [ 2001, 2006, 2006, 1001, 1001 ], self::codes( $result ) );
		$this->assertSame( [ 1, 2, 3, 2, 3 ], array_column( $result, SuppressionRules::BLOG_KEY ) );
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

		// A password or e-mail set right after the user was created is part of the creation.
		foreach ( [ 4004, 4005 ] as $code ) {
			foreach ( [ 4000, 9300 ] as $creation ) {
				$result = $rules->apply(
					[
						self::event( $creation, self::user( 1 ) ),
						self::event( $code, self::user( 1 ), [ self::change( 'a', 'b', 'c' ) ] ),
					]
				);
				$this->assertSame( [ $creation ], self::codes( $result ), "$code must yield to $creation" );
			}
		}

		// The profile event only carries the profile fields: nothing of it is
		// said by a password or e-mail change.
		$result = $rules->apply(
			[
				self::event( 4005, self::user( 1 ), [ self::change( 'user_email', 'a@example.com', 'b@example.com' ) ] ),
				self::event( 4004, self::user( 1 ), [ self::change( 'password', null, null ) ] ),
				self::event( 4001, self::user( 1 ), [ self::change( 'display_name', 'A', 'B' ) ] ),
			]
		);
		$this->assertSame( [ 4005, 4004, 4001 ], self::codes( $result ) );
	}

	/**
	 * An order edit (9105) and its item events (9106–9108) say nothing next
	 * to the order's creation (not 9107: creating an order removes no item),
	 * trash or delete in the same request; an edit next to a status change
	 * goes into the status event.
	 */
	public function test_defaults_drop_order_edits_under_creation_trash_and_delete(): void {
		$rules = SuppressionRules::defaults();
		$edit  = [ self::change( 'billing.city', 'Budapest', 'Szeged' ) ];

		$beaten_by = [
			9105 => [ 9100, 9102, 9109 ],
			9106 => [ 9100, 9102, 9109 ],
			9107 => [ 9102, 9109 ],
			9108 => [ 9100, 9102, 9109 ],
		];
		foreach ( $beaten_by as $code => $winners ) {
			foreach ( $winners as $winner ) {
				$result = $rules->apply(
					[
						self::event( $code, self::order( 5 ), $edit ),
						self::event( $winner, self::order( 5 ) ),
						self::event( $code, self::order( 6 ), $edit ),
					]
				);
				$this->assertSame( [ $winner, $code ], self::codes( $result ), "$code must yield to $winner on the same order only" );
				$this->assertArrayNotHasKey( 'changes', $result[0], "$code is dropped under $winner, not folded" );
			}
		}

		$this->assertSame( [ 9100, 9107 ], self::codes( $rules->apply( [ self::event( 9100, self::order( 5 ) ), self::event( 9107, self::order( 5 ), $edit ) ] ) ) );

		$folded = $rules->apply(
			[
				self::event( 9105, self::order( 5 ), $edit ),
				self::event( 9101, self::order( 5 ), [ self::change( 'status', 'on-hold', 'completed' ) ], [ 'metadata' => [ 'old_status' => 'on-hold' ] ] ),
			]
		);
		$this->assertSame( [ 9101 ], self::codes( $folded ) );
		$this->assertSame( [ 'billing.city', 'status' ], array_column( $folded[0]['changes'], 'field' ) );
	}

	/**
	 * The edits of an order in one request are one event; an edit that
	 * changed nothing is none.
	 */
	public function test_defaults_fold_order_edits_and_drop_empty_ones(): void {
		$rules = SuppressionRules::defaults();

		$folded = $rules->apply(
			[
				self::event( 9105, self::order( 5 ), [ self::change( 'billing.city', 'Budapest', 'Szeged' ) ] ),
				self::event( 9105, self::order( 5 ), [] ),
				self::event( 9105, self::order( 5 ), [ self::change( 'total', '25.00', '30.00' ) ] ),
			]
		);
		$this->assertSame( [ 9105 ], self::codes( $folded ) );
		$this->assertSame( [ 'billing.city', 'total' ], array_column( $folded[0]['changes'], 'field' ) );

		$this->assertSame( [], $rules->apply( [ self::event( 9105, self::order( 5 ), [] ) ] ) );
	}

	/**
	 * A restore can arrive as WooCommerce's restore, WordPress's restore of
	 * the order post and a status change out of the trash, all in one
	 * request: one restore stays. A trash or restore the store tells hides
	 * the post event of the same order; a status change that is no restore
	 * stays.
	 */
	public function test_defaults_keep_one_order_restore_and_one_order_trash(): void {
		$rules    = SuppressionRules::defaults();
		$restored = [ 'metadata' => [ 'previous_status' => 'processing' ] ];

		$result = $rules->apply(
			[
				self::event( 2004, self::order( 5 ) ),
				self::event( 9110, self::order( 5 ), null, $restored ),
				self::event( 9101, self::order( 5 ), [ self::change( 'status', 'trash', 'processing' ) ], [ 'metadata' => [ 'old_status' => 'trash' ] ] ),
				self::event( 9110, self::order( 5 ), null, $restored ),
			]
		);
		$this->assertSame( [ 9110 ], self::codes( $result ) );
		$this->assertSame( 'processing', $result[0]['metadata']['previous_status'] );

		// Restores that differ (one carries the edits of its save) are still one.
		$merged = $rules->apply(
			[
				self::event( 9110, self::order( 5 ), [ self::change( 'billing.city', 'Budapest', 'Szeged' ) ], $restored ),
				self::event( 9110, self::order( 5 ), null, [ 'metadata' => [ 'order_id' => 5 ] ] ),
			]
		);
		$this->assertSame( [ 9110 ], self::codes( $merged ) );
		$this->assertSame( [ 'billing.city' ], array_column( $merged[0]['changes'], 'field' ) );
		$this->assertSame( 'processing', $merged[0]['metadata']['previous_status'] );

		$this->assertSame( [ 9109 ], self::codes( $rules->apply( [ self::event( 2003, self::order( 5 ) ), self::event( 9109, self::order( 5 ) ) ] ) ) );
		$this->assertSame( [ 2003, 2004 ], self::codes( $rules->apply( [ self::event( 2003, self::order( 5 ) ), self::event( 2004, self::order( 6 ) ) ] ) ) );

		$status = $rules->apply(
			[
				self::event( 9110, self::order( 5 ), null, $restored ),
				self::event( 9101, self::order( 5 ), [ self::change( 'status', 'pending', 'processing' ) ], [ 'metadata' => [ 'old_status' => 'pending' ] ] ),
			]
		);
		$this->assertSame( [ 9110, 9101 ], self::codes( $status ) );
	}

	/**
	 * Two edits of a term in one request are one event, and an edit that
	 * changed nothing (or was undone) is not one. Menu saves fold the same
	 * way, but a menu save without a change of its own stays: it is a save of
	 * the menu's items.
	 */
	public function test_defaults_fold_term_and_menu_updates(): void {
		$rules = SuppressionRules::defaults();
		$term  = [
			'type' => 'term',
			'id'   => '7',
		];
		$menu  = [
			'type' => 'menu',
			'id'   => '9',
		];

		$folded = $rules->apply(
			[
				self::event( 2121, $term, [ self::change( 'name', 'News', 'Updates' ) ] ),
				self::event( 2121, $term, [ self::change( 'slug', 'news', 'updates' ) ] ),
			]
		);
		$this->assertCount( 1, $folded );
		$this->assertSame( [ 'name', 'slug' ], array_column( $folded[0]['changes'], 'field' ) );

		$this->assertSame( [], $rules->apply( [ self::event( 2121, $term, [] ) ] ) );
		$this->assertSame(
			[],
			$rules->apply(
				[
					self::event( 2121, $term, [ self::change( 'name', 'News', 'Updates' ) ] ),
					self::event( 2121, $term, [ self::change( 'name', 'Updates', 'News' ) ] ),
				]
			)
		);

		$menus = $rules->apply(
			[
				self::event( 2401, $menu, [ self::change( 'name', 'Main', 'Primary' ) ] ),
				self::event( 2401, $menu, [] ),
			]
		);
		$this->assertCount( 1, $menus );
		$this->assertSame( [ self::change( 'name', 'Main', 'Primary' ) ], $menus[0]['changes'] );
		$this->assertSame( [ 2401 ], self::codes( $rules->apply( [ self::event( 2401, $menu, [] ) ] ) ) );
	}

	/**
	 * A file's edit screen writes the alt text and the other texts apart:
	 * the edits of one request are one event, and an edit that changed
	 * nothing (or was undone) is not one. Each file is folded on its own.
	 */
	public function test_defaults_fold_media_edits(): void {
		$rules = SuppressionRules::defaults();
		$file  = [
			'type' => 'media',
			'id'   => '7',
		];
		$other = [
			'type' => 'media',
			'id'   => '8',
		];

		$folded = $rules->apply(
			[
				self::event( 2011, $file, [ self::change( 'alt', null, 'Logo' ) ] ),
				self::event( 2011, $other, [ self::change( 'title', 'A', 'B' ) ] ),
				self::event( 2011, $file, [ self::change( 'title', 'IMG_0042', 'Logo' ), self::change( 'description', null, null ) ] ),
			]
		);
		$this->assertSame( [ 2011, 2011 ], self::codes( $folded ) );
		$this->assertSame( [ 'alt', 'title', 'description' ], array_column( $folded[0]['changes'], 'field' ) );
		$this->assertSame( '8', $folded[1]['target']['id'] );

		$this->assertSame( [], $rules->apply( [ self::event( 2011, $file, [] ) ] ) );
		$this->assertSame(
			[],
			$rules->apply(
				[
					self::event( 2011, $file, [ self::change( 'alt', 'Logo', 'Old logo' ) ] ),
					self::event( 2011, $file, [ self::change( 'alt', 'Old logo', 'Logo' ) ] ),
				]
			)
		);

	}

	/**
	 * An upload that sets the alt text in the same request (the REST API
	 * writes it right after the file) is one upload carrying the alt text;
	 * edits of a file deleted in the same request are not worth keeping.
	 * Uploads and deletes themselves are never folded or dropped.
	 */
	public function test_defaults_fold_media_edits_into_the_upload_and_drop_them_under_a_delete(): void {
		$rules = SuppressionRules::defaults();
		$file  = [
			'type' => 'media',
			'id'   => '7',
		];
		$other = [
			'type' => 'media',
			'id'   => '8',
		];

		$uploaded = $rules->apply(
			[
				self::event( 2010, $file ),
				self::event( 2011, $file, [ self::change( 'alt', null, 'Logo' ) ] ),
				self::event( 2011, $other, [ self::change( 'title', 'A', 'B' ) ] ),
			]
		);
		$this->assertSame( [ 2010, 2011 ], self::codes( $uploaded ) );
		$this->assertSame( [ self::change( 'alt', null, 'Logo' ) ], $uploaded[0]['changes'] );
		$this->assertSame( '8', $uploaded[1]['target']['id'] );

		$deleted = $rules->apply(
			[
				self::event( 2011, $file, [ self::change( 'title', 'IMG_0042', 'Logo' ) ] ),
				self::event( 2012, $file ),
			]
		);
		$this->assertSame( [ 2012 ], self::codes( $deleted ) );
		$this->assertArrayNotHasKey( 'changes', $deleted[0] );

		$this->assertSame(
			[ 2010, 2012 ],
			self::codes(
				$rules->apply(
					[
						self::event( 2010, $file ),
						self::event( 2011, $file, [ self::change( 'alt', null, 'Logo' ) ] ),
						self::event( 2012, $file ),
					]
				)
			)
		);
	}
}
