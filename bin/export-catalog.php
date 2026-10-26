<?php
/**
 * Prints the event catalog as JSON.
 *
 * Usage: php bin/export-catalog.php > catalog.json
 *
 * Runs with plain PHP: no WordPress is loaded, only the Composer autoloader.
 *
 * @package HelloLog
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// The plugin classes bail out without ABSPATH; the value itself is never used.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant, defined only so the plugin files load outside WordPress.
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

( static function (): void {
	$catalog = new HelloLog\Events\EventCatalog();
	HelloLog\Sensors\CatalogSeeder::seed( $catalog );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- plain CLI script, wp_json_encode() does not exist here.
	$json = json_encode(
		HelloLog\Sensors\CatalogExporter::export( $catalog ),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);

	if ( false === $json ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI error output on STDERR, not a filesystem write.
		fwrite( STDERR, 'Could not encode the catalog: ' . json_last_error_msg() . PHP_EOL );
		exit( 1 );
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON for a file or pipe, not HTML.
	echo $json . PHP_EOL;
} )();
