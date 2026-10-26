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
 * names (both sides masked) and values whose reported texts would look
 * identical (both sides null): long values cut to the same text, and arrays
 * that only differ in a credential-like key.
 *
 * Arrays and objects are written out in full, so a credential-like KEY inside
 * one (`secret_key`, `access_token`, `iban`…) has its value replaced by
 * {@see SensitiveOption::REDACTED} at every depth, in every reported text.
 * So does the `value` of a name/value pair whose `name`, `key` or `id` is
 * credential-like (`{"name":"secret_key","value":"…"}`), the way settings
 * screens store their fields. The comparison still sees the real values, so
 * a changed secret is never lost, only never shown.
 */
final class ChangeSet {

	/** Longest `old`/`new` value, in characters, the ellipsis included. */
	public const MAX_VALUE_LENGTH = 1024;

	private const ELLIPSIS = '…';

	/**
	 * The `user:password@` part of a URL: everything after `scheme://` up to
	 * the LAST `@` before the first `/`, `?`, `#` or space (the authority
	 * ends there). Greedy on purpose: a password may hold `@` itself
	 * (`mysql://root:p@ss@host/db`), and the host never does. The slashes may
	 * be escaped the way JSON writes them (`https:\/\/`), with any number of
	 * backslashes for text that was escaped more than once.
	 *
	 * A scheme is only tried from the start of a run of scheme characters
	 * (the lookbehind), and a run's leading digits and signs are taken without
	 * giving any back (possessive), so a long run is read once, not once from
	 * every position in it: the time stays linear even where PCRE has no JIT.
	 * The scheme still starts at its first letter, as before.
	 */
	private const URL_USERINFO = '~(?<![a-z0-9+.\-])([0-9+.\-]*+[a-z][a-z0-9+.\-]*:(?:\\\\*/){2})[^\s/?#]*@~i';

	/**
	 * A `?name=value` or `&name=value` query parameter. The value ends at the
	 * next `&`, `#`, `?` or space, so a URL nested in a value has its own
	 * parameters looked at too. Name and value are taken whole (possessive)
	 * and neither can hold the `?`/`&` a match starts with, so every
	 * character is read once: linear even where PCRE has no JIT.
	 */
	private const QUERY_PARAMETER = '~([?&])([^=&#?\s]++)=([^&#?\s]++)~';

	/**
	 * The keys that name the field of a name/value pair; its `value` is masked
	 * when one of them is credential-like.
	 *
	 * @var array<int, string>
	 */
	private const PAIR_NAME_KEYS = [ 'name', 'key', 'id' ];

	/**
	 * UTF-8 byte order mark. A JSON decoder refuses it, so it is dropped from
	 * the front of a text before decoding.
	 */
	private const BOM = "\xEF\xBB\xBF";

	/**
	 * Whitespace dropped from the front of a text before decoding: the JSON
	 * kind plus vertical tab and form feed.
	 */
	private const LEADING_SPACE = " \t\n\r\x0B\x0C";

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
	 * A string with the secrets inside it masked, see {@see self::stringify()}
	 * for the rules. For callers that report a scalar value on its own.
	 *
	 * @param string $text Any string.
	 */
	public static function redact_text( string $text ): string {
		return self::text( $text, 0, true );
	}

