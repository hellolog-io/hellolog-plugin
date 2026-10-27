<?php
/**
 * Stand-ins for `WC()` with its shipping method registry, `wc_get_attribute()`,
 * and the WordPress calls the shop configuration sensor makes to tell the site
 * and the host of a URL.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Sensors\Integrations\Fixtures\ConfigState;

	/**
	 * The shop, holding only its shipping method registry.
	 */
	function WC(): object { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		if ( ConfigState::$throws ) {
			throw new \RuntimeException( 'no shop' );
		}

		return new class() {

			public function shipping(): object {
				return new class() {

					/**
					 * @return array<string, object> Method id => method type.
					 */
					public function get_shipping_methods(): array {
						$methods = [];
						foreach ( ConfigState::$shipping_methods as $id => $title ) {
							$methods[ $id ] = new class( $title ) {

								public function __construct( private string $title ) {
								}

								public function get_method_title(): string {
									return $this->title;
								}
							};
						}

						return $methods;
					}
				};
			}
		};
	}

	/**
	 * @param int|string $id Attribute id.
	 */
	function wc_get_attribute( $id ): ?object {
		if ( ConfigState::$throws ) {
			throw new \RuntimeException( 'no attributes' );
		}

		$found = ConfigState::$attributes[ ConfigState::$blog_id ][ (int) $id ] ?? null;

		return null === $found ? null : (object) array_merge( [ 'id' => (int) $id ], $found );
	}

	function get_current_blog_id(): int {
		if ( ConfigState::$throws ) {
			throw new \RuntimeException( 'no site' );
		}

		return ConfigState::$blog_id;
	}

	/**
	 * @param string $url       URL.
	 * @param int    $component A `PHP_URL_*` part, or -1 for all.
	 * @return mixed
	 */
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}
