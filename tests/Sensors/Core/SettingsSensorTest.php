<?php
/**
 * Tests for the core settings events of {@see HelloLog\Sensors\Core\SettingsSensor}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Events\ActionSchedulerContext;
use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\RequestContext;
use HelloLog\Events\RequestSource;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Core\SettingsSensor;
use HelloLog\Sensors\SensitiveOption;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Core\Fixtures\BuildingRecorder;
use HelloLog\Tests\Sensors\Core\Fixtures\Hooks;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use HelloLog\Tests\Sensors\Fixtures\RequestState;
use PHPUnit\Framework\TestCase;

/**
 * A core setting is reported the way the plugin settings are: the setting
 * as its target, the old-to-new change, and values that never carry a
 * secret, in the changes, the template values or the metadata.
 *
 * The roles option is told by role: which roles came or went, and which
 * capabilities each role gained or lost.
 */
final class SettingsSensorTest extends TestCase {

	private const URL_WITH_PASSWORD = 'see https://user:p@ss@host/x';

	/**
	 * The roles option of a site, as `WP_Roles` stores it (shortened).
	 *
	 * @var array<string, array{name: string, capabilities: array<string, bool>}>
	 */
	private const ROLES = [
		'administrator' => [
			'name'         => 'Administrator',
			'capabilities' => [
				'manage_options' => true,
				'read'           => true,
			],
		],
		'editor'        => [
			'name'         => 'Editor',
			'capabilities' => [
				'edit_others_posts' => true,
				'read'              => true,
			],
		],
		'subscriber'    => [
			'name'         => 'Subscriber',
			'capabilities' => [
				'read'    => true,
				'level_0' => true,
			],
		],
	];

