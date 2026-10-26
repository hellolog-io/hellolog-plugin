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
