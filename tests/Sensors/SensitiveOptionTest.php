<?php
/**
 * Tests for {@see HelloLog\Sensors\SensitiveOption}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors;

use HelloLog\Sensors\SensitiveOption;
use PHPUnit\Framework\TestCase;

final class SensitiveOptionTest extends TestCase {

	/**
	 * @dataProvider sensitive_names
	 */
	public function test_credential_like_names_are_sensitive( string $name ): void {
		$this->assertTrue( SensitiveOption::is_sensitive( $name ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function sensitive_names(): array {
		return [
			'api key'       => [ 'woocommerce_shipping_api_key' ],
			'secret'        => [ 'woocommerce_gateway_client_secret' ],
			'token'         => [ 'lw_seo_indexnow_token' ],
			'password'      => [ 'woocommerce_smtp_password' ],
			'mixed case'    => [ 'woocommerce_PayPal_ApiKey' ],
			'license'       => [ 'lw_cookie_license' ],
			'private'       => [ 'woocommerce_private_cert' ],
			'credentials'   => [ 'woocommerce_x_credentials' ],
		];
	}

	/**
	 * @dataProvider auth_names
	 */
	public function test_authentication_names_are_sensitive( string $name ): void {
		$this->assertTrue( SensitiveOption::is_sensitive( $name ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function auth_names(): array {
		return [
			'auth key'       => [ 'auth_key' ],
			'authorization'  => [ 'woocommerce_x_authorization' ],
			'authorize url'  => [ 'lw_x_authorize_url' ],
			'authorise'      => [ 'lw_x_authorised_domains' ],
			'authentication' => [ 'lw_x_authentication' ],
			'oauth'          => [ 'lw_x_oauth_client' ],
			'oauth token'    => [ 'lw_x_oauth_token' ],
			'upper case'     => [ 'WOOCOMMERCE_AUTHORIZATION' ],
			'authorization header' => [ 'authorization_header' ],
		];
	}

	/**
	 * @dataProvider bank_names
	 */
	public function test_bank_detail_names_are_sensitive( string $name ): void {
		$this->assertTrue( SensitiveOption::is_sensitive( $name ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function bank_names(): array {
		return [
			'iban'           => [ 'iban' ],
			'upper case iban' => [ 'IBAN' ],
			'account iban'   => [ 'woocommerce_bacs_iban' ],
			'account number' => [ 'account_number' ],
			'sort code'      => [ 'sort_code' ],
			'bic'            => [ 'bic' ],
			'bic prefixed'   => [ 'bank_bic' ],
			'bic suffixed'   => [ 'bic_code' ],
			'upper case bic' => [ 'BIC' ],
			'routing number' => [ 'routing_number' ],
			'swift'          => [ 'swift' ],
			'swift code'     => [ 'swift_code' ],
			'signature'      => [ 'webhook_signature' ],
		];
	}

	/**
	 * Names arrive as snake_case options, camelCase array keys, kebab-case
	 * settings and HTTP header names; all of them are read the same way.
	 *
	 * @dataProvider differently_written_names
	 */
	public function test_a_credential_is_found_however_the_name_is_written( string $name ): void {
		$this->assertTrue( SensitiveOption::is_sensitive( $name ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function differently_written_names(): array {
		return [
			'camel account number'    => [ 'accountNumber' ],
			'camel sort code'         => [ 'sortCode' ],
			'camel routing number'    => [ 'routingNumber' ],
			'camel bic code'          => [ 'bicCode' ],
			'camel bank bic'          => [ 'bankBic' ],
			'pascal account number'   => [ 'AccountNumber' ],
			'header api key'          => [ 'X-API-KEY' ],
			'header authorization'    => [ 'Authorization' ],
			'kebab routing number'    => [ 'routing-number' ],
			'kebab api key'           => [ 'api-key' ],
			'spaced api key'          => [ 'api key' ],
			'camel client secret'     => [ 'clientSecret' ],
			'camel access token'      => [ 'accessToken' ],
			'camel card number'       => [ 'cardNumber' ],
			'camel bank account'      => [ 'bankAccount' ],
			'camel connection string' => [ 'connectionString' ],
			'camel auth token'        => [ 'authToken' ],
			'digit then capital'      => [ 'oauth2Token' ],
		];
	}

	/**
	 * @dataProvider more_credential_names
	 */
	public function test_card_bank_and_connection_details_are_sensitive( string $name ): void {
		$this->assertTrue( SensitiveOption::is_sensitive( $name ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function more_credential_names(): array {
		return [
			'card number'         => [ 'card_number' ],
			'bank account'        => [ 'bank_account' ],
			'bank account detail' => [ 'woocommerce_bank_account_details' ],
			'connection string'   => [ 'connection_string' ],
			'bearer'              => [ 'bearer' ],
			'bearer header'       => [ 'bearer_header' ],
			'cvv'                 => [ 'cvv' ],
			'cvv in a name'       => [ 'card_cvv' ],
			'cvv2'                => [ 'cvv2' ],
			'cvc'                 => [ 'stripe_cvc' ],
			'otp'                 => [ 'otp' ],
			'otp in a name'       => [ 'login_otp' ],
			'otp in a name, end'  => [ 'otp_code' ],
			'jwt'                 => [ 'jwt' ],
			'jwt in a name'       => [ 'session_jwt' ],
			'dsn'                 => [ 'sentry_dsn' ],
			'pem'                 => [ 'ssl_pem' ],
			'cert'                => [ 'client_cert' ],
			'cert, start'         => [ 'cert_path' ],
		];
	}

	/**
	 * `pin` is deliberately not a fragment: it sits inside shipping and
	 * pinterest. The short ones only count as whole words.
	 *
	 * @dataProvider look_alike_names
	 */
	public function test_names_that_only_look_like_a_credential_are_not_sensitive( string $name ): void {
		$this->assertFalse( SensitiveOption::is_sensitive( $name ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function look_alike_names(): array {
		return [
			'shipping method'     => [ 'shipping_method' ],
			'pinterest url'       => [ 'pinterest_url' ],
			'concert date'        => [ 'concert_date' ],
			'author id'           => [ 'author_id' ],
			'camel author id'     => [ 'authorId' ],
			'pascal author'       => [ 'AuthorName' ],
			'potpourri'           => [ 'potpourri_shop' ],
			'camel potpourri'     => [ 'potpourriShop' ],
			'certificate'         => [ 'certified_partner' ],
			'accordance'          => [ 'accordance_level' ],
			'jwtless'             => [ 'jwtless_mode' ],
			'dsnx'                => [ 'dsnx' ],
			'camel fabric'        => [ 'fabricType' ],
			'camel public'        => [ 'publicName' ],
			'camel bicycle'       => [ 'bicycleShop' ],
			'camel shipping'      => [ 'shippingMethod' ],
			'card on its own'     => [ 'card_title' ],
			'bank on its own'     => [ 'bank_name' ],
			'account on its own'  => [ 'account_name' ],
		];
	}

	/**
	 * `bic` is short enough to sit inside ordinary words.
	 *
	 * @dataProvider words_with_bic_inside
	 */
	public function test_a_name_that_only_contains_bic_inside_a_word_is_not_sensitive( string $name ): void {
		$this->assertFalse( SensitiveOption::is_sensitive( $name ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function words_with_bic_inside(): array {
		return [
			'bicycle' => [ 'shop_bicycle_rental' ],
			'bicolor' => [ 'banner_bicolor' ],
			'fabric'  => [ 'fabric_type' ],
			'public'  => [ 'public_name' ],
		];
	}

	/**
	 * @dataProvider author_names
	 */
	public function test_author_names_are_not_sensitive( string $name ): void {
		$this->assertFalse( SensitiveOption::is_sensitive( $name ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function author_names(): array {
		return [
			'post author'          => [ 'post_author' ],
			'comment author'       => [ 'comment_author' ],
			'comment author email' => [ 'comment_author_email' ],
			'comment author url'   => [ 'comment_author_url' ],
			'author id'            => [ 'author_id' ],
			'authors'              => [ 'authors' ],
			'plain author'         => [ 'author' ],
			'author with digits'   => [ 'author2' ],
			'upper case'           => [ 'POST_AUTHOR' ],
			'product author'       => [ 'woocommerce_product_author' ],
		];
	}

	public function test_ordinary_names_are_not_sensitive(): void {
		$this->assertFalse( SensitiveOption::is_sensitive( 'woocommerce_currency' ) );
		$this->assertFalse( SensitiveOption::is_sensitive( 'woocommerce_calc_taxes' ) );
		$this->assertFalse( SensitiveOption::is_sensitive( 'lw_seo_options' ) );
	}

	public function test_value_masks_credentials_inside_a_url(): void {
		$this->assertSame(
			'sftp://[redacted]@files.example.com/backups',
			SensitiveOption::value( 'woocommerce_backup_target', 'sftp://bob:hunter2@files.example.com/backups' )
		);
		$this->assertSame( 'https://example.com/x', SensitiveOption::value( 'woocommerce_backup_target', 'https://example.com/x' ) );
	}

	public function test_value_masks_credentials_inside_a_json_text(): void {
		$this->assertSame(
			'{"enabled":"yes","secret_key":"[redacted]"}',
			SensitiveOption::value( 'woocommerce_gateway_json', '{"secret_key":"sk_live_abc","enabled":"yes"}' )
		);
	}

	public function test_value_keeps_the_text_of_other_scalars(): void {
		$this->assertSame( '1', SensitiveOption::value( 'woocommerce_x', 1 ) );
		$this->assertSame( '1', SensitiveOption::value( 'woocommerce_x', true ) );
		$this->assertSame( '', SensitiveOption::value( 'woocommerce_x', false ) );
		$this->assertSame( '1.5', SensitiveOption::value( 'woocommerce_x', 1.5 ) );
	}

	public function test_value_masks_sensitive_options_only(): void {
		$this->assertSame( SensitiveOption::REDACTED, SensitiveOption::value( 'woocommerce_x_api_key', 'sk_live_123' ) );
		$this->assertSame( 'HUF', SensitiveOption::value( 'woocommerce_currency', 'HUF' ) );
		$this->assertSame( '<complex>', SensitiveOption::value( 'woocommerce_currency', [ 'a' ] ) );
	}

	/**
	 * When the regex engine gives up on a name, the name counts as a
	 * credential: failing the other way would report the value unmasked.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_name_the_regex_engine_gives_up_on_is_sensitive(): void {
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '1' );

		$this->assertTrue( SensitiveOption::is_sensitive( 'api_key' ) );
		$this->assertTrue( SensitiveOption::is_sensitive( 'woocommerce_PayPal_ApiKey' ) );
		$this->assertSame( SensitiveOption::REDACTED, SensitiveOption::value( 'lw_x_api_key', 987654 ) );
	}
}
