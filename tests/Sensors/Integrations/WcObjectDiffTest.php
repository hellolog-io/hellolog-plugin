<?php
/**
 * Tests for {@see HelloLog\Sensors\Integrations\WcObjectDiff}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Integrations;

use HelloLog\Sensors\Integrations\WcObjectDiff;
use PHPUnit\Framework\TestCase;

/**
 * The helper is pure: two plain-array snapshots of a shop object in, an
 * old-to-new change list out. The tests pin how nested data is flattened to
 * dot keys, that no object can reach the change list, that dates are compared
 * as instants, and how a `billing.*` style field selector expands.
 */
final class WcObjectDiffTest extends TestCase {

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

	public function test_nested_arrays_flatten_to_dot_keys(): void {
		$flat = WcObjectDiff::flatten(
			[
				'status'   => 'processing',
				'billing'  => [
					'city'      => 'Budapest',
					'address_1' => 'Fo utca 1',
				],
				'shipping' => [
					'address' => [ 'line' => 'Kapu 2' ],
				],
			]
		);

		$this->assertSame(
			[
				'status'                => 'processing',
				'billing.city'          => 'Budapest',
				'billing.address_1'     => 'Fo utca 1',
				'shipping.address.line' => 'Kapu 2',
			],
			$flat
		);
	}

	public function test_a_prefix_is_put_in_front_of_every_key(): void {
		$flat = WcObjectDiff::flatten( [ 'city' => 'Budapest' ], 'billing' );

		$this->assertSame( [ 'billing.city' => 'Budapest' ], $flat );
	}

	public function test_lists_stay_lists_and_scalars_keep_their_type(): void {
		$flat = WcObjectDiff::flatten(
			[
				'tags'    => [ 'a', 'b' ],
				'rows'    => [
					[
						'sku' => 'x',
						'qty' => 2,
					],
				],
				'empty'   => [],
				'nothing' => null,
				'virtual' => true,
				'total'   => 12.5,
				'count'   => 3,
			]
		);

		$this->assertSame(
			[
				'tags'    => [ 'a', 'b' ],
				'rows'    => [
					[
						'sku' => 'x',
						'qty' => 2,
					],
				],
				'empty'   => [],
				'nothing' => null,
				'virtual' => true,
				'total'   => 12.5,
				'count'   => 3,
			],
			$flat
		);
	}

	public function test_a_date_becomes_its_iso_8601_text(): void {
		$flat = WcObjectDiff::flatten( [ 'date_created' => new \DateTimeImmutable( '2026-10-26T10:00:00+00:00' ) ] );

		$this->assertSame( [ 'date_created' => '2026-10-26T10:00:00+00:00' ], $flat );
	}

	public function test_a_date_is_written_in_utc(): void {
		$flat = WcObjectDiff::flatten( [ 'date_paid' => new \DateTimeImmutable( '2026-10-26T12:30:00+02:00' ) ] );

		$this->assertSame( [ 'date_paid' => '2026-10-26T10:30:00+00:00' ], $flat );
	}

	public function test_a_mutable_date_is_not_touched(): void {
		$date = new \DateTime( '2026-10-26T12:30:00+02:00' );

		WcObjectDiff::flatten( [ 'date_paid' => $date ] );

		$this->assertSame( '2026-10-26T12:30:00+02:00', $date->format( 'c' ) );
	}

	public function test_an_object_with_a_text_form_becomes_that_text(): void {
		$flat = WcObjectDiff::flatten( [ 'label' => self::stringable( 'Gold member' ) ] );

		$this->assertSame( [ 'label' => 'Gold member' ], $flat );
	}

	public function test_any_other_object_is_dropped(): void {
		$flat = WcObjectDiff::flatten(
			[
				'kept'    => 'yes',
				'product' => new \stdClass(),
				'hook'    => static fn() => 1,
				'nested'  => [
					'kept'  => 'also',
					'thing' => new \ArrayObject( [ 1 ] ),
				],
			]
		);

		$this->assertSame(
			[
				'kept'        => 'yes',
				'nested.kept' => 'also',
			],
			$flat
		);
	}

