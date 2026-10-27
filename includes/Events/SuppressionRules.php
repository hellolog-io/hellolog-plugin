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
 * "changes the role", an order status change also writes a note saying so. These
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
 *    dropped, so a chain (A beats B beats C) removes both B and C. A
 *    condition that throws counts as "not different": that payload stays.
 *    A dropped payload of a fold code hands its changes to a surviving
 *    payload on the same target of one of the codes listed for it (the last
 *    one before it, else the first one after it), folded in time order;
 *    without such a payload it is just dropped. Fields listed for its code
 *    (ones the event it folds into tells itself) stay out of what it hands
 *    over.
 * 2. Merge. Payloads of a merge code on the same target become one, at the
 *    position of the first, with their changes folded together.
 * 3. Drop empty. A payload of a drop-empty code that has no changes left is
 *    removed.
 * 4. Repeats collapse. A payload is dropped when it is the same as the last
 *    payload kept on its target (without a target: the last one kept of its
 *    code). Two payloads are the same when they match in everything but the
 *    moment they were built (`occurred_at`) and their `client_event_id`, so
 *    events that differ only in their metadata, vars, actor or links are
 *    both kept. Only the last kept one counts: a value that goes A to B, back
 *    to A and to B again keeps all three steps, or the record would end on
 *    the wrong value.
 *
 * A payload without a target takes part in nothing but the removal of
 * repeats.
 *
 * On a network every payload may carry the site it happened on under
 * {@see self::BLOG_KEY}. The site is part of the target (`post:42` is a
 * different post on every site) and of the code group of a payload without
 * a target, so the events of one site never thin out those of another.
 */
final class SuppressionRules {

	/**
	 * Payload key of the site (blog id) an event was dispatched on. Set and
	 * removed again by {@see BufferedDispatcher}: it is never sent.
	 */
	public const BLOG_KEY = '_blog_id';

	/** Wire limits of `changes`, the same as the ones {@see EventBuilder} applies. */
	private const MAX_CHANGES      = 50;
	private const MAX_CHANGE_FIELD = 64;

	private const ELLIPSIS = '…';

	/**
	 * The roles a site hands to new users on its own (WordPress's default
	 * role, the shop's customer role). Only their first assignment is told by
	 * the registration event; every other role is logged.
	 *
	 * @var array<int, string>
	 */
	private const DEFAULT_ROLES = [ 'customer', 'subscriber' ];

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
	 * Code => codes a suppressed payload of that code folds its changes into.
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $fold = [];

	/**
	 * Code => fields a suppressed payload of that code leaves out of the
	 * changes it folds.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $fold_without = [];

	/**
	 * @param array<int, array<int, int>>         $suppress   Code => codes; the key code is dropped when any listed
	 *                                                        code exists for the same target.
	 * @param array<int, int>                     $merge      Codes whose payloads on one target are folded into one.
	 * @param array<int, int>                     $drop_empty Codes dropped when they carry no changes.
	 * @param array<int, callable(array): bool>   $conditions Code => test; when present, that code's suppression
	 *                                                        applies only to payloads for which it returns true.
	 * @param array<int, array<int, int>>         $fold       Code => codes; a suppressed payload of the key code
	 *                                                        folds its changes into a surviving payload of a listed
	 *                                                        code on the same target instead of losing them.
	 * @param array<int, array<int, string>>      $fold_without Code => fields; a suppressed payload of the key code
	 *                                                        folds its changes without these fields, which the
	 *                                                        event it folds into tells itself.
	 */
	public function __construct( array $suppress = [], array $merge = [], array $drop_empty = [], array $conditions = [], array $fold = [], array $fold_without = [] ) {
		foreach ( $suppress as $code => $others ) {
			$this->suppress[ (int) $code ] = self::codes( (array) $others );
		}
		$this->merge      = self::codes( $merge );
		$this->drop_empty = self::codes( $drop_empty );
		foreach ( $conditions as $code => $condition ) {
			$this->conditions[ (int) $code ] = $condition;
		}
		foreach ( $fold as $code => $into ) {
			$this->fold[ (int) $code ] = self::codes( (array) $into );
		}
		foreach ( $fold_without as $code => $fields ) {
			$this->fold_without[ (int) $code ] = self::fields( (array) $fields );
		}
	}

