<?php
/**
 * What the WordPress stand-ins of the term and menu sensor tests answer.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core\Fixtures;

/**
 * Shared state of `wordpress-term-functions.php`. Only ever used inside a
 * test that runs in its own process, together with those stubs.
 */
final class TermState {

	/**
	 * Term id => the term `get_term()` finds. An id that is not listed has no
	 * such term.
	 *
	 * @var array<int, \WP_Term>
	 */
	public static array $terms = [];

	/** Whether `get_term()` fails the way a broken filter on it would. */
	public static bool $reads_throw = false;
}
