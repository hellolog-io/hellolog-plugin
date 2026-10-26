<?php
/**
 * Tests for {@see HelloLog\Events\ChangeSet}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Events;

use HelloLog\Events\ChangeSet;
use PHPUnit\Framework\TestCase;

/**
 * The helper is pure: snapshots in, an old-to-new list out. The tests pin the
 * output contract (a 0-indexed list of exact `field`/`old`/`new` triples with
 * string-or-null values), the masking rules and the merge rule.
 */
final class ChangeSetTest extends TestCase {

	/**
	 * @param string  $field Field name.
	 * @param ?string $old   Old value.
	 * @param ?string $new   New value.
	 *
	 * @return array{field: string, old: ?string, new: ?string}
	 */
	private static function item( string $field, ?string $old, ?string $new ): array {
		return [
			'field' => $field,
			'old'   => $old,
			'new'   => $new,
		];
	}

	// ---- diff ----------------------------------------------------------

	public function test_identical_snapshots_produce_no_changes(): void {
		$row = [
			'post_title'  => 'Hello',
			'post_status' => 'draft',
		];

		$this->assertSame( [], ChangeSet::diff( $row, $row, [ 'post_title', 'post_status' ] ) );
	}

	public function test_title_change_is_one_exact_item(): void {
		$changes = ChangeSet::diff(
			[
				'post_title'  => 'Hello',
				'post_status' => 'draft',
			],
			[
				'post_title'  => 'Hello world',
				'post_status' => 'draft',
			],
			[ 'post_title', 'post_status' ]
		);

		$this->assertSame( [ self::item( 'post_title', 'Hello', 'Hello world' ) ], $changes );
	}

	public function test_items_follow_the_fields_order_not_the_snapshot_order(): void {
		$changes = ChangeSet::diff(
			[
				'a' => '1',
				'b' => '1',
				'c' => '1',
			],
			[
				'c' => '2',
				'b' => '2',
				'a' => '2',
			],
			[ 'b', 'c', 'a' ]
		);

		$this->assertSame( [ 'b', 'c', 'a' ], array_column( $changes, 'field' ) );
	}

	public function test_only_listed_fields_are_compared(): void {
		$changes = ChangeSet::diff(
			[
				'a' => '1',
				'b' => '1',
			],
			[
				'a' => '2',
				'b' => '2',
			],
			[ 'a' ]
		);

		$this->assertSame( [ self::item( 'a', '1', '2' ) ], $changes );
	}

	public function test_result_is_a_zero_indexed_list_when_leading_fields_are_unchanged(): void {
		$changes = ChangeSet::diff(
			[
				'a' => '1',
				'b' => '1',
				'c' => '1',
			],
			[
				'a' => '1',
				'b' => '2',
				'c' => '2',
			],
			[ 'a', 'b', 'c' ]
		);

		$this->assertSame( [ 0, 1 ], array_keys( $changes ) );
	}

	public function test_missing_keys_count_as_null(): void {
		$added   = ChangeSet::diff( [], [ 'k' => 'v' ], [ 'k' ] );
		$removed = ChangeSet::diff( [ 'k' => 'v' ], [], [ 'k' ] );
		$absent  = ChangeSet::diff( [], [], [ 'k' ] );

		$this->assertSame( [ self::item( 'k', null, 'v' ) ], $added );
		$this->assertSame( [ self::item( 'k', 'v', null ) ], $removed );
		$this->assertSame( [], $absent );
	}

	public function test_an_empty_string_differs_from_a_missing_value(): void {
		$changes = ChangeSet::diff( [], [ 'k' => '' ], [ 'k' ] );

		$this->assertSame( [ self::item( 'k', null, '' ) ], $changes );
	}

	public function test_integer_field_names_become_string_fields(): void {
		$changes = ChangeSet::diff( [ 7 => 'a' ], [ 7 => 'b' ], [ 7 ] );

		$this->assertSame( [ self::item( '7', 'a', 'b' ) ], $changes );
		$this->assertIsString( $changes[0]['field'] );
	}

	public function test_numbers_and_numeric_strings_are_equal_after_stringifying(): void {
		$this->assertSame( [], ChangeSet::diff( [ 'n' => 5 ], [ 'n' => '5' ], [ 'n' ] ) );
		$this->assertSame(
			[ self::item( 'n', '5', '6' ) ],
			ChangeSet::diff( [ 'n' => 5 ], [ 'n' => 6 ], [ 'n' ] )
		);
	}

	public function test_boolean_change_reads_yes_no(): void {
		$changes = ChangeSet::diff( [ 'on' => true ], [ 'on' => false ], [ 'on' ] );

		$this->assertSame( [ self::item( 'on', 'yes', 'no' ) ], $changes );
	}

