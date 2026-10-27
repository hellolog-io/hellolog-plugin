<?php
/**
 * State of the WordPress stand-ins a booted plugin runs on.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Support;

/**
 * Shared state of `wordpress-functions.php`: options per site, hooks with
 * their callbacks (run the way core runs them), the site the request is on.
 * Only ever used inside a test that runs in its own process.
 */
final class Wp {

	/** Whether the install is a network. */
	public static bool $multisite = false;

	/** The site the request is on. */
	public static int $blog = 1;

	/**
	 * Sites the request switched away from, the last one on top.
	 *
	 * @var array<int, int>
	 */
	public static array $stack = [];

	/**
	 * Ids of the network's active sites.
	 *
	 * @var array<int, int>
	 */
	public static array $sites = [ 1 ];

	/**
	 * Site id => option name => value. An option that is not listed does
	 * not exist.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $options = [];

	/**
	 * Hook => its callbacks, in the order they were added.
	 *
	 * @var array<string, array<int, array{callback: callable, priority: int, accepted_args: int}>>
	 */
	public static array $hooks = [];

	/**
	 * The actions and filters running, the innermost last.
	 *
	 * @var array<int, string>
	 */
	public static array $doing = [];

	/**
	 * Every `do_action()` call as `[ hook, args ]`.
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	public static array $actions = [];

	/**
	 * Run the callbacks of a hook the way core does: by priority, then in the
	 * order they were added, each given as many arguments as it accepts. A
	 * filter passes each callback's answer to the next.
	 *
	 * @param string            $hook      Hook name.
	 * @param array<int, mixed> $args      Arguments.
	 * @param bool              $is_filter Whether the first argument is the value being filtered.
	 * @return mixed The filtered value; the first argument for an action.
	 */
	public static function run( string $hook, array $args, bool $is_filter = false ) {
		$callbacks = self::$hooks[ $hook ] ?? [];
		$order     = array_keys( $callbacks );
		usort(
			$order,
			static fn( int $a, int $b ): int => [ $callbacks[ $a ]['priority'], $a ] <=> [ $callbacks[ $b ]['priority'], $b ]
		);

		self::$doing[] = $hook;
		try {
			foreach ( $order as $i ) {
				$result = call_user_func_array( $callbacks[ $i ]['callback'], array_slice( $args, 0, $callbacks[ $i ]['accepted_args'] ) );
				if ( $is_filter ) {
					$args[0] = $result;
				}
			}
		} finally {
			array_pop( self::$doing );
		}

		return $args[0] ?? null;
	}
}
