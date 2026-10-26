<?php
/**
 * The lw-firewall sensor — blocked requests + rule changes.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Events\RequestContext;
use HelloLog\Sensors\AbstractSensor;
use HelloLog\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to the lw-firewall action `lw_firewall_blocked` (if available)
 * and the `lw_firewall_*` option-prefix for rule changes.
 */
final class LwFirewallSensor extends AbstractSensor {

	public function key(): string {
		return 'lw-firewall';
	}

	public function should_load(): bool {
		return defined( 'LW_FIREWALL_VERSION' ) || class_exists( 'LightweightPlugins\\Firewall\\Plugin', false );
	}

	public function boot(): void {
		add_action( 'lw_firewall_blocked', [ $this, 'on_blocked' ], 10, 2 );
		add_action( 'updated_option', [ $this, 'on_option' ], 10, 3 );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function on_blocked( string $reason, array $context ): void {
		$ip = (string) ( $context['ip'] ?? '' );
		// The blocked client's IP arrives in the hook context, outside
		// RequestContext, so the operator's anonymisation setting is
		// applied here as well.
		if ( '' !== $ip && ( new Options() )->anonymize_ip() ) {
			$ip = RequestContext::anonymize( $ip );
		}
		$this->emit(
			9500,
			[
				'ip'       => $ip,
				'reason'   => $reason,
				'metadata' => array_merge(
					$context,
					[
						'reason' => $reason,
						'ip'     => $ip,
					]
				),
			]
		);
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public function on_option( string $option, $old_value, $value ): void {
		if ( ! str_starts_with( $option, 'lw_firewall' ) ) {
			return;
		}
		if ( $old_value === $value ) {
			return;
		}
		$this->emit(
			9501,
			[
				'rule'     => $option,
				'metadata' => [ 'option' => $option ],
			]
		);
	}
}
