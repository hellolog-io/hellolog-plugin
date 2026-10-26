<?php
/**
 * Turns before/after snapshots into an old-to-new change list.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Events;

use HelloLog\Sensors\SensitiveOption;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helper behind every "X changed from A to B" event. It never calls into
 * WordPress, so it can run before, after or outside any hook.
 *
 * Every item is exactly `[ 'field' => string, 'old' => ?string, 'new' => ?string ]`
 * and the list is always 0-indexed. Values are strings or null — never numbers
 * or booleans — and are cut to {@see self::MAX_VALUE_LENGTH} characters.
 *
 * Some items carry no readable values and only say "this field changed":
 * fields that hold content or a password (both sides null), credential-like
 * names (both sides masked) and long values whose cut-off texts would look
 * identical (both sides null).
 */
final class ChangeSet {

	/** Longest `old`/`new` value, in characters, the ellipsis included. */
	public const MAX_VALUE_LENGTH = 1024;

	private const ELLIPSIS = '…';

	/**
	 * Deepest array level that is still written out; anything below becomes
	 * `…`. Keeps a self-referencing array from recursing forever.
	 */
	private const MAX_DEPTH = 10;

	/**
	 * Fields whose value is a password hash. Never sent, not even masked.
	 */
	private const PASSWORD_FIELDS = [ 'password', 'user_pass' ];

	/**
	 * Compare two snapshots field by field.
	 *
	 * Fields are compared by their stringified value, so `5` and `'5'`, or a
	 * list in a different order, are not a change. The comparison runs on the
	 * full value; only the reported values are truncated.
	 *
	 * @param array<int|string, mixed> $before         Snapshot before the change.
	 * @param array<int|string, mixed> $after          Snapshot after the change.
	 * @param array<int, int|string>   $fields         Names of the fields to compare; the result follows this order.
	 * @param array<int, int|string>   $content_fields Names of fields reported as "changed" without values.
	 *
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function diff( array $before, array $after, array $fields, array $content_fields = [] ): array {
		$content = [];
		foreach ( $content_fields as $name ) {
			$content[ (string) $name ] = true;
		}

		$changes = [];
		$seen    = [];
		foreach ( $fields as $field ) {
			if ( ! is_int( $field ) && ! is_string( $field ) ) {
				continue;
			}

			$name = (string) $field;
			if ( isset( $seen[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;

			$old = self::normalize( $before[ $name ] ?? null );
			$new = self::normalize( $after[ $name ] ?? null );
			if ( $old === $new ) {
				continue;
			}

			if ( isset( $content[ $name ] ) || in_array( strtolower( $name ), self::PASSWORD_FIELDS, true ) ) {
				$old = null;
				$new = null;
			} elseif ( SensitiveOption::is_sensitive( $name ) ) {
				$old = SensitiveOption::REDACTED;
				$new = SensitiveOption::REDACTED;
			} else {
				$old_cut = self::truncate( $old );
				$new_cut = self::truncate( $new );

				// Values that only differ past the cut-off would read as "no
				// change" (and merge() would drop them), so they become a
				// plain "changed" flag instead.
				$old = $old_cut === $new_cut ? null : $old_cut;
				$new = $old_cut === $new_cut ? null : $new_cut;
			}

			$changes[] = [
				'field' => $name,
				'old'   => $old,
				'new'   => $new,
			];
		}

		return $changes;
	}

	/**
	 * The loggable text of a single value.
	 *
	 * A null stays null, booleans read `yes`/`no`, numbers and strings are
	 * cast, a list becomes its sorted values joined by `, ` and any other
	 * array becomes JSON with its keys sorted at every depth. The result is
	 * cut to {@see self::MAX_VALUE_LENGTH} characters, ending in `…`.
	 *
	 * @param mixed $v Any value.
	 */
	public static function stringify( $v ): ?string {
		return self::truncate( self::normalize( $v ) );
	}

	/**
	 * Fold two change lists, `$a` first in time, into one.
	 *
	 * A field present in both keeps the `old` of `$a` and the `new` of `$b`;
	 * it keeps the position of its first appearance. A field whose final `old`
	 * equals its final `new` has returned to where it started and is dropped.
	 *
	 * Items that carry no values — content and password fields and long values
	 * cut to the same text (null/null), masked credentials (`[redacted]` on
	 * both sides) — look like "no change" but are not: folding can't tell
	 * whether they returned to the start, so they stay.
	 *
	 * @param array<int, array{field: string, old: ?string, new: ?string}> $a Earlier changes.
	 * @param array<int, array{field: string, old: ?string, new: ?string}> $b Later changes.
	 *
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function merge( array $a, array $b ): array {
		$folded = [];
		foreach ( array_merge( array_values( $a ), array_values( $b ) ) as $item ) {
			$field  = (string) ( $item['field'] ?? '' );
			$old    = $item['old'] ?? null;
			$new    = $item['new'] ?? null;
			$opaque = self::is_valueless( $old, $new );

			if ( isset( $folded[ $field ] ) ) {
				$folded[ $field ]['new']    = $new;
				$folded[ $field ]['opaque'] = $folded[ $field ]['opaque'] || $opaque;
				continue;
			}

			$folded[ $field ] = [
				'field'  => $field,
				'old'    => $old,
				'new'    => $new,
				'opaque' => $opaque,
			];
		}

		$merged = [];
		foreach ( $folded as $entry ) {
			if ( ! $entry['opaque'] && $entry['old'] === $entry['new'] ) {
				continue;
			}

			$merged[] = [
				'field' => $entry['field'],
				'old'   => $entry['old'],
				'new'   => $entry['new'],
			];
		}

		return $merged;
	}

	/**
	 * Whether an item is a pure "changed" flag: equal sides that are null or
	 * the credential mask, not a real value.
	 *
	 * @param ?string $old Old side.
	 * @param ?string $new New side.
	 */
	private static function is_valueless( ?string $old, ?string $new ): bool {
		return $old === $new && ( null === $old || SensitiveOption::REDACTED === $old );
	}

