<?php
/**
 * Enqueues the Vue admin SPA on the plugin's own screens.
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
 * Wires the single Vue bundle (`assets/admin/app.{js,css}`) on the
 * `Tools → helloLOG` screen and serialises every piece of
 * state the SPA needs to boot into `window.hellologAdmin`. The PHP page
 * only renders an empty `<div id="hellolog-app">` — the top-bar inside
 * the SPA owns the Logs ↔ Settings switch.
 *
 * The asset version string combines `HELLOLOG_VERSION` with the bundle
 * mtime so editing the JS/CSS busts the browser cache without bumping
 * the plugin header version.
 */
final class AssetsLoader {

	private const HANDLE   = 'hellolog-admin';
	private const JS_REL   = 'assets/admin/app.js';
	private const CSS_REL  = 'assets/admin/app.css';
	private const MOUNT_ID = 'hellolog-app';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue( string $hook ): void {
		if ( ! $this->is_plugin_screen( $hook ) ) {
			return;
		}

		$js_abs = HELLOLOG_DIR . self::JS_REL;
		if ( ! file_exists( $js_abs ) ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			HELLOLOG_URL . self::JS_REL,
			[],
			$this->asset_version( $js_abs ),
			true
		);

		$css_abs = HELLOLOG_DIR . self::CSS_REL;
		if ( file_exists( $css_abs ) ) {
			wp_enqueue_style(
				self::HANDLE,
				HELLOLOG_URL . self::CSS_REL,
				[],
				$this->asset_version( $css_abs )
			);
			wp_add_inline_style( self::HANDLE, $this->screen_reset_css() );
		}

		// `wp_localize_script()` would casts booleans and ints to strings,
		// which breaks v-model checkboxes on the Vue side. Inline JSON keeps
		// `isConfigured: true` a boolean and `queue.pending: 0` an int.
		wp_add_inline_script(
			self::HANDLE,
			'var hellologAdmin = ' . wp_json_encode( $this->bootstrap_data() ) . ';',
			'before'
		);

		wp_add_inline_script( self::HANDLE, $this->notice_cleanup_script(), 'before' );
	}

	/**
	 * Inline CSS that does two jobs on our admin screen:
	 *
	 * 1. Hide every WP admin notice. Third-party plugins emit custom-named
	 *    banners (`analytify-review-notice`, `wp-rocket-notice`, mu-plugin
	 *    debug boxes echoed directly into the body, …), so we match by
	 *    class/id substring page-wide. Anything inside our SPA root is
	 *    excluded so legitimate UI like `hellolog-notice-pill` keeps rendering.
	 * 2. Strip the WordPress `.wrap` margin and `#wpbody-content` padding
	 *    so the SPA truly fills the full admin width. The Vue layout owns
	 *    its own padding from there.
	 */
	private function screen_reset_css(): string {
		$vue_root = '#' . self::MOUNT_ID;
		return '.updated,.error,.update-nag,'
			. '[class*="notice"]:not(' . $vue_root . '):not(' . $vue_root . ' *),'
			. '[id*="notice"]:not(' . $vue_root . '):not(' . $vue_root . ' *)'
			. '{display:none!important;}'
			. '#wpbody-content{padding-bottom:0!important;}'
			. '#wpbody-content>.wrap{margin:0!important;}'
			. '#wpcontent{padding-left:0!important;}'
			. $vue_root . '{margin:0;padding:0;}';
	}

	/**
	 * Vanilla-JS DOM cleanup that physically removes every WP admin notice
	 * from the page before Vue mounts. CSS `display:none` alone is brittle —
	 * a third-party stylesheet loaded after us can override it with its own
	 * `!important` rule, or a plugin can re-inject the notice from JS. By
	 * stripping the nodes outright we guarantee the SPA owns the surface.
	 * The cleanup runs once at script-load (footer-time, DOM is ready) and
	 * once more on `DOMContentLoaded` to catch late echoes.
	 */
	private function notice_cleanup_script(): string {
		$root_id = self::MOUNT_ID;
		return <<<JS
(function(){
  var rootId='{$root_id}';
  var sel='.updated,.error,.update-nag,[class*="notice"],[id*="notice"]';
  function strip(){
    var root=document.getElementById(rootId);
    document.querySelectorAll(sel).forEach(function(n){
      if(root && (n===root || root.contains(n))) return;
      if(n.parentNode) n.parentNode.removeChild(n);
    });
  }
  strip();
  if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',strip);
  }
})();
JS;
	}

	private function is_plugin_screen( string $hook ): bool {
		return str_contains( $hook, AdminPage::SLUG );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function bootstrap_data(): array {
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
			'anonymizeIp'    => $options->anonymize_ip(),
			'sensors'        => $this->sensors_payload( $plugin, $options ),
			'queue'          => $this->queue_payload(),
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

	private function asset_version( string $abs_path ): string {
		$mtime = filemtime( $abs_path );
		if ( false !== $mtime ) {
			return HELLOLOG_VERSION . '.' . $mtime;
		}
		return HELLOLOG_VERSION;
	}
}
