<?php
/**
 * Stand-ins for the WordPress user class and lookups the user sensors call
 * from their hooks.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` and `class_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Sensors\Core\Fixtures\WpState;

	/**
	 * A user: a bag of fields, with the roles readable as a property like core's.
	 */
	class WP_User {

		/** @var int */
		public $ID = 0;

		/** @var array<int, string> */
		public $roles = [];

		/** @param array<string, mixed> $values */
		public function __construct( private array $values ) {
			$this->ID    = (int) ( $values['ID'] ?? 0 );
			$this->roles = array_values( (array) ( $values['roles'] ?? [] ) );
		}

		public function __get( string $name ) {
			return $this->values[ $name ] ?? null;
		}

		public function __set( string $name, $value ): void {
			$this->values[ $name ] = $value;
		}

		public function __isset( string $name ): bool {
			return isset( $this->values[ $name ] );
		}
	}

	/**
	 * @param string     $field Only `id` is supported.
	 * @param int|string $value User id.
	 * @return WP_User|false
	 */
	function get_user_by( string $field, $value ) {
		return 'id' === $field ? ( WpState::$users[ (int) $value ] ?? false ) : false;
	}

	/**
	 * @param int $user_id User id.
	 * @return WP_User|false
	 */
	function get_userdata( int $user_id ) {
		return WpState::$users[ $user_id ] ?? false;
	}

	/**
	 * @param string $value A string; core also takes arrays, the sensors never pass one.
	 */
	function wp_unslash( $value ): string {
		return stripslashes( (string) $value );
	}
}
