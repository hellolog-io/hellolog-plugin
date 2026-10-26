<?php
/**
 * Stand-ins for the WordPress term class and the term lookup the taxonomy and
 * menu sensors call from their hooks.
 *
 * Loaded only by tests that run in a separate process: defining these makes
 * `function_exists()` and `class_exists()` true for the rest of the process.
 * Load `wordpress-user-functions.php` with it for the site id and
 * `add_action()`.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Sensors\Core\Fixtures\TermState;

	/**
	 * A term: its public fields, like core's.
	 */
	class WP_Term {

		/** @var int */
		public $term_id = 0;

		/** @var string */
		public $name = '';

		/** @var string */
		public $slug = '';

		/** @var string */
		public $taxonomy = '';

		/** @var string */
		public $description = '';

		/** @var int */
		public $parent = 0;

		/** @param array<string, mixed> $values */
		public function __construct( array $values ) {
			foreach ( $values as $name => $value ) {
				$this->$name = $value;
			}
		}
	}

	class WP_Error {
	}

	/**
	 * @param mixed $thing Anything.
	 */
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}

	/**
	 * Like core, a term of another taxonomy than the one asked for is not found.
	 *
	 * @param int|object $term     Term id.
	 * @param string     $taxonomy Taxonomy; empty for any.
	 */
	function get_term( $term, string $taxonomy = '' ): ?WP_Term {
		if ( TermState::$reads_throw ) {
			throw new \RuntimeException( 'unreadable' );
		}
		$found = TermState::$terms[ (int) $term ] ?? null;
		if ( null === $found || ( '' !== $taxonomy && $found->taxonomy !== $taxonomy ) ) {
			return null;
		}

		return $found;
	}
}