	/**
	 * The built-in table.
	 */
	public static function defaults(): self {
		return new self(
			[
				2006 => [ 2000, 2001, 2002, 2003, 2004, 2005, 2007, 2008, 2009, 2013 ],
				2000 => [ 2001 ],
				4001 => [ 4000, 9300 ],
				4002 => [ 4000, 9300 ],
				4004 => [ 4000, 9300 ],
				4005 => [ 4000, 9300 ],
				4000 => [ 9300 ],
				9103 => [ 9101 ],
				2011 => [ 2010, 2012 ],

				// An order's trash, restore or permanent delete that WooCommerce
				// tells itself hides the post event of the same order; the post
				// event stays for the screens that fire no WooCommerce hook.
				2003 => [ 9109 ],
				2004 => [ 9110 ],
				2005 => [ 9102 ],

				// An order edit says nothing of its own next to the order's
				// creation, status change, restore, trash or delete in the same
				// request (it goes into them, see the folds below). Item events
				// say nothing next to the order's creation (creating an order
				// removes no item) or its permanent delete; a trash hides none of
				// them.
				9105 => [ 9100, 9101, 9102, 9109, 9110 ],
				9106 => [ 9100, 9102 ],
				9107 => [ 9102 ],
				9108 => [ 9100, 9102 ],

				// A status change out of the trash is the restore.
				9101 => [ 9110 ],

				// A product edit says nothing of its own next to the product's
				// creation (it tells no changes) or permanent delete, and goes
				// into its trash, restore or revert (see the folds below).
				9001 => [ 9000, 9002, 2003, 2004, 2013 ],
			],
			// Role changes are not merged: folding two of them would keep the
			// message of the first and understate the final privilege. A
			// restore can arrive from up to three hooks of one request. A
			// product edit can arrive from WordPress's write of the post, its
			// terms, its status change and two saves of the shop. Item changes
			// are not merged either: every item of an order is on the order's
			// target, and folding two would mix their quantities under the
			// first one's name.
			[ 2006, 4001, 2121, 2401, 2011, 9105, 9110, 9001 ],
			// A menu save without changes of its own stays: it saved the
			// menu's items.
			[ 2006, 4001, 2121, 2011, 9105, 9001, 9108 ],
			[
				// Only the note that is nothing but WooCommerce's sentence about the
				// status change repeats it, whoever it is filed under. Everything
				// else stays: what the shop notes about a payment or stock, a note
				// for the customer, and any text of a person.
				9103 => static fn( array $p ): bool => 'status' === ( $p['metadata']['note_type'] ?? '' ),

				// A shop sign-up says it all only for the role the shop gives
				// its customers. Any other role is a privilege handed out during
				// the sign-up and must stay on record with the registration.
				4000 => static fn( array $p ): bool => 'customer' === ( $p['vars']['role'] ?? null ),

				// Only the plain default role a new user starts with is part of the
				// registration. Any other first role (a multisite grant after the
				// default one, an editor, a shop manager) and any later change,
				// from a role the user already had, is an event of its own.
				4002 => static fn( array $p ): bool => self::is_first_default_role( $p ),

				// Only a status change from the trash is a restore; any other
				// status change of a restored order stays.
				9101 => static fn( array $p ): bool => 'trash' === ( $p['metadata']['old_status'] ?? null ),
			],
			[
				// A post update the status event of its post makes redundant still
				// says what else changed (the classic editor sets the sticky flag
				// only after the save that changed the status): its changes go into
				// the status event. A creation tells no changes, and the last update
				// of a post that is trashed or deleted says nothing that matters,
				// so under those it is just dropped.
				2006 => [ 2001, 2002, 2004, 2007, 2008, 2009, 2013 ],

				// An upload that sets the alt text in the same request is one
				// upload carrying it; the edits of a file deleted in the same
				// request are just dropped.
				2011 => [ 2010 ],

				// An order edit is never lost to another event of its order: it
				// goes into the status change of its save, the restore, the trash
				// or the delete, so a trash cannot hide a change of the total.
				// Only under the creation alone it is dropped: a creation tells
				// no changes.
				9105 => [ 9101, 9110, 9109, 9102 ],

				// A product edit made in the request that trashes, restores or
				// reverts the product goes into that event.
				9001 => [ 2003, 2004, 2013 ],
			],
			[
				// A product edit folded into the trash, restore or revert of its
				// product leaves its status out: that event says it.
				9001 => [ 'status' ],
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

		$fold = $this->fold;
		foreach ( $other->fold as $code => $codes ) {
			$fold[ $code ] = array_merge( $fold[ $code ] ?? [], $codes );
		}

		$fold_without = $this->fold_without;
		foreach ( $other->fold_without as $code => $fields ) {
			$fold_without[ $code ] = array_merge( $fold_without[ $code ] ?? [], $fields );
		}

		return new self(
			$suppress,
			array_merge( $this->merge, $other->merge ),
			array_merge( $this->drop_empty, $other->drop_empty ),
			$conditions,
			$fold,
			$fold_without
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

		$suppressed = [];
		foreach ( $payloads as $index => $payload ) {
			$suppressed[ $index ] = $this->is_suppressed( $payload, $present );
		}

		$kept = [];
		foreach ( $this->fold_suppressed( $payloads, $suppressed ) as $index => $payload ) {
			if ( ! $suppressed[ $index ] ) {
				$kept[] = $payload;
			}
		}

		return self::without_duplicates( $this->prune_empty( $this->fold_merges( $kept ) ) );
	}

	/**
	 * Hand the changes of every suppressed payload of a fold code to the
	 * surviving payload it folds into, see {@see self::fold_target()}. The
	 * changes of that payload and of everything folded into it are folded
	 * in dispatch order (earliest `old`, latest `new`, a field back where it
	 * started is dropped) and clamped to the wire limits; its
	 * `metadata.changed`, when it has one, follows them. A folded payload
	 * hands over its changes without the fields listed for its code.
	 *
	 * @param array<int, array<string, mixed>> $payloads   Payloads, as a list.
	 * @param array<int, bool>                 $suppressed Index => whether the payload is suppressed.
	 *
	 * @return array<int, array<string, mixed>> The payloads, same indexes, the fold targets updated.
	 */
	private function fold_suppressed( array $payloads, array $suppressed ): array {
		$into = [];
		foreach ( $payloads as $index => $payload ) {
			$codes = $this->fold[ self::code_of( $payload ) ] ?? [];
			$key   = self::target_key( $payload );
			if ( ! $suppressed[ $index ] || [] === $codes || null === $key ) {
				continue;
			}

			$target = self::fold_target( $payloads, $suppressed, $index, $key, $codes );
			if ( null !== $target ) {
				$into[ $target ][] = $index;
			}
		}

		foreach ( $into as $target => $folded ) {
			$order = array_merge( $folded, [ $target ] );
			sort( $order );

			$changes = [];
			foreach ( $order as $index ) {
				$items = self::changes_of( $payloads[ $index ] );
				if ( $index !== $target ) {
					$items = $this->foldable( $payloads[ $index ], $items );
				}
				$changes = ChangeSet::merge( $changes, $items );
			}
			$changes = self::clamp_changes( $changes );

			$payload = self::with_changed_fields( $payloads[ $target ], $changes );
			unset( $payload['changes'] );
			if ( [] !== $changes ) {
				$payload['changes'] = $changes;
			}
			$payloads[ $target ] = $payload;
		}

		return $payloads;
	}

	/**
	 * The changes a suppressed payload hands over when it folds: all but the
	 * fields listed for its code.
	 *
	 * @param array<string, mixed>                                         $payload The folded payload.
	 * @param array<int, array{field: string, old: ?string, new: ?string}> $changes Its changes.
	 *
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	private function foldable( array $payload, array $changes ): array {
		$without = $this->fold_without[ self::code_of( $payload ) ] ?? [];
		if ( [] === $without ) {
			return $changes;
		}

		return array_values(
			array_filter(
				$changes,
				static fn( $item ): bool => ! is_array( $item ) || ! in_array( (string) ( $item['field'] ?? '' ), $without, true )
			)
		);
	}

	/**
	 * The surviving payload of one of `$codes` on the target `$key` that the
	 * suppressed payload at `$index` folds into: the last one before it in
	 * dispatch order, else the first one after it; null when there is none.
	 *
	 * @param array<int, array<string, mixed>> $payloads   Payloads, as a list.
	 * @param array<int, bool>                 $suppressed Index => whether the payload is suppressed.
	 * @param int                              $index      Index of the suppressed payload.
	 * @param string                           $key        Its target key.
	 * @param array<int, int>                  $codes      Codes it folds into.
	 */
	private static function fold_target( array $payloads, array $suppressed, int $index, string $key, array $codes ): ?int {
		$before = null;
		$after  = null;
		foreach ( $payloads as $candidate => $payload ) {
			if ( $suppressed[ $candidate ] || ! in_array( self::code_of( $payload ), $codes, true ) || self::target_key( $payload ) !== $key ) {
				continue;
			}
			if ( $candidate < $index ) {
				$before = $candidate;
			} elseif ( null === $after ) {
				$after = $candidate;
			}
		}

		return $before ?? $after;
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
				return ! isset( $this->conditions[ $code ] ) || $this->condition_holds( $this->conditions[ $code ], $payload );
			}
		}

		return false;
	}

	/**
	 * Run a condition. One that throws is treated as not holding, so the
	 * payload stays: a broken test must not decide what is audited, nor stop
	 * the other payloads from being thinned out.
	 *
	 * @param callable             $condition Condition of the payload's code.
	 * @param array<string, mixed> $payload   Payload.
	 */
	private function condition_holds( callable $condition, array $payload ): bool {
		try {
			return (bool) $condition( $payload );
		} catch ( \Throwable $e ) {
			return false;
		}
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
			$changes             = self::clamp_changes( self::changes_of( $result[ $position ] ) );
			$result[ $position ] = self::with_changed_fields( $result[ $position ], $changes );
			if ( [] === $changes ) {
				unset( $result[ $position ]['changes'] );
				continue;
			}
			$result[ $position ]['changes'] = $changes;
		}

		return $result;
	}