	/**
	 * Full, untruncated text of a value — what two snapshots are compared on.
	 *
	 * @param mixed $v     Any value.
	 * @param int   $depth Array nesting level of `$v`.
	 */
	private static function normalize( $v, int $depth = 0 ): ?string {
		if ( null === $v ) {
			return null;
		}
		if ( is_bool( $v ) ) {
			return $v ? 'yes' : 'no';
		}
		if ( is_float( $v ) && ! is_finite( $v ) ) {
			// Casting NAN/INF to string raises a warning on newer PHP versions.
			return is_nan( $v ) ? 'NAN' : ( $v > 0 ? 'INF' : '-INF' );
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			return (string) $v;
		}
		if ( is_string( $v ) ) {
			return self::scrub( $v );
		}
		if ( is_object( $v ) ) {
			if ( $v instanceof \Stringable ) {
				return self::scrub( (string) $v );
			}
			$v = get_object_vars( $v );
		}
		if ( is_array( $v ) ) {
			if ( $depth > self::MAX_DEPTH ) {
				return self::ELLIPSIS;
			}

			return self::is_list( $v ) ? self::join_list( $v, $depth ) : self::encode( $v, $depth );
		}

		// Resources and anything else without a readable form.
		return null;
	}

	/**
	 * Whether the keys are exactly 0..n-1 in order. Looks at the keys only, so
	 * it stays cheap and safe on self-referencing arrays; `array_is_list()`
	 * needs PHP 8.1.
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

	/**
	 * A list is a set for our purposes: sorted, so a reorder is not a change.
	 *
	 * @param array<int, mixed> $list  List array.
	 * @param int               $depth Array nesting level of `$list`.
	 */
	private static function join_list( array $list, int $depth ): string {
		$parts = [];
		foreach ( $list as $item ) {
			$parts[] = self::normalize( $item, $depth + 1 ) ?? '';
		}
		sort( $parts, SORT_STRING );

		return implode( ', ', $parts );
	}

	/**
	 * JSON text of an associative array, keys sorted at every depth so the
	 * same data always gives the same string.
	 *
	 * @param array<int|string, mixed> $data  Associative array.
	 * @param int                      $depth Array nesting level of `$data`.
	 */
	private static function encode( array $data, int $depth ): string {
		$json = self::json( self::sort_keys( $data, $depth ) );

		return false === $json ? '<complex>' : $json;
	}

	/**
	 * Recursively sort the keys of every associative array. Lists keep their
	 * order. Builds a new array and never writes into the input, which may
	 * hold references to the caller's own variables.
	 *
	 * @param mixed $v     Any value.
	 * @param int   $depth Array nesting level of `$v`.
	 *
	 * @return mixed
	 */
	private static function sort_keys( $v, int $depth ) {
		if ( ! is_array( $v ) ) {
			return $v;
		}
		if ( $depth > self::MAX_DEPTH ) {
			return self::ELLIPSIS;
		}

		$sorted = [];
		foreach ( $v as $key => $item ) {
			$sorted[ $key ] = self::sort_keys( $item, $depth + 1 );
		}
		if ( ! self::is_list( $v ) ) {
			ksort( $sorted, SORT_STRING );
		}

		return $sorted;
	}

	/**
	 * Replace bytes that are not valid UTF-8 with U+FFFD, the way the JSON
	 * encoder does for array values, so every reported text is valid UTF-8.
	 *
	 * @param string $text Any text.
	 */
	private static function scrub( string $text ): string {
		if ( mb_check_encoding( $text, 'UTF-8' ) ) {
			return $text;
		}

		$json = self::json( $text );
		$out  = false === $json ? null : json_decode( $json );

		return is_string( $out ) ? $out : '';
	}

	/**
	 * Compact, deterministic JSON that never fails on bad UTF-8.
	 *
	 * @param mixed $v Any value.
	 *
	 * @return string|false
	 */
	private static function json( $v ) {
		// This helper must stay free of WordPress calls, so no wp_json_encode().
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return json_encode(
			$v,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
		);
	}

	/**
	 * Cut to {@see self::MAX_VALUE_LENGTH} characters, the `…` included.
	 *
	 * @param ?string $value Text to cut.
	 */
	private static function truncate( ?string $value ): ?string {
		if ( null === $value || mb_strlen( $value, 'UTF-8' ) <= self::MAX_VALUE_LENGTH ) {
			return $value;
		}

		return mb_substr( $value, 0, self::MAX_VALUE_LENGTH - 1, 'UTF-8' ) . self::ELLIPSIS;
	}
}