	public function test_a_list_gets_the_same_rules_as_a_single_value(): void {
		$flat = WcObjectDiff::flatten(
			[
				'dates' => [
					new \DateTimeImmutable( '2026-10-26T12:00:00+02:00' ),
					new \stdClass(),
					self::stringable( 'later' ),
					'plain',
				],
			]
		);

		$this->assertSame(
			[
				'dates' => [ '2026-10-26T10:00:00+00:00', 'later', 'plain' ],
			],
			$flat
		);
	}

	public function test_a_list_that_lost_an_item_is_still_a_list(): void {
		$flat = WcObjectDiff::flatten( [ 'ids' => [ new \stdClass(), 'a', new \stdClass(), 'b' ] ] );

		$this->assertSame( [ 'ids' => [ 'a', 'b' ] ], $flat );
		$this->assertSame( [ 0, 1 ], array_keys( $flat['ids'] ) );
	}

	public function test_objects_inside_a_list_of_arrays_are_cleaned_too(): void {
		$flat = WcObjectDiff::flatten(
			[
				'rows' => [
					[
						'sku'  => 'x',
						'when' => new \DateTimeImmutable( '2026-10-26T12:00:00+02:00' ),
						'obj'  => new \stdClass(),
					],
				],
			]
		);

		$this->assertSame(
			[
				'rows' => [
					[
						'sku'  => 'x',
						'when' => '2026-10-26T10:00:00+00:00',
					],
				],
			],
			$flat
		);
	}

	public function test_a_list_of_dates_is_compared_as_instants(): void {
		$changes = WcObjectDiff::changes(
			[ 'dates' => [ new \DateTimeImmutable( '2026-10-26T10:00:00+00:00' ) ] ],
			[ 'dates' => [ new \DateTimeImmutable( '2026-10-26T12:00:00+02:00' ) ] ],
			[ 'dates' ]
		);

		$this->assertSame( [], $changes );
	}

	public function test_a_changed_list_of_dates_is_reported_as_text(): void {
		$changes = WcObjectDiff::changes(
			[ 'dates' => [ new \DateTimeImmutable( '2026-10-26T10:00:00+00:00' ) ] ],
			[ 'dates' => [ new \DateTimeImmutable( '2026-10-27T10:00:00+00:00' ) ] ],
			[ 'dates' ]
		);

		$this->assertSame( [ self::item( 'dates', '2026-10-26T10:00:00+00:00', '2026-10-27T10:00:00+00:00' ) ], $changes );
	}

	public function test_nothing_nested_too_deep_is_followed(): void {
		$deep = 'leaf';
		for ( $i = 0; $i < 30; $i++ ) {
			$deep = [ 'k' => $deep ];
		}

		$flat = WcObjectDiff::flatten( $deep );

		$this->assertCount( 1, $flat );
		$this->assertSame( '…', reset( $flat ) );
	}

	public function test_a_list_nested_too_deep_is_cut_the_same_way(): void {
		$deep = 'leaf';
		for ( $i = 0; $i < 30; $i++ ) {
			$deep = [ $deep ];
		}

		$flat = WcObjectDiff::flatten( [ 'rows' => $deep ] );

		$this->assertCount( 1, $flat );
		$this->assertArrayHasKey( 'rows', $flat );
		$this->assertStringContainsString( '…', (string) json_encode( $flat['rows'], JSON_UNESCAPED_UNICODE ) );
		$this->assertStringNotContainsString( 'leaf', (string) json_encode( $flat['rows'], JSON_UNESCAPED_UNICODE ) );
	}

