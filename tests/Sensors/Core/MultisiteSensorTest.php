<?php
/**
 * Tests for the site events of {@see HelloLog\Sensors\Core\MultisiteSensor}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Events\EventDispatcher;
use HelloLog\Sensors\Core\MultisiteSensor;
use HelloLog\Tests\Sensors\Core\Fixtures\BuildingRecorder;
use PHPUnit\Framework\TestCase;

/**
 * A site added to or deleted from the network is the target of its event,
 * named by its id, which does not change; its label is where it lives. Each
 * test runs in its own process, with the site stand-in.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class MultisiteSensorTest extends TestCase {

	protected function setUp(): void {
		require_once __DIR__ . '/Fixtures/wordpress-site-class.php';
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}> Domain, path, label.
	 */
	public static function sites(): array {
		return [
			'a subdomain site'             => [ 'shop.example.com', '/', 'shop.example.com' ],
			'a subdirectory site'          => [ 'example.com', '/blog2/', 'example.com/blog2' ],
			'a site in a deeper path'      => [ 'example.com', '/a/b/', 'example.com/a/b' ],
			'a path without a last slash'  => [ 'example.com', '/blog2', 'example.com/blog2' ],
		];
	}

	/**
	 * @dataProvider sites
	 *
	 * @param string $domain Domain of the site.
	 * @param string $path   Path of the site.
	 * @param string $label  Label of its target.
	 */
	public function test_a_created_site_is_the_target_of_its_event( string $domain, string $path, string $label ): void {
		$recorder = $this->recorder();
		( new MultisiteSensor( $recorder ) )->on_site_created( new \WP_Site( 12, $domain, $path ) );

		$this->assertSame( [ 4100 ], array_column( $recorder->calls, 0 ) );
		$fields = $recorder->calls[0][1];
		$this->assertSame(
			[
				'type'  => 'site',
				'id'    => '12',
				'label' => $label,
			],
			$fields['target']
		);
		$this->assertSame( $domain, $fields['domain'] );
		$this->assertSame(
			[
				'site_id' => 12,
				'domain'  => $domain,
				'path'    => $path,
			],
			$fields['metadata']
		);
	}

	/**
	 * @dataProvider sites
	 *
	 * @param string $domain Domain of the site.
	 * @param string $path   Path of the site.
	 * @param string $label  Label of its target.
	 */
	public function test_a_deleted_site_is_the_target_of_its_event( string $domain, string $path, string $label ): void {
		$recorder = $this->recorder();
		( new MultisiteSensor( $recorder ) )->on_site_deleted( new \WP_Site( 12, $domain, $path ) );

		$this->assertSame( [ 4101 ], array_column( $recorder->calls, 0 ) );
		$fields = $recorder->calls[0][1];
		$this->assertSame( 'site', $fields['target']['type'] );
		$this->assertSame( '12', $fields['target']['id'] );
		$this->assertSame( $label, $fields['target']['label'] );
		$this->assertSame( $domain, $fields['domain'] );
	}

	public function test_the_same_site_is_one_target_when_created_and_when_deleted(): void {
		$recorder = new BuildingRecorder();
		$sensor   = new MultisiteSensor( $recorder );
		$sensor->on_site_created( new \WP_Site( 12, 'example.com', '/blog2/' ) );
		$sensor->on_site_deleted( new \WP_Site( 12, 'example.com', '/blog2/' ) );

		$this->assertSame( [ 4100, 4101 ], array_column( $recorder->payloads, 'code' ) );
		$this->assertSame( $recorder->payloads[0]['target'], $recorder->payloads[1]['target'] );
		$this->assertSame( 'Site example.com created on the network.', $recorder->payloads[0]['message'] );
		$this->assertSame( 'Site example.com deleted from the network.', $recorder->payloads[1]['message'] );
	}

	private function recorder(): EventDispatcher {
		return new class() implements EventDispatcher {

			/** @var array<int, array{0: int, 1: array<string, mixed>}> */
			public array $calls = [];

			public function dispatch( int $code, array $fields = [] ): void {
				$this->calls[] = [ $code, $fields ];
			}
		};
	}
}
