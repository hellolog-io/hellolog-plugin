<?php
/**
 * `wp hellolog` WP-CLI commands.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Cli;

use HelloLog\Plugin;
use HelloLog\Queue\QueueRepository;
use HelloLog\Settings\Options;
use HelloLog\Transport\ApiClient;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Operator surface for the plugin from the CLI. Wraps the same services
 * the admin UI uses, so behaviour matches the WP admin Settings page.
 *
 * Registered as `wp hellolog <subcommand>` once Plugin::boot() runs.
 */
final class Command {

	/**
	 * Show the plugin's current state.
	 *
	 * Reports the backend endpoint, whether a token is set, and how many
	 * rows are sitting in each queue status (pending / sending / dead).
	 *
	 * ## EXAMPLES
	 *
	 *     wp hellolog status
	 */
	public function status(): void {
		$opts   = new Options();
		$repo   = new QueueRepository();
		$counts = $repo->counts_by_status();

		WP_CLI::log( 'API key:     ' . ( $opts->is_configured() ? 'set' : 'missing' ) );
		WP_CLI::log( 'License:     ' . ( $opts->is_active() ? 'active (sensors attached)' : 'inactive — run `wp hellolog test` to validate' ) );
		WP_CLI::log( 'Anonymize IP: ' . ( $opts->anonymize_ip() ? 'on' : 'off' ) );
		WP_CLI::log( 'Queue table: ' . $repo->table() );
		WP_CLI::log(
			sprintf(
				'  pending=%d  sending=%d  dead=%d',
				(int) ( $counts[ QueueRepository::STATUS_PENDING ] ?? 0 ),
				(int) ( $counts[ QueueRepository::STATUS_SENDING ] ?? 0 ),
				(int) ( $counts[ QueueRepository::STATUS_DEAD ] ?? 0 )
			)
		);

		$disabled = $opts->sensor_filters();
		if ( ! empty( $disabled ) ) {
			WP_CLI::log( 'Disabled sensors: ' . implode( ', ', array_keys( array_filter( $disabled ) ) ) );
		} else {
			WP_CLI::log( 'Disabled sensors: (none)' );
		}
	}

	/**
	 * Flush the local queue to the backend right now (skip the 30-second tick).
	 *
	 * ## EXAMPLES
	 *
	 *     wp hellolog flush
	 */
	public function flush(): void {
		$opts = new Options();
		if ( ! $opts->is_configured() ) {
			WP_CLI::error( 'No site token configured. Run `wp hellolog set-token <value>` first.' );
		}
		do_action( 'hellolog_flush_queue' );
		WP_CLI::success( 'Flush triggered.' );
	}

	/**
	 * Send a one-off ping event to the backend (bypasses the queue).
	 *
	 * ## EXAMPLES
	 *
	 *     wp hellolog test
	 */
	public function test(): void {
		$opts = new Options();
		if ( ! $opts->is_configured() ) {
			WP_CLI::error( 'No site token configured.' );
		}
		$client = new ApiClient( $opts->endpoint_url(), $opts->token() );
		$batch  = wp_json_encode(
			[
				'batch_id' => 'cli-' . substr( md5( (string) microtime( true ) ), 0, 8 ),
				'events'   => [
					[
						'code'        => 9999,
						'occurred_at' => gmdate( 'Y-m-d\TH:i:s.v\Z' ),
						'severity'    => 'info',
						'object'      => 'system',
						'event_type'  => 'connection-test',
						'message'     => 'Test event from `wp hellolog test`.',
					],
				],
			]
		);
		$result = $client->post_batch( is_string( $batch ) ? $batch : '' );
		// A successful test event flips the license to "active" — this
		// is the only path from "stored key" to "sensors attached".
		$opts->mark_active( $result->ok );
		if ( $result->ok ) {
			WP_CLI::success( sprintf( 'HTTP %d %s — license verified, sensors will attach on next request.', $result->status, $result->body ) );
			return;
		}
		WP_CLI::error( sprintf( 'HTTP %d %s — license NOT verified, sensors stay detached.', $result->status, $result->body ) );
	}

	/**
	 * Set the site bearer token.
	 *
	 * ## OPTIONS
	 *
	 * <token>
	 * : The bearer token issued by the backend (hellolog_live_<prefix>_<secret>).
	 *
	 * ## EXAMPLES
	 *
	 *     wp hellolog set-token hellolog_live_xxxxxxxx_yyyyyyyy...
	 *
	 * @subcommand set-token
	 *
	 * @param array<int, string> $args
	 */
	public function set_token( array $args ): void {
		$token = (string) ( $args[0] ?? '' );
		if ( '' === $token ) {
			WP_CLI::error( 'Empty token.' );
		}
		update_option( Options::KEY_TOKEN, sanitize_text_field( $token ) );
		// New key — license must be re-verified before sensors run.
		( new Options() )->mark_active( false );
		WP_CLI::success(
			'Token saved (last 4: ' . substr( $token, -4 ) . '). '
			. 'Run `wp hellolog test` to verify and activate.'
		);
	}