	public function test_a_resource_is_dropped(): void {
		$handle = fopen( 'php://memory', 'rb' );
		$this->assertNotFalse( $handle );
		$closed = fopen( 'php://memory', 'rb' );
		$this->assertNotFalse( $closed );
		fclose( $closed );

		$flat = WcObjectDiff::flatten(
			[
				'kept'   => 'yes',
				'open'   => $handle,
				'closed' => $closed,
				'list'   => [ 'a', $handle, 'b' ],
			]
		);
		fclose( $handle );

		$this->assertSame(
			[
				'kept' => 'yes',
				'list' => [ 'a', 'b' ],
			],
			$flat
		);
	}

	public function test_a_self_referencing_array_ends(): void {
		$a         = [ 'x' => 1 ];
		$a['self'] = &$a;

		$flat = WcObjectDiff::flatten( $a );

		$this->assertSame( 1, $flat['x'] );
		$this->assertSame( '…', end( $flat ) );
	}

	public function test_a_nested_field_change_is_reported_by_its_dot_key(): void {
		$changes = WcObjectDiff::changes(
			[
				'billing' => [
					'city'      => 'Budapest',
					'address_1' => 'Fo utca 1',
				],
			],
			[ 'billing' => [ 'city' => 'Szeged' ] ],
			[ 'billing.city' ]
		);

		$this->assertSame( [ self::item( 'billing.city', 'Budapest', 'Szeged' ) ], $changes );
	}

	public function test_a_wildcard_expands_to_the_keys_that_are_present(): void {
		$changes = WcObjectDiff::changes(
			[
				'billing'       => [
					'first_name' => 'Anna',
					'city'       => 'Budapest',
				],
				'billing_email' => 'anna@example.com',
				'shipping'      => [ 'city' => 'Pecs' ],
			],
			[
				'billing'       => [
					'city'     => 'Szeged',
					'postcode' => '6720',
				],
				'billing_email' => 'anna@example.test',
				'shipping'      => [ 'city' => 'Gyor' ],
			],
			[ 'billing.*' ]
		);

		$this->assertSame(
			[
				self::item( 'billing.city', 'Budapest', 'Szeged' ),
				self::item( 'billing.postcode', null, '6720' ),
			],
			$changes
		);
	}

	public function test_a_wildcard_follows_nested_keys_and_keeps_its_place_in_the_field_order(): void {
		$changes = WcObjectDiff::changes(
			[
				'status' => 'pending',
				'total'  => '10',
				'meta'   => [
					'a' => [ 'x' => '1' ],
					'b' => '2',
				],
			],
			[
				'status' => 'completed',
				'total'  => '12',
				'meta'   => [
					'a' => [ 'x' => '9' ],
					'b' => '3',
				],
			],
			[ 'total', 'meta.*', 'status' ]
		);

		$this->assertSame(
			[
				self::item( 'total', '10', '12' ),
				self::item( 'meta.a.x', '1', '9' ),
				self::item( 'meta.b', '2', '3' ),
				self::item( 'status', 'pending', 'completed' ),
			],
			$changes
		);
	}

	public function test_a_wildcard_with_no_match_gives_nothing(): void {
		$changes = WcObjectDiff::changes(
			[ 'status' => 'pending' ],
			[ 'status' => 'completed' ],
			[ 'billing.*' ]
		);

		$this->assertSame( [], $changes );
	}

	public function test_a_value_that_stayed_the_same_is_not_a_change(): void {
		$changes = WcObjectDiff::changes(
			[
				'billing' => [ 'city' => 'Budapest' ],
				'qty'     => 5,
			],
			[
				'billing' => [ 'city' => 'Budapest' ],
				'qty'     => '5',
			],
			[ 'billing.city', 'qty' ]
		);

		$this->assertSame( [], $changes );
	}

	public function test_a_field_that_is_not_asked_for_is_ignored(): void {
		$changes = WcObjectDiff::changes(
			[
				'status' => 'pending',
				'note'   => 'a',
			],
			[
				'status' => 'completed',
				'note'   => 'b',
			],
			[ 'status' ]
		);

		$this->assertSame( [ self::item( 'status', 'pending', 'completed' ) ], $changes );
	}

