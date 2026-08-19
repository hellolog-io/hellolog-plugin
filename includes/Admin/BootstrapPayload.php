<?php
/**
 * Builds the `window.hellologAdmin` bootstrap payload for the Vue admin SPA.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Admin;

use HelloLog\Plugin;
use HelloLog\Queue\QueueRepository;
use HelloLog\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Split out of {@see AssetsLoader} so enqueueing and payload assembly stay
 * separate responsibilities. The caller JSON-encodes {@see self::build()}'s
 * array directly rather than going through `wp_localize_script()`, which
 * would cast booleans/ints to strings and break v-model checkboxes on the
 * Vue side (`isConfigured: true` must stay a boolean, `queue.pending: 0` an
 * int).
 */
final class BootstrapPayload {

	/**
	 * @return array<string, mixed>
	 */
	public function build(): array {
		$plugin  = Plugin::instance();
		$options = $plugin->options();
		$token   = $options->token();

		return [
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'adminUrl'       => admin_url(),
			'restUrl'        => rest_url( 'hellolog/v1/' ),
			'restNonce'      => wp_create_nonce( 'wp_rest' ),
			'nonce'          => wp_create_nonce( ActivityLogAjax::ACTION ),
			'testNonce'      => wp_create_nonce( TestConnectionHandler::ACTION ),
			'endpoint'       => Options::ENDPOINT_URL,
			'dashboard_url'  => Options::DASHBOARD_URL,
			'tokenLastFour'  => '' !== $token ? substr( $token, -4 ) : '',
			'isConfigured'   => $options->is_configured(),
			'isLicenseValid' => $options->is_active(),
			'api_access'     => $options->api_access(),
			'anonymizeIp'    => $options->anonymize_ip(),
			'sensors'        => $this->sensors_payload( $plugin, $options ),
			'queue'          => $this->queue_payload(),
			'self_update'    => (bool) apply_filters( 'hellolog_self_update', true ),
			'download_url'   => 'https://github.com/hellolog-io/hellolog-plugin/releases/latest/download/hellolog.zip',
		];
	}

	/**
	 * @return array<int, array{key:string,label:string,enabled:bool}>
	 */
	private function sensors_payload( Plugin $plugin, Options $options ): array {
		$disabled = $options->sensor_filters();
		$out      = [];
		foreach ( $plugin->sensors()->sensors() as $key => $_sensor ) {
			$out[] = [
				'key'     => $key,
				'label'   => ucwords( str_replace( [ '-', '_' ], ' ', $key ) ),
				'enabled' => empty( $disabled[ $key ] ),
			];
		}
		return $out;
	}

	/**
	 * @return array{pending:int,sending:int,dead:int}
	 */
	private function queue_payload(): array {
		$counts = ( new QueueRepository() )->counts_by_status();
		return [
			'pending' => (int) ( $counts[ QueueRepository::STATUS_PENDING ] ?? 0 ),
			'sending' => (int) ( $counts[ QueueRepository::STATUS_SENDING ] ?? 0 ),
			'dead'    => (int) ( $counts[ QueueRepository::STATUS_DEAD ] ?? 0 ),
		];
	}
}
