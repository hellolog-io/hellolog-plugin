<?php
/**
 * Sensor: WordPress general settings changes.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Core;

use HelloLog\Events\ChangeSet;
use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\OptionChangePolicy;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Subscribes to {@see updated_option} and reports changes to the well-known
 * options. We special-case the high-impact ones (site URL, admin email,
 * search-engine visibility, permalink structure) so they get their own
 * severity-MEDIUM/HIGH codes; everything else in the watchlist rolls up
 * to code 6000.
 *
 * Each event has the setting as its target and the old-to-new change, built
 * the way the plugin settings sensors build theirs (see
 * {@see OptionChangePolicy::target()} and {@see OptionChangePolicy::changes()}),
 * and every value it carries is masked (a password in a URL, a
 * credential-like key in JSON). Unlike plugin settings, every write to a
 * watched option is logged, whoever made it: a cron job or a visitor's
 * request changing the site address is exactly what has to be on record.
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
			$changes = OptionChangePolicy::changes( $option, $old_value, $value );
			if ( [] === $changes ) {
				return; // Nothing a reader could see changed (`1` and `'1'`).
			}

			$old = ChangeSet::redact_text( $this->stringify( $old_value ) );
			$new = ChangeSet::redact_text( $this->stringify( $value ) );
			$this->emit(
				$this->code_for( $option ),
				[
					'option'   => $option,
					'target'   => OptionChangePolicy::target( $option ),
					'changes'  => $changes,
					'old'      => $old,
					'new'      => $new,
					'state'    => $new,
					'metadata' => [
						'option' => $option,
						'old'    => $old,
						'new'    => $new,
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
	 * The text of a value, before masking. Arrays and objects are written out
	 * whole: masking has to read the JSON to find a credential-like key in it,
	 * and the event builder shortens long metadata values afterwards.
	 *
	 * @param mixed $value
	 */
	private function stringify( $value ): string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		$encoded = wp_json_encode( $value );
		return is_string( $encoded ) ? $encoded : '';
	}
}
