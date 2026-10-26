<?php
/**
 * Tests for {@see HelloLog\Events\ChangeSet}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Events;

use HelloLog\Events\ChangeSet;
use HelloLog\Sensors\SensitiveOption;
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

	/**
	 * The text JSON-encoded `$times` times over, slashes escaped the way
	 * `json_encode()` writes them by default.
	 *
	 * @param string $text  Any text.
	 * @param int    $times How many string literals to wrap it in.
	 */
	private static function encode_times( string $text, int $times ): string {
		for ( $i = 0; $i < $times; $i++ ) {
			$text = json_encode( $text, JSON_THROW_ON_ERROR );
		}

		return $text;
	}

	/**
	 * The JSON escape of one character: a backslash, `u` and four hex digits.
	 *
	 * @param string $char One UTF-8 character.
	 */
	private static function json_escape( string $char ): string {
		return sprintf( '%su%04x', chr( 92 ), mb_ord( $char, 'UTF-8' ) );
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
			'an at sign in the password'        => [ 'mysql://root:p@ss@host/db', 'mysql://[redacted]@host/db' ],
			'two at signs in the password'      => [ 'mysql://root:p@ss@word@host:3306/db', 'mysql://[redacted]@host:3306/db' ],
			'an at sign and a colon'            => [ 'amqp://guest:p:a@ss@broker.example.com:5672', 'amqp://[redacted]@broker.example.com:5672' ],
			'an at sign, no path'               => [ 'redis://:p@ss@cache.example.com', 'redis://[redacted]@cache.example.com' ],
			'an at sign, the query follows'     => [ 'https://bob:p@ss@api.example.com?x=1', 'https://[redacted]@api.example.com?x=1' ],
			'an at sign, then a text'           => [ 'Restore from sftp://bob:p@ss@files.example.com now, please', 'Restore from sftp://[redacted]@files.example.com now, please' ],
			'escaped slashes'                   => [ 'https:\/\/admin:hunter2@db.example.com\/path', 'https:\/\/[redacted]@db.example.com\/path' ],
			'escaped slashes, at sign in it'    => [ 'see mysql:\/\/root:p@ss@host\/db', 'see mysql:\/\/[redacted]@host\/db' ],
			// Three backslashes before each slash: a twice-encoded JSON text.
			'slashes escaped twice'             => [ 'x https:\\\\\\/\\\\\\/u:hunter2@host\\\\\\/db', 'x https:\\\\\\/\\\\\\/[redacted]@host\\\\\\/db' ],
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
			'escaped, query at'     => [ 'https:\/\/example.com\/path?mail=bob@example.com' ],
			'escaped, path at'      => [ 'https:\/\/example.com\/@bob\/posts' ],
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

	/**
	 * A decoded JSON text is always written out again, never passed through as
	 * it came: where nothing is masked it is only compact JSON with sorted keys.
	 */
	public function test_a_json_string_without_secrets_is_reported_as_compact_json(): void {
		$this->assertSame( '{"enabled":true,"title":"Card"}', ChangeSet::stringify( '{ "title": "Card",  "enabled": true }' ) );
		$this->assertSame( '[1,2,3]', ChangeSet::stringify( '[1, 2, 3]' ) );
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

	/**
	 * Nesting deeper than the reported depth is cut to `…`, and the cut must
	 * not hand back the raw text it replaced.
	 *
	 * @return array<string, array{0: string}> A JSON text with `%s` where the secret goes.
	 */
	public static function deeply_nested_json(): array {
		$secret = '{"apiKey":"%s"}';

		return [
			'objects, secret at depth 11'    => [ str_repeat( '{"a":', 10 ) . $secret . str_repeat( '}', 10 ) ],
			'objects, secret at depth 12'    => [ str_repeat( '{"a":', 11 ) . $secret . str_repeat( '}', 11 ) ],
			'objects, secret at depth 15'    => [ str_repeat( '{"a":', 14 ) . $secret . str_repeat( '}', 14 ) ],
			'objects, secret at depth 40'    => [ str_repeat( '{"a":', 39 ) . $secret . str_repeat( '}', 39 ) ],
			'lists, secret at depth 11'      => [ str_repeat( '[', 10 ) . $secret . str_repeat( ']', 10 ) ],
			'lists, secret at depth 15'      => [ str_repeat( '[', 14 ) . $secret . str_repeat( ']', 14 ) ],
			'mixed, secret at depth 13'      => [ str_repeat( '{"a":[', 6 ) . $secret . str_repeat( ']}', 6 ) ],
			'a password key, depth 12'       => [ str_repeat( '{"a":', 11 ) . '{"note":"x","password":"%s"}' . str_repeat( '}', 11 ) ],
		];
	}

	/**
	 * @dataProvider deeply_nested_json
	 *
	 * @param string $template JSON text with `%s` where the secret goes.
	 */
	public function test_a_secret_below_the_depth_limit_of_a_json_text_is_never_reported( string $template ): void {
		$reported = (string) ChangeSet::stringify( sprintf( $template, 'SECRETVALUE' ) );

		$this->assertStringNotContainsString( 'SECRETVALUE', $reported );
		$this->assertStringNotContainsString( 'apiKey', $reported );
		$this->assertStringContainsString( '…', $reported );
	}

	/**
	 * @dataProvider deeply_nested_json
	 *
	 * @param string $template JSON text with `%s` where the secret goes.
	 */
	public function test_a_secret_below_the_depth_limit_never_leaves_through_a_diff( string $template ): void {
		$changes = ChangeSet::diff(
			[ 'gateway_json' => sprintf( $template, 'OLDSECRET' ) ],
			[ 'gateway_json' => sprintf( $template, 'NEWSECRET' ) ],
			[ 'gateway_json' ]
		);

		$this->assertSame( [ self::item( 'gateway_json', null, null ) ], $changes );
		$json = (string) json_encode( $changes );
		$this->assertStringNotContainsString( 'OLDSECRET', $json );
		$this->assertStringNotContainsString( 'NEWSECRET', $json );
	}

	/**
	 * @dataProvider deeply_nested_json
	 *
	 * @param string $template JSON text with `%s` where the secret goes.
	 */
	public function test_a_secret_below_the_depth_limit_stays_out_next_to_a_visible_change( string $template ): void {
		$changes = ChangeSet::diff(
			[ 'gateway_json' => sprintf( $template, 'OLDSECRET' ) ],
			[ 'gateway_json' => '{"changed":"yes"}' ],
			[ 'gateway_json' ]
		);

		$this->assertCount( 1, $changes );
		$json = (string) json_encode( $changes );
		$this->assertStringNotContainsString( 'OLDSECRET', $json );
		$this->assertSame( '{"changed":"yes"}', $changes[0]['new'] );
	}

	/**
	 * Past the decoder's own nesting limit the text cannot be read, so it is
	 * never reported as written: a secret in front of the nesting would go
	 * out with it.
	 */
	public function test_a_json_text_nested_beyond_the_decoder_limit_is_cut_not_passed_through(): void {
		$text = '{"apiKey":"SECRETVALUE","a":' . str_repeat( '{"a":', 520 ) . '1' . str_repeat( '}', 520 ) . '}';

		$this->assertSame( '…', ChangeSet::stringify( $text ) );

		$changes = ChangeSet::diff(
			[ 'gateway_json' => $text ],
			[ 'gateway_json' => str_replace( 'SECRETVALUE', 'OTHERVALUE', $text ) ],
			[ 'gateway_json' ]
		);
		$this->assertSame( [ self::item( 'gateway_json', null, null ) ], $changes );
	}

	/**
	 * A JSON text that is itself written as a JSON string literal: the
	 * decoded value is again a JSON object or list.
	 */
	public function test_a_double_encoded_json_string_has_its_secrets_masked(): void {
		$once  = (string) json_encode( [ 'apiKey' => 'S', 'enabled' => 'yes' ] );
		$twice = (string) json_encode( $once );

		$this->assertSame( '{"apiKey":"[redacted]","enabled":"yes"}', ChangeSet::stringify( $twice ) );
		$this->assertSame( '"{\"apiKey\":\"S\"}"', (string) json_encode( '{"apiKey":"S"}' ), 'The fixture is a string literal holding JSON.' );
		$this->assertSame( '{"apiKey":"[redacted]"}', ChangeSet::stringify( '"{\"apiKey\":\"S\"}"' ) );
	}

	public function test_a_triple_encoded_json_string_has_its_secrets_masked(): void {
		$text = (string) json_encode( (string) json_encode( (string) json_encode( [ 'password' => 'S3CRET', 'ok' => 1 ] ) ) );

		$reported = (string) ChangeSet::stringify( $text );

		$this->assertStringNotContainsString( 'S3CRET', $reported );
		$this->assertSame( '{"ok":1,"password":"[redacted]"}', $reported );
	}

	public function test_a_double_encoded_list_has_its_secrets_masked(): void {
		$reported = (string) ChangeSet::stringify( '"[{\"token\":\"T\",\"n\":1}]"' );

		$this->assertSame( '[{"n":1,"token":"[redacted]"}]', $reported );
	}

	public function test_a_double_encoded_secret_never_leaves_through_a_diff(): void {
		$encode = static fn( string $secret ): string => (string) json_encode( (string) json_encode( [ 'apiKey' => $secret, 'enabled' => 'yes' ] ) );

		$changes = ChangeSet::diff(
			[ 'gateway_json' => $encode( 'OLDSECRET' ) ],
			[ 'gateway_json' => $encode( 'NEWSECRET' ) ],
			[ 'gateway_json' ]
		);

		$this->assertSame( [ self::item( 'gateway_json', null, null ) ], $changes );
	}

	public function test_a_json_string_literal_that_holds_plain_text_is_untouched(): void {
		foreach ( [ '"hello"', '"a \"quoted\" word"', '"{not json}"', '"123"' ] as $text ) {
			$this->assertSame( $text, ChangeSet::stringify( $text ) );
		}
	}

	public function test_a_string_literal_holding_a_credential_url_is_masked(): void {
		$this->assertSame( '"https://[redacted]@api.example.com/v1"', ChangeSet::stringify( '"https://bob:hunter2@api.example.com/v1"' ) );
	}

	/**
	 * Secrets that a look at the raw text would miss: a URL whose slashes are
	 * escaped, a value JSON-encoded more often than the decoder unwraps, a
	 * byte order mark in front of the JSON.
	 *
	 * @return array<string, array{0: string, 1: string}> Text, the secret inside it.
	 */
	public static function hidden_secrets(): array {
		$bom    = "\xEF\xBB\xBF";
		$object = '{"apiKey":"SEC","enabled":"yes"}';
		$url    = 'https://user:p@ss@host/db';

		return [
			'escaped url in a string literal'       => [ '"https:\/\/user:p@ss@host\/db"', 'p@ss' ],
			'the same, spaces around it'            => [ " \"https:\\/\\/user:p@ss@host\\/db\"\n", 'p@ss' ],
			'escaped url in plain text'             => [ 'Restore from https:\/\/user:p@ss@host\/db now', 'p@ss' ],
			'escaped url in broken json'            => [ '{"target":"mysql:\/\/root:p@ss@host\/db",', 'p@ss' ],
			'unicode-escaped slashes in a literal'  => [ str_replace( '/', self::json_escape( '/' ), '"https://user:p@ss@host/db"' ), 'p@ss' ],
			'unicode-escaped at sign in a literal'  => [ '"https:\/\/user:p@ss' . self::json_escape( '@' ) . 'host\/db"', 'p@ss' ],
			'a url encoded twice'                   => [ self::encode_times( $url, 2 ), 'p@ss' ],
			'a url encoded 5 times'                 => [ self::encode_times( $url, 5 ), 'p@ss' ],
			'a url encoded 10 times'                => [ self::encode_times( $url, 10 ), 'p@ss' ],
			'a url encoded 11 times'                => [ self::encode_times( $url, 11 ), 'p@ss' ],
			'a url encoded 14 times'                => [ self::encode_times( $url, 14 ), 'p@ss' ],
			'a literal of a json object, 11 times'  => [ self::encode_times( $object, 11 ), 'SEC' ],
			'a literal of a json object, 12 times'  => [ self::encode_times( $object, 12 ), 'SEC' ],
			'a literal of a json object, 13 times'  => [ self::encode_times( $object, 13 ), 'SEC' ],
			'a literal of a json object, 14 times'  => [ self::encode_times( $object, 14 ), 'SEC' ],
			'a bom before a json object'            => [ $bom . $object, 'SEC' ],
			'a bom between spaces before an object' => [ " \n" . $bom . '  ' . $object, 'SEC' ],
			'two boms before a json object'         => [ $bom . $bom . $object, 'SEC' ],
			'a bom before a json list'              => [ $bom . '[' . $object . ']', 'SEC' ],
			'a bom before a string literal'         => [ $bom . self::encode_times( $object, 1 ), 'SEC' ],
			'a bom inside a string literal'         => [ self::encode_times( $bom . $object, 1 ), 'SEC' ],
			'a bom before a literal with a url'     => [ $bom . '"https:\/\/user:p@ss@host\/db"', 'p@ss' ],
		];
	}

	/**
	 * Every way a value leaves: a single text, either side of a diff, both
	 * sides at once, a value inside an array, an option value on its own.
	 *
	 * @dataProvider hidden_secrets
	 *
	 * @param string $text   A text with a secret hidden in it.
	 * @param string $secret The secret.
	 */
	public function test_a_hidden_secret_is_never_reported( string $text, string $secret ): void {
		$reports = [
			'stringify'        => ChangeSet::stringify( $text ),
			'redact_text'      => ChangeSet::redact_text( $text ),
			'inside an array'  => ChangeSet::stringify( [ 'target' => $text ] ),
			'diff, old side'   => ChangeSet::diff( [ 'f' => $text ], [ 'f' => 'other' ], [ 'f' ] ),
			'diff, new side'   => ChangeSet::diff( [ 'f' => 'other' ], [ 'f' => $text ], [ 'f' ] ),
			'diff, both sides' => ChangeSet::diff( [ 'f' => $text ], [ 'f' => $text . ' ' ], [ 'f' ] ),
			'option value'     => SensitiveOption::value( 'lw_backup_target', $text ),
		];

		foreach ( $reports as $where => $report ) {
			$this->assertStringNotContainsString( $secret, json_encode( $report, JSON_THROW_ON_ERROR ), $where );
		}
	}

	public function test_an_escaped_url_in_a_string_literal_is_masked_and_reads_unescaped(): void {
		$this->assertSame( '"https://[redacted]@host/db"', ChangeSet::stringify( '"https:\/\/user:p@ss@host\/db"' ) );
		$this->assertSame( '"https://[redacted]@host/db"', SensitiveOption::value( 'lw_backup_target', '"https:\/\/user:p@ss@host\/db"' ) );
	}

	public function test_a_url_encoded_twice_is_masked_inside_both_literals(): void {
		$this->assertSame(
			'"\"https://[redacted]@host/db\""',
			ChangeSet::stringify( self::encode_times( 'https://user:p@ss@host/db', 2 ) )
		);
	}

	public function test_an_escaped_url_password_change_only_is_a_changed_flag(): void {
		$changes = ChangeSet::diff(
			[ 'backup_target' => '"sftp:\/\/bob:OLDPASS@files.example.com\/backups"' ],
			[ 'backup_target' => '"sftp:\/\/bob:NEWPASS@files.example.com\/backups"' ],
			[ 'backup_target' ]
		);

		$this->assertSame( [ self::item( 'backup_target', null, null ) ], $changes );
	}

	/**
	 * A string literal without secrets is written out again as a compact
	 * literal: still the same text, only easier to read.
	 */
	public function test_a_json_string_literal_without_secrets_round_trips_readably(): void {
		$this->assertSame( '"https://example.com/path"', ChangeSet::stringify( '"https:\/\/example.com\/path"' ) );
		$this->assertSame( '"café"', ChangeSet::stringify( '"caf' . self::json_escape( 'é' ) . '"' ) );
		$this->assertSame( '"hello"', ChangeSet::stringify( " \"hello\"\n" ) );
		$this->assertSame( '"hello"', SensitiveOption::value( 'lw_x', '"hello"' ) );

		$text = self::encode_times( 'plain "quoted" text', 4 );
		$this->assertSame( $text, ChangeSet::stringify( $text ) );
		$this->assertSame( 'plain "quoted" text', json_decode( json_decode( json_decode( json_decode( (string) ChangeSet::stringify( $text ) ) ) ) ) );
	}

	public function test_a_json_object_encoded_ten_times_is_still_unwrapped_and_masked(): void {
		$this->assertSame(
			'{"apiKey":"[redacted]","enabled":"yes"}',
			ChangeSet::stringify( self::encode_times( '{"apiKey":"SEC","enabled":"yes"}', 10 ) )
		);
	}

	/**
	 * Through the untruncated paths: ten layers of `hello` run past the
	 * 1024-character cut.
	 */
	public function test_text_encoded_ten_times_still_round_trips(): void {
		$text = self::encode_times( 'hello', 10 );

		$this->assertSame( $text, ChangeSet::redact_text( $text ) );
		$this->assertSame( $text, SensitiveOption::value( 'lw_x', $text ) );
	}

	/**
	 * Past the unwrap limit the text cannot be looked into, so it is cut to
	 * the same `…` a too-deep structure gets, never passed through.
	 */
	public function test_a_string_literal_past_the_unwrap_limit_is_cut(): void {
		foreach ( [ 11, 12, 13, 14 ] as $times ) {
			$text = self::encode_times( '{"apiKey":"SEC"}', $times );

			$this->assertSame( '…', ChangeSet::stringify( $text ), "$times times" );
			$this->assertSame( '…', ChangeSet::redact_text( $text ), "$times times" );
			$this->assertSame( '…', SensitiveOption::value( 'lw_x', $text ), "$times times" );
		}

		$this->assertSame( '…', ChangeSet::stringify( self::encode_times( 'hello', 11 ) ) );
	}

	public function test_past_the_unwrap_limit_a_diff_is_a_changed_flag(): void {
		$changes = ChangeSet::diff(
			[ 'gateway_json' => self::encode_times( '{"apiKey":"OLDSECRET"}', 12 ) ],
			[ 'gateway_json' => self::encode_times( '{"apiKey":"NEWSECRET"}', 12 ) ],
			[ 'gateway_json' ]
		);

		$this->assertSame( [ self::item( 'gateway_json', null, null ) ], $changes );
	}

	public function test_a_byte_order_mark_does_not_hide_a_json_text(): void {
		$this->assertSame( '{"apiKey":"[redacted]"}', ChangeSet::stringify( "\xEF\xBB\xBF" . '{"apiKey":"SEC"}' ) );
		$this->assertSame( '{"apiKey":"[redacted]"}', SensitiveOption::value( 'lw_x', "\xEF\xBB\xBF" . '{"apiKey":"SEC"}' ) );
		$this->assertSame( '"https://[redacted]@host/db"', ChangeSet::stringify( "\xEF\xBB\xBF" . '"https:\/\/user:p@ss@host\/db"' ) );
	}

	public function test_a_byte_order_mark_in_front_of_plain_text_is_kept(): void {
		$this->assertSame( "\xEF\xBB\xBFhello", ChangeSet::stringify( "\xEF\xBB\xBFhello" ) );
	}

	/**
	 * Prefixes longer than the regex engine's default backtrack limit.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function long_json_prefixes(): array {
		return [
			'spaces'                  => [ str_repeat( ' ', 1100000 ) ],
			'all json whitespace'     => [ str_repeat( " \t\n\r", 300000 ) ],
			'byte order marks'        => [ str_repeat( "\xEF\xBB\xBF", 1100000 ) ],
			'marks between newlines'  => [ str_repeat( "\xEF\xBB\xBF\n", 600000 ) ],
		];
	}

	/**
	 * @dataProvider long_json_prefixes
	 *
	 * @param string $prefix What goes in front of the JSON.
	 */
	public function test_a_long_prefix_does_not_hide_a_json_text( string $prefix ): void {
		$this->assertSame(
			'{"apiKey":"[redacted]","enabled":"yes"}',
			ChangeSet::redact_text( $prefix . '{"apiKey":"SEC","enabled":"yes"}' )
		);
	}

	/**
	 * Without JIT the regex engine gives up on a long prefix; the strip in
	 * front of JSON detection must not depend on it, or the raw text goes out.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @dataProvider long_json_prefixes
	 *
	 * @param string $prefix What goes in front of the JSON.
	 */
	public function test_without_pcre_jit_a_long_prefix_still_does_not_hide_a_json_text( string $prefix ): void {
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '1000000' );

		$text    = $prefix . '{"apiKey":"SEC","enabled":"yes"}';
		$reports = [
			'stringify'      => ChangeSet::stringify( $text ),
			'redact_text'    => ChangeSet::redact_text( $text ),
			'diff, old side' => ChangeSet::diff( [ 'f' => $text ], [ 'f' => 'other' ], [ 'f' ] ),
			'diff, new side' => ChangeSet::diff( [ 'f' => 'other' ], [ 'f' => $text ], [ 'f' ] ),
			'option value'   => SensitiveOption::value( 'lw_backup_target', $text ),
		];

		foreach ( $reports as $where => $report ) {
			$this->assertStringNotContainsString( 'SEC', json_encode( $report, JSON_THROW_ON_ERROR ), $where );
		}
		$this->assertSame( '{"apiKey":"[redacted]","enabled":"yes"}', $reports['redact_text'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function long_scheme_like_runs(): array {
		return [
			'letters, then an at sign'            => [ str_repeat( 'a', 300000 ) . '@' ],
			'letters and digits, then an at sign' => [ str_repeat( 'a1', 150000 ) . '@' ],
			'scheme characters, then an at sign'  => [ str_repeat( 'a+b.c-', 50000 ) . '@' ],
		];
	}

	/**
	 * Without JIT a URL rule that tries a scheme from every position of a long
	 * run of scheme characters takes quadratic time: a value of a few hundred
	 * kilobytes would stall the request that logs it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @dataProvider long_scheme_like_runs
	 *
	 * @param string $text A long text with nothing to mask.
	 */
	public function test_without_pcre_jit_a_long_scheme_like_run_is_read_in_linear_time( string $text ): void {
		ini_set( 'pcre.jit', '0' );
		// A runaway regex fails this test instead of stalling the suite.
		set_time_limit( 10 );

		$start   = microtime( true );
		$masked  = ChangeSet::redact_text( $text );
		$elapsed = microtime( true ) - $start;

		$this->assertSame( $text, $masked );
		$this->assertLessThan( 0.5, $elapsed );
	}

	/**
	 * The URL rule without JIT reports exactly what it reports with it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_pcre_jit_urls_are_masked_the_same_way(): void {
		ini_set( 'pcre.jit', '0' );

		foreach ( self::urls_with_userinfo() as $name => [ $text, $reported ] ) {
			$this->assertSame( $reported, ChangeSet::stringify( $text ), $name );
		}
		foreach ( self::urls_without_userinfo() as $name => [ $text ] ) {
			$this->assertSame( $text, ChangeSet::stringify( $text ), $name );
		}
		$this->assertSame( '1https://[redacted]@h', ChangeSet::redact_text( '1https://u:p@h' ) );
		$this->assertSame( '.https://[redacted]@h', ChangeSet::redact_text( '.https://u:p@h' ) );
		$this->assertSame( 'a+b.c-d://[redacted]@h', ChangeSet::redact_text( 'a+b.c-d://u:p@h' ) );
		$this->assertSame( 'a:b@c', ChangeSet::redact_text( 'a:b@c' ) );
	}

	/**
	 * A text the regex engine gives up on is cut, never reported unmasked.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_text_the_regex_engine_gives_up_on_is_cut(): void {
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '1' );

		$this->assertSame( '…', ChangeSet::stringify( 'see https://user:SEC@host/db' ) );
		$this->assertSame( '…', ChangeSet::redact_text( 'see https://user:SEC@host/db' ) );
		$this->assertSame( '…', ChangeSet::stringify( '"https:\/\/user:SEC@host\/db"' ) );
	}

	/**
	 * A key the regex engine gives up on counts as a credential, so even a
	 * value no text rule looks at (a number) stays out.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_key_the_regex_engine_gives_up_on_is_masked(): void {
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '1' );

		$this->assertSame( '{"apiKey":"[redacted]"}', ChangeSet::stringify( [ 'apiKey' => 987654 ] ) );
		$this->assertSame(
			[ self::item( 'api_key', SensitiveOption::REDACTED, SensitiveOption::REDACTED ) ],
			ChangeSet::diff( [ 'api_key' => 111111 ], [ 'api_key' => 222222 ], [ 'api_key' ] )
		);
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
