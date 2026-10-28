<?php
/**
 * Stand-in for the WordPress class of a site of a network, with the fields
 * the multisite sensor reads.
 *
 * Loaded only by tests that run in a separate process: defining it makes
 * `class_exists()` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	/**
	 * A site of the network. Core keeps its ids as numeric strings.
	 */
	class WP_Site {

		/** @var string */
		public $blog_id;

		/** @var string */
		public $domain;

		/** @var string */
		public $path;

		public function __construct( int $blog_id, string $domain, string $path ) {
			$this->blog_id = (string) $blog_id;
			$this->domain  = $domain;
			$this->path    = $path;
		}
	}
}
