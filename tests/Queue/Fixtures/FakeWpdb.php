<?php
/**
 * A `$wpdb` that records its inserts.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Queue\Fixtures;

/**
 * Just the part of `wpdb` the queue writes through, on a network whose
 * main site (1) has the base prefix and every other site `wp_<id>_`. Only
 * ever used inside a test that runs in its own process.
 */
final class FakeWpdb {

	/** Table prefix of the current site. */
	public string $prefix = 'wp_';

	/** Id of the last inserted row. */
	public int $insert_id = 0;

	/**
	 * Every `insert()` call as `[ table, data ]`.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public array $inserts = [];

	public function get_blog_prefix( ?int $blog_id = null ): string {
		if ( null === $blog_id ) {
			return $this->prefix;
		}

		return 1 === $blog_id ? 'wp_' : 'wp_' . $blog_id . '_';
	}

	/**
	 * @param string               $table  Table name.
	 * @param array<string, mixed> $data   Row.
	 * @param mixed                $format Column formats.
	 */
	public function insert( string $table, array $data, $format = null ): int {
		$this->inserts[] = [ $table, $data ];
		++$this->insert_id;

		return 1;
	}
}
