<?php
/**
 * Tests for {@see HelloLog\Sensors\Core\TaxonomySensor}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\RequestContext;
use HelloLog\Events\RequestSource;
use HelloLog\Events\SuppressionRules;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Core\TaxonomySensor;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Core\Fixtures\TermState;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use PHPUnit\Framework\TestCase;

/**
 * Menus have their own sensor (a menu is a `nav_menu` term, so tracking the
 * term too logs every menu change twice), and the others are internal
 * bookkeeping rather than something an editor manages.
 *
 * An edit says what changed: the term is read just before core writes it
 * (`edit_terms`) and again once the write is done (`edited_term`). Every term
 * event names the term as its target.
 *
 * The sensor calls WordPress, so every test that does runs in its own process
 * with the stand-ins of `Fixtures/wordpress-term-functions.php`.
 */
final class TaxonomySensorTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: bool}> taxonomy, expected.
	 */
	public static function taxonomies(): array {
		return [
			'category'              => [ 'category', true ],
			'tag'                   => [ 'post_tag', true ],
			'custom taxonomy'       => [ 'genre', true ],
			'product category'      => [ 'product_cat', true ],
			'product tag'           => [ 'product_tag', true ],
			'product attribute'     => [ 'pa_color', true ],
			'link category'         => [ 'link_category', true ],
			'menu'                  => [ 'nav_menu', false ],
			'post format'           => [ 'post_format', false ],
			'theme of a template'   => [ 'wp_theme', false ],
			'template part area'    => [ 'wp_template_part_area', false ],
			'wrong case'            => [ 'Nav_Menu', true ],
		];
	}

	/**
	 * @dataProvider taxonomies
	 */
	public function test_is_tracked( string $taxonomy, bool $expected ): void {
		$this->assertSame( $expected, TaxonomySensor::is_tracked( $taxonomy ) );
	}

	public function test_term_fields_reads_the_name_slug_parent_and_description(): void {
		$term = (object) [
			'term_id'     => 7,
			'name'        => 'News',
			'slug'        => 'news',
			'taxonomy'    => 'category',
			'description' => 'Everything new.',
			'parent'      => 0,
		];

		$this->assertSame(
			[
				'name'        => 'News',
				'slug'        => 'news',
				'parent'      => '',
				'description' => 'Everything new.',
			],
			TaxonomySensor::term_fields(
				$term,
				function (): string {
					$this->fail( 'A term without a parent has no parent to name.' );
				}
			)
		);
	}

	public function test_term_fields_names_the_parent_with_the_resolver_it_is_given(): void {
		$term  = (object) [
			'name'        => 'Local',
			'slug'        => 'local',
			'taxonomy'    => 'category',
			'description' => '',
			'parent'      => '5',
		];
		$asked = [];

		$fields = TaxonomySensor::term_fields(
			$term,
			static function ( int $id ) use ( &$asked ): string {
				$asked[] = $id;

				return 'News';
			}
		);

		$this->assertSame( 'News', $fields['parent'] );
		$this->assertSame( [ 5 ], $asked );
	}

	public function test_term_fields_reads_missing_fields_as_empty(): void {
		$this->assertSame(
			[
				'name'        => '',
				'slug'        => '',
				'parent'      => '',
				'description' => '',
			],
			TaxonomySensor::term_fields( new \stdClass() )
		);
	}

	public function test_a_renamed_term_has_one_name_change(): void {
		$this->assertSame(
			[
				[
					'field' => 'name',
					'old'   => 'News',
					'new'   => 'Updates',
				],
			],
			TaxonomySensor::term_changes( $this->fields( 'News' ), $this->fields( 'Updates' ) )
		);
	}

	public function test_an_unchanged_term_has_no_changes(): void {
		$this->assertSame( [], TaxonomySensor::term_changes( $this->fields( 'News' ), $this->fields( 'News' ) ) );
	}

	public function test_the_changes_follow_name_slug_parent_and_description(): void {
		$before = $this->fields( 'News', 'news', '', 'Old text.' );
		$after  = $this->fields( 'Updates', 'updates', 'Archive', 'New text.' );

		$this->assertSame(
			[
				[
					'field' => 'name',
					'old'   => 'News',
					'new'   => 'Updates',
				],
				[
					'field' => 'slug',
					'old'   => 'news',
					'new'   => 'updates',
				],
				[
					'field' => 'parent',
					'old'   => '',
					'new'   => 'Archive',
				],
				[
					'field' => 'description',
					'old'   => null,
					'new'   => null,
				],
			],
			TaxonomySensor::term_changes( $before, $after )
		);
	}

	/**
	 * A description is text somebody wrote: the log says it changed, never
	 * what it says.
	 */
	public function test_a_changed_description_is_reported_without_its_text(): void {
		$this->assertSame(
			[
				[
					'field' => 'description',
					'old'   => null,
					'new'   => null,
				],
			],
			TaxonomySensor::term_changes(
				$this->fields( 'News', 'news', '', 'Old text.' ),
				$this->fields( 'News', 'news', '', 'New text.' )
			)
		);
	}

	public function test_the_term_target_is_the_id_labelled_with_the_name(): void {
		$this->assertSame(
			[
				'type'  => 'term',
				'id'    => '7',
				'label' => 'News',
			],
			TaxonomySensor::term_target( 7, 'News' )
		);
	}

	/**
	 * An edit is told once: the snapshot is taken on `edit_terms`, the diff
	 * on `edited_term`, and `edit_term` (which fires in between) is not used.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_hooks_are_registered_with_the_arguments_they_read(): void {
		$this->wordpress();

		( new TaxonomySensor( $this->recorder() ) )->boot();

		$this->assertSame( 3, WpState::$actions['created_term']['accepted_args'] );
		$this->assertSame( 2, WpState::$actions['edit_terms']['accepted_args'] );
		$this->assertSame( 3, WpState::$actions['edited_term']['accepted_args'] );
		$this->assertSame( 2, WpState::$actions['pre_delete_term']['accepted_args'] );
		$this->assertArrayNotHasKey( 'edit_term', WpState::$actions );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_edit_reports_what_changed_since_just_before_the_write(): void {
		$this->put_term( 7, 'News', 'news' );
		$recorder = $this->recorder();
		$sensor   = new TaxonomySensor( $recorder );

		$sensor->on_before_edit( 7, 'category' );
		$this->put_term( 7, 'Updates', 'updates' );
		$sensor->on_edited( 7, 70, 'category' );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 2121, $code );
		$this->assertSame( TaxonomySensor::term_target( 7, 'Updates' ), $fields['target'] );
		$this->assertSame( 'Updates', $fields['name'] );
		$this->assertSame( 'category', $fields['taxonomy'] );
		$this->assertSame(
			[
				[
					'field' => 'name',
					'old'   => 'News',
					'new'   => 'Updates',
				],
				[
					'field' => 'slug',
					'old'   => 'news',
					'new'   => 'updates',
				],
			],
			$fields['changes']
		);
		$this->assertSame(
			[
				'term_id'  => 7,
				'slug'     => 'updates',
				'taxonomy' => 'category',
			],
			$fields['metadata']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_new_parent_is_reported_by_name(): void {
		$this->put_term( 3, 'Sports', 'sports' );
		$this->put_term( 4, 'Archive', 'archive' );
		$this->put_term( 7, 'Local', 'local', 3 );
		$recorder = $this->recorder();
		$sensor   = new TaxonomySensor( $recorder );

		$sensor->on_before_edit( 7, 'category' );
		$this->put_term( 7, 'Local', 'local', 4 );
		$sensor->on_edited( 7, 70, 'category' );

		$this->assertSame(
			[
				[
					'field' => 'parent',
					'old'   => 'Sports',
					'new'   => 'Archive',
				],
			],
			$recorder->calls[0][1]['changes']
		);
	}

	/**
	 * A parent that can no longer be found is still a parent: it is named by
	 * its id rather than reading as "no parent".
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_parent_that_cannot_be_found_is_named_by_its_id(): void {
		$this->put_term( 7, 'Local', 'local', 3 );

		$this->assertSame( '#3', TaxonomySensor::term_fields( TermState::$terms[7] )['parent'] );
	}

	/**
	 * Saving a term without changing anything reaches the log as nothing.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_without_changes_logs_nothing(): void {
		$this->put_term( 7, 'News', 'news' );
		$recorder = $this->recorder();
		$sensor   = new TaxonomySensor( $recorder );

		$sensor->on_before_edit( 7, 'category' );
		$sensor->on_edited( 7, 70, 'category' );

		$this->assertSame( [], $recorder->calls[0][1]['changes'] );
		$this->assertSame( [], $this->payloads( $recorder ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_menus_are_left_to_the_menus_sensor(): void {
		$this->wordpress();
		TermState::$terms[9] = new \WP_Term(
			[
				'term_id'  => 9,
				'name'     => 'Main',
				'slug'     => 'main',
				'taxonomy' => 'nav_menu',
			]
		);
		$recorder = $this->recorder();
		$sensor   = new TaxonomySensor( $recorder );

		$sensor->on_created( 9, 90, 'nav_menu' );
		$sensor->on_before_edit( 9, 'nav_menu' );
		$sensor->on_edited( 9, 90, 'nav_menu' );
		$sensor->on_deleted( 9, 'nav_menu' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * A term id is only unique on its own site: a snapshot taken on another
	 * site of the network is not compared with this one.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_snapshot_taken_on_another_site_is_not_used(): void {
		$this->put_term( 7, 'News', 'news' );
		$recorder = $this->recorder();
		$sensor   = new TaxonomySensor( $recorder );

		switch_to_blog( 2 );
		$sensor->on_before_edit( 7, 'category' );
		restore_current_blog();
		$this->put_term( 7, 'Updates', 'news' );
		$sensor->on_edited( 7, 70, 'category' );

		$this->assertSame( [], $recorder->calls[0][1]['changes'] );

		// The snapshot of site 2 is still there for site 2.
		switch_to_blog( 2 );
		$sensor->on_edited( 7, 70, 'category' );
		restore_current_blog();

		$this->assertSame( 'name', $recorder->calls[1][1]['changes'][0]['field'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_snapshot_is_compared_once(): void {
		$this->put_term( 7, 'News', 'news' );
		$recorder = $this->recorder();
		$sensor   = new TaxonomySensor( $recorder );

		$sensor->on_before_edit( 7, 'category' );
		$this->put_term( 7, 'Updates', 'news' );
		$sensor->on_edited( 7, 70, 'category' );
		$sensor->on_edited( 7, 70, 'category' );

		$this->assertCount( 1, $recorder->calls[0][1]['changes'] );
		$this->assertSame( [], $recorder->calls[1][1]['changes'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_term_that_cannot_be_read_before_the_edit_is_reported_and_never_thrown(): void {
		$this->put_term( 7, 'News', 'news' );
		$this->failure_listener();
		$recorder               = $this->recorder();
		$sensor                 = new TaxonomySensor( $recorder );
		TermState::$reads_throw = true;

		$sensor->on_before_edit( 7, 'category' );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 'hellolog_dispatch_failed', WpCalls::$actions[0][0] );
		$this->assertSame( 2121, WpCalls::$actions[0][1][1] );
		$this->assertSame(
			[
				'term_id'  => 7,
				'taxonomy' => 'category',
			],
			WpCalls::$actions[0][1][2]
		);
	}

	/**
	 * A snapshot of an earlier edit is not compared with this one when this
	 * one's could not be taken.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failed_snapshot_drops_the_one_before_it(): void {
		$this->put_term( 7, 'News', 'news' );
		$this->failure_listener();
		$recorder = $this->recorder();
		$sensor   = new TaxonomySensor( $recorder );

		$sensor->on_before_edit( 7, 'category' );
		TermState::$reads_throw = true;
		$sensor->on_before_edit( 7, 'category' );
		TermState::$reads_throw = false;
		$this->put_term( 7, 'Updates', 'news' );
		$sensor->on_edited( 7, 70, 'category' );

		$this->assertSame( [], $recorder->calls[0][1]['changes'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_term_that_cannot_be_read_after_the_edit_is_reported_and_never_thrown(): void {
		$this->put_term( 7, 'News', 'news' );
		$this->failure_listener();
		$recorder = $this->recorder();
		$sensor   = new TaxonomySensor( $recorder );

		$sensor->on_before_edit( 7, 'category' );
		TermState::$reads_throw = true;
		$sensor->on_edited( 7, 70, 'category' );

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( 'hellolog_dispatch_failed', WpCalls::$actions[0][0] );
		$this->assertSame( 2121, WpCalls::$actions[0][1][1] );
		$this->assertSame(
			[
				'term_id'  => 7,
				'taxonomy' => 'category',
			],
			WpCalls::$actions[0][1][2]
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_creation_and_deletion_name_the_term_as_target(): void {
		$this->put_term( 7, 'News', 'news' );
		$recorder = $this->recorder();
		$sensor   = new TaxonomySensor( $recorder );

		$sensor->on_created( 7, 70, 'category' );
		$sensor->on_deleted( 7, 'category' );

		$this->assertSame( 2120, $recorder->calls[0][0] );
		$this->assertSame( TaxonomySensor::term_target( 7, 'News' ), $recorder->calls[0][1]['target'] );
		$this->assertSame( 2122, $recorder->calls[1][0] );
		$this->assertSame( TaxonomySensor::term_target( 7, 'News' ), $recorder->calls[1][1]['target'] );
	}

	/**
	 * What reaches the log: two edits of one term in a request are one event,
	 * and the message reads naturally.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_payloads_carry_the_target_and_the_folded_changes(): void {
		$this->put_term( 7, 'News', 'news' );
		$recorder = $this->recorder();
		$sensor   = new TaxonomySensor( $recorder );

		$sensor->on_before_edit( 7, 'category' );
		$this->put_term( 7, 'Updates', 'news' );
		$sensor->on_edited( 7, 70, 'category' );
		$sensor->on_before_edit( 7, 'category' );
		$this->put_term( 7, 'Updates', 'updates' );
		$sensor->on_edited( 7, 70, 'category' );

		$payloads = $this->payloads( $recorder );
		$this->assertCount( 1, $payloads );
		$this->assertSame( 2121, $payloads[0]['code'] );
		$this->assertSame( 'Term "Updates" updated in category.', $payloads[0]['message'] );
		$this->assertSame(
			[
				'type'  => 'term',
				'id'    => '7',
				'label' => 'Updates',
			],
			$payloads[0]['target']
		);
		$this->assertSame(
			[
				[
					'field' => 'name',
					'old'   => 'News',
					'new'   => 'Updates',
				],
				[
					'field' => 'slug',
					'old'   => 'news',
					'new'   => 'updates',
				],
			],
			$payloads[0]['changes']
		);
	}

	/**
	 * A term renamed and renamed back in one request has not changed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_rename_undone_in_the_same_request_logs_nothing(): void {
		$this->put_term( 7, 'News', 'news' );
		$recorder = $this->recorder();
		$sensor   = new TaxonomySensor( $recorder );

		$sensor->on_before_edit( 7, 'category' );
		$this->put_term( 7, 'Updates', 'news' );
		$sensor->on_edited( 7, 70, 'category' );
		$sensor->on_before_edit( 7, 'category' );
		$this->put_term( 7, 'News', 'news' );
		$sensor->on_edited( 7, 70, 'category' );

		$this->assertSame( [], $this->payloads( $recorder ) );
	}

	/**
	 * The fields of a term with these values.
	 *
	 * @return array{name: string, slug: string, parent: string, description: string}
	 */
	private function fields( string $name, string $slug = 'news', string $parent = '', string $description = '' ): array {
		return [
			'name'        => $name,
			'slug'        => $slug,
			'parent'      => $parent,
			'description' => $description,
		];
	}

	/**
	 * Load the WordPress stand-ins. Only call this from a test of its own process.
	 */
	private function wordpress(): void {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-term-functions.php';
	}

	/**
	 * Store a category with these fields, or replace the one stored. Only
	 * call this from a test of its own process.
	 *
	 * @param int    $id     Term id.
	 * @param string $name   Name.
	 * @param string $slug   Slug.
	 * @param int    $parent Parent term id; 0 for none.
	 */
	private function put_term( int $id, string $name, string $slug, int $parent = 0 ): void {
		$this->wordpress();

		TermState::$terms[ $id ] = new \WP_Term(
			[
				'term_id'  => $id,
				'name'     => $name,
				'slug'     => $slug,
				'taxonomy' => 'category',
				'parent'   => $parent,
			]
		);
	}

	/**
	 * Load the `do_action()` stand-in, which records the failure reports.
	 * Only call this from a test of its own process.
	 */
	private function failure_listener(): void {
		require_once __DIR__ . '/../../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';
	}

	/**
	 * A dispatcher that records every call as `[ code, fields ]`.
	 */
	private function recorder(): EventDispatcher {
		return new class() implements EventDispatcher {

			/** @var array<int, array{0: int, 1: array<string, mixed>}> */
			public array $calls = [];

			public function dispatch( int $code, array $fields = [] ): void {
				$this->calls[] = [ $code, $fields ];
			}
		};
	}

	/**
	 * Build what the recorder saw as an admin (user 1) would on the dashboard
	 * and run the default per-request rules over it.
	 *
	 * @param EventDispatcher $recorder The recording dispatcher.
	 * @return array<int, array<string, mixed>> The payloads that would be written.
	 */
	private function payloads( EventDispatcher $recorder ): array {
		$catalog = new EventCatalog();
		CatalogSeeder::seed( $catalog );

		$builder = new class( $catalog ) extends EventBuilder {

			public function __construct( EventCatalog $catalog ) {
				parent::__construct( $catalog, false, static fn(): string => RequestSource::ADMIN );
			}

			protected function current_context(): RequestContext {
				return new RequestContext( 1, 'admin', [ 'administrator' ], null, null, null );
			}
		};

		$payloads = [];
		foreach ( $recorder->calls as [ $code, $fields ] ) {
			$payload = $builder->build( $code, $fields );
			$this->assertIsArray( $payload );
			$payloads[] = $payload;
		}

		return SuppressionRules::defaults()->apply( $payloads );
	}
}
