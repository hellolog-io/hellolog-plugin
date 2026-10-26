<?php
/**
 * Tests for the v2 payload of {@see HelloLog\Events\EventBuilder}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Events;

use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDefinition;
use HelloLog\Events\RequestContext;
use HelloLog\Events\RequestSource;
use PHPUnit\Framework\TestCase;

/**
 * The builder reads the current user through `current_context()` and the
 * request source through an injected callable, so both are pinned here
 * without WordPress. The payload must stay clean: every optional key is
 * either a well-formed value or absent.
 */
final class EventBuilderV2Test extends TestCase {

	private const CODE = 99001;

	/**
	 * A builder with a fixed request context and a fixed source.
	 *
	 * @param string          $source   Request source the builder reports.
	 * @param RequestContext? $ctx      Context of the current request; defaults to admin user 1.
	 * @param string          $template Message template of the test event.
	 */
	private function builder(
		string $source = RequestSource::ADMIN,
		?RequestContext $ctx = null,
		string $template = '{username} did {thing}'
	): EventBuilder {
		$catalog = new EventCatalog();
		$catalog->register( new EventDefinition( self::CODE, 'user', 'updated', 'info', $template ) );

		$ctx ??= new RequestContext( 1, 'admin', [ 'administrator' ], '203.0.113.9', 'PHPUnit', null );

		return new class( $catalog, $ctx, $source ) extends EventBuilder {

			public function __construct( EventCatalog $catalog, private RequestContext $ctx, string $source ) {
				parent::__construct( $catalog, false, static fn(): string => $source );
			}

			protected function current_context(): RequestContext {
				return $this->ctx;
			}
		};
	}

	/**
	 * @param array<string, mixed> $fields Sensor-supplied fields.
	 *
	 * @return array<string, mixed>
	 */
	private function build( EventBuilder $builder, array $fields = [] ): array {
		$payload = $builder->build( self::CODE, $fields );
		$this->assertIsArray( $payload );

		return $payload;
	}

	public function test_schema_and_source_present(): void {
		$payload = $this->build( $this->builder( RequestSource::ADMIN ) );

		$this->assertSame( 2, $payload['schema'] );
		$this->assertSame( 'admin', $payload['source'] );
	}

	public function test_source_comes_from_the_injected_callable_on_every_build(): void {
		$sources = [ 'rest', 'cli' ];
		$catalog = new EventCatalog();
		$catalog->register( new EventDefinition( self::CODE, 'user', 'updated', 'info', '' ) );
		$builder = new EventBuilder( $catalog, false, static function () use ( &$sources ): string {
			return array_shift( $sources );
		} );

		$this->assertSame( 'rest', $builder->build( self::CODE )['source'] );
		$this->assertSame( 'cli', $builder->build( self::CODE )['source'] );
	}

	public function test_unknown_code_returns_null(): void {
		$this->assertNull( $this->builder()->build( 12345 ) );
	}

