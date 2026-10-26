<?php
/**
 * Stand-ins for the WordPress user class, lookups and site switching the user
 * sensors call from their hooks.
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
	 * @param string     $field `id` or `login`.
	 * @param int|string $value User id or login name.
	 * @return WP_User|false
	 */
	function get_user_by( string $field, $value ) {
		if ( 'id' === $field ) {
			return WpState::$users[ (int) $value ] ?? false;
		}
		if ( 'login' === $field ) {
			foreach ( WpState::$users as $user ) {
				if ( $user->user_login === $value ) {
					return $user;
				}
			}
		}

		return false;
	}

	/**
	 * @param int $user_id User id.
	 * @return WP_User|false
	 */
	function get_userdata( int $user_id ) {
		return WpState::$users[ $user_id ] ?? false;
	}

	/**
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @param bool   $single  Only the single read is stubbed.
	 * @return mixed
	 */
	function get_user_meta( int $user_id, string $key = '', bool $single = false ) {
		return WpState::$user_meta[ $user_id ][ $key ] ?? '';
	}

	function get_current_blog_id(): int {
		return WpState::$blog_id;
	}

	function switch_to_blog( int $blog_id ): bool {
		WpState::$blog_stack[] = WpState::$blog_id;
		WpState::$blog_id      = $blog_id;

		return true;
	}

	function restore_current_blog(): bool {
		if ( [] === WpState::$blog_stack ) {
			return false;
		}
		WpState::$blog_id = array_pop( WpState::$blog_stack );

		return true;
	}

	/**
	 * Records the registration; nothing is ever fired.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of arguments the callback accepts.
	 */
	function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		WpState::$actions[ $hook_name ] = [
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		];

		return true;
	}

	/**
	 * @param string $value A string; core also takes arrays, the sensors never pass one.
	 */
	function wp_unslash( $value ): string {
		return stripslashes( (string) $value );
	}

	/**
	 * @param string $value A string; the request IP is read through it.
	 */
	function sanitize_text_field( $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}