	/**
	 * A folded payload that lists the names of its changed fields in
	 * `metadata.changed` (the post update does) lists those of the folded
	 * changes, not only those of the first payload. A payload without that
	 * list is returned as it is.
	 *
	 * @param array<string, mixed>                                         $payload Folded payload.
	 * @param array<int, array{field: string, old: ?string, new: ?string}> $changes Its folded changes.
	 *
	 * @return array<string, mixed>
	 */
	private static function with_changed_fields( array $payload, array $changes ): array {
		if ( ! isset( $payload['metadata'] ) || ! is_array( $payload['metadata'] ) || ! array_key_exists( 'changed', $payload['metadata'] ) ) {
			return $payload;
		}

		$payload['metadata']['changed'] = array_column( $changes, 'field' );

		return $payload;
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
	 * Whether a role change is only the plain default role a new user starts
	 * with: its `role` item has no previous role and the new role is one of
	 * {@see self::DEFAULT_ROLES}. A payload with no `role` item is not one.
	 *
	 * @param array<string, mixed> $payload Payload.
	 */
	private static function is_first_default_role( array $payload ): bool {
		foreach ( self::changes_of( $payload ) as $item ) {
			if ( 'role' === ( $item['field'] ?? null ) ) {
				$old = $item['old'] ?? null;

				return ( null === $old || '' === $old ) && in_array( $item['new'] ?? null, self::DEFAULT_ROLES, true );
			}
		}

		return false;
	}

	/**
	 * `site|type:id` of the payload's target; null when it has no usable one.
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

		return ( '' === $type || '' === $id ) ? null : self::site_of( $payload ) . '|' . $type . ':' . $id;
	}

	/**
	 * The site a payload was dispatched on, as text; empty when it does not
	 * say.
	 *
	 * @param array<string, mixed> $payload Payload.
	 */
	private static function site_of( array $payload ): string {
		$site = $payload[ self::BLOG_KEY ] ?? null;

		return is_int( $site ) || ( is_string( $site ) && ctype_digit( $site ) ) ? (string) $site : '';
	}

	/**
	 * Drop every payload that is the same as the last payload kept on its
	 * target, or, without a target, the last one kept of its code (on its
	 * site).
	 *
	 * @param array<int, array<string, mixed>> $payloads Payloads.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function without_duplicates( array $payloads ): array {
		$unique = [];
		$last   = [];
		foreach ( $payloads as $payload ) {
			$key         = self::target_key( $payload );
			$group       = null === $key ? 'code|' . self::site_of( $payload ) . '|' . self::code_of( $payload ) : 'target|' . $key;
			$fingerprint = self::fingerprint( $payload );
			if ( ( $last[ $group ] ?? null ) === $fingerprint ) {
				continue;
			}
			$last[ $group ] = $fingerprint;
			$unique[]       = $payload;
		}

		return $unique;
	}

	/**
	 * What makes two payloads "the same event": everything but the time it was
	 * built and its client id, with the keys of every associative array in a
	 * fixed order.
	 *
	 * @param array<string, mixed> $payload Payload.
	 */
	private static function fingerprint( array $payload ): string {
		unset( $payload['occurred_at'], $payload['client_event_id'] );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Never stored or unserialized: only a stable string to compare payloads by.
		return md5( serialize( self::sorted( $payload ) ) );
	}

	/**
	 * The value with the keys of every associative array sorted; lists keep
	 * their order.
	 *
	 * @param mixed $value Any value.
	 *
	 * @return mixed
	 */
	private static function sorted( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$sorted = [];
		foreach ( $value as $key => $item ) {
			$sorted[ $key ] = self::sorted( $item );
		}

		// `array_is_list()` needs PHP 8.1.
		$list = [] === $sorted || array_keys( $sorted ) === range( 0, count( $sorted ) - 1 );
		if ( ! $list ) {
			ksort( $sorted, SORT_STRING );
		}

		return $sorted;
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
	 * Field names as a de-duplicated list of strings.
	 *
	 * @param array<int|string, mixed> $fields Field names.
	 *
	 * @return array<int, string>
	 */
	private static function fields( array $fields ): array {
		return array_values( array_unique( array_map( 'strval', array_filter( $fields, 'is_scalar' ) ) ) );
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
