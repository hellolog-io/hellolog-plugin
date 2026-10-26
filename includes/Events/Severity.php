<?php
/**
 * Severity levels for audit events.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Wire-level severity strings accepted by the HelloLog API.
 */
final class Severity {

	public const INFO     = 'info';
	public const LOW      = 'low';
	public const MEDIUM   = 'medium';
	public const HIGH     = 'high';
	public const CRITICAL = 'critical';

	private const ALL = [
		self::INFO,
		self::LOW,
		self::MEDIUM,
		self::HIGH,
		self::CRITICAL,
	];

	public static function is_valid( string $level ): bool {
		return in_array( $level, self::ALL, true );
	}
}