	public function test_target_does_not_replace_actor(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'target' => [
					'type'  => 'user',
					'id'    => '7',
					'label' => 'bob',
				],
			]
		);

		$this->assertSame( 'admin', $payload['user']['username'] );
		$this->assertSame( 1, $payload['user']['id'] );
		$this->assertSame(
			[
				'type'  => 'user',
				'id'    => '7',
				'label' => 'bob',
			],
			$payload['target']
		);
	}

	public function test_cron_has_no_actor_but_initiated_by(): void {
		$payload = $this->build( $this->builder( RequestSource::ACTION_SCHEDULER ) );

		$this->assertArrayNotHasKey( 'user', $payload );
		$this->assertSame( 'admin', $payload['metadata']['initiated_by'] );
	}

	public function test_background_without_a_current_user_has_neither_actor_nor_initiated_by(): void {
		$ctx     = new RequestContext( null, null, [], null, null, null );
		$payload = $this->build( $this->builder( RequestSource::CRON, $ctx ) );

		$this->assertArrayNotHasKey( 'user', $payload );
		$this->assertArrayNotHasKey( 'metadata', $payload );
	}

	public function test_initiated_by_is_not_set_for_interactive_sources(): void {
		$payload = $this->build( $this->builder( RequestSource::REST ) );

		$this->assertArrayNotHasKey( 'metadata', $payload );
	}

	public function test_sensor_supplied_initiated_by_is_kept(): void {
		$payload = $this->build(
			$this->builder( RequestSource::CRON ),
			[ 'metadata' => [ 'initiated_by' => 'scheduler' ] ]
		);

		$this->assertSame( 'scheduler', $payload['metadata']['initiated_by'] );
	}

	public function test_explicit_actor_kept_in_background(): void {
		$payload = $this->build(
			$this->builder( RequestSource::CRON ),
			[
				'actor' => [
					'id'       => 5,
					'username' => 'bob',
				],
			]
		);

		$this->assertSame(
			[
				'id'       => 5,
				'username' => 'bob',
			],
			$payload['user']
		);
		$this->assertSame( 'admin', $payload['metadata']['initiated_by'] );
	}

	public function test_explicit_actor_overrides_the_current_user_when_interactive(): void {
		$payload = $this->build(
			$this->builder( RequestSource::ADMIN ),
			[
				'actor' => [
					'id'       => 9,
					'username' => 'bob',
					'roles'    => [ 'editor' ],
				],
			]
		);

		$this->assertSame(
			[
				'id'       => 9,
				'username' => 'bob',
				'roles'    => [ 'editor' ],
			],
			$payload['user']
		);
	}

	public function test_partial_actor_is_not_completed_from_the_current_user(): void {
		foreach ( [ RequestSource::ADMIN, RequestSource::REST, RequestSource::CRON ] as $source ) {
			$payload = $this->build(
				$this->builder( $source ),
				[ 'actor' => [ 'username' => 'bob' ] ]
			);

			$this->assertSame( [ 'username' => 'bob' ], $payload['user'], $source );
		}
	}

	public function test_empty_explicit_actor_falls_back_to_the_normal_policy(): void {
		$empty_actors = [
			'null user'      => [ 'user' => null ],
			'null actor'     => [ 'actor' => null ],
			'empty array'    => [ 'actor' => [] ],
			'only blanks'    => [
				'actor' => [
					'id'       => null,
					'username' => '',
					'roles'    => [],
				],
			],
			'not an array'   => [ 'actor' => 'bob' ],
		];

		foreach ( $empty_actors as $name => $fields ) {
			$interactive = $this->build( $this->builder( RequestSource::ADMIN ), $fields );
			$this->assertSame( 'admin', $interactive['user']['username'], $name );
			$this->assertSame( 1, $interactive['user']['id'], $name );

			$background = $this->build( $this->builder( RequestSource::CRON ), $fields );
			$this->assertArrayNotHasKey( 'user', $background, $name );
			$this->assertSame( 'admin', $background['metadata']['initiated_by'], $name );
		}
	}

	public function test_legacy_user_field_is_an_alias_of_actor(): void {
		$payload = $this->build(
			$this->builder( RequestSource::CRON ),
			[ 'user' => [ 'username' => 'bob' ] ]
		);

		$this->assertSame( [ 'username' => 'bob' ], $payload['user'] );
	}

	public function test_actor_wins_over_the_legacy_user_field(): void {
		$payload = $this->build(
			$this->builder( RequestSource::CRON ),
			[
				'actor' => [ 'username' => 'new' ],
				'user'  => [ 'username' => 'old' ],
			]
		);

		$this->assertSame( 'new', $payload['user']['username'] );
	}

	public function test_vars_filled_from_template_fields(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'username' => 'bob',
				'thing'    => 'x',
				'other'    => 'y',
			]
		);

		$this->assertSame(
			[
				'username' => 'bob',
				'thing'    => 'x',
			],
			$payload['vars']
		);
		$this->assertSame( 'bob did x', $payload['message'] );
	}

	public function test_explicit_vars_win_over_template_fields(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'username' => 'bob',
				'thing'    => 'x',
				'vars'     => [
					'thing' => 'explicit',
					'extra' => 'e',
				],
			]
		);

		$this->assertSame( 'explicit', $payload['vars']['thing'] );
		$this->assertSame( 'bob', $payload['vars']['username'] );
		$this->assertSame( 'e', $payload['vars']['extra'] );
		$this->assertSame( 'bob did explicit', $payload['message'] );
	}

	public function test_message_is_rendered_from_vars_alone(): void {
		$payload = $this->build(
			$this->builder( RequestSource::ADMIN, null, '{a} did {b}' ),
			[
				'vars' => [
					'a' => 'x',
					'b' => 'y',
				],
			]
		);

		$this->assertSame( 'x did y', $payload['message'] );
		$this->assertSame(
			[
				'a' => 'x',
				'b' => 'y',
			],
			$payload['vars']
		);
	}

	public function test_message_reads_a_bool_the_way_vars_do(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'username' => 'bob',
				'thing'    => true,
			]
		);

		$this->assertSame( 'yes', $payload['vars']['thing'] );
		$this->assertSame( 'bob did yes', $payload['message'] );
	}

	public function test_explicit_message_wins_over_the_rendered_one(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'message'  => 'custom',
				'username' => 'bob',
				'thing'    => 'x',
			]
		);

		$this->assertSame( 'custom', $payload['message'] );
		$this->assertSame( 'x', $payload['vars']['thing'] );
	}

	public function test_placeholder_without_a_value_stays_in_the_message(): void {
		$payload = $this->build( $this->builder(), [ 'username' => 'bob' ] );

		$this->assertSame( 'bob did {thing}', $payload['message'] );
	}

	public function test_vars_with_a_key_over_64_characters_are_dropped(): void {
		$vars = [
			str_repeat( 'k', 65 )   => 'long',
			str_repeat( 'é', 65 )   => 'long multibyte',
			str_repeat( 'k', 64 )   => 'exact',
			str_repeat( 'é', 64 )   => 'exact multibyte',
		];

		$payload = $this->build( $this->builder(), [ 'vars' => $vars ] );

		$this->assertSame(
			[
				str_repeat( 'k', 64 ) => 'exact',
				str_repeat( 'é', 64 ) => 'exact multibyte',
			],
			$payload['vars']
		);
	}

	public function test_dropped_long_var_keys_do_not_count_against_the_limit(): void {
		$vars = [];
		for ( $i = 1; $i <= 5; $i++ ) {
			$vars[ str_repeat( 'k', 64 ) . $i ] = 'dropped';
		}
		for ( $i = 1; $i <= 40; $i++ ) {
			$vars[ 'k' . $i ] = 'v';
		}

		$payload = $this->build( $this->builder(), [ 'vars' => $vars ] );

		$this->assertCount( 40, $payload['vars'] );
		$this->assertArrayHasKey( 'k40', $payload['vars'] );
	}

	public function test_vars_that_are_only_long_keys_are_omitted(): void {
		$payload = $this->build( $this->builder(), [ 'vars' => [ str_repeat( 'k', 65 ) => 'v' ] ] );

		$this->assertArrayNotHasKey( 'vars', $payload );
	}

	public function test_vars_are_strings_with_non_scalars_stringified(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'username' => 42,
				'thing'    => true,
				'vars'     => [
					'list'  => [ 'b', 'a' ],
					'ratio' => 1.5,
					'nope'  => null,
					7       => 'numeric key',
					''      => 'empty key',
				],
			]
		);

		$this->assertSame(
			[
				'username' => '42',
				'thing'    => 'yes',
				'list'     => 'a, b',
				'ratio'    => '1.5',
			],
			$payload['vars']
		);
	}

	public function test_changes_and_vars_are_clamped(): void {
		$changes = [];
		for ( $i = 1; $i <= 60; $i++ ) {
			$changes[] = [
				'field' => str_repeat( 'f', 100 ) . $i,
				'old'   => str_repeat( 'a', 2000 ),
				'new'   => 'b',
			];
		}
		$vars = [];
		for ( $i = 1; $i <= 50; $i++ ) {
			$vars[ 'k' . $i ] = 'v';
		}
		$vars['long'] = str_repeat( 'é', 600 );

		$payload = $this->build(
			$this->builder(),
			[
				'changes'  => $changes,
				'vars'     => $vars,
				'username' => str_repeat( 'x', 600 ),
			]
		);

		$this->assertCount( 50, $payload['changes'] );
		$this->assertSame( 64, mb_strlen( $payload['changes'][0]['field'], 'UTF-8' ) );
		$this->assertSame( 1024, mb_strlen( $payload['changes'][0]['old'], 'UTF-8' ) );
		$this->assertStringEndsWith( '…', $payload['changes'][0]['old'] );
		$this->assertSame( 'b', $payload['changes'][0]['new'] );

		$this->assertCount( 40, $payload['vars'] );
		$this->assertSame( 512, mb_strlen( $payload['vars']['username'], 'UTF-8' ) );
		$this->assertStringEndsWith( '…', $payload['vars']['username'] );
	}

	public function test_long_vars_value_is_cut_to_512_characters(): void {
		$payload = $this->build( $this->builder(), [ 'vars' => [ 'long' => str_repeat( 'é', 600 ) ] ] );

		$this->assertSame( 512, mb_strlen( $payload['vars']['long'], 'UTF-8' ) );
		$this->assertSame( str_repeat( 'é', 511 ) . '…', $payload['vars']['long'] );
	}

	public function test_v1_shape_when_no_v2_fields(): void {
		$payload = $this->build( $this->builder() );

		foreach ( [ 'target', 'changes', 'links', 'vars' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $payload, $key );
		}
	}

	public function test_empty_containers_are_omitted(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'target'  => [],
				'changes' => [],
				'links'   => [],
				'vars'    => [],
			]
		);

		foreach ( [ 'target', 'changes', 'links', 'vars' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $payload, $key );
		}
	}

	public function test_non_array_v2_fields_are_ignored(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'target'  => 'user:7',
				'changes' => 'title',
				'links'   => 'https://example.com/',
				'vars'    => 'x',
				'actor'   => 'bob',
			]
		);

		foreach ( [ 'target', 'changes', 'links', 'vars' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $payload, $key );
		}
		$this->assertSame( 'admin', $payload['user']['username'] );
	}

	// ---- target ----------------------------------------------------------

	public function test_target_id_is_always_a_string(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'target' => [
					'type' => 'post',
					'id'   => 42,
				],
			]
		);

		$this->assertSame(
			[
				'type' => 'post',
				'id'   => '42',
			],
			$payload['target']
		);
	}

	public function test_target_needs_a_type_and_an_id(): void {
		$builder = $this->builder();

		$cases = [
			'no id'        => [ 'type' => 'post' ],
			'empty id'     => [
				'type' => 'post',
				'id'   => '',
			],
			'null id'      => [
				'type' => 'post',
				'id'   => null,
			],
			'array id'     => [
				'type' => 'post',
				'id'   => [ 1 ],
			],
			'no type'      => [ 'id' => '7' ],
			'empty type'   => [
				'type' => '',
				'id'   => '7',
			],
			'only a label' => [ 'label' => 'bob' ],
		];
		foreach ( $cases as $name => $target ) {
			$payload = $this->build( $builder, [ 'target' => $target ] );
			$this->assertArrayNotHasKey( 'target', $payload, $name );
		}
	}

	public function test_target_id_zero_is_a_valid_id(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'target' => [
					'type' => 'term',
					'id'   => 0,
				],
			]
		);

		$this->assertSame( '0', $payload['target']['id'] );
	}

	public function test_target_label_is_omitted_when_empty_and_unknown_keys_dropped(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'target' => [
					'type'  => 'post',
					'id'    => 5,
					'label' => '',
					'extra' => 'x',
				],
			]
		);

		$this->assertSame(
			[
				'type' => 'post',
				'id'   => '5',
			],
			$payload['target']
		);
	}

	public function test_over_long_target_type_drops_the_whole_target(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'target' => [
					'type'  => str_repeat( 't', 33 ),
					'id'    => '7',
					'label' => 'bob',
				],
			]
		);

		$this->assertArrayNotHasKey( 'target', $payload );
	}

	public function test_over_long_target_id_drops_the_whole_target(): void {
		foreach ( [ str_repeat( 'i', 65 ), str_repeat( 'é', 65 ) ] as $id ) {
			$payload = $this->build(
				$this->builder(),
				[
					'target' => [
						'type'  => 'post',
						'id'    => $id,
						'label' => 'Hello',
					],
				]
			);

			$this->assertArrayNotHasKey( 'target', $payload );
		}
	}

	public function test_target_type_and_id_at_the_limit_are_kept_untouched(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'target' => [
					'type' => str_repeat( 'é', 32 ),
					'id'   => str_repeat( 'i', 64 ),
				],
			]
		);

		$this->assertSame(
			[
				'type' => str_repeat( 'é', 32 ),
				'id'   => str_repeat( 'i', 64 ),
			],
			$payload['target']
		);
	}

	public function test_target_label_is_clamped_but_never_drops_the_target(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'target' => [
					'type'  => 'post',
					'id'    => '5',
					'label' => str_repeat( 'é', 300 ),
				],
			]
		);

		$this->assertSame( 'post', $payload['target']['type'] );
		$this->assertSame( '5', $payload['target']['id'] );
		$this->assertSame( 255, mb_strlen( $payload['target']['label'], 'UTF-8' ) );
		$this->assertSame( str_repeat( 'é', 254 ) . '…', $payload['target']['label'] );

		$exact = $this->build(
			$this->builder(),
			[
				'target' => [
					'type'  => 'post',
					'id'    => '5',
					'label' => str_repeat( 'é', 255 ),
				],
			]
		);
		$this->assertSame( str_repeat( 'é', 255 ), $exact['target']['label'] );
	}

	// ---- changes ---------------------------------------------------------

	public function test_changes_are_reindexed_and_cast(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'changes' => [
					3 => [
						'field' => 'title',
						'old'   => 'A',
						'new'   => 'B',
					],
					7 => [
						'field' => 42,
						'old'   => 1,
						'new'   => 2,
					],
					9 => [
						'field' => 'flag',
						'old'   => false,
						'new'   => true,
					],
					12 => [
						'field' => 'tags',
						'old'   => null,
						'new'   => [ 'b', 'a' ],
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'field' => 'title',
					'old'   => 'A',
					'new'   => 'B',
				],
				[
					'field' => '42',
					'old'   => '1',
					'new'   => '2',
				],
				[
					'field' => 'flag',
					'old'   => 'no',
					'new'   => 'yes',
				],
				[
					'field' => 'tags',
					'old'   => null,
					'new'   => 'a, b',
				],
			],
			$payload['changes']
		);
		$this->assertSame( [ 0, 1, 2, 3 ], array_keys( $payload['changes'] ) );
	}

	public function test_change_items_hold_exactly_field_old_new(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'changes' => [
					[
						'field' => 'title',
						'extra' => 'x',
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'field' => 'title',
					'old'   => null,
					'new'   => null,
				],
			],
			$payload['changes']
		);
	}

	public function test_bad_change_items_are_dropped(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'changes' => [
					'not an item',
					[
						'field' => '',
						'old'   => 'a',
						'new'   => 'b',
					],
					[
						'old' => 'a',
						'new' => 'b',
					],
					[
						'field' => [ 'x' ],
						'old'   => 'a',
						'new'   => 'b',
					],
					[
						'field' => 'kept',
						'old'   => 'a',
						'new'   => 'b',
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'field' => 'kept',
					'old'   => 'a',
					'new'   => 'b',
				],
			],
			$payload['changes']
		);
	}

	public function test_changes_are_omitted_when_every_item_is_dropped(): void {
		$payload = $this->build( $this->builder(), [ 'changes' => [ [ 'field' => '' ], 'x' ] ] );

		$this->assertArrayNotHasKey( 'changes', $payload );
	}

	public function test_dropped_items_do_not_count_against_the_limit(): void {
		$changes = array_fill( 0, 10, [ 'field' => '' ] );
		for ( $i = 1; $i <= 50; $i++ ) {
			$changes[] = [
				'field' => 'f' . $i,
				'old'   => 'a',
				'new'   => 'b',
			];
		}

		$payload = $this->build( $this->builder(), [ 'changes' => $changes ] );

		$this->assertCount( 50, $payload['changes'] );
		$this->assertSame( 'f50', $payload['changes'][49]['field'] );
	}

	// ---- links -----------------------------------------------------------

	public function test_links_keep_only_absolute_http_urls_under_known_keys(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'links' => [
					'edit'     => 'https://example.com/wp-admin/post.php?post=5&action=edit',
					'view'     => false,
					'revision' => '/wp-admin/revision.php?revision=9',
					'other'    => 'https://example.com/other',
				],
			]
		);

		$this->assertSame( [ 'edit' => 'https://example.com/wp-admin/post.php?post=5&action=edit' ], $payload['links'] );
	}

	public function test_links_reject_non_strings_and_other_schemes(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'links' => [
					'edit'     => [ 'https://example.com/' ],
					'view'     => 'javascript:alert(1)',
					'revision' => 'ftp://example.com/file',
				],
			]
		);

		$this->assertArrayNotHasKey( 'links', $payload );
	}

	public function test_links_reject_objects_and_empty_strings(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'links' => [
					'edit' => new \stdClass(),
					'view' => '',
				],
			]
		);

		$this->assertArrayNotHasKey( 'links', $payload );
	}

	public function test_links_keep_all_three_known_keys_in_a_fixed_order(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'links' => [
					'revision' => 'http://example.com/r',
					'view'     => 'https://example.com/v',
					'edit'     => 'https://example.com/e',
				],
			]
		);

		$this->assertSame(
			[
				'edit'     => 'https://example.com/e',
				'view'     => 'https://example.com/v',
				'revision' => 'http://example.com/r',
			],
			$payload['links']
		);
	}

	// ---- unchanged behaviour ---------------------------------------------

	public function test_v1_fields_still_work(): void {
		$payload = $this->build(
			$this->builder(),
			[
				'post'            => [
					'id'   => 5,
					'type' => 'page',
				],
				'title'           => 'Hello',
				'client_event_id' => 'abc',
			]
		);

		$this->assertSame( 5, $payload['metadata']['post_id'] );
		$this->assertSame( 'page', $payload['metadata']['post_type'] );
		$this->assertSame( 'Hello', $payload['metadata']['post_title'] );
		$this->assertSame( 'abc', $payload['client_event_id'] );
		$this->assertSame( '203.0.113.9', $payload['client_ip'] );
	}
}
