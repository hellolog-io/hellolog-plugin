<?php
/**
 * Declarative rules that thin out the events of one request.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Events;

defined( 'ABSPATH' ) || exit;

/**
 * One user action often fires several WordPress hooks, and each of them
 * produces an event: publishing a post also "updates" it, a registration also
 * "changes the role", an order status change also writes a system note. These
 * rules say which of those events say the same thing less precisely, so only
 * the most specific one is kept.
 *
 * The rules work on built payload arrays (see {@see EventBuilder::build()}),
 * never call into WordPress, and apply to the events of a single request in
 * dispatch order:
 *
 * 1. Suppress. A payload is dropped when one of the codes listed for it
 *    exists on the same target, unless a condition says this payload is
 *    different. Which codes exist is decided on the input, before anything is
 *    dropped, so a chain (A beats B beats C) removes both B and C.
 * 2. Exact duplicates (same code, target, changes and message) collapse to
 *    the first.
 * 3. Merge. Payloads of a merge code on the same target become one, at the
 *    position of the first, with their changes folded together.
 * 4. Drop empty. A payload of a drop-empty code that has no changes left is
 *    removed.
 *
 * A payload without a target takes part in nothing but exact-duplicate
 * removal.
 */
final class SuppressionRules {

	/** Wire limits of `changes`, the same as the ones {@see EventBuilder} applies. */
	private const MAX_CHANGES      = 50;
	private const MAX_CHANGE_FIELD = 64;

	private const ELLIPSIS = '…';

	/**
	 * Code => codes that make it redundant.
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $suppress = [];

	/**
	 * Codes whose payloads on one target are folded into one.
	 *
	 * @var array<int, int>
	 */
	private array $merge = [];

	/**
	 * Codes dropped when they carry no changes.
	 *
	 * @var array<int, int>
	 */
	private array $drop_empty = [];

	/**
	 * Code => test deciding whether that code's suppression applies to a payload.
	 *
	 * @var array<int, callable>
	 */
	private array $conditions = [];

	/**
	 * @param array<int, array<int, int>>         $suppress   Code => codes; the key code is dropped when any listed
	 *                                                        code exists for the same target.
	 * @param array<int, int>                     $merge      Codes whose payloads on one target are folded into one.
	 * @param array<int, int>                     $drop_empty Codes dropped when they carry no changes.
	 * @param array<int, callable(array): bool>   $conditions Code => test; when present, that code's suppression
	 *                                                        applies only to payloads for which it returns true.
	 */
	public function __construct( array $suppress = [], array $merge = [], array $drop_empty = [], array $conditions = [] ) {
		foreach ( $suppress as $code => $others ) {
			$this->suppress[ (int) $code ] = self::codes( (array) $others );
		}
		$this->merge      = self::codes( $merge );
		$this->drop_empty = self::codes( $drop_empty );
		foreach ( $conditions as $code => $condition ) {
			$this->conditions[ (int) $code ] = $condition;
		}
	}

	/**
	 * The built-in table.
	 */
	public static function defaults(): self {
		return new self(
			[
				2006 => [ 2000, 2001, 2002, 2003, 2004, 2005, 2007, 2008, 2009 ],
				2000 => [ 2001 ],
				4001 => [ 4000, 9300 ],
				4002 => [ 4000, 9300 ],
				4000 => [ 9300 ],
				9103 => [ 9101 ],
			],
			[ 2006, 4001, 4002 ],
			[ 2006, 4001 ],
			[
				// Only the notes WooCommerce writes itself repeat a status change;
				// a note a person added must stay.
				9103 => static fn( array $p ): bool => 'system' === ( $p['metadata']['note_type'] ?? '' ),
			]
		);
	}

