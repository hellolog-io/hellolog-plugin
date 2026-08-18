<?php
/**
 * Sensor: theme/plugin file editor saves.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Sensors\AbstractSensor;

defined( 'ABSPATH' ) || exit;

/**
 * Picks up wp-admin Theme Editor and Plugin Editor saves
 * (`wp_ajax_edit-theme-plugin-file`). Periodic disk-integrity scans
 * are a separate concern handled by FilesystemScanCron (M7+).
 *
 * {@see self::detect_editor_save()} is a read-only audit observer, not the
 * form handler: WP core's own save path verifies its own nonce before
 * writing. We log the attempt regardless of outcome — including
 * rejected/forged ones, which is desirable for a security log — and never
 * perform the write ourselves, so no NonceVerification check belongs here.
 */
final class FilesSensor extends AbstractSensor {

	public function key(): string {
		return 'core-files';
	}

	public function boot(): void {
		add_action( 'admin_init', [ $this, 'detect_editor_save' ], 10, 0 );
	}

	public function detect_editor_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see class docblock; observer only, no privileged action performed.
		if ( ! isset( $_POST['action'], $_POST['file'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see class docblock; observer only.
		$action = sanitize_key( wp_unslash( (string) $_POST['action'] ) );
		if ( ! in_array( $action, [ 'edit-theme-plugin-file', 'update', 'updateheaders' ], true ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see class docblock; observer only.
		$file = sanitize_text_field( wp_unslash( (string) $_POST['file'] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see class docblock; observer only.
		$kind = ! empty( $_POST['plugin'] ) ? 'plugin' : ( ! empty( $_POST['theme'] ) ? 'theme' : 'unknown' );

		$this->emit(
			6300,
			[
				'kind'     => ucfirst( $kind ),
				'file'     => $file,
				'metadata' => [
					'kind' => $kind,
					'file' => $file,
				],
			]
		);
	}
}
