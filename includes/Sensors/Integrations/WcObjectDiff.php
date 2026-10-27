<?php
/**
 * Turns two snapshots of a shop object into a field-by-field change list.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Events\ChangeSet;

defined( 'ABSPATH' ) || exit;

/**
 * Shop objects (orders, products, coupons, customers) hand out their data as
 * nested arrays holding dates and other objects. This helper makes two such
 * snapshots comparable and feeds them to {@see ChangeSet::diff()}.
 *
 * Flattening gives every nested value a dot key (`billing.city`); a list stays
 * one value. What is left is plain data: a date is its ISO 8601 text in UTC,
 * so the same instant held in two time zones is not a change; an object with a
 * text form is that text; any other object is dropped. The rules hold at every
 * level, inside lists too, so no object ever reaches the change list. The
 * helper never calls into WordPress or WooCommerce.
 */
final class WcObjectDiff {

	/**
	 * Deepest nesting that is still read; anything below becomes `…`, like
	 * {@see ChangeSet} does, so a self-referencing array cannot recurse
	 * forever.
	 */
	private const MAX_DEPTH = 10;

	private const ELLIPSIS = '…';

	/**
	 * Nested associative arrays as one level of dot keys.
	 *
	 * `[ 'billing' => [ 'city' => 'Budapest' ] ]` becomes
	 * `[ 'billing.city' => 'Budapest' ]`. A list (and an empty array) is one
	 * value and keeps its shape. Values are cleaned as described on the class.
	 *
	 * @param array<int|string, mixed> $a      Snapshot, possibly nested.
	 * @param string                   $prefix Dot key to put in front of every key; empty for none.
	 *
	 * @return array<int|string, mixed>
	 */
	public static function flatten( array $a, string $prefix = '' ): array {
		$flat = [];
		self::flatten_into( $flat, $a, $prefix, 0 );

		return $flat;
	}

	/**
	 * Compare two snapshots of an object, field by field.
	 *
	 * `$pending` holds only what is about to be written: a field it does not
	 * mention keeps its stored value, so it is never a change. `$fields` names
	 * the dot keys to compare, in the order the result follows. A name that
	 * ends in `*` is a prefix wildcard: `billing.*` stands for every key that
	 * starts with `billing.` and exists in either snapshot. `$content_fields`
	 * are exact dot keys reported as "changed" without values.
	 *
	 * @param array<int|string, mixed> $stored         Snapshot of what is stored now.
	 * @param array<int|string, mixed> $pending        The values about to replace it.
	 * @param array<int, int|string>   $fields         Dot keys to compare; may hold prefix wildcards like `billing.*`.
	 * @param array<int, int|string>   $content_fields Dot keys reported without their values.
	 *
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function changes( array $stored, array $pending, array $fields, array $content_fields = [] ): array {
		$stored_flat = self::flatten( $stored );
		$after       = $stored_flat;
		foreach ( self::flatten( $pending ) as $key => $value ) {
			$after[ $key ] = $value;
		}

		return ChangeSet::diff( $stored_flat, $after, self::expand( $fields, $after ), $content_fields );
	}

	/**
	 * Replace every prefix wildcard in a field list by the keys it matches,
	 * at the wildcard's own position.
	 *
	 * @param array<int, int|string>   $fields Field names, may hold wildcards.
	 * @param array<int|string, mixed> $keyed  Flat snapshot whose keys are the ones that exist.
	 *
	 * @return array<int, int|string>
	 */
	private static function expand( array $fields, array $keyed ): array {
		$names = [];
		foreach ( $fields as $field ) {
			if ( ! is_string( $field ) || ! str_ends_with( $field, '*' ) ) {
				$names[] = $field;
				continue;
			}

			$prefix = substr( $field, 0, -1 );
			foreach ( array_keys( $keyed ) as $key ) {
				if ( str_starts_with( (string) $key, $prefix ) ) {
					$names[] = (string) $key;
				}
			}
		}

		return $names;
	}

	/**
	 * Add the flattened `$a` to `$flat`.
	 *
	 * @param array<int|string, mixed> $flat   Result being built.
	 * @param array<int|string, mixed> $a      Array to read.
	 * @param string                   $prefix Dot key to put in front of every key; empty for none.
	 * @param int                      $depth  Nesting level of `$a`.
	 */
	private static function flatten_into( array &$flat, array $a, string $prefix, int $depth ): void {
		foreach ( $a as $key => $value ) {
			if ( self::is_dropped( $value ) ) {
				continue;
			}

			$name = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( ! is_array( $value ) || self::is_list( $value ) ) {
				$flat[ $name ] = self::clean( $value, $depth + 1 );
			} elseif ( $depth >= self::MAX_DEPTH ) {
				$flat[ $name ] = self::ELLIPSIS;
			} else {
				self::flatten_into( $flat, $value, $name, $depth + 1 );
			}
		}
	}

	/**
	 * A value as plain data: dates and text objects become text, arrays are
	 * cleaned item by item. A list that loses an item is renumbered, so it
	 * stays a list.
	 *
	 * @param mixed $value Any value that is not dropped.
	 * @param int   $depth Nesting level of `$value`.
	 *
	 * @return mixed
	 */
	private static function clean( $value, int $depth ) {
		if ( $value instanceof \DateTimeInterface ) {
			return \DateTimeImmutable::createFromInterface( $value )
				->setTimezone( new \DateTimeZone( 'UTC' ) )
				->format( 'c' );
		}
		if ( $value instanceof \Stringable ) {
			return (string) $value;
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( $depth > self::MAX_DEPTH ) {
			return self::ELLIPSIS;
		}

		$clean = [];
		foreach ( $value as $key => $item ) {
			if ( ! self::is_dropped( $item ) ) {
				$clean[ $key ] = self::clean( $item, $depth + 1 );
			}
		}

		return self::is_list( $value ) ? array_values( $clean ) : $clean;
	}

	/**
	 * Whether a value has no place in the change list: an object that is
	 * neither a date nor has a text form, or a resource (open or closed).
	 *
	 * @param mixed $value Any value.
	 */
	private static function is_dropped( $value ): bool {
		if ( is_object( $value ) ) {
			return ! ( $value instanceof \DateTimeInterface || $value instanceof \Stringable );
		}

		return ! ( null === $value || is_scalar( $value ) || is_array( $value ) );
	}

	/**
	 * Whether the keys are exactly 0..n-1 in order (an empty array is a
	 * list). Looks at the keys only; `array_is_list()` needs PHP 8.1.
	 *
	 * @param array<int|string, mixed> $data Array to inspect.
	 */
	private static function is_list( array $data ): bool {
		$expected = 0;
		foreach ( $data as $key => $unused ) {
			if ( $key !== $expected++ ) {
				return false;
			}
		}

		return true;
	}
}