	public function test_a_field_missing_from_the_pending_data_is_unchanged(): void {
		$changes = WcObjectDiff::changes(
			[
				'status' => 'pending',
				'note'   => 'a',
			],
			[ 'status' => 'completed' ],
			[ 'status', 'note' ]
		);

		$this->assertSame( [ self::item( 'status', 'pending', 'completed' ) ], $changes );
	}

	public function test_a_value_set_to_null_is_a_change(): void {
		$changes = WcObjectDiff::changes(
			[ 'coupon' => [ 'expires' => 'soon' ] ],
			[ 'coupon' => [ 'expires' => null ] ],
			[ 'coupon.expires' ]
		);

		$this->assertSame( [ self::item( 'coupon.expires', 'soon', null ) ], $changes );
	}

	public function test_the_same_instant_in_two_zones_is_not_a_change(): void {
		$changes = WcObjectDiff::changes(
			[ 'date_paid' => new \DateTimeImmutable( '2026-10-26T10:00:00+00:00' ) ],
			[ 'date_paid' => new \DateTimeImmutable( '2026-10-26T12:00:00+02:00' ) ],
			[ 'date_paid' ]
		);

		$this->assertSame( [], $changes );
	}

	public function test_a_moved_date_is_reported_in_utc(): void {
		$changes = WcObjectDiff::changes(
			[ 'date_paid' => new \DateTimeImmutable( '2026-10-26T10:00:00+00:00' ) ],
			[ 'date_paid' => new \DateTimeImmutable( '2026-10-27T12:00:00+02:00' ) ],
			[ 'date_paid' ]
		);

		$this->assertSame( [ self::item( 'date_paid', '2026-10-26T10:00:00+00:00', '2026-10-27T10:00:00+00:00' ) ], $changes );
	}

	public function test_a_date_set_for_the_first_time_has_no_old_value(): void {
		$changes = WcObjectDiff::changes(
			[ 'date_paid' => null ],
			[ 'date_paid' => new \DateTimeImmutable( '2026-10-26T10:00:00+00:00' ) ],
			[ 'date_paid' ]
		);

		$this->assertSame( [ self::item( 'date_paid', null, '2026-10-26T10:00:00+00:00' ) ], $changes );
	}

	public function test_content_fields_are_reported_without_their_values(): void {
		$changes = WcObjectDiff::changes(
			[
				'description' => 'old text',
				'status'      => 'draft',
			],
			[
				'description' => 'new text',
				'status'      => 'publish',
			],
			[ 'description', 'status' ],
			[ 'description' ]
		);

		$this->assertSame(
			[
				self::item( 'description', null, null ),
				self::item( 'status', 'draft', 'publish' ),
			],
			$changes
		);
	}

	public function test_numeric_keys_keep_their_meaning(): void {
		$changes = WcObjectDiff::changes(
			[
				'10' => 'a',
				'20' => 'b',
			],
			[ '10' => 'c' ],
			[ '10', '20' ]
		);

		$this->assertSame( [ self::item( '10', 'a', 'c' ) ], $changes );
	}

	public function test_an_object_never_reaches_the_change_list(): void {
		$changes = WcObjectDiff::changes(
			[ 'product' => new \stdClass() ],
			[ 'product' => new \ArrayObject( [ 'secret' => 'x' ] ) ],
			[ 'product' ]
		);

		$this->assertSame( [], $changes );
	}

	/**
	 * An object that has only a text form.
	 *
	 * @param string $text What the object reads as.
	 */
	private static function stringable( string $text ): \Stringable {
		return new class( $text ) implements \Stringable {

			/** @var string */
			private $text;

			public function __construct( string $text ) {
				$this->text = $text;
			}

			public function __toString(): string {
				return $this->text;
			}
		};
	}
}