	/**
	 * These rules plus the ones of `$other`; neither operand changes.
	 *
	 * Lists are unioned. When both sides hold a condition for the same code,
	 * both must hold, so adding rules can narrow a suppression but never
	 * silently widen one.
	 *
	 * @param self $other Rules to add.
	 */
	public function with( self $other ): self {
		$suppress = $this->suppress;
		foreach ( $other->suppress as $code => $codes ) {
			$suppress[ $code ] = array_merge( $suppress[ $code ] ?? [], $codes );
		}

		$conditions = $this->conditions;
		foreach ( $other->conditions as $code => $added ) {
			if ( isset( $conditions[ $code ] ) ) {
				$existing            = $conditions[ $code ];
				$conditions[ $code ] = static fn( array $p ): bool => $existing( $p ) && $added( $p );
				continue;
			}
			$conditions[ $code ] = $added;
		}

		return new self(
			$suppress,
			array_merge( $this->merge, $other->merge ),
			array_merge( $this->drop_empty, $other->drop_empty ),
			$conditions
		);
	}

	/**
	 * Thin out the events of one request.
	 *
	 * @param array<int, array<string, mixed>> $payloads Built payloads in dispatch order.
	 *
	 * @return array<int, array<string, mixed>> The survivors, as a list, in order.
	 */
	public function apply( array $payloads ): array {
		$payloads = array_values( $payloads );
		$present  = self::presence( $payloads );

		$kept = [];
		$seen = [];
		foreach ( $payloads as $payload ) {
			if ( $this->is_suppressed( $payload, $present ) ) {
				continue;
			}

			$fingerprint = self::fingerprint( $payload );
			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}
			$seen[ $fingerprint ] = true;

			$kept[] = $payload;
		}