	/**
	 * Compare two snapshots field by field.
	 *
	 * Fields are compared by their stringified value, so `5` and `'5'`, or a
	 * list in a different order, are not a change. The comparison runs on the
	 * full, unmasked value; only the reported values are masked and truncated.
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
				// What is reported is the masked text: the comparison above ran
				// on the real values, the reported ones never carry a secret.
				$old_cut = self::truncate( self::normalize( $before[ $name ] ?? null, 0, true ) );
				$new_cut = self::truncate( self::normalize( $after[ $name ] ?? null, 0, true ) );

				// Values that only differ past the cut-off, or only in a masked
				// secret, would read as "no change" (and merge() would drop
				// them), so they become a plain "changed" flag instead.
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
	 * array becomes JSON with its keys sorted at every depth. The value of a
	 * credential-like key inside an array or object reads
	 * {@see SensitiveOption::REDACTED}. The result is cut to
	 * {@see self::MAX_VALUE_LENGTH} characters, ending in `…`.
	 *
	 * @param mixed $v Any value.
	 */
	public static function stringify( $v ): ?string {
		return self::truncate( self::normalize( $v, 0, true ) );
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
	 * With `$mask` the value of every credential-like key inside an array or
	 * object is replaced by {@see SensitiveOption::REDACTED}: that is the text
	 * that may be reported. Without it the text is for comparing only and must
	 * never leave this class.
	 *
	 * @param mixed $v     Any value.
	 * @param int   $depth Array nesting level of `$v`.
	 * @param bool  $mask  Whether to mask credential-like keys.
	 */
	private static function normalize( $v, int $depth = 0, bool $mask = false ): ?string {
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
			return self::text( $v, $depth, $mask );
		}
		if ( is_object( $v ) ) {
			if ( $v instanceof \Stringable ) {
				return self::text( (string) $v, $depth, $mask );
			}
			$v = get_object_vars( $v );
		}
		if ( is_array( $v ) ) {
			if ( $depth > self::MAX_DEPTH ) {
				return self::ELLIPSIS;
			}

			return self::is_list( $v ) ? self::join_list( $v, $depth, $mask ) : self::encode( $v, $depth, $mask );
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
	 * @param bool              $mask  Whether to mask credential-like keys.
	 */
	private static function join_list( array $list, int $depth, bool $mask ): string {
		$parts = [];
		foreach ( $list as $item ) {
			$parts[] = self::normalize( $item, $depth + 1, $mask ) ?? '';
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
	 * @param bool                     $mask  Whether to mask credential-like keys.
	 */
	private static function encode( array $data, int $depth, bool $mask ): string {
		$json = self::json( self::sort_keys( $data, $depth, $mask ) );

		return false === $json ? '<complex>' : $json;
	}

	/**
	 * Recursively sort the keys of every associative array. Lists keep their
	 * order. Builds a new array and never writes into the input, which may
	 * hold references to the caller's own variables. A nested object is read
	 * the way a top-level one is: its text if it has one, else its properties,
	 * so no object can carry a value past the masking.
	 *
	 * With `$mask`, the value of every credential-like key is replaced by
	 * {@see SensitiveOption::REDACTED}, whatever it holds, and so is the
	 * `value` of a secret name/value pair, see {@see self::is_secret_pair()}.
	 *
	 * @param mixed $v     Any value.
	 * @param int   $depth Array nesting level of `$v`.
	 * @param bool  $mask  Whether to mask credential-like keys.
	 *
	 * @return mixed
	 */
	private static function sort_keys( $v, int $depth, bool $mask ) {
		if ( is_object( $v ) ) {
			if ( $depth > self::MAX_DEPTH ) {
				return self::ELLIPSIS;
			}
			if ( $v instanceof \Stringable ) {
				return self::text( (string) $v, $depth, $mask );
			}
			$v = get_object_vars( $v );
		}
		if ( is_string( $v ) ) {
			return $mask ? self::mask_text( $v, $depth ) : $v;
		}
		if ( ! is_array( $v ) ) {
			return $v;
		}
		if ( $depth > self::MAX_DEPTH ) {
			return self::ELLIPSIS;
		}

		$secret_pair = $mask && self::is_secret_pair( $v );

		$sorted = [];
		foreach ( $v as $key => $item ) {
			if ( $mask && is_string( $key ) && ( SensitiveOption::is_sensitive( $key ) || ( $secret_pair && 'value' === $key ) ) ) {
				$sorted[ $key ] = SensitiveOption::REDACTED;
				continue;
			}
			$sorted[ $key ] = self::sort_keys( $item, $depth + 1, $mask );
		}
		if ( ! self::is_list( $v ) ) {
			ksort( $sorted, SORT_STRING );
		}

		return $sorted;
	}

	/**
	 * Whether an array is a name/value pair that holds a secret: it has a
	 * `value`, and its `name`, `key` or `id` is a credential-like name.
	 *
	 * @param array<int|string, mixed> $data Array to inspect.
	 */
	private static function is_secret_pair( array $data ): bool {
		if ( ! array_key_exists( 'value', $data ) ) {
			return false;
		}
		foreach ( self::PAIR_NAME_KEYS as $name_key ) {
			if ( isset( $data[ $name_key ] ) && is_string( $data[ $name_key ] ) && SensitiveOption::is_sensitive( $data[ $name_key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Text of a string value: valid UTF-8 and, with `$mask`, free of secrets.
	 *
	 * @param string $text  Any string.
	 * @param int    $depth Array nesting level of the value.
	 * @param bool   $mask  Whether to mask secrets inside the text.
	 */
	private static function text( string $text, int $depth, bool $mask ): string {
		$text = self::scrub( $text );

		return $mask ? self::mask_text( $text, $depth ) : $text;
	}

	/**
	 * A string can carry secrets of its own, in three shapes.
	 *
	 * Text that is a JSON string literal is unwrapped first, as many times
	 * over as it was encoded, and the two rules below apply to what is
	 * inside. Only {@see self::MAX_DEPTH} literals are unwrapped: text still
	 * wrapped past that cannot be looked into and becomes `…`, like a
	 * structure nested too deep, never the raw text.
	 *
	 * Text that is a JSON object or list is decoded, masked with the same
	 * credential-like-key rule as an array and ALWAYS written out again as
	 * compact JSON with sorted keys (the string literals it came in are
	 * dropped). It is never passed through as written, not even when nothing
	 * was masked: below the depth limit the structure is cut to `…` before
	 * any key could be seen, so only the rewrite keeps the raw text out.
	 *
	 * Any other text has the `user:password@` part of its URLs masked, and
	 * the value of every `?name=` or `&name=` query parameter whose name is
	 * credential-like (`?api_key=…`, `&token=…`). When it came out of string
	 * literals it is wrapped in the same number of literals again, written
	 * compact (`https://…`, not `https:\/\/…`), so an escaped URL is masked
	 * like a plain one.
	 *
	 * Whitespace and a byte order mark in front of a text do not hide its
	 * JSON. PHP-serialized text is NOT unserialized: that can instantiate
	 * objects chosen by whoever wrote the value.
	 *
	 * A text the masking cannot finish (the regex engine gives up, the
	 * encoder fails) becomes `…`: it is never reported unmasked.
	 *
	 * @param string $text  Valid UTF-8 text.
	 * @param int    $depth Array nesting level of the value.
	 */
	private static function mask_text( string $text, int $depth ): string {
		$layers = 0;
		$inner  = self::decode_literal( $text );
		while ( null !== $inner ) {
			if ( $layers >= self::MAX_DEPTH ) {
				return self::ELLIPSIS;
			}
			$text  = $inner;
			$inner = self::decode_literal( $text );
			++$layers;
		}

		$too_deep = false;
		$decoded  = self::decode_json( $text, $too_deep );
		if ( $too_deep ) {
			return self::ELLIPSIS;
		}
		if ( null !== $decoded ) {
			$masked = self::json( self::sort_keys( $decoded, $depth + 1, true ) );

			return false === $masked ? self::ELLIPSIS : $masked;
		}

		$masked = preg_replace( self::URL_USERINFO, '$1' . SensitiveOption::REDACTED . '@', $text );
		if ( null !== $masked ) {
			$masked = preg_replace_callback(
				self::QUERY_PARAMETER,
				static fn( array $m ): string => SensitiveOption::is_sensitive( urldecode( $m[2] ) )
					? $m[1] . $m[2] . '=' . SensitiveOption::REDACTED
					: $m[0],
				$masked
			);
		}
		for ( $i = 0; $i < $layers && null !== $masked; $i++ ) {
			$masked = self::json( $masked );
			if ( false === $masked ) {
				$masked = null;
			}
		}

		return $masked ?? self::ELLIPSIS;
	}

	/**
	 * The value of a JSON string literal; null when the text is not one.
	 *
	 * @param string $text Any text.
	 */
	private static function decode_literal( string $text ): ?string {
		$body = self::json_body( $text );
		if ( '"' !== ( $body[0] ?? '' ) ) {
			return null;
		}

		$value = json_decode( $body );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * The array or object a text is the JSON of; null for anything else.
	 *
	 * Text nested deeper than the decoder allows cannot be read at all; that
	 * is reported through `$too_deep` and the caller must not pass it on.
	 *
	 * @param string $text     Any text.
	 * @param bool   $too_deep Set to true when the text is JSON the decoder refused for its depth.
	 *
	 * @return array<int|string, mixed>|null
	 */
	private static function decode_json( string $text, bool &$too_deep ): ?array {
		$body  = self::json_body( $text );
		$first = $body[0] ?? '';
		if ( '{' !== $first && '[' !== $first ) {
			return null;
		}

		$decoded = json_decode( $body, true, 512, JSON_BIGINT_AS_STRING );
		if ( null === $decoded && JSON_ERROR_DEPTH === json_last_error() ) {
			$too_deep = true;
		}

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * The text as a JSON decoder must see it: without the whitespace and
	 * byte order marks in front of it, however many and in whatever mix.
	 * Used for decoding only; plain text is reported with its own leading
	 * characters.
	 *
	 * Plain string functions on purpose, one pass: a regex can give up on a
	 * long prefix (backtrack limit), and the unstripped text would then pass
	 * as plain text with its JSON unmasked.
	 *
	 * @param string $text Any text.
	 */
	private static function json_body( string $text ): string {
		$offset = 0;
		do {
			$start   = $offset;
			$offset += strspn( $text, self::LEADING_SPACE, $offset );
			while ( self::BOM === substr( $text, $offset, 3 ) ) {
				$offset += 3;
			}
		} while ( $offset !== $start );

		return 0 === $offset ? $text : substr( $text, $offset );
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