	/**
	 * Clear the stored site token. Disables the Activity Log surface and the
	 * outgoing queue until a new token is set.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hellolog clear-token
	 *
	 * @subcommand clear-token
	 */
	public function clear_token(): void {
		delete_option( Options::KEY_TOKEN );
		( new Options() )->mark_active( false );
		WP_CLI::success( 'Token cleared. Sensors detached.' );
	}

	/**
	 * Wipe rows from the local outgoing queue. Useful after a long
	 * stretch with a bad token — the dead pile-up can be deleted in one
	 * shot once the operator stops the bleeding.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Limit to one queue status (pending / sending / dead). Default: all.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hellolog clear-queue --status=dead
	 *     wp hellolog clear-queue
	 *
	 * @subcommand clear-queue
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public function clear_queue( array $args, array $assoc = [] ): void {
		unset( $args );
		$repo    = new QueueRepository();
		$status  = isset( $assoc['status'] ) ? (string) $assoc['status'] : '';
		$deleted = $repo->purge( '' !== $status ? $status : null );
		WP_CLI::success( sprintf( 'Deleted %d row(s)%s.', $deleted, '' !== $status ? ' with status=' . $status : '' ) );
	}

	/**
	 * Move every `dead` row back to `pending` so the flusher picks them
	 * up again. Use after a backend outage / token rotation, once
	 * `wp hellolog test` confirms the backend is healthy again.
	 *
	 * The follow-up `wp hellolog flush` (or the Action Scheduler's
	 * 30-second tick) sends them. Successful deliveries delete the row;
	 * failures retry the normal back-off ladder.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hellolog status            # see the dead count first
	 *     wp hellolog test              # confirm the backend accepts a ping
	 *     wp hellolog requeue-dead      # dead -> pending
	 *     wp hellolog flush             # send them now (or wait 60s)
	 *
	 * @subcommand requeue-dead
	 */
	public function requeue_dead(): void {
		$repo     = new QueueRepository();
		$counts   = $repo->counts_by_status();
		$dead_now = (int) ( $counts[ QueueRepository::STATUS_DEAD ] ?? 0 );
		if ( 0 === $dead_now ) {
			WP_CLI::success( 'No dead rows to requeue.' );
			return;
		}
		$moved = $repo->requeue_dead();
		WP_CLI::success( sprintf( 'Requeued %d row(s) from dead to pending. Run `wp hellolog flush` to send them now.', $moved ) );
	}

	/**
	 * List every registered sensor and whether it's currently enabled.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hellolog sensors
	 */
	public function sensors(): void {
		$disabled = new Options();
		$disabled = $disabled->sensor_filters();

		$sensors = Plugin::instance()->sensors()->sensors();
		if ( empty( $sensors ) ) {
			WP_CLI::warning( 'No sensors registered (plugin booted?).' );
			return;
		}

		$rows = [];
		foreach ( $sensors as $key => $sensor ) {
			$rows[] = [
				'key'     => $key,
				'enabled' => empty( $disabled[ $key ] ) ? 'yes' : 'NO',
				'class'   => substr( get_class( $sensor ), strrpos( get_class( $sensor ), '\\' ) + 1 ),
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'key', 'enabled', 'class' ] );
	}

	/**
	 * Enable a sensor by its key.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Sensor key (run `wp hellolog sensors` to list them).
	 *
	 * @subcommand enable-sensor
	 *
	 * @param array<int, string> $args
	 */
	public function enable_sensor( array $args ): void {
		$this->update_sensor_state( (string) ( $args[0] ?? '' ), false );
	}

	/**
	 * Disable a sensor by its key.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Sensor key (run `wp hellolog sensors` to list them).
	 *
	 * @subcommand disable-sensor
	 *
	 * @param array<int, string> $args
	 */
	public function disable_sensor( array $args ): void {
		$this->update_sensor_state( (string) ( $args[0] ?? '' ), true );
	}

	private function update_sensor_state( string $key, bool $disable ): void {
		if ( '' === $key ) {
			WP_CLI::error( 'Sensor key required.' );
		}
		$filters         = (array) get_option( Options::KEY_SENSOR_FILTERS, [] );
		$filters[ $key ] = $disable;
		if ( ! $disable ) {
			unset( $filters[ $key ] );
		}
		update_option( Options::KEY_SENSOR_FILTERS, $filters );
		WP_CLI::success( sprintf( 'Sensor %s %s.', $key, $disable ? 'disabled' : 'enabled' ) );
	}
}