		return $this->prune_empty( $this->fold_merges( $kept ) );
	}

	/**
	 * Which codes exist on which target, over the whole input.
	 *
	 * @param array<int, array<string, mixed>> $payloads Payloads.
	 *
	 * @return array<string, array<int, bool>> Target key => code => true.
	 */
	private static function presence( array $payloads ): array {
		$present = [];
		foreach ( $payloads as $payload ) {
			$key = self::target_key( $payload );
			if ( null !== $key ) {
				$present[ $key ][ self::code_of( $payload ) ] = true;
			}
		}

		return $present;
	}

	/**
	 * Whether another event on the same target makes this one redundant.
	 *
	 * @param array<string, mixed>             $payload Payload.
	 * @param array<string, array<int, bool>>  $present Output of {@see self::presence()}.
	 */
	private function is_suppressed( array $payload, array $present ): bool {
		$key = self::target_key( $payload );
		if ( null === $key ) {
			return false;
		}

		$code = self::code_of( $payload );
		foreach ( $this->suppress[ $code ] ?? [] as $other ) {
			if ( isset( $present[ $key ][ $other ] ) ) {
				// Asked only now: a test callable never runs for a payload
				// nothing could suppress.
				return ! isset( $this->conditions[ $code ] ) || (bool) ( $this->conditions[ $code ] )( $payload );
			}
		}

		return false;
	}

	/**
	 * Fold the payloads of a merge code on one target into the first of them.
	 *
	 * @param array<int, array<string, mixed>> $payloads Payloads.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fold_merges( array $payloads ): array {
		$result = [];
		$groups = [];
		$folded = [];
		foreach ( $payloads as $payload ) {
			$key  = self::target_key( $payload );
			$code = self::code_of( $payload );

			if ( null !== $key && in_array( $code, $this->merge, true ) ) {
				$group = $code . '|' . $key;
				if ( isset( $groups[ $group ] ) ) {
					$position = $groups[ $group ];
					$earlier  = $result[ $position ];

					$earlier['changes']  = ChangeSet::merge( self::changes_of( $earlier ), self::changes_of( $payload ) );
					$result[ $position ] = $earlier;
					$folded[ $position ] = true;
					continue;
				}
				$groups[ $group ] = count( $result );
			}

			$result[] = $payload;
		}

		// Clamp once, after folding: a change that cancels out later should
		// not have taken up a slot of the limit on the way.
		foreach ( array_keys( $folded ) as $position ) {
			$changes = self::clamp_changes( self::changes_of( $result[ $position ] ) );
			if ( [] === $changes ) {
				unset( $result[ $position ]['changes'] );
				continue;
			}
			$result[ $position ]['changes'] = $changes;
		}

		return $result;
	}

	/**
	 * Remove the payloads of a drop-empty code that carry no changes.
	 *
	 * @param array<int, array<string, mixed>> $payloads Payloads.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function prune_empty( array $payloads ): array {
		$result = [];
		foreach ( $payloads as $payload ) {
			if (
				null !== self::target_key( $payload )
				&& in_array( self::code_of( $payload ), $this->drop_empty, true )
				&& [] === self::changes_of( $payload )
			) {
				continue;
			}
			$result[] = $payload;
		}

		return $result;
	}

	/**
	 * `type:id` of the payload's target; null when it has no usable one.
	 *
	 * @param array<string, mixed> $payload Payload.
	 */
	private static function target_key( array $payload ): ?string {
		$target = $payload['target'] ?? null;
		if ( ! is_array( $target ) || ! is_scalar( $target['type'] ?? null ) || ! is_scalar( $target['id'] ?? null ) ) {
			return null;
		}

		$type = (string) $target['type'];
		$id   = (string) $target['id'];

		return ( '' === $type || '' === $id ) ? null : $type . ':' . $id;
	}

	/**
	 * What makes two payloads "the same event": code, target, changes and message.
	 *
	 * @param array<string, mixed> $payload Payload.
	 */
	private static function fingerprint( array $payload ): string {
		$parts = [
			self::code_of( $payload ),
			self::target_key( $payload ),
			self::changes_of( $payload ),
			$payload['message'] ?? null,
		];

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Never stored or unserialized: only a stable string to compare payloads by.
		return md5( serialize( $parts ) );
	}

	/**
	 * @param array<string, mixed> $payload Payload.
	 */
	private static function code_of( array $payload ): int {
		return (int) ( $payload['code'] ?? 0 );
	}

	/**
	 * The payload's `changes`, or an empty list when it has none.
	 *
	 * @param array<string, mixed> $payload Payload.
	 *
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	private static function changes_of( array $payload ): array {
		return isset( $payload['changes'] ) && is_array( $payload['changes'] ) ? $payload['changes'] : [];
	}

	/**
	 * A folded list is built from two lists the builder already clamped, so it
	 * can only break the wire limits by being longer: keep the first 50, and
	 * cut each item to its limits regardless.
	 *
	 * @param array<int, array{field: string, old: ?string, new: ?string}> $changes Folded changes.
	 *
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	private static function clamp_changes( array $changes ): array {
		$clamped = [];
		foreach ( array_slice( array_values( $changes ), 0, self::MAX_CHANGES ) as $item ) {
			$clamped[] = [
				'field' => self::clamp( $item['field'], self::MAX_CHANGE_FIELD ),
				'old'   => null === $item['old'] ? null : self::clamp( $item['old'], ChangeSet::MAX_VALUE_LENGTH ),
				'new'   => null === $item['new'] ? null : self::clamp( $item['new'], ChangeSet::MAX_VALUE_LENGTH ),
			];
		}

		return $clamped;
	}

	/**
	 * Cut to `$max` characters, the ellipsis included.
	 *
	 * @param string $text Text.
	 * @param int    $max  Longest result, in characters.
	 */
	private static function clamp( string $text, int $max ): string {
		if ( mb_strlen( $text, 'UTF-8' ) <= $max ) {
			return $text;
		}

		return mb_substr( $text, 0, $max - 1, 'UTF-8' ) . self::ELLIPSIS;
	}

	/**
	 * Codes as a de-duplicated list of integers.
	 *
	 * @param array<int|string, mixed> $codes Codes.
	 *
	 * @return array<int, int>
	 */
	private static function codes( array $codes ): array {
		return array_values( array_unique( array_map( 'intval', $codes ) ) );
	}
}
