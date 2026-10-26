<?php
/**
 * Sensor: WordPress general settings changes.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Sensors\AbstractSensor;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Subscribes to {@see updated_option} and reports changes to the well-known
 * options. We special-case the high-impact ones (site URL, admin email,
 * search-engine visibility, permalink structure) so they get their own
 * severity-MEDIUM/HIGH codes; everything else in the watchlist rolls up
 * to code 6000.
 */
final class SettingsSensor extends AbstractSensor {

	private const WATCHED = [
		'blogname',
		'blogdescription',
		'siteurl',
		'home',
		'admin_email',
		'blog_public',
		'permalink_structure',
		'default_role',
		'date_format',
		'time_format',
		'start_of_week',
		'timezone_string',
		'WPLANG',
		'category_base',
		'tag_base',
		'comment_registration',
		'comment_moderation',
		'require_name_email',
		'comments_notify',
		'moderation_notify',
		'users_can_register',
		'posts_per_page',
		'page_for_posts',
		'page_on_front',
		'show_on_front',
	];

	public function key(): string {
		return 'core-settings';
	}

	public function boot(): void {
		add_action( 'updated_option', [ $this, 'on_update' ], 10, 3 );
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 */
	public function on_update( string $option, $old_value, $value ): void {
		// Reading the values of a write can fail (an object whose text throws);
		// that must never reach the request that saved the setting.
		try {
			if ( ! in_array( $option, self::WATCHED, true ) ) {
				return;
			}
			if ( $old_value === $value ) {
				return;
			}

			$code = $this->code_for( $option );
			$this->emit(
				$code,
				[
					'option'   => $option,
					'old'      => $this->stringify( $old_value ),
					'new'      => $this->stringify( $value ),
					'state'    => $this->stringify( $value ),
					'metadata' => [
						'option' => $option,
						'old'    => $this->stringify( $old_value ),
						'new'    => $this->stringify( $value ),
					],
				]
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, $this->code_for( $option ), [ 'option' => $option ] );
		}
	}

	private function code_for( string $option ): int {
		return match ( $option ) {
			'siteurl', 'home'     => 6001,
			'admin_email'         => 6002,
			'permalink_structure' => 6003,
			'blog_public'         => 6004,
			default               => 6000,
		};
	}

	/**
	 * @param mixed $value
	 */
	private function stringify( $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		$encoded = wp_json_encode( $value );
		return is_string( $encoded ) ? substr( $encoded, 0, 256 ) : '';
	}
}