	public function test_content_fields_report_only_that_they_changed(): void {
		$changes = ChangeSet::diff(
			[
				'post_title'   => 'T',
				'post_content' => 'old body',
			],
			[
				'post_title'   => 'T',
				'post_content' => 'new body',
			],
			[ 'post_title', 'post_content' ],
			[ 'post_content' ]
		);

		$this->assertSame( [ self::item( 'post_content', null, null ) ], $changes );
	}

	public function test_unchanged_content_fields_are_silent(): void {
		$row = [ 'post_content' => 'same body' ];

		$this->assertSame( [], ChangeSet::diff( $row, $row, [ 'post_content' ], [ 'post_content' ] ) );
	}

	public function test_content_change_past_the_truncation_limit_is_still_detected(): void {
		$old = str_repeat( 'x', 2000 );
		$new = substr( $old, 0, 1999 ) . 'y';

		$changes = ChangeSet::diff(
			[ 'post_content' => $old ],
			[ 'post_content' => $new ],
			[ 'post_content' ],
			[ 'post_content' ]
		);

		$this->assertSame( [ self::item( 'post_content', null, null ) ], $changes );
	}

	public function test_long_values_that_differ_only_after_the_limit_become_a_changed_flag(): void {
		$old = str_repeat( 'x', 2000 );
		$new = substr( $old, 0, 1999 ) . 'y';

		$changes = ChangeSet::diff( [ 'f' => $old ], [ 'f' => $new ], [ 'f' ] );

		// Both sides would be cut to the same text, which reads as "no change"
		// and would be dropped later — so the item carries no values instead.
		$this->assertSame( [ self::item( 'f', null, null ) ], $changes );
	}

	public function test_long_values_that_differ_within_the_limit_keep_their_cut_values(): void {
		$old = str_repeat( 'x', 2000 );
		$new = 'y' . substr( $old, 1 );

		$changes = ChangeSet::diff( [ 'f' => $old ], [ 'f' => $new ], [ 'f' ] );

		$this->assertCount( 1, $changes );
		$this->assertSame( 1024, mb_strlen( $changes[0]['old'], 'UTF-8' ) );
		$this->assertSame( 1024, mb_strlen( $changes[0]['new'], 'UTF-8' ) );
		$this->assertNotSame( $changes[0]['old'], $changes[0]['new'] );
	}

	public function test_a_long_value_change_survives_merge(): void {
		$old = str_repeat( 'x', 2000 );
		$new = substr( $old, 0, 1999 ) . 'y';

		$changes = ChangeSet::diff( [ 'f' => $old ], [ 'f' => $new ], [ 'f' ] );

		$this->assertSame( $changes, ChangeSet::merge( $changes, [] ) );
		$this->assertSame( $changes, ChangeSet::merge( [], $changes ) );
		$this->assertSame( $changes, ChangeSet::merge( $changes, $changes ) );
	}

	public function test_author_fields_keep_their_values(): void {
		$changes = ChangeSet::diff(
			[
				'post_author'    => 1,
				'comment_author' => 'Ann',
			],
			[
				'post_author'    => 2,
				'comment_author' => 'Bob',
			],
			[ 'post_author', 'comment_author' ]
		);

		$this->assertSame(
			[ self::item( 'post_author', '1', '2' ), self::item( 'comment_author', 'Ann', 'Bob' ) ],
			$changes
		);
	}

	public function test_credential_like_names_are_redacted_on_both_sides(): void {
		$changes = ChangeSet::diff(
			[ 'stripe_secret_key' => 'sk_live_old' ],
			[ 'stripe_secret_key' => 'sk_live_new' ],
			[ 'stripe_secret_key' ]
		);

		$this->assertSame( [ self::item( 'stripe_secret_key', '[redacted]', '[redacted]' ) ], $changes );
	}

	public function test_an_unchanged_credential_is_not_reported(): void {
		$row = [ 'api_token' => 'same' ];

		$this->assertSame( [], ChangeSet::diff( $row, $row, [ 'api_token' ] ) );
	}

	public function test_a_credential_set_for_the_first_time_is_redacted_not_null(): void {
		$changes = ChangeSet::diff( [], [ 'smtp_password_x_key' => 'abc' ], [ 'smtp_password_x_key' ] );

		$this->assertSame( [ self::item( 'smtp_password_x_key', '[redacted]', '[redacted]' ) ], $changes );
	}

