<?php
/**
 * Plugin Name:       helloLOG
 * Plugin URI:        https://hellolog.io
 * Description:       Lightweight WordPress activity log.
 * Version:           0.4.3
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            goBird
 * Author URI:        https://gobird.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hellolog
 * Domain Path:       /languages
 *
 * @package HelloLog
 * @version 0.4.3
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// Bail out gracefully on outdated PHP. The plugin relies on typed
// constructor promotion, `match`, named arguments and other PHP 8.0+
// features in the autoloaded classes — letting WordPress include them
// on PHP 7.x would fatal the whole site. Instead we stop loading the
// plugin here and surface a single admin notice so the operator knows
// what happened. `version_compare` has existed since PHP 4.1, so the
// check itself is safe on every plausible runtime.
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'helloLOG requires PHP %1$s or newer. Your site is running PHP %2$s, so the plugin is not loaded. Upgrade PHP or deactivate the plugin.', 'hellolog' ),
						'8.0',
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

const HELLOLOG_VERSION = '0.4.3';
const HELLOLOG_FILE    = __FILE__;

define( 'HELLOLOG_DIR', plugin_dir_path( __FILE__ ) );
define( 'HELLOLOG_URL', plugin_dir_url( __FILE__ ) );

require_once HELLOLOG_DIR . 'vendor/autoload.php';

// Action Scheduler ships as a Composer dependency. Its bootstrap auto-detects
// the highest-versioned copy across active plugins, so loading it here is safe
// even when WooCommerce (or another plugin) already bundles it.
require_once HELLOLOG_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

register_activation_hook( __FILE__, [ \HelloLog\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \HelloLog\Deactivator::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		\HelloLog\Plugin::instance()->boot();
	}
);
