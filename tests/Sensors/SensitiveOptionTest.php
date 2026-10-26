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

	public function test_value_masks_sensitive_options_only(): void {
		$this->assertSame( SensitiveOption::REDACTED, SensitiveOption::value( 'woocommerce_x_api_key', 'sk_live_123' ) );
		$this->assertSame( 'HUF', SensitiveOption::value( 'woocommerce_currency', 'HUF' ) );
		$this->assertSame( '<complex>', SensitiveOption::value( 'woocommerce_currency', [ 'a' ] ) );
	}
}