	public function test_a_core_setting_has_the_setting_as_its_target_and_its_change(): void {
		$recorder = $this->recorder();
		( new SettingsSensor( $recorder ) )->on_update( 'blogname', 'Old name', 'New name' );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 6000, $code );
		$this->assertSame(
			[
				'type'  => 'setting',
				'id'    => 'blogname',
				'label' => 'blogname',
			],
			$fields['target']
		);
		$this->assertSame(
			[
				[
					'field' => 'blogname',
					'old'   => 'Old name',
					'new'   => 'New name',
				],
			],
			$fields['changes']
		);
		$this->assertSame(
			[
				'option' => 'blogname',
				'old'    => 'Old name',
				'new'    => 'New name',
			],
			$fields['metadata']
		);
	}

	public function test_a_password_in_a_url_reaches_no_part_of_the_payload(): void {
		$recorder = $this->recorder();
		( new SettingsSensor( $recorder ) )->on_update( 'blogdescription', 'Just another site', self::URL_WITH_PASSWORD );

		$this->assertCount( 1, $recorder->calls );
		$fields = $recorder->calls[0][1];
		$masked = 'see https://' . SensitiveOption::REDACTED . '@host/x';
		$this->assertSame( $masked, $fields['changes'][0]['new'] );
		$this->assertSame( $masked, $fields['metadata']['new'] );

		$payload = $this->payload( $recorder->calls[0] );
		$this->assertSame( 'blogdescription', $payload['target']['id'] );
		$this->assertStringNotContainsString( 'p@ss', (string) json_encode( $payload ) );
		$this->assertStringNotContainsString( 'p@ss', serialize( $fields ) );
	}

	/**
	 * The site address and the admin e-mail tell their values in the message.
	 */
	public function test_the_message_values_are_masked_too(): void {
		$recorder = $this->recorder();
		( new SettingsSensor( $recorder ) )->on_update( 'siteurl', 'https://example.com', 'https://admin:hunter2@example.com' );

		$this->assertSame( 6001, $recorder->calls[0][0] );
		$payload = $this->payload( $recorder->calls[0] );
		$this->assertSame( 'Site URL changed from https://example.com to https://' . SensitiveOption::REDACTED . '@example.com.', $payload['message'] );
		$this->assertStringNotContainsString( 'hunter2', (string) json_encode( $payload ) );
	}

	/**
	 * `updated_option` fires when the stored text differs, which `1` and `'1'`
	 * do, but nothing a reader could see changed.
	 */
	public function test_a_write_that_changes_nothing_a_reader_can_see_logs_nothing(): void {
		$recorder = $this->recorder();
		$sensor   = new SettingsSensor( $recorder );
		$sensor->on_update( 'blog_public', 1, '1' );
		$sensor->on_update( 'posts_per_page', '10', 10 );

		$this->assertSame( [], $recorder->calls );
	}

	public function test_an_option_that_is_not_watched_logs_nothing(): void {
		$recorder = $this->recorder();
		( new SettingsSensor( $recorder ) )->on_update( 'woocommerce_currency', 'USD', 'EUR' );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * Unlike plugin settings, a core setting changed by a background run or a
	 * visitor's request is exactly what an audit log has to show.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_core_setting_changed_by_cron_or_a_visitor_is_still_logged(): void {
		require_once __DIR__ . '/../Fixtures/wordpress-request-functions.php';
		RequestState::$admin   = false;
		RequestState::$user_id = 0;

		$recorder = $this->recorder();
		$sensor   = new SettingsSensor( $recorder );
		$sensor->on_update( 'home', 'https://example.com', 'https://example.test' );

		RequestState::$cron = true;
		$sensor->on_update( 'admin_email', 'owner@example.com', 'someone@example.test' );

		$this->assertSame( [ 6001, 6002 ], array_column( $recorder->calls, 0 ) );
	}

	/**
	 * While WordPress sets up a new site of a network it writes some of the
	 * watched settings (the privacy policy page it drafts, for one): that is
	 * the site's creation, logged on its own, not a settings change.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_settings_written_while_a_network_site_is_set_up_log_nothing(): void {
		$this->on_site_with_prefix( 'wp_' );
		require_once __DIR__ . '/Fixtures/wordpress-content-functions.php';
		$recorder = $this->recorder();
		$sensor   = new SettingsSensor( $recorder );

		WpState::$doing_action = 'wp_initialize_site';
		$sensor->on_update( 'wp_page_for_privacy_policy', '', '3' );
		$sensor->on_add( 'blogdescription', 'Just another site' );
		$this->assertSame( [], $recorder->calls );

		WpState::$doing_action = null;
		$sensor->on_update( 'wp_page_for_privacy_policy', '3', '7' );
		$this->assertSame( [ 6000 ], array_column( $recorder->calls, 0 ) );
	}

	// ---- the core settings allow-list ---------------------------------------

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}> Option, old value, new value.
	 */
	public static function more_core_settings(): array {
		return [
			'default comment status'      => [ 'default_comment_status', 'open', 'closed' ],
			'default ping status'         => [ 'default_ping_status', 'open', 'closed' ],
			'close comments on old posts' => [ 'close_comments_for_old_posts', '0', '1' ],
			'days before comments close'  => [ 'close_comments_days_old', '14', '30' ],
			'previously approved author'  => [ 'comment_previously_approved', '1', '0' ],
			'links before moderation'     => [ 'comment_max_links', '2', '5' ],
			'threaded comments'           => [ 'thread_comments', '1', '0' ],
			'utc offset'                  => [ 'gmt_offset', '0', '2' ],
			'site icon'                   => [ 'site_icon', '0', '42' ],
			'privacy policy page'         => [ 'wp_page_for_privacy_policy', '3', '7' ],
			'default category'            => [ 'default_category', '1', '5' ],
			'uploads in dated folders'    => [ 'uploads_use_yearmonth_folders', '1', '0' ],
		];
	}

	/**
	 * @dataProvider more_core_settings
	 */
	public function test_more_core_settings_are_logged( string $option, string $old, string $new ): void {
		$recorder = $this->recorder();
		( new SettingsSensor( $recorder ) )->on_update( $option, $old, $new );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 6000, $code );
		$this->assertSame( $option, $fields['target']['id'] );
		$this->assertSame(
			[
				[
					'field' => $option,
					'old'   => $old,
					'new'   => $new,
				],
			],
			$fields['changes']
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function word_lists(): array {
		return [
			'comment moderation words' => [ 'moderation_keys' ],
			'disallowed comment words' => [ 'disallowed_keys' ],
		];
	}

	/**
	 * The words a site holds comments for (or throws them out for) are a
	 * list somebody wrote: the event says the list changed, never what is
	 * on it, in the changes, the metadata or the template values.
	 *
	 * @dataProvider word_lists
	 */
	public function test_a_comment_word_list_is_reported_as_changed_without_its_words( string $option ): void {
		$recorder = $this->recorder();
		$sensor   = new SettingsSensor( $recorder );
		$sensor->on_update( $option, "casino\nlottery", "casino\nlottery\nhttps://user:hunter2@spam.example.com" );
		$sensor->on_add( $option, 'roulette' );

		$this->assertSame( [ 6000, 6000 ], array_column( $recorder->calls, 0 ) );
		foreach ( $recorder->calls as $call ) {
			$fields = $call[1];
			$this->assertSame(
				[
					[
						'field' => $option,
						'old'   => null,
						'new'   => null,
					],
				],
				$fields['changes']
			);
			$this->assertSame( [ 'option' => $option ], $fields['metadata'] );
			$this->assertArrayNotHasKey( 'old', $fields );
			$this->assertArrayNotHasKey( 'new', $fields );
			$this->assertArrayNotHasKey( 'state', $fields );

			$text = serialize( $fields ) . json_encode( $this->payload( $call ) );
			foreach ( [ 'casino', 'lottery', 'hunter2', 'roulette' ] as $word ) {
				$this->assertStringNotContainsString( $word, $text );
			}
		}
	}

	/**
	 * A word list saved again with the same words is no change.
	 *
	 * @dataProvider word_lists
	 */
	public function test_a_word_list_saved_unchanged_logs_nothing( string $option ): void {
		$recorder = $this->recorder();
		( new SettingsSensor( $recorder ) )->on_update( $option, "casino\nlottery", "casino\nlottery" );

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * `update_option()` adds an option that does not exist yet, and only
	 * `added_option` tells that: the setting is logged from nothing.
	 */
	public function test_a_core_setting_written_for_the_first_time_is_logged_from_nothing(): void {
		$recorder = $this->recorder();
		( new SettingsSensor( $recorder ) )->on_add( 'WPLANG', 'hu_HU' );

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 6000, $code );
		$this->assertSame(
			[
				'type'  => 'setting',
				'id'    => 'WPLANG',
				'label' => 'WPLANG',
			],
			$fields['target']
		);
		$this->assertSame(
			[
				[
					'field' => 'WPLANG',
					'old'   => null,
					'new'   => 'hu_HU',
				],
			],
			$fields['changes']
		);
		$this->assertSame(
			[
				'option' => 'WPLANG',
				'old'    => null,
				'new'    => 'hu_HU',
			],
			$fields['metadata']
		);
	}

	public function test_an_added_value_is_masked_too(): void {
		$recorder = $this->recorder();
		( new SettingsSensor( $recorder ) )->on_add( 'blogdescription', self::URL_WITH_PASSWORD );

		$fields = $recorder->calls[0][1];
		$masked = 'see https://' . SensitiveOption::REDACTED . '@host/x';
		$this->assertSame( $masked, $fields['changes'][0]['new'] );
		$this->assertSame( $masked, $fields['metadata']['new'] );
		$this->assertStringNotContainsString( 'p@ss', serialize( $fields ) . json_encode( $this->payload( $recorder->calls[0] ) ) );
	}

	public function test_an_option_that_is_not_watched_logs_nothing_when_added(): void {
		$recorder = $this->recorder();
		( new SettingsSensor( $recorder ) )->on_add( 'woocommerce_currency', 'EUR' );

		$this->assertSame( [], $recorder->calls );
	}

	// ---- role capabilities --------------------------------------------------

	public function test_a_new_role_is_reported_with_the_capabilities_it_has(): void {
		$new                 = self::ROLES;
		$new['shop_auditor'] = [
			'name'         => 'Shop auditor',
			'capabilities' => [
				'view_shop_reports' => true,
				'read'              => true,
			],
		];

		$this->assertSame(
			[
				[
					'field' => 'role:shop_auditor',
					'old'   => null,
					'new'   => 'added',
				],
				[
					'field' => 'caps:shop_auditor',
					'old'   => null,
					'new'   => 'read, view_shop_reports',
				],
			],
			SettingsSensor::roles_changes( self::ROLES, $new )
		);
	}

	public function test_a_new_role_without_capabilities_is_only_reported_as_added(): void {
		$new                 = self::ROLES;
		$new['shop_auditor'] = [
			'name'         => 'Shop auditor',
			'capabilities' => [],
		];

		$this->assertSame(
			[
				[
					'field' => 'role:shop_auditor',
					'old'   => null,
					'new'   => 'added',
				],
			],
			SettingsSensor::roles_changes( self::ROLES, $new )
		);
	}

	public function test_a_removed_role_is_reported_with_the_capabilities_it_had(): void {
		$new = self::ROLES;
		unset( $new['subscriber'] );

		$this->assertSame(
			[
				[
					'field' => 'role:subscriber',
					'old'   => 'present',
					'new'   => null,
				],
				[
					'field' => 'caps:subscriber',
					'old'   => 'level_0, read',
					'new'   => null,
				],
			],
			SettingsSensor::roles_changes( self::ROLES, $new )
		);
	}

	public function test_a_capability_given_to_a_role_is_reported_as_added(): void {
		$new = self::ROLES;
		$new['editor']['capabilities']['manage_options'] = true;

		$this->assertSame(
			[
				[
					'field' => 'caps:editor',
					'old'   => null,
					'new'   => 'manage_options',
				],
			],
			SettingsSensor::roles_changes( self::ROLES, $new )
		);
	}

	public function test_capabilities_taken_and_given_are_listed_sorted_on_their_sides(): void {
		$new = self::ROLES;
		unset( $new['editor']['capabilities']['read'], $new['editor']['capabilities']['edit_others_posts'] );
		$new['editor']['capabilities']['moderate_comments'] = true;
		$new['editor']['capabilities']['list_users']        = true;

		$this->assertSame(
			[
				[
					'field' => 'caps:editor',
					'old'   => 'edit_others_posts, read',
					'new'   => 'list_users, moderate_comments',
				],
			],
			SettingsSensor::roles_changes( self::ROLES, $new )
		);
	}

	/**
	 * `add_cap( $role, $cap, false )` stores the capability as denied: the
	 * role does not have it, the same as when it is not listed at all.
	 */
	public function test_a_denied_capability_is_not_one_the_role_has(): void {
		$denied = self::ROLES;
		$denied['editor']['capabilities']['manage_options'] = false;
		$this->assertSame( [], SettingsSensor::roles_changes( self::ROLES, $denied ) );

		$revoked = self::ROLES;

		$revoked['editor']['capabilities']['read'] = false;
		$this->assertSame(
			[
				[
					'field' => 'caps:editor',
					'old'   => 'read',
					'new'   => null,
				],
			],
			SettingsSensor::roles_changes( self::ROLES, $revoked )
		);
	}

	public function test_a_renamed_role_with_the_same_capabilities_is_no_change(): void {
		$new                   = self::ROLES;
		$new['editor']['name'] = 'Chief editor';

		$this->assertSame( [], SettingsSensor::roles_changes( self::ROLES, $new ) );
	}

	/**
	 * Role and capability names are identifiers, not secrets: a name that
	 * reads like a credential is reported as it is.
	 */
	public function test_role_and_capability_names_are_never_masked(): void {
		$new                       = self::ROLES;
		$new['license_key_keeper'] = [
			'name'         => 'License keeper',
			'capabilities' => [ 'manage_api_keys' => true ],
		];
		$new['editor']['capabilities']['reset_passwords'] = true;

		$changes = SettingsSensor::roles_changes( self::ROLES, $new );
		$this->assertSame(
			[ 'caps:editor', 'role:license_key_keeper', 'caps:license_key_keeper' ],
			array_column( $changes, 'field' )
		);
		$this->assertSame( [ 'reset_passwords', 'added', 'manage_api_keys' ], array_column( $changes, 'new' ) );

		$payload = $this->payload( [ 6005, [ 'changes' => $changes ] ] );
		$this->assertSame( $changes, $payload['changes'] );
	}

	/**
	 * A list longer than a change value is cut, and the powerful
	 * capabilities come first, so the cut can never hide them. Alphabetically
	 * both would be past the cut here.
	 */
	public function test_a_long_capability_list_is_cut_after_the_powerful_capabilities(): void {
		$caps = [];
		for ( $i = 0; $i < 118; $i++ ) {
			$caps[ sprintf( 'custom_cap_%03d', $i ) ] = true;
		}
		$caps['promote_users']  = true;
		$caps['manage_options'] = true;
		$new                    = self::ROLES;
		$new['shop_manager']    = [
			'name'         => 'Shop manager',
			'capabilities' => $caps,
		];

		$changes = SettingsSensor::roles_changes( self::ROLES, $new );
		$this->assertSame( 'caps:shop_manager', $changes[1]['field'] );
		$this->assertSame( 1024, mb_strlen( (string) $changes[1]['new'], 'UTF-8' ) );
		$this->assertStringStartsWith( 'manage_options, promote_users, custom_cap_000, custom_cap_001, ', (string) $changes[1]['new'] );
		$this->assertStringEndsWith( '…', (string) $changes[1]['new'] );
	}

	/**
	 * The capabilities that run the site (settings, users, plugins, themes,
	 * code, the network) lead a list, most powerful first; the rest follow
	 * in alphabetical order.
	 */
	public function test_powerful_capabilities_lead_a_list_and_the_rest_follow_in_order(): void {
		$new = self::ROLES;
		unset( $new['editor']['capabilities']['read'], $new['editor']['capabilities']['edit_others_posts'] );
		foreach ( [ 'zz_report', 'edit_users', 'aa_report', 'promote_users', 'manage_options', 'moderate_comments' ] as $cap ) {
			$new['editor']['capabilities'][ $cap ] = true;
		}
		$old = self::ROLES;
		$old['editor']['capabilities']['install_plugins'] = true;

		$this->assertSame(
			[
				[
					'field' => 'caps:editor',
					'old'   => 'install_plugins, edit_others_posts, read',
					'new'   => 'manage_options, promote_users, edit_users, aa_report, moderate_comments, zz_report',
				],
			],
			SettingsSensor::roles_changes( $old, $new )
		);
	}

	public function test_the_roles_option_is_named_by_the_table_prefix_of_the_site(): void {
		$this->assertTrue( SettingsSensor::is_roles_option( 'wp2_user_roles', 'wp2_' ) );
		$this->assertFalse( SettingsSensor::is_roles_option( 'wp_user_roles', 'wp2_' ) );
		$this->assertFalse( SettingsSensor::is_roles_option( 'wp2_user_roles_backup', 'wp2_' ) );
		$this->assertTrue( SettingsSensor::is_roles_option( 'wp_3_user_roles', 'wp_3_' ) );
	}

	/**
	 * `WP_Roles::add_cap()` and `remove_cap()` write the whole option on every
	 * call, so a plugin setting up its capabilities writes it many times in
	 * one request. That is one event, at shutdown, from the roles before the
	 * first write to the roles after the last.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_capability_edits_of_a_request_are_one_event_at_shutdown(): void {
		$this->on_site_with_prefix( 'wp_' );
		$recorder = $this->recorder();
		$sensor   = new SettingsSensor( $recorder );

		$step1 = self::ROLES;
		$step1['editor']['capabilities']['manage_options'] = true;
		$step2 = $step1;
		$step2['editor']['capabilities']['list_users'] = true;
		$step3 = $step2;
		unset( $step3['editor']['capabilities']['edit_others_posts'] );

		$sensor->on_update( 'wp_user_roles', self::ROLES, $step1 );
		$sensor->on_update( 'wp_user_roles', $step1, $step2 );
		$sensor->on_update( 'wp_user_roles', $step2, $step3 );
		$this->assertSame( [], $recorder->calls, 'Nothing goes out before shutdown.' );

		$sensor->emit_role_changes();

		$this->assertCount( 1, $recorder->calls );
		[ $code, $fields ] = $recorder->calls[0];
		$this->assertSame( 6005, $code );
		$this->assertSame(
			[
				'type'  => 'setting',
				'id'    => 'wp_user_roles',
				'label' => 'wp_user_roles',
			],
			$fields['target']
		);
		$this->assertSame(
			[
				[
					'field' => 'caps:editor',
					'old'   => 'edit_others_posts',
					'new'   => 'manage_options, list_users',
				],
			],
			$fields['changes']
		);

		$payload = $this->payload( $recorder->calls[0] );
		$this->assertSame( 'critical', $payload['severity'] );
		$this->assertSame( 'setting', $payload['object'] );
		$this->assertSame( 'updated', $payload['event_type'] );
		$this->assertSame( 'User role capabilities changed.', $payload['message'] );
		$this->assertSame( 'wp_user_roles', $payload['target']['id'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_capability_edits_that_cancel_out_log_nothing(): void {
		$this->on_site_with_prefix( 'wp_' );
		$recorder = $this->recorder();
		$sensor   = new SettingsSensor( $recorder );

		$granted = self::ROLES;
		$granted['editor']['capabilities']['manage_options'] = true;
		$sensor->on_update( 'wp_user_roles', self::ROLES, $granted );
		$sensor->on_update( 'wp_user_roles', $granted, self::ROLES );
		$sensor->emit_role_changes();

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * The event goes out once. A write made after it (by a later shutdown
	 * callback) is its own event, at the second emission, from where the
	 * first one ended.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_role_edits_go_out_once_and_a_later_edit_on_its_own(): void {
		$this->on_site_with_prefix( 'wp_' );
		$recorder = $this->recorder();
		$sensor   = new SettingsSensor( $recorder );

		$step1 = self::ROLES;
		$step1['editor']['capabilities']['manage_options'] = true;
		$step2 = $step1;
		$step2['editor']['capabilities']['list_users'] = true;

		$sensor->on_update( 'wp_user_roles', self::ROLES, $step1 );
		$sensor->emit_role_changes();
		$sensor->emit_role_changes();
		$this->assertCount( 1, $recorder->calls );

		$sensor->on_update( 'wp_user_roles', $step1, $step2 );
		$sensor->emit_role_changes();

		$this->assertSame( [ 6005, 6005 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame(
			[
				[
					'field' => 'caps:editor',
					'old'   => null,
					'new'   => 'list_users',
				],
			],
			$recorder->calls[1][1]['changes']
		);
	}

	/**
	 * On a network every site has its own roles option, named by its table
	 * prefix. A request that edits the roles of two sites makes one event on
	 * each, each on its own site.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_each_site_of_a_network_gets_its_own_event_on_that_site(): void {
		$this->on_site_with_prefix( 'wp_' );
		$recorder = $this->recorder();
		$sensor   = new SettingsSensor( $recorder );

		$granted = self::ROLES;
		$granted['editor']['capabilities']['manage_options'] = true;
		$without = self::ROLES;
		unset( $without['subscriber'] );

		$sensor->on_update( 'wp_user_roles', self::ROLES, $granted );
		switch_to_blog( 2 );
		$GLOBALS['wpdb']->prefix = 'wp_2_';
		$sensor->on_update( 'wp_2_user_roles', self::ROLES, $without );
		restore_current_blog();
		$GLOBALS['wpdb']->prefix = 'wp_';
		$sensor->emit_role_changes();

		$this->assertSame( [ 6005, 6005 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame( [ 1, 2 ], $recorder->blogs );
		$this->assertSame( [ 'wp_user_roles', 'wp_2_user_roles' ], array_column( array_column( array_column( $recorder->calls, 1 ), 'target' ), 'id' ) );
		$this->assertSame( 'caps:editor', $recorder->calls[0][1]['changes'][0]['field'] );
		$this->assertSame( 'role:subscriber', $recorder->calls[1][1]['changes'][0]['field'] );
		$this->assertSame( 1, WpState::$blog_id, 'Back on the site of the request.' );
	}

	/**
	 * Only the roles option of the current site counts: the name is compared
	 * with the table prefix in force when the write happens.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_roles_option_of_another_prefix_logs_nothing(): void {
		$this->on_site_with_prefix( 'wp_' );
		$recorder = $this->recorder();
		$sensor   = new SettingsSensor( $recorder );

		$without = self::ROLES;
		unset( $without['subscriber'] );
		$sensor->on_update( 'wp_2_user_roles', self::ROLES, $without );
		$sensor->on_update( 'user_roles', self::ROLES, $without );
		$sensor->emit_role_changes();

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * WordPress sets up a new site of a network in `wp_initialize_site`: the
	 * roles option it creates and fills there holds the roles the site
	 * starts with, not an edit. Whether it was setting the site up is taken
	 * at the first write, so it is still known at shutdown, when it no
	 * longer is.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_roles_a_site_is_set_up_with_log_nothing(): void {
		$this->on_site_with_prefix( 'wp_' );
		require_once __DIR__ . '/Fixtures/wordpress-content-functions.php';
		$recorder = $this->recorder();
		$sensor   = new SettingsSensor( $recorder );

		$first                 = [ 'administrator' => self::ROLES['administrator'] ];
		WpState::$doing_action = 'wp_initialize_site';
		switch_to_blog( 5 );
		$GLOBALS['wpdb']->prefix = 'wp_5_';
		$sensor->on_add( 'wp_5_user_roles', $first );
		$sensor->on_update( 'wp_5_user_roles', $first, self::ROLES );
		restore_current_blog();
		$GLOBALS['wpdb']->prefix = 'wp_';
		WpState::$doing_action   = null;
		$sensor->emit_role_changes();

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * An install (`WP_INSTALLING`) sets the site's roles up too.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_roles_an_install_sets_up_log_nothing(): void {
		$this->on_site_with_prefix( 'wp_' );
		require_once __DIR__ . '/Fixtures/wordpress-content-functions.php';
		define( 'WP_INSTALLING', true );
		$recorder = $this->recorder();
		$sensor   = new SettingsSensor( $recorder );

		$sensor->on_add( 'wp_user_roles', self::ROLES );
		$sensor->emit_role_changes();

		$this->assertSame( [], $recorder->calls );
	}

	/**
	 * `wpmu_create_blog()` turns `wp_installing()` on and never off: a role
	 * edit later in the same request, once the new site is set up, is an
	 * edit like any other.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_role_edit_after_a_site_was_created_in_the_request_is_logged(): void {
		$this->on_site_with_prefix( 'wp_' );
		require_once __DIR__ . '/Fixtures/wordpress-content-functions.php';
		$recorder = $this->recorder();
		$sensor   = new SettingsSensor( $recorder );

		$granted = self::ROLES;
		$granted['editor']['capabilities']['manage_options'] = true;
		WpState::$installing = true;
		$sensor->on_update( 'wp_user_roles', self::ROLES, $granted );
		$sensor->emit_role_changes();

		$this->assertSame( [ 6005 ], array_column( $recorder->calls, 0 ) );
	}

	/**
	 * Outside an install, a roles option that is created (it was missing,
	 * deleted earlier, or added over a row the cache did not know about)
	 * is compared with no roles at all: every role in it is reported.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_roles_option_created_outside_an_install_reports_every_role(): void {
		$this->on_site_with_prefix( 'wp_' );
		$recorder = $this->recorder();
		$sensor   = new SettingsSensor( $recorder );

		$sensor->on_add( 'wp_user_roles', self::ROLES );
		$sensor->emit_role_changes();

		$this->assertSame( [ 6005 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame(
			[ 'role:administrator', 'caps:administrator', 'role:editor', 'caps:editor', 'role:subscriber', 'caps:subscriber' ],
			array_column( $recorder->calls[0][1]['changes'], 'field' )
		);
		$this->assertSame( [ null, null, null, null, null, null ], array_column( $recorder->calls[0][1]['changes'], 'old' ) );
	}

	/**
	 * `delete_option` fires before the row is removed, so the roles are
	 * still readable: a site left without its roles option has every role
	 * removed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_the_roles_option_reports_every_role_removed(): void {
		$this->on_site_with_prefix( 'wp_' );
		WpState::$options[1]['wp_user_roles'] = self::ROLES;
		$recorder                             = $this->recorder();
		$sensor                               = new SettingsSensor( $recorder );

		$sensor->on_delete( 'wp_user_roles' );
		$sensor->emit_role_changes();

		$this->assertSame( [ 6005 ], array_column( $recorder->calls, 0 ) );
		$this->assertSame(
			[
				[
					'field' => 'role:administrator',
					'old'   => 'present',
					'new'   => null,
				],
				[
					'field' => 'caps:administrator',
					'old'   => 'manage_options, read',
					'new'   => null,
				],
				[
					'field' => 'role:editor',
					'old'   => 'present',
					'new'   => null,
				],
				[
					'field' => 'caps:editor',
					'old'   => 'edit_others_posts, read',
					'new'   => null,
				],
				[
					'field' => 'role:subscriber',
					'old'   => 'present',
					'new'   => null,
				],
				[
					'field' => 'caps:subscriber',
					'old'   => 'level_0, read',
					'new'   => null,
				],
			],
			$recorder->calls[0][1]['changes']
		);
		$this->assertSame( 'wp_user_roles', $recorder->calls[0][1]['target']['id'] );
	}

	/**
	 * Deleting the roles option and writing it again in one request (the
	 * write falls back to adding it) is one edit, from the roles before the
	 * delete to the roles written: a capability slipped in that way shows.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_roles_deleted_and_written_again_are_one_event_with_the_escalation(): void {
		$this->on_site_with_prefix( 'wp_' );
		WpState::$options[1]['wp_user_roles'] = self::ROLES;
		$recorder                             = $this->recorder();
		$sensor                               = new SettingsSensor( $recorder );

		$escalated = self::ROLES;
		$escalated['subscriber']['capabilities']['manage_options'] = true;
		$sensor->on_delete( 'wp_user_roles' );
		$sensor->on_add( 'wp_user_roles', $escalated );
		$sensor->emit_role_changes();

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( 6005, $recorder->calls[0][0] );
		$this->assertSame(
			[
				[
					'field' => 'caps:subscriber',
					'old'   => null,
					'new'   => 'manage_options',
				],
			],
			$recorder->calls[0][1]['changes']
		);
	}

	/**
	 * An update, a delete and an add in one request still start from the
	 * roles before the update.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_update_then_a_delete_and_an_add_keep_the_first_old_roles(): void {
		$this->on_site_with_prefix( 'wp_' );
		$recorder = $this->recorder();
		$sensor   = new SettingsSensor( $recorder );

		$step1 = self::ROLES;
		$step1['editor']['capabilities']['list_users'] = true;
		$step2 = self::ROLES;
		unset( $step2['subscriber'] );

		$sensor->on_update( 'wp_user_roles', self::ROLES, $step1 );
		WpState::$options[1]['wp_user_roles'] = $step1;
		$sensor->on_delete( 'wp_user_roles' );
		$sensor->on_add( 'wp_user_roles', $step2 );
		$sensor->emit_role_changes();

		$this->assertCount( 1, $recorder->calls );
		$this->assertSame( [ 'role:subscriber', 'caps:subscriber' ], array_column( $recorder->calls[0][1]['changes'], 'field' ) );
	}

	/**
	 * Any other option, the roles option of another site included, is not
	 * even read when it is deleted (a read here would fail).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_another_option_reads_nothing_and_logs_nothing(): void {
		$this->on_site_with_prefix( 'wp_' );
		require_once __DIR__ . '/../../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';
		WpState::$option_reads_throw = true;
		$recorder                    = $this->recorder();
		$sensor                      = new SettingsSensor( $recorder );

		$sensor->on_delete( 'blogname' );
		$sensor->on_delete( 'wp_2_user_roles' );
		$sensor->emit_role_changes();

		$this->assertSame( [], $recorder->calls );
		$this->assertSame( [], WpCalls::$actions );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_roles_that_cannot_be_read_on_delete_are_reported_and_never_thrown(): void {
		$this->on_site_with_prefix( 'wp_' );
		require_once __DIR__ . '/../../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';
		WpState::$option_reads_throw = true;
		$recorder                    = $this->recorder();
		$sensor                      = new SettingsSensor( $recorder );

		$sensor->on_delete( 'wp_user_roles' );
		$sensor->emit_role_changes();

		$this->assertSame( [], $recorder->calls );
		$this->assertCount( 1, WpCalls::$actions );
		[ $hook, $args ] = WpCalls::$actions[0];
		$this->assertSame( 'hellolog_dispatch_failed', $hook );
		$this->assertSame( 'unreadable', $args[0]->getMessage() );
		$this->assertSame( 6005, $args[1] );
		$this->assertSame( [ 'option' => 'wp_user_roles' ], $args[2] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_option_hooks_and_both_shutdown_emissions_are_registered(): void {
		$this->on_site_with_prefix( 'wp_' );
		$sensor = new SettingsSensor( $this->recorder() );
		$sensor->boot();

		$this->assertSame( 3, WpState::$actions['updated_option']['accepted_args'] );
		$this->assertSame( 2, WpState::$actions['added_option']['accepted_args'] );
		$this->assertSame( 1, WpState::$actions['delete_option']['accepted_args'] );

		$shutdown = WpState::$callbacks['shutdown'];
		// Before the buffer is written at priority 1, and again before the
		// final flush at PHP_INT_MAX for writes made by later callbacks.
		$this->assertSame( [ 0, PHP_INT_MAX - 1 ], array_column( $shutdown, 'priority' ) );
		foreach ( $shutdown as $registration ) {
			$this->assertSame( [ $sensor, 'emit_role_changes' ], $registration['callback'] );
		}

		// Before a job begins, and when it ends, before its context closes
		// at PHP_INT_MAX.
		$this->assertSame( [ 10, 0 ], array_values( WpState::$actions['action_scheduler_before_execute'] ) );
		foreach ( [ 'action_scheduler_after_execute', 'action_scheduler_failed_execution' ] as $hook ) {
			$this->assertSame( [ PHP_INT_MAX - 1, 0 ], array_values( WpState::$actions[ $hook ] ), $hook );
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function job_ends(): array {
		return [
			'a job that succeeds' => [ 'action_scheduler_after_execute' ],
			'a job that fails'    => [ 'action_scheduler_failed_execution' ],
		];
	}

	/**
	 * Role edits an Action Scheduler job makes go out when the job ends,
	 * while the job is still the context: the event comes from the job and
	 * keeps who set it in motion, instead of reading as the doing of whoever
	 * loaded the page that started the queue runner.
	 *
	 * @dataProvider job_ends
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $end The hook that ends the job.
	 */
	public function test_role_edits_a_job_makes_go_out_when_the_job_ends_as_the_job( string $end ): void {
		$this->on_site_with_prefix( 'wp_' );
		$recorder = new BuildingRecorder();
		ActionSchedulerContext::register();
		$sensor = new SettingsSensor( $recorder );
		$sensor->boot();

		$granted = self::ROLES;
		$granted['editor']['capabilities']['manage_options'] = true;

		Hooks::fire( 'action_scheduler_before_execute', 41, 'WP Cron' );
		Hooks::fire( 'action_scheduler_begin_execute', 41, 'WP Cron' );
		$sensor->on_update( 'wp_user_roles', self::ROLES, $granted );
		Hooks::fire( $end, 41, 'action_scheduler_failed_execution' === $end ? new \RuntimeException( 'failed' ) : null, 'WP Cron' );

		$this->assertSame( [ 6005 ], array_column( $recorder->payloads, 'code' ) );
		$this->assertSame( 'action-scheduler', $recorder->payloads[0]['source'] );
		$this->assertSame( 'admin', $recorder->payloads[0]['metadata']['initiated_by'] );
		$this->assertArrayNotHasKey( 'user', $recorder->payloads[0] );

		Hooks::fire( 'shutdown' );
		$this->assertCount( 1, $recorder->payloads, 'Not again at shutdown.' );
	}

	/**
	 * Edits made before a job are told on their own, before the job begins,
	 * so the job's edits are not folded into them; edits made after it wait
	 * for shutdown.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_role_edits_around_a_job_are_told_apart_from_the_jobs(): void {
		$this->on_site_with_prefix( 'wp_' );
		$recorder = new BuildingRecorder();
		ActionSchedulerContext::register();
		$sensor = new SettingsSensor( $recorder );
		$sensor->boot();

		$step1 = self::ROLES;
		$step1['editor']['capabilities']['list_users'] = true;
		$step2 = $step1;
		$step2['editor']['capabilities']['manage_options'] = true;
		$step3 = $step2;
		$step3['editor']['capabilities']['moderate_comments'] = true;

		$sensor->on_update( 'wp_user_roles', self::ROLES, $step1 );
		Hooks::fire( 'action_scheduler_before_execute', 41, 'WP Cron' );
		Hooks::fire( 'action_scheduler_begin_execute', 41, 'WP Cron' );
		$sensor->on_update( 'wp_user_roles', $step1, $step2 );
		Hooks::fire( 'action_scheduler_after_execute', 41, null, 'WP Cron' );
		$sensor->on_update( 'wp_user_roles', $step2, $step3 );
		Hooks::fire( 'shutdown' );

		$this->assertSame( [ 6005, 6005, 6005 ], array_column( $recorder->payloads, 'code' ) );
		$this->assertSame(
			[ 'list_users', 'manage_options', 'moderate_comments' ],
			array_column( array_merge( ...array_column( $recorder->payloads, 'changes' ) ), 'new' )
		);
		$this->assertSame( 'action-scheduler', $recorder->payloads[1]['source'] );
		$this->assertNotSame( 'action-scheduler', $recorder->payloads[0]['source'] );
		$this->assertNotSame( 'action-scheduler', $recorder->payloads[2]['source'] );
	}

	/**
	 * @param array{0: int, 1: array<string, mixed>} $call A recorded dispatch.
	 *
	 * @return array<string, mixed>
	 */
	private function payload( array $call ): array {
		$catalog = new EventCatalog();
		CatalogSeeder::seed( $catalog );
		$builder = new class( $catalog ) extends EventBuilder {

			public function __construct( EventCatalog $catalog ) {
				parent::__construct( $catalog, false, static fn(): string => RequestSource::REST );
			}

			protected function current_context(): RequestContext {
				return new RequestContext( 1, 'admin', [ 'administrator' ], null, null, null );
			}
		};

		$payload = $builder->build( $call[0], $call[1] );
		$this->assertIsArray( $payload );

		return $payload;
	}

	/**
	 * A site of a network whose tables start with the given prefix: the
	 * site and option stand-ins are loaded and `$wpdb` names the prefix.
	 * Only call this from a test of its own process.
	 *
	 * @param string $prefix Table prefix of the current site.
	 */
	private function on_site_with_prefix( string $prefix ): void {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-option-functions.php';
		$GLOBALS['wpdb'] = (object) [ 'prefix' => $prefix ];
	}

	/**
	 * A dispatcher that keeps what it was given, and the site each event was
	 * dispatched on in `blogs` (0 outside a network).
	 */
	private function recorder(): EventDispatcher {
		return new class() implements EventDispatcher {

			/** @var array<int, array{0: int, 1: array<string, mixed>}> */
			public array $calls = [];

			/** @var array<int, int> */
			public array $blogs = [];

			public function dispatch( int $code, array $fields = [] ): void {
				$this->calls[] = [ $code, $fields ];
				$this->blogs[] = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
			}
		};
	}
}
