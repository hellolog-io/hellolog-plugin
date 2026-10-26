<?php
/**
 * Tests for {@see HelloLog\Sensors\CatalogExporter} and bin/export-catalog.php.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Events;

use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDefinition;
use HelloLog\Sensors\CatalogExporter;
use HelloLog\Sensors\CatalogSeeder;
use PHPUnit\Framework\TestCase;

/**
 * The exported list is the machine-readable form of the seeded catalog:
 * sorted by code, one row per code, with the template's placeholder names.
 */
final class ExportCatalogTest extends TestCase {

	/**
	 * @return list<array{code:int,label:string,severity:string,object:string,event_type:string,vars:list<string>}>
	 */
	private function seeded_export(): array {
		$catalog = new EventCatalog();
		CatalogSeeder::seed( $catalog );
		return CatalogExporter::export( $catalog );
	}

	public function test_export_is_sorted_unique_and_lists_vars(): void {
		$rows = $this->seeded_export();

		$this->assertNotEmpty( $rows );

		$previous = null;
		$by_code  = [];
		foreach ( $rows as $row ) {
			if ( null !== $previous ) {
				$this->assertGreaterThan( $previous, $row['code'], 'codes must be strictly ascending' );
			}
			$previous                = $row['code'];
			$by_code[ $row['code'] ] = $row;

			$this->assertNotSame( '', $row['label'], "code {$row['code']} has an empty label" );
			$this->assertNotSame( '', $row['object'], "code {$row['code']} has an empty object" );
			$this->assertNotSame( '', $row['event_type'], "code {$row['code']} has an empty event_type" );
			$this->assertNotSame( '', $row['severity'], "code {$row['code']} has an empty severity" );
			$this->assertIsArray( $row['vars'] );
		}

		$this->assertArrayHasKey( 9103, $by_code );
		$this->assertSame( [ 'id', 'note' ], $by_code[9103]['vars'] );

		$this->assertArrayHasKey( 4002, $by_code );
		$this->assertSame( [ 'username', 'old_role', 'new_role' ], $by_code[4002]['vars'] );

		$this->assertArrayHasKey( 2013, $by_code );
		$this->assertSame( '{post_type} "{title}" reverted to auto-draft.', $by_code[2013]['label'] );
		$this->assertSame( [ 'post_type', 'title' ], $by_code[2013]['vars'] );
		$this->assertSame( 'post', $by_code[2013]['object'] );
		$this->assertSame( 'updated', $by_code[2013]['event_type'] );
		$this->assertSame( 'medium', $by_code[2013]['severity'] );
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: string, 3: string}> Code, label, event type, severity.
	 */
	public static function user_account_codes(): array {
		return [
			'password reset completed' => [ 1004, 'Password reset completed for {username}.', 'password-reset', 'medium' ],
			'password changed'         => [ 4004, 'Password changed for {username}.', 'updated', 'high' ],
			'e-mail changed'           => [ 4005, 'E-mail address changed for {username}.', 'updated', 'high' ],
		];
	}

	/**
	 * @dataProvider user_account_codes
	 */
	public function test_user_account_codes_are_seeded( int $code, string $label, string $event_type, string $severity ): void {
		$by_code = array_column( $this->seeded_export(), null, 'code' );

		$this->assertArrayHasKey( $code, $by_code );
		$this->assertSame( $label, $by_code[ $code ]['label'] );
		$this->assertSame( [ 'username' ], $by_code[ $code ]['vars'] );
		$this->assertSame( 'user', $by_code[ $code ]['object'] );
		$this->assertSame( $event_type, $by_code[ $code ]['event_type'] );
		$this->assertSame( $severity, $by_code[ $code ]['severity'] );
	}

	public function test_vars_keep_first_appearance_order_without_duplicates(): void {
		$catalog = new EventCatalog();
		// Registered out of order on purpose: the export sorts by code.
		$catalog->register( new EventDefinition( 20, 'post', 'updated', 'info', 'Static text.' ) );
		$catalog->register( new EventDefinition( 10, 'post', 'created', 'low', '{b} then {a} then {b} again, plus {a_1}.' ) );

		$rows = CatalogExporter::export( $catalog );

		$this->assertSame( [ 10, 20 ], array_column( $rows, 'code' ) );
		$this->assertSame( [ 'b', 'a', 'a_1' ], $rows[0]['vars'] );
		$this->assertSame( [], $rows[1]['vars'] );
		$this->assertSame( '{b} then {a} then {b} again, plus {a_1}.', $rows[0]['label'] );
		$this->assertSame(
			[ 'code', 'label', 'severity', 'object', 'event_type', 'vars' ],
			array_keys( $rows[0] )
		);
	}

	public function test_script_prints_the_export_as_json_without_wordpress(): void {
		$script = dirname( __DIR__, 2 ) . '/bin/export-catalog.php';
		$this->assertFileExists( $script );

		// A fresh interpreter: no bootstrap, so ABSPATH is genuinely undefined.
		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1' );
		$this->assertIsString( $output );

		$decoded = json_decode( $output, true );
		$this->assertIsArray( $decoded, 'script output must be a JSON array, got: ' . substr( $output, 0, 200 ) );
		$this->assertSame( $this->seeded_export(), $decoded );
		$this->assertStringContainsString( '→', $output, 'unicode must not be escaped' );
	}
}
