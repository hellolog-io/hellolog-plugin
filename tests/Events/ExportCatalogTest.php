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

	/**
	 * @return array<string, array{0: int, 1: string, 2: array<int, string>, 3: string, 4: string}> Code, label, vars, event type, severity.
	 */
	public static function post_status_codes(): array {
		return [
			'scheduled'            => [ 2007, '{post_type} "{title}" scheduled for {date}.', [ 'post_type', 'title', 'date' ], 'updated', 'info' ],
			'submitted for review' => [ 2008, '{post_type} "{title}" submitted for review.', [ 'post_type', 'title' ], 'submitted', 'info' ],
			'made private'         => [ 2009, '{post_type} "{title}" made private.', [ 'post_type', 'title' ], 'updated', 'low' ],
		];
	}

	/**
	 * @dataProvider post_status_codes
	 *
	 * @param array<int, string> $vars Expected placeholder names.
	 */
	public function test_post_status_codes_are_seeded( int $code, string $label, array $vars, string $event_type, string $severity ): void {
		$by_code = array_column( $this->seeded_export(), null, 'code' );

		$this->assertArrayHasKey( $code, $by_code );
		$this->assertSame( $label, $by_code[ $code ]['label'] );
		$this->assertSame( $vars, $by_code[ $code ]['vars'] );
		$this->assertSame( 'post', $by_code[ $code ]['object'] );
		$this->assertSame( $event_type, $by_code[ $code ]['event_type'] );
		$this->assertSame( $severity, $by_code[ $code ]['severity'] );
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: string}> Code, label, object.
	 */
	public static function auto_update_codes(): array {
		return [
			'plugin auto-updates' => [ 5005, 'Automatic updates {state} for plugin {name}.', 'plugin' ],
			'theme auto-updates'  => [ 5105, 'Automatic updates {state} for theme {name}.', 'theme' ],
		];
	}

	/**
	 * @dataProvider auto_update_codes
	 */
	public function test_auto_update_codes_are_seeded( int $code, string $label, string $object ): void {
		$by_code = array_column( $this->seeded_export(), null, 'code' );

		$this->assertArrayHasKey( $code, $by_code );
		$this->assertSame( $label, $by_code[ $code ]['label'] );
		$this->assertSame( [ 'state', 'name' ], $by_code[ $code ]['vars'] );
		$this->assertSame( $object, $by_code[ $code ]['object'] );
		$this->assertSame( 'updated', $by_code[ $code ]['event_type'] );
		$this->assertSame( 'medium', $by_code[ $code ]['severity'] );
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: string, 3: string}> Code, label, event type, severity.
	 */
	public static function media_codes(): array {
		return [
			'uploaded' => [ 2010, 'Media "{title}" uploaded.', 'created', 'info' ],
			'edited'   => [ 2011, 'Media "{title}" edited.', 'updated', 'info' ],
			'deleted'  => [ 2012, 'Media "{title}" deleted.', 'deleted', 'low' ],
		];
	}

	/**
	 * @dataProvider media_codes
	 */
	public function test_media_codes_are_seeded( int $code, string $label, string $event_type, string $severity ): void {
		$by_code = array_column( $this->seeded_export(), null, 'code' );

		$this->assertArrayHasKey( $code, $by_code );
		$this->assertSame( $label, $by_code[ $code ]['label'] );
		$this->assertSame( [ 'title' ], $by_code[ $code ]['vars'] );
		$this->assertSame( 'media', $by_code[ $code ]['object'] );
		$this->assertSame( $event_type, $by_code[ $code ]['event_type'] );
		$this->assertSame( $severity, $by_code[ $code ]['severity'] );
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: string, 3: string}> Code, label, event type, severity.
	 */
	public static function order_codes(): array {
		return [
			'edited'   => [ 9105, 'WC order #{id} edited.', 'updated', 'medium' ],
			'trashed'  => [ 9109, 'WC order #{id} moved to trash.', 'trashed', 'medium' ],
			'restored' => [ 9110, 'WC order #{id} restored from trash.', 'restored', 'low' ],
		];
	}

	/**
	 * @dataProvider order_codes
	 */
	public function test_order_codes_are_seeded( int $code, string $label, string $event_type, string $severity ): void {
		$by_code = array_column( $this->seeded_export(), null, 'code' );

		$this->assertArrayHasKey( $code, $by_code );
		$this->assertSame( $label, $by_code[ $code ]['label'] );
		$this->assertSame( [ 'id' ], $by_code[ $code ]['vars'] );
		$this->assertSame( 'order', $by_code[ $code ]['object'] );
		$this->assertSame( $event_type, $by_code[ $code ]['event_type'] );
		$this->assertSame( $severity, $by_code[ $code ]['severity'] );
	}

	/**
	 * @return array<string, array{0: int, 1: string, 2: array<int, string>, 3: string}> Code, label, vars, severity.
	 */
	public static function order_item_and_note_codes(): array {
		return [
			'item added'   => [ 9106, 'Item "{name}" added to WC order #{id}.', [ 'name', 'id' ], 'low' ],
			'item removed' => [ 9107, 'Item removed from WC order #{id}.', [ 'id' ], 'medium' ],
			'item changed' => [ 9108, 'Item "{name}" changed on WC order #{id}.', [ 'name', 'id' ], 'low' ],
			'note deleted' => [ 9111, 'Note deleted from WC order #{id}.', [ 'id' ], 'low' ],
		];
	}

	/**
	 * @dataProvider order_item_and_note_codes
	 *
	 * @param int                $code     Code.
	 * @param string             $label    Message template.
	 * @param array<int, string> $vars     Template vars.
	 * @param string             $severity Severity.
	 */
	public function test_order_item_and_note_codes_are_seeded( int $code, string $label, array $vars, string $severity ): void {
		$by_code = array_column( $this->seeded_export(), null, 'code' );

		$this->assertArrayHasKey( $code, $by_code );
		$this->assertSame( $label, $by_code[ $code ]['label'] );
		$this->assertSame( $vars, $by_code[ $code ]['vars'] );
		$this->assertSame( 'order', $by_code[ $code ]['object'] );
		$this->assertSame( 'updated', $by_code[ $code ]['event_type'] );
		$this->assertSame( $severity, $by_code[ $code ]['severity'] );
	}

	public function test_the_role_capabilities_code_is_seeded(): void {
		$by_code = array_column( $this->seeded_export(), null, 'code' );

		$this->assertArrayHasKey( 6005, $by_code );
		$this->assertSame( 'User role capabilities changed.', $by_code[6005]['label'] );
		$this->assertSame( [], $by_code[6005]['vars'] );
		$this->assertSame( 'setting', $by_code[6005]['object'] );
		$this->assertSame( 'updated', $by_code[6005]['event_type'] );
		$this->assertSame( 'critical', $by_code[6005]['severity'] );
	}

	public function test_the_hellolog_settings_code_is_seeded(): void {
		$by_code = array_column( $this->seeded_export(), null, 'code' );

		$this->assertArrayHasKey( 6600, $by_code );
		$this->assertSame( 'HelloLog settings changed.', $by_code[6600]['label'] );
		$this->assertSame( [], $by_code[6600]['vars'] );
		$this->assertSame( 'setting', $by_code[6600]['object'] );
		$this->assertSame( 'updated', $by_code[6600]['event_type'] );
		$this->assertSame( 'critical', $by_code[6600]['severity'] );
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
