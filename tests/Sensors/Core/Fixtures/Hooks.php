<?php
/**
 * Fires the callbacks the `add_action()` stand-in recorded.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core\Fixtures;

/**
 * Only ever used together with `wordpress-user-functions.php`, whose
 * `add_action()` records the callbacks in {@see WpState::$callbacks}.
 */
final class Hooks {

	/**
	 * Fire a hook the way `do_action()` does: every callback registered on
	 * it, by priority (in the order of registration within one), with as
	 * many arguments as it accepts.
	 *
	 * @param string $hook    Hook name.
	 * @param mixed  ...$args Arguments of the hook.
	 */
	public static function fire( string $hook, ...$args ): void {
		$callbacks = WpState::$callbacks[ $hook ] ?? [];
		usort( $callbacks, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );
		foreach ( $callbacks as $registered ) {
			( $registered['callback'] )( ...array_slice( $args, 0, $registered['accepted_args'] ) );
		}
	}
}
