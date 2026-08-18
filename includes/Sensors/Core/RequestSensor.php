<?php
/**
 * Sensor: 404 monitor + REST/XML-RPC tracking.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Sensors\AbstractSensor;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Disabled by default — turn on via the Filters tab. These hooks fire on
 * every request, so the volume can be very high on busy sites. Emit only
 * once per request: track REST / XML-RPC / template_redirect 404 cases.
 */
final class RequestSensor extends AbstractSensor {

	public function key(): string {
		return 'core-request';
	}

	public function boot(): void {
		add_action( 'template_redirect', [ $this, 'detect_404' ], 999 );
		add_filter( 'rest_post_dispatch', [ $this, 'detect_rest' ], 999, 3 );
		add_filter( 'xmlrpc_methods', [ $this, 'wrap_xmlrpc' ], 999, 1 );
	}

	public function detect_404(): void {
		if ( ! is_404() ) {
			return;
		}
		$path   = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$this->emit(
			6400,
			[
				'path'     => $path,
				'metadata' => [
					'path'   => $path,
					'method' => $method,
				],
			]
		);
	}

	/**
	 * @param mixed                $result
	 * @param mixed                $server
	 * @param \WP_REST_Request|null $request
	 * @return mixed
	 */
	public function detect_rest( $result, $server, $request ) {
		if ( ! $request || ! is_object( $request ) ) {
			return $result;
		}
		$status = $result instanceof WP_REST_Response ? (int) $result->get_status() : 0;
		$this->emit(
			6401,
			[
				'path'     => (string) $request->get_route(),
				'status'   => $status,
				'metadata' => [
					'method' => (string) $request->get_method(),
					'route'  => (string) $request->get_route(),
					'status' => $status,
				],
			]
		);
		return $result;
	}

	/**
	 * @param array<string, callable> $methods
	 * @return array<string, callable>
	 */
	public function wrap_xmlrpc( array $methods ): array {
		foreach ( $methods as $name => $callback ) {
			$methods[ $name ] = function () use ( $name, $callback ) {
				$this->emit(
					6402,
					[
						'method'   => $name,
						'metadata' => [ 'method' => $name ],
					]
				);
				return call_user_func_array( $callback, func_get_args() );
			};
		}
		return $methods;
	}
}