	/**
	 * A gateway's whole settings array is one option. The keys inside it that
	 * look like credentials must never be sent, whatever else changes.
	 *
	 * @param string $enabled Value of the `enabled` setting.
	 * @param string $suffix  Distinguishes the secrets of one snapshot from another.
	 *
	 * @return array<string, mixed>
	 */
	private static function stripe_settings( string $enabled, string $suffix ): array {
		return [
			'enabled'         => $enabled,
			'title'           => 'Card',
			'secret_key'      => 'sk_live_' . $suffix,
			'test_secret_key' => 'sk_test_' . $suffix,
			'webhook_secret'  => 'whsec_' . $suffix,
		];
	}

	public function test_a_nested_secret_that_is_the_only_change_becomes_a_changed_flag(): void {
		$changes = ChangeSet::diff(
			[ 'woocommerce_stripe_settings' => self::stripe_settings( 'yes', 'OLD' ) ],
			[ 'woocommerce_stripe_settings' => self::stripe_settings( 'yes', 'NEW' ) ],
			[ 'woocommerce_stripe_settings' ]
		);

		$this->assertSame( [ self::item( 'woocommerce_stripe_settings', null, null ) ], $changes );
	}

	public function test_nested_secrets_are_masked_next_to_a_visible_change(): void {
		$changes = ChangeSet::diff(
			[ 'woocommerce_stripe_settings' => self::stripe_settings( 'no', 'OLD' ) ],
			[ 'woocommerce_stripe_settings' => self::stripe_settings( 'yes', 'NEW' ) ],
			[ 'woocommerce_stripe_settings' ]
		);

		$this->assertSame(
			[
				self::item(
					'woocommerce_stripe_settings',
					'{"enabled":"no","secret_key":"[redacted]","test_secret_key":"[redacted]","title":"Card","webhook_secret":"[redacted]"}',
					'{"enabled":"yes","secret_key":"[redacted]","test_secret_key":"[redacted]","title":"Card","webhook_secret":"[redacted]"}'
				),
			],
			$changes
		);

		$json = (string) json_encode( $changes );
		foreach ( [ 'sk_live_', 'sk_test_', 'whsec_', 'OLD', 'NEW' ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
	}

	public function test_a_structure_stored_under_a_credential_like_key_is_masked_whole(): void {
		$tokens = static fn( string $suffix ): array => [
			'access_token'  => 'at_' . $suffix,
			'refresh_token' => 'rt_' . $suffix,
			'expires'       => 3600,
		];

		$changes = ChangeSet::diff(
			[
				'wpseo' => [
					'content_analysis_active' => false,
					'semrush_tokens'          => $tokens( 'OLD' ),
					'wincher_tokens'          => $tokens( 'OLD' ),
				],
			],
			[
				'wpseo' => [
					'content_analysis_active' => true,
					'semrush_tokens'          => $tokens( 'NEW' ),
					'wincher_tokens'          => $tokens( 'NEW' ),
				],
			],
			[ 'wpseo' ]
		);

		$this->assertSame(
			[
				self::item(
					'wpseo',
					'{"content_analysis_active":false,"semrush_tokens":"[redacted]","wincher_tokens":"[redacted]"}',
					'{"content_analysis_active":true,"semrush_tokens":"[redacted]","wincher_tokens":"[redacted]"}'
				),
			],
			$changes
		);

		$json = (string) json_encode( $changes );
		foreach ( [ 'at_', 'rt_', 'OLD', 'NEW' ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
	}

	/**
	 * @param string $iban IBAN of the second account.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function bacs_accounts( string $iban ): array {
		return [
			[
				'account_name'   => 'Shop Ltd',
				'account_number' => '12345678',
				'sort_code'      => '12-34-56',
				'bank_name'      => 'Example Bank',
				'iban'           => 'GB33BUKB20201555555555',
				'bic'            => 'BUKBGB22',
			],
			[
				'account_name'   => 'Shop Ltd EUR',
				'account_number' => '87654321',
				'sort_code'      => '65-43-21',
				'bank_name'      => 'Other Bank',
				'iban'           => $iban,
				'bic'            => 'ABCDDEFF',
			],
		];
	}

	public function test_bank_details_inside_a_list_of_accounts_are_masked(): void {
		$changes = ChangeSet::diff(
			[ 'woocommerce_bacs_accounts' => self::bacs_accounts( 'DE89370400440532013000' ) ],
			[ 'woocommerce_bacs_accounts' => self::bacs_accounts( 'FR1420041010050500013M02606' ) ],
			[ 'woocommerce_bacs_accounts' ]
		);

		$this->assertSame( [ self::item( 'woocommerce_bacs_accounts', null, null ) ], $changes );

		$renamed = self::bacs_accounts( 'DE89370400440532013000' );
		$renamed[1]['account_name'] = 'Shop Ltd EUR 2';

		$changes = ChangeSet::diff(
			[ 'woocommerce_bacs_accounts' => self::bacs_accounts( 'DE89370400440532013000' ) ],
			[ 'woocommerce_bacs_accounts' => $renamed ],
			[ 'woocommerce_bacs_accounts' ]
		);

		$this->assertCount( 1, $changes );
		$json = (string) json_encode( $changes );
		$this->assertStringContainsString( 'Shop Ltd EUR 2', $json );
		$this->assertStringContainsString( '"iban":"[redacted]"', (string) $changes[0]['new'] );
		foreach ( [ '12345678', '87654321', '12-34-56', 'GB33BUKB', 'DE89', 'BUKBGB22', 'ABCDDEFF' ] as $detail ) {
			$this->assertStringNotContainsString( $detail, $json );
		}
	}

	public function test_nested_masking_ignores_the_case_of_a_key(): void {
		$changes = ChangeSet::diff(
			[ 'gateway' => [ 'API_KEY' => 'one' ] ],
			[ 'gateway' => [ 'API_KEY' => 'two' ] ],
			[ 'gateway' ]
		);

		$this->assertSame( [ self::item( 'gateway', null, null ) ], $changes );
	}

	public function test_a_secret_inside_a_nested_object_is_masked_too(): void {
		$old = [ 'gateway' => [ 'mode' => 'test', 'client' => (object) [ 'client_secret' => 'OLD', 'id' => 'abc' ] ] ];
		$new = [ 'gateway' => [ 'mode' => 'live', 'client' => (object) [ 'client_secret' => 'NEW', 'id' => 'abc' ] ] ];

		$changes = ChangeSet::diff( $old, $new, [ 'gateway' ] );

		$this->assertSame(
			[
				self::item(
					'gateway',
					'{"client":{"client_secret":"[redacted]","id":"abc"},"mode":"test"}',
					'{"client":{"client_secret":"[redacted]","id":"abc"},"mode":"live"}'
				),
			],
			$changes
		);
	}

	public function test_a_credential_like_key_in_a_list_item_is_masked(): void {
		$changes = ChangeSet::diff(
			[ 'hooks' => [ [ 'url' => 'https://a.example', 'secret' => 'OLD' ] ] ],
			[ 'hooks' => [ [ 'url' => 'https://b.example', 'secret' => 'NEW' ] ] ],
			[ 'hooks' ]
		);

		$this->assertCount( 1, $changes );
		$this->assertStringNotContainsString( 'OLD', (string) json_encode( $changes ) );
		$this->assertStringNotContainsString( 'NEW', (string) json_encode( $changes ) );
	}

	// ---- secrets inside string values ---------------------------------

	/**
	 * @return array<string, array{0: string, 1: string}> Text, what is reported.
	 */
	public static function urls_with_userinfo(): array {
		return [
			'user and password'      => [ 'https://admin:hunter2@db.example.com/path', 'https://[redacted]@db.example.com/path' ],
			'a token as the user'    => [ 'https://ghp_abc123@github.com/org/repo.git', 'https://[redacted]@github.com/org/repo.git' ],
			'password only'          => [ 'redis://:hunter2@cache.example.com:6379', 'redis://[redacted]@cache.example.com:6379' ],
			'a connection string'    => [ 'mysql://root:hunter2@127.0.0.1:3306/shop', 'mysql://[redacted]@127.0.0.1:3306/shop' ],
			'inside a longer text'   => [ 'Backup to sftp://bob:hunter2@files.example.com now', 'Backup to sftp://[redacted]@files.example.com now' ],
			'two urls'               => [ 'http://a:b@one.example http://c:d@two.example', 'http://[redacted]@one.example http://[redacted]@two.example' ],
		];
	}

	/**
	 * @dataProvider urls_with_userinfo
	 *
	 * @param string $text     A text with credentials in a URL.
	 * @param string $reported What may be reported.
	 */
	public function test_url_credentials_in_a_value_are_masked( string $text, string $reported ): void {
		$this->assertSame( $reported, ChangeSet::stringify( $text ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function urls_without_userinfo(): array {
		return [
			'plain url'             => [ 'https://example.com/path' ],
			'an at sign in a query' => [ 'https://example.com/path?mail=bob@example.com' ],
			'an at sign in a path'  => [ 'https://example.com/@bob/posts' ],
			'a mail address'        => [ 'bob@example.com' ],
			'a mailto link'         => [ 'mailto:bob@example.com' ],
			'a port'                => [ 'http://localhost:8080/x' ],
		];
	}

	/**
	 * @dataProvider urls_without_userinfo
	 *
	 * @param string $text A text with nothing to mask.
	 */
	public function test_a_url_without_credentials_is_untouched( string $text ): void {
		$this->assertSame( $text, ChangeSet::stringify( $text ) );
	}

	public function test_a_change_of_a_url_password_only_is_a_changed_flag(): void {
		$changes = ChangeSet::diff(
			[ 'backup_target' => 'sftp://bob:OLDPASS@files.example.com/backups' ],
			[ 'backup_target' => 'sftp://bob:NEWPASS@files.example.com/backups' ],
			[ 'backup_target' ]
		);

		$this->assertSame( [ self::item( 'backup_target', null, null ) ], $changes );
	}

	public function test_a_url_changed_next_to_its_credentials_shows_the_host_not_the_password(): void {
		$changes = ChangeSet::diff(
			[ 'backup_target' => 'sftp://bob:OLDPASS@old.example.com/backups' ],
			[ 'backup_target' => 'sftp://bob:NEWPASS@new.example.com/backups' ],
			[ 'backup_target' ]
		);

		$this->assertSame(
			[ self::item( 'backup_target', 'sftp://[redacted]@old.example.com/backups', 'sftp://[redacted]@new.example.com/backups' ) ],
			$changes
		);
		$this->assertStringNotContainsString( 'PASS', (string) json_encode( $changes ) );
	}

	public function test_a_url_with_credentials_inside_an_array_is_masked(): void {
		$this->assertSame(
			'{"name":"main","url":"mysql://[redacted]@db.example.com/shop"}',
			ChangeSet::stringify(
				[
					'name' => 'main',
					'url'  => 'mysql://root:hunter2@db.example.com/shop',
				]
			)
		);
	}

	public function test_a_json_string_has_its_secrets_masked(): void {
		$this->assertSame(
			'{"enabled":"yes","secret_key":"[redacted]"}',
			ChangeSet::stringify( '{"secret_key":"sk_live_abc","enabled":"yes"}' )
		);
	}

	public function test_a_json_list_string_has_its_secrets_masked(): void {
		$this->assertSame(
			'[{"name":"a","token":"[redacted]"},{"name":"b","token":"[redacted]"}]',
			ChangeSet::stringify( '[{"name":"a","token":"t1"},{"name":"b","token":"t2"}]' )
		);
	}

	public function test_json_inside_json_is_masked_at_every_level(): void {
		$inner = (string) json_encode( [ 'client_secret' => 'deep', 'id' => 'abc' ] );
		$text  = (string) json_encode( [ 'upstream' => $inner, 'mode' => 'live' ] );

		$reported = (string) ChangeSet::stringify( $text );

		$this->assertStringNotContainsString( 'deep', $reported );
		$this->assertStringContainsString( 'client_secret', $reported );
		$this->assertStringContainsString( '[redacted]', $reported );
	}

	public function test_a_json_string_with_a_credential_url_inside_is_masked(): void {
		$this->assertSame(
			'{"endpoint":"https://[redacted]@api.example.com/v1"}',
			ChangeSet::stringify( '{"endpoint":"https://bob:hunter2@api.example.com/v1"}' )
		);
	}

	public function test_a_json_string_without_secrets_is_reported_as_it_was_written(): void {
		$text = '{ "title": "Card",  "enabled": true }';

		$this->assertSame( $text, ChangeSet::stringify( $text ) );
		$this->assertSame( '[1, 2, 3]', ChangeSet::stringify( '[1, 2, 3]' ) );
	}

	public function test_text_that_only_looks_like_json_is_untouched(): void {
		foreach ( [ '{not json}', '[draft] Hello', '{"a":', '{}x', '"quoted"', '123', 'true' ] as $text ) {
			$this->assertSame( $text, ChangeSet::stringify( $text ) );
		}
	}

	public function test_a_json_string_that_only_changed_in_a_secret_is_a_changed_flag(): void {
		$changes = ChangeSet::diff(
			[ 'gateway_json' => '{"secret_key":"OLD","enabled":"yes"}' ],
			[ 'gateway_json' => '{"secret_key":"NEW","enabled":"yes"}' ],
			[ 'gateway_json' ]
		);

		$this->assertSame( [ self::item( 'gateway_json', null, null ) ], $changes );
	}

	public function test_a_json_string_changed_next_to_its_secrets_masks_them(): void {
		$changes = ChangeSet::diff(
			[ 'gateway_json' => '{"secret_key":"OLD","enabled":"no"}' ],
			[ 'gateway_json' => '{"secret_key":"NEW","enabled":"yes"}' ],
			[ 'gateway_json' ]
		);

		$this->assertSame(
			[
				self::item(
					'gateway_json',
					'{"enabled":"no","secret_key":"[redacted]"}',
					'{"enabled":"yes","secret_key":"[redacted]"}'
				),
			],
			$changes
		);
	}

	/**
	 * A PHP-serialized string is never unserialized: that can instantiate
	 * objects chosen by whoever wrote the option.
	 */
	public function test_a_serialized_string_is_never_unserialized(): void {
		$probe = serialize( new UnserializeProbe() );

		ChangeSet::stringify( $probe );
		ChangeSet::diff( [ 'x' => $probe . ' ' ], [ 'x' => $probe ], [ 'x' ] );

		$this->assertFalse( UnserializeProbe::$woken );
	}

	public function test_a_key_that_is_not_a_credential_keeps_its_value(): void {
		$changes = ChangeSet::diff(
			[ 'gateway' => [ 'title' => 'Card', 'authors' => 'Ann' ] ],
			[ 'gateway' => [ 'title' => 'Credit card', 'authors' => 'Ann' ] ],
			[ 'gateway' ]
		);

		$this->assertSame(
			[ self::item( 'gateway', '{"authors":"Ann","title":"Card"}', '{"authors":"Ann","title":"Credit card"}' ) ],
			$changes
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function password_field_provider(): array {
		return [
			'password'  => [ 'password' ],
			'user_pass' => [ 'user_pass' ],
			'uppercase' => [ 'Password' ],
		];
	}

	/**
	 * @dataProvider password_field_provider
	 *
	 * @param string $field Field name.
	 */
	public function test_password_fields_carry_no_values_at_all( string $field ): void {
		$changes = ChangeSet::diff( [ $field => 'hash-1' ], [ $field => 'hash-2' ], [ $field ] );

		$this->assertSame( [ self::item( $field, null, null ) ], $changes );
	}

	public function test_a_duplicate_field_name_is_reported_once(): void {
		$changes = ChangeSet::diff( [ 'a' => '1' ], [ 'a' => '2' ], [ 'a', 'a' ] );

		$this->assertCount( 1, $changes );
	}

	// ---- stringify -----------------------------------------------------

	public function test_stringify_scalars(): void {
		$this->assertNull( ChangeSet::stringify( null ) );
		$this->assertSame( 'yes', ChangeSet::stringify( true ) );
		$this->assertSame( 'no', ChangeSet::stringify( false ) );
		$this->assertSame( '42', ChangeSet::stringify( 42 ) );
		$this->assertSame( '0', ChangeSet::stringify( 0 ) );
		$this->assertSame( '1.5', ChangeSet::stringify( 1.5 ) );
		$this->assertSame( 'text', ChangeSet::stringify( 'text' ) );
		$this->assertSame( '', ChangeSet::stringify( '' ) );
	}

	public function test_stringify_non_finite_floats(): void {
		$this->assertSame( 'NAN', ChangeSet::stringify( NAN ) );
		$this->assertSame( 'INF', ChangeSet::stringify( INF ) );
		$this->assertSame( '-INF', ChangeSet::stringify( -INF ) );
	}

	public function test_list_arrays_are_sorted_and_joined(): void {
		$this->assertSame( 'a, b', ChangeSet::stringify( [ 'b', 'a' ] ) );
		$this->assertSame( '', ChangeSet::stringify( [] ) );
		$this->assertSame( 'no, yes', ChangeSet::stringify( [ true, false ] ) );
	}

	public function test_reordered_lists_are_not_a_change(): void {
		$this->assertSame( [], ChangeSet::diff( [ 'roles' => [ 'b', 'a' ] ], [ 'roles' => [ 'a', 'b' ] ], [ 'roles' ] ) );
	}

	public function test_a_changed_list_is_reported_as_sorted_text(): void {
		$changes = ChangeSet::diff(
			[ 'roles' => [ 'editor' ] ],
			[ 'roles' => [ 'editor', 'author' ] ],
			[ 'roles' ]
		);

		$this->assertSame( [ self::item( 'roles', 'editor', 'author, editor' ) ], $changes );
	}

	public function test_assoc_arrays_become_json_with_sorted_keys(): void {
		$this->assertSame(
			'{"a":1,"b":2}',
			ChangeSet::stringify(
				[
					'b' => 2,
					'a' => 1,
				]
			)
		);
	}

	public function test_nested_assoc_keys_are_sorted_too(): void {
		$one = [
			'z' => [
				'y' => 1,
				'x' => 2,
			],
			'a' => 'é/',
		];
		$two = [
			'a' => 'é/',
			'z' => [
				'x' => 2,
				'y' => 1,
			],
		];

		$this->assertSame( '{"a":"é/","z":{"x":2,"y":1}}', ChangeSet::stringify( $one ) );
		$this->assertSame( ChangeSet::stringify( $one ), ChangeSet::stringify( $two ) );
	}

	public function test_a_key_order_difference_is_not_a_change(): void {
		$before = [
			'opt' => [
				'b' => 1,
				'a' => 2,
			],
		];
		$after  = [
			'opt' => [
				'a' => 2,
				'b' => 1,
			],
		];

		$this->assertSame( [], ChangeSet::diff( $before, $after, [ 'opt' ] ) );
	}

	public function test_stringify_masks_credential_like_keys_at_every_depth(): void {
		$this->assertSame(
			'{"a":{"api_key":"[redacted]","name":"x"},"token":"[redacted]"}',
			ChangeSet::stringify(
				[
					'token' => 'tk',
					'a'     => [
						'name'    => 'x',
						'api_key' => 'k',
					],
				]
			)
		);
	}

	public function test_long_strings_are_cut_to_1024_characters_ending_with_an_ellipsis(): void {
		$out = ChangeSet::stringify( str_repeat( 'a', 2000 ) );

		$this->assertSame( 1024, mb_strlen( $out, 'UTF-8' ) );
		$this->assertSame( '…', mb_substr( $out, -1, null, 'UTF-8' ) );
		$this->assertSame( str_repeat( 'a', 1023 ), mb_substr( $out, 0, 1023, 'UTF-8' ) );
	}

	public function test_truncation_counts_characters_not_bytes(): void {
		$out = ChangeSet::stringify( str_repeat( 'é', 1500 ) );

		$this->assertSame( 1024, mb_strlen( $out, 'UTF-8' ) );
		$this->assertTrue( mb_check_encoding( $out, 'UTF-8' ) );
		$this->assertSame( str_repeat( 'é', 1023 ) . '…', $out );
	}

	public function test_a_value_of_exactly_1024_characters_is_kept_whole(): void {
		$exact = str_repeat( 'b', 1024 );

		$this->assertSame( $exact, ChangeSet::stringify( $exact ) );
		$this->assertSame( 1024, mb_strlen( ChangeSet::stringify( $exact . 'c' ), 'UTF-8' ) );
	}

	public function test_long_json_is_truncated_as_well(): void {
		$out = ChangeSet::stringify( [ 'k' => str_repeat( 'a', 3000 ) ] );

		$this->assertSame( 1024, mb_strlen( $out, 'UTF-8' ) );
		$this->assertSame( '…', mb_substr( $out, -1, null, 'UTF-8' ) );
	}

	public function test_invalid_utf8_inside_an_array_does_not_break_stringify(): void {
		$out = ChangeSet::stringify( [ 'k' => "bad\xB1" ] );

		$this->assertIsString( $out );
		$this->assertTrue( mb_check_encoding( $out, 'UTF-8' ) );
	}

	public function test_a_self_referencing_array_is_cut_off_instead_of_recursing_forever(): void {
		$a         = [ 'k' => 'v' ];
		$a['self'] = &$a;

		$out = ChangeSet::stringify( $a );

		$this->assertIsString( $out );
		$this->assertLessThanOrEqual( 1024, mb_strlen( $out, 'UTF-8' ) );
		$this->assertStringContainsString( '…', $out );
		$this->assertSame( [ 'k', 'self' ], array_keys( $a ), 'the input must not be modified' );
	}

	public function test_a_self_referencing_list_is_cut_off_too(): void {
		$list   = [ 'a' ];
		$list[] = &$list;

		$out = ChangeSet::stringify( $list );

		$this->assertIsString( $out );
		$this->assertStringContainsString( '…', $out );
	}

	public function test_very_deep_nesting_is_cut_to_an_ellipsis(): void {
		$deep = 'leaf';
		for ( $i = 0; $i < 30; $i++ ) {
			$deep = [ 'a' => $deep ];
		}

		$out = ChangeSet::stringify( $deep );

		$this->assertStringContainsString( '"…"', $out );
		$this->assertStringNotContainsString( 'leaf', $out );
	}

	public function test_ordinary_nesting_is_not_cut(): void {
		$deep = 1;
		for ( $i = 0; $i < 5; $i++ ) {
			$deep = [ 'a' => $deep ];
		}

		$this->assertSame( '{"a":{"a":{"a":{"a":{"a":1}}}}}', ChangeSet::stringify( $deep ) );
	}

	public function test_invalid_utf8_in_a_string_is_replaced(): void {
		$out = ChangeSet::stringify( "bad\xB1end" );

		$this->assertSame( "bad\u{FFFD}end", $out );
		$this->assertTrue( mb_check_encoding( $out, 'UTF-8' ) );
	}

	public function test_valid_multibyte_strings_pass_through_untouched(): void {
		$this->assertSame( 'árvíztűrő tükörfúrógép', ChangeSet::stringify( 'árvíztűrő tükörfúrógép' ) );
	}

	public function test_invalid_utf8_never_reaches_a_reported_value(): void {
		$changes = ChangeSet::diff(
			[ 'a' => "x\xB1" ],
			[
				'a'    => "y\xB1",
				'list' => [ "z\xB1" ],
			],
			[ 'a', 'list' ]
		);

		$this->assertCount( 2, $changes );
		foreach ( $changes as $change ) {
			foreach ( [ 'old', 'new' ] as $side ) {
				if ( null !== $change[ $side ] ) {
					$this->assertTrue( mb_check_encoding( $change[ $side ], 'UTF-8' ), $change['field'] . ' ' . $side );
				}
			}
		}
	}

	// ---- merge ---------------------------------------------------------

	public function test_merge_keeps_the_first_old_and_the_last_new(): void {
		$merged = ChangeSet::merge(
			[ self::item( 'status', 'draft', 'pending' ) ],
			[ self::item( 'status', 'pending', 'publish' ) ]
		);

		$this->assertSame( [ self::item( 'status', 'draft', 'publish' ) ], $merged );
	}

	public function test_merge_drops_a_field_that_returned_to_its_original_value(): void {
		$merged = ChangeSet::merge(
			[ self::item( 'status', 'draft', 'pending' ) ],
			[ self::item( 'status', 'pending', 'draft' ) ]
		);

		$this->assertSame( [], $merged );
	}

	public function test_merge_drops_a_value_that_was_added_and_removed_again(): void {
		$merged = ChangeSet::merge(
			[ self::item( 'opt', null, 'x' ) ],
			[ self::item( 'opt', 'x', null ) ]
		);

		$this->assertSame( [], $merged );
	}

	public function test_merge_concatenates_distinct_fields_in_order(): void {
		$merged = ChangeSet::merge(
			[ self::item( 'a', '1', '2' ), self::item( 'b', '1', '2' ) ],
			[ self::item( 'c', '1', '2' ) ]
		);

		$this->assertSame( [ 'a', 'b', 'c' ], array_column( $merged, 'field' ) );
		$this->assertSame( [ 0, 1, 2 ], array_keys( $merged ) );
	}

	public function test_merge_keeps_the_position_of_the_first_appearance(): void {
		$merged = ChangeSet::merge(
			[ self::item( 'a', '1', '2' ), self::item( 'b', '1', '2' ) ],
			[ self::item( 'a', '2', '3' ) ]
		);

		$this->assertSame(
			[ self::item( 'a', '1', '3' ), self::item( 'b', '1', '2' ) ],
			$merged
		);
	}

	public function test_merge_with_an_empty_side_returns_the_other_side(): void {
		$items = [ self::item( 'a', '1', '2' ) ];

		$this->assertSame( $items, ChangeSet::merge( $items, [] ) );
		$this->assertSame( $items, ChangeSet::merge( [], $items ) );
		$this->assertSame( [], ChangeSet::merge( [], [] ) );
	}

	public function test_merge_result_is_a_list_after_dropping_items_in_the_middle(): void {
		$merged = ChangeSet::merge(
			[ self::item( 'a', '1', '2' ), self::item( 'b', 'x', 'y' ), self::item( 'c', '1', '2' ) ],
			[ self::item( 'b', 'y', 'x' ) ]
		);

		$this->assertSame( [ 0, 1 ], array_keys( $merged ) );
		$this->assertSame( [ 'a', 'c' ], array_column( $merged, 'field' ) );
	}

	public function test_merge_keeps_the_changed_flag_of_content_fields(): void {
		$flag = self::item( 'post_content', null, null );

		$this->assertSame( [ $flag ], ChangeSet::merge( [ $flag ], [] ) );
		$this->assertSame( [ $flag ], ChangeSet::merge( [ $flag ], [ $flag ] ) );
	}

	public function test_merge_keeps_the_changed_flag_of_redacted_fields(): void {
		$flag = self::item( 'api_key', '[redacted]', '[redacted]' );

		$this->assertSame( [ $flag ], ChangeSet::merge( [ $flag ], [ $flag ] ) );
	}

	public function test_merge_drops_a_no_op_item_with_equal_values(): void {
		$merged = ChangeSet::merge( [ self::item( 'a', 'same', 'same' ) ], [] );

		$this->assertSame( [], $merged );
	}

	public function test_merge_output_has_exactly_the_three_keys(): void {
		$merged = ChangeSet::merge(
			[ self::item( 'a', '1', '2' ) ],
			[ self::item( 'a', '2', '3' ) ]
		);

		$this->assertSame( [ 'field', 'old', 'new' ], array_keys( $merged[0] ) );
	}
}

/**
 * An object that records being unserialized.
 */
final class UnserializeProbe {

	public static bool $woken = false;

	public function __wakeup(): void {
		self::$woken = true;
	}
}
