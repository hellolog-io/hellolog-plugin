<?php
/**
 * Tests for {@see HelloLog\Sensors\OptionChangePolicy}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors;

use HelloLog\Events\RequestSource;
use HelloLog\Sensors\OptionChangePolicy;
use HelloLog\Sensors\SensitiveOption;
use HelloLog\Tests\Events\Fixtures\AuthState;
use HelloLog\Tests\Sensors\Fixtures\RequestState;
use PHPUnit\Framework\TestCase;

final class OptionChangePolicyTest extends TestCase {

	/**
	 * @dataProvider decisions
	 */
	public function test_only_a_person_changing_a_real_setting_is_logged( string $option, string $source, bool $has_actor, bool $expected ): void {
		$this->assertSame( $expected, OptionChangePolicy::should_log( $option, $source, $has_actor ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool, 3: bool}> Option, source, has actor, logged.
	 */
	public static function decisions(): array {
		return [
			'woo tracks queue bookkeeping in admin'   => [ 'woocommerce_clear_ces_tracks_queue_for_page', 'admin', true, false ],
			'rank math notifications in admin'        => [ 'rank_math_notifications', 'admin', true, false ],
			'woo currency saved in admin'             => [ 'woocommerce_currency', 'admin', true, true ],
			'woo guest checkout saved over ajax'      => [ 'woocommerce_enable_guest_checkout', 'ajax', true, true ],
			'rank math modules saved in admin'        => [ 'rank_math_modules', 'admin', true, true ],
			'woo currency from cron without actor'    => [ 'woocommerce_currency', 'cron', false, false ],
			'woo currency from the frontend'          => [ 'woocommerce_currency', 'frontend', true, false ],
			'yoast titles saved over rest'            => [ 'wpseo_titles', 'rest', true, true ],
			'woo db version bumped by cli'            => [ 'woocommerce_db_version', 'cli', true, false ],
		];
	}

	/**
	 * Reading the signed-in person while WordPress is still determining who
	 * that is would apply `determine_current_user` again from inside itself.
	 * Nobody is signed in yet at that point.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_signed_in_person_is_not_read_while_the_user_is_being_determined(): void {
		require_once __DIR__ . '/Fixtures/wordpress-request-functions.php';
		require_once __DIR__ . '/../Events/Fixtures/wordpress-auth-functions.php';

		AuthState::$filters = [ 'determine_current_user' ];
		$this->assertFalse( OptionChangePolicy::should_log_current( 'woocommerce_currency' ) );
		$this->assertSame( 0, RequestState::$user_id_reads );

		AuthState::$filters = [];
		$this->assertTrue( OptionChangePolicy::should_log_current( 'woocommerce_currency' ) );
		$this->assertSame( 1, RequestState::$user_id_reads );
	}

	public function test_the_original_three_argument_rule_still_holds(): void {
		$this->assertTrue( OptionChangePolicy::should_log( 'woocommerce_currency', 'admin', true ) );
		$this->assertFalse( OptionChangePolicy::should_log( 'woocommerce_currency', 'admin', false ) );
		$this->assertFalse( OptionChangePolicy::should_log( 'woocommerce_currency', 'cron', true ) );
	}

	/**
	 * A request can change its own method (`_method`, `X-HTTP-Method-Override`)
	 * and plugins save settings from GET links, so the method is recorded
	 * with the event but never decides whether it is logged.
	 */
	public function test_the_rule_does_not_depend_on_the_http_method(): void {
		$names = array_map(
			static fn( \ReflectionParameter $parameter ): string => $parameter->getName(),
			( new \ReflectionMethod( OptionChangePolicy::class, 'should_log' ) )->getParameters()
		);

		$this->assertSame( [ 'option', 'source', 'has_actor', 'background' ], $names );
	}

	/**
	 * @dataProvider sources
	 */
	public function test_a_real_setting_is_logged_for_interactive_sources_only( string $source, bool $expected ): void {
		$this->assertSame( $expected, OptionChangePolicy::should_log( 'woocommerce_currency', $source, true ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}> Source, logged.
	 */
	public static function sources(): array {
		return [
			RequestSource::ADMIN            => [ RequestSource::ADMIN, true ],
			RequestSource::AJAX             => [ RequestSource::AJAX, true ],
			RequestSource::REST             => [ RequestSource::REST, true ],
			RequestSource::CLI              => [ RequestSource::CLI, true ],
			RequestSource::FRONTEND         => [ RequestSource::FRONTEND, false ],
			RequestSource::CRON             => [ RequestSource::CRON, false ],
			RequestSource::ACTION_SCHEDULER => [ RequestSource::ACTION_SCHEDULER, false ],
			RequestSource::XMLRPC           => [ RequestSource::XMLRPC, false ],
			'an unknown source'             => [ 'something-else', false ],
			'an empty source'               => [ '', false ],
		];
	}

	/**
	 * A person is needed everywhere except the command line: WP-CLI runs as
	 * nobody unless `--user` is given, and a command is always somebody's
	 * deliberate act.
	 *
	 * @dataProvider sources
	 */
	public function test_only_the_command_line_is_logged_without_a_signed_in_person( string $source ): void {
		$this->assertSame(
			RequestSource::CLI === $source,
			OptionChangePolicy::should_log( 'woocommerce_currency', $source, false )
		);
	}

	/**
	 * @dataProvider sources
	 */
	public function test_background_work_is_never_logged_whatever_the_source( string $source ): void {
		foreach ( [ true, false ] as $has_actor ) {
			$this->assertFalse( OptionChangePolicy::should_log( 'woocommerce_currency', $source, $has_actor, true ) );
		}
	}

	public function test_a_cron_event_run_from_the_command_line_is_background_work(): void {
		$this->assertTrue( OptionChangePolicy::should_log( 'woocommerce_currency', RequestSource::CLI, false, false ) );
		$this->assertFalse( OptionChangePolicy::should_log( 'woocommerce_currency', RequestSource::CLI, false, true ) );
	}

	/**
	 * Real names (the brief and production): the first group is plugin
	 * bookkeeping. Names marked "(synthetic)" are made up to exercise exactly
	 * one alternative of the pattern.
	 *
	 * @dataProvider internal_options
	 */
	public function test_bookkeeping_options_are_never_logged( string $option ): void {
		$this->assertTrue( OptionChangePolicy::is_internal( $option ) );
		$this->assertFalse( OptionChangePolicy::should_log( $option, 'admin', true ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function internal_options(): array {
		return [
			// Real names.
			'tracks queue (real)'                    => [ 'woocommerce_clear_ces_tracks_queue_for_page' ],
			'rank math notifications (real)'         => [ 'rank_math_notifications' ],
			'woo db version (real)'                  => [ 'woocommerce_db_version' ],
			'woo marketplace suggestions (real)'     => [ 'woocommerce_marketplace_suggestions' ],
			'firewall log (real)'                    => [ 'lw_firewall_log' ],
			'firewall user locks (real)'             => [ 'lw_firewall_user_locks' ],
			'seo cleanup done flag (real)'           => [ 'lw_seo_cleanup_v1314_done' ],
			'woo orders table created flag (real)'   => [ 'woocommerce_custom_orders_table_created' ],
			'woo helper last refresh (real)'         => [ 'woocommerce_admin-wc-helper-last-refresh' ],
			// One synthetic name per alternative of the pattern.
			'leading underscore (synthetic)'         => [ '_woocommerce_helper_data' ],
			'transient (synthetic)'                  => [ 'woocommerce_admin_transient_x' ],
			'queue (synthetic)'                      => [ 'woocommerce_sync_queue' ],
			'notification (synthetic)'               => [ 'wpseo_first_notification' ],
			'plural notices (synthetic)'             => [ 'wpseo_dismissed_notices' ],
			'admin notice (synthetic)'               => [ 'rank_math_admin_notice' ],
			'admin notices (synthetic)'              => [ 'woocommerce_admin_notices' ],
			'tracks (synthetic)'                     => [ 'woocommerce_tracks_anon_id' ],
			'cache (synthetic)'                      => [ 'woocommerce_cache_settings' ],
			'lock (synthetic)'                       => [ 'woocommerce_task_lock' ],
			'locks (synthetic)'                      => [ 'lw_firewall_ip_locks' ],
			'log (synthetic)'                        => [ 'lw_cookie_consent_log' ],
			'bans (synthetic)'                       => [ 'lw_firewall_ip_bans' ],
			'baseline (synthetic)'                   => [ 'lw_firewall_file_baseline' ],
			'rev (synthetic)'                        => [ 'lw_seo_cache_rev' ],
			'done (synthetic)'                       => [ 'lw_cookie_migration_done' ],
			'skipped (synthetic)'                    => [ 'lw_seo_skipped_urls' ],
			'version (synthetic)'                    => [ 'rank_math_version' ],
			'last run (synthetic)'                   => [ 'woocommerce_last_run' ],
			'hyphenated last run (synthetic)'        => [ 'woocommerce_admin-sync-last-run' ],
			'timestamp (synthetic)'                  => [ 'wpseo_activation_timestamp' ],
			'hyphenated timestamp (synthetic)'       => [ 'woocommerce_admin-sync-timestamp' ],
			'onboarding (synthetic)'                 => [ 'woocommerce_onboarding_profile' ],
			'task list (synthetic)'                  => [ 'woocommerce_task_list_hidden' ],
			'admin notes (synthetic)'                => [ 'woocommerce_admin_notes_x' ],
			'dismissed (synthetic)'                  => [ 'rank_math_dismissed_x' ],
			'flush rewrite (synthetic)'              => [ 'wpseo_flush_rewrite' ],
			'schema version (synthetic)'             => [ 'wpseo_schema_version' ],
			'usage tracking (synthetic)'             => [ 'woocommerce_allow_tracking_usage_tracking' ],
			'table created (synthetic)'              => [ 'rank_math_redirections_table_created' ],
		];
	}

	/**
	 * Settings that the bookkeeping pattern must NOT swallow.
	 *
	 * @dataProvider setting_options
	 */
	public function test_ordinary_setting_names_are_not_internal( string $option ): void {
		$this->assertFalse( OptionChangePolicy::is_internal( $option ) );
		$this->assertTrue( OptionChangePolicy::should_log( $option, 'admin', true ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function setting_options(): array {
		return [
			'currency (real)'                      => [ 'woocommerce_currency' ],
			'guest checkout (real)'                => [ 'woocommerce_enable_guest_checkout' ],
			'demo store notice (real)'             => [ 'woocommerce_demo_store_notice' ],
			'show marketplace suggestions (real)'  => [ 'woocommerce_show_marketplace_suggestions' ],
			'rank math modules (real)'             => [ 'rank_math_modules' ],
			'rank math general (real)'             => [ 'rank-math-options-general' ],
			'yoast titles (real)'                  => [ 'wpseo_titles' ],
			'firewall rules (synthetic)'           => [ 'lw_firewall_rules' ],
			'termly banner position (synthetic)'   => [ 'termly_banner_position' ],
			'a word that holds last (synthetic)'   => [ 'woocommerce_plastic-bag-fee' ],
			'a word that holds timestamp (synthetic)' => [ 'woocommerce_timestamps-display' ],
		];
	}

	/**
	 * @dataProvider method_words
	 *
	 * @param string  $raw      What the server reports.
	 * @param ?string $expected The method that is recorded, null for none.
	 */
	public function test_only_a_plain_method_word_is_recorded( string $raw, ?string $expected ): void {
		$this->assertSame( $expected, OptionChangePolicy::method_name( $raw ) );
	}

	/**
	 * @return array<string, array{0: string, 1: ?string}>
	 */
	public static function method_words(): array {
		return [
			'upper case'                 => [ 'POST', 'POST' ],
			'lower case'                 => [ 'post', 'POST' ],
			'mixed case'                 => [ 'Get', 'GET' ],
			'a hyphenated method'        => [ 'VERSION-CONTROL', 'VERSION-CONTROL' ],
			'a trailing newline'         => [ "POST\n", null ],
			'a trailing newline, GET'    => [ "GET\n", null ],
			'a leading newline'          => [ "\nPOST", null ],
			'a trailing space'           => [ 'POST ', null ],
			'an embedded space'          => [ 'PO ST', null ],
			'punctuation'                => [ 'POST;x', null ],
			'a leading hyphen'           => [ '-POST', null ],
			'a digit'                    => [ 'POST2', null ],
			'twenty letters'             => [ str_repeat( 'A', 20 ), str_repeat( 'A', 20 ) ],
			'twenty-one letters'         => [ str_repeat( 'A', 21 ), null ],
			'empty'                      => [ '', null ],
		];
	}

	public function test_the_target_is_the_option_as_a_setting(): void {
		$this->assertSame(
			[
				'type'  => 'setting',
				'id'    => 'woocommerce_currency',
				'label' => 'woocommerce_currency',
			],
			OptionChangePolicy::target( 'woocommerce_currency' )
		);
	}

	public function test_the_changes_are_the_old_and_the_new_value_of_the_option(): void {
		$this->assertSame(
			[
				[
					'field' => 'woocommerce_currency',
					'old'   => 'USD',
					'new'   => 'EUR',
				],
			],
			OptionChangePolicy::changes( 'woocommerce_currency', 'USD', 'EUR' )
		);
	}

	public function test_changes_write_scalars_as_strings(): void {
		$this->assertSame(
			[
				[
					'field' => 'woocommerce_enable_guest_checkout',
					'old'   => 'no',
					'new'   => 'yes',
				],
			],
			OptionChangePolicy::changes( 'woocommerce_enable_guest_checkout', false, true )
		);
	}

	public function test_changes_of_a_credential_like_option_are_masked(): void {
		$changes = OptionChangePolicy::changes( 'woocommerce_shipping_api_key', 'old-secret-value', 'new-secret-value' );

		$this->assertSame(
			[
				[
					'field' => 'woocommerce_shipping_api_key',
					'old'   => SensitiveOption::REDACTED,
					'new'   => SensitiveOption::REDACTED,
				],
			],
			$changes
		);
		$this->assertStringNotContainsString( 'secret-value', (string) json_encode( $changes ) );
	}

	public function test_changes_of_an_array_option_list_the_option_once(): void {
		$changes = OptionChangePolicy::changes( 'rank_math_modules', [ 'seo', 'sitemap' ], [ 'seo', 'sitemap', 'redirections' ] );

		$this->assertSame( [ 'rank_math_modules' ], array_column( $changes, 'field' ) );
		$this->assertSame( 'redirections, seo, sitemap', $changes[0]['new'] );
	}

	/**
	 * @return array<string, array{0: string, 1: mixed, 2: mixed}> Option, old value, new value.
	 */
	public static function secrets_in_values(): array {
		return [
			'a key in a query string' => [ 'woocommerce_x_url', 'https://shop.example.com/', 'https://shop.example.com/hook?api_key=SECRET1' ],
			'a webhook url'           => [ 'lw_seo_slack_webhook', '', 'https://hooks.example.com/services/T0/B0/SECRET2' ],
			'name and value pairs'    => [ 'woocommerce_gw_settings', [], [ [ 'name' => 'secret_key', 'value' => 'SECRET3' ] ] ],
			'pairs in a json text'    => [ 'woocommerce_gw_json', '', '[{"name":"secret_key","value":"SECRET4"}]' ],
		];
	}

	/**
	 * @dataProvider secrets_in_values
	 *
	 * @param string $option Option name.
	 * @param mixed  $old    Value before.
	 * @param mixed  $new    Value after.
	 */
	public function test_secrets_inside_a_value_never_reach_the_changes( string $option, $old, $new ): void {
		$changes = OptionChangePolicy::changes( $option, $old, $new );

		$this->assertCount( 1, $changes );
		$this->assertDoesNotMatchRegularExpression( '/SECRET\d/', (string) json_encode( $changes, JSON_UNESCAPED_SLASHES ) );
	}
}
