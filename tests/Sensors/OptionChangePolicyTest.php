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
use PHPUnit\Framework\TestCase;

final class OptionChangePolicyTest extends TestCase {

	/**
	 * @dataProvider decisions
	 */
	public function test_only_a_person_saving_a_real_setting_is_logged( string $option, string $source, bool $has_actor, bool $expected ): void {
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
	 * @dataProvider sources
	 */
	public function test_no_source_is_logged_without_a_signed_in_person( string $source ): void {
		$this->assertFalse( OptionChangePolicy::should_log( 'woocommerce_currency', $source, false ) );
	}

	/**
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
			'leading underscore'   => [ '_woocommerce_helper_data' ],
			'transient'            => [ 'woocommerce_admin_transient_x' ],
			'queue'                => [ 'woocommerce_clear_ces_tracks_queue_for_page' ],
			'notifications'        => [ 'rank_math_notifications' ],
			'notification'         => [ 'wpseo_first_notification' ],
			'notices'              => [ 'wpseo_dismissed_notices' ],
			'notice'               => [ 'rank_math_admin_notice' ],
			'tracks'               => [ 'woocommerce_tracks_anon_id' ],
			'cache'                => [ 'woocommerce_cache_settings' ],
			'lock'                 => [ 'woocommerce_task_lock' ],
			'version'              => [ 'rank_math_version' ],
			'db version'           => [ 'woocommerce_db_version' ],
			'last run'             => [ 'woocommerce_last_run' ],
			'timestamp'            => [ 'wpseo_activation_timestamp' ],
			'onboarding'           => [ 'woocommerce_onboarding_profile' ],
			'task list'            => [ 'woocommerce_task_list_hidden' ],
			'marketplace'          => [ 'woocommerce_marketplace_suggestions' ],
			'admin notes'          => [ 'woocommerce_admin_notes_x' ],
			'dismissed'            => [ 'rank_math_dismissed_x' ],
			'flush rewrite'        => [ 'wpseo_flush_rewrite' ],
			'schema version'       => [ 'wpseo_schema_version' ],
			'usage tracking'       => [ 'woocommerce_allow_tracking_usage_tracking' ],
		];
	}

	/**
	 * @dataProvider setting_options
	 */
	public function test_ordinary_setting_names_are_not_internal( string $option ): void {
		$this->assertFalse( OptionChangePolicy::is_internal( $option ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function setting_options(): array {
		return [
			'currency'       => [ 'woocommerce_currency' ],
			'guest checkout' => [ 'woocommerce_enable_guest_checkout' ],
			'modules'        => [ 'rank_math_modules' ],
			'titles'         => [ 'wpseo_titles' ],
			'termly'         => [ 'termly_banner_position' ],
			'firewall'       => [ 'lw_firewall_rules' ],
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
}
