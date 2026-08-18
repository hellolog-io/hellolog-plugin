<?php
/**
 * Plugin singleton — wires the runtime services on `plugins_loaded`.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog;

use HelloLog\Admin\ActivityLogAjax;
use HelloLog\Admin\AdminPage;
use HelloLog\Admin\AssetsLoader;
use HelloLog\Admin\TestConnectionHandler;
use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\NullEventDispatcher;
use HelloLog\Queue\QueueEventDispatcher;
use HelloLog\Queue\QueueRepository;
use HelloLog\Scheduler\ActionSchedulerBridge;
use HelloLog\Scheduler\AsLogPruner;
use HelloLog\Scheduler\FlushLock;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Core\CommentsSensor;
use HelloLog\Sensors\Core\ContentSensor;
use HelloLog\Sensors\Core\DatabaseSensor;
use HelloLog\Sensors\Core\FilesSensor;
use HelloLog\Sensors\Core\FailedLoginSensor;
use HelloLog\Sensors\Core\LoginLogoutSensor;
use HelloLog\Sensors\Core\PluginsSensor;
use HelloLog\Sensors\Core\AppPasswordsSensor;
use HelloLog\Sensors\Core\MenusSensor;
use HelloLog\Sensors\Core\MultisiteSensor;
use HelloLog\Sensors\Core\RequestSensor;
use HelloLog\Sensors\Core\SettingsSensor;
use HelloLog\Sensors\Core\SystemSensor;
use HelloLog\Sensors\Core\TaxonomySensor;
use HelloLog\Sensors\Core\ThemesSensor;
use HelloLog\Sensors\Core\TwoFactorSensor;
use HelloLog\Sensors\Core\UserProfileSensor;
use HelloLog\Sensors\Core\WidgetsSensor;
use HelloLog\Sensors\Integrations\FormsLoader;
use HelloLog\Sensors\Integrations\IntegrationsLoader;
use HelloLog\Sensors\Integrations\SeoAcfLoader;
use HelloLog\Sensors\Integrations\WooCommerceLoader;
use HelloLog\Sensors\Integrations\LwLoader;
use HelloLog\Sensors\SensorManager;
use HelloLog\Settings\Options;
use HelloLog\Transport\ApiClient;
use HelloLog\Transport\PayloadBuilder;
use HelloLog\Transport\QueueFlusher;
use HelloLog\Transport\RetryPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level bootstrap. Keep this file thin — wire dependencies, do not
 * implement business logic here.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	private Options $options;

	private EventCatalog $catalog;

	private EventBuilder $builder;

	private EventDispatcher $dispatcher;

	private SensorManager $sensors;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Load translations, assemble the service graph, and let sensors register.
	 * Idempotent.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->load_textdomain();
		$this->assemble_services();

		// ALWAYS register the flush scheduler — even when inactive. The bridge
		// keeps a recurring action alive only while `is_active()`, and tears
		// down any orphan otherwise. Registering this unconditionally, right
		// after `$this->options` exists, is what lets a deconfigured install
		// self-heal instead of ticking an unhandled action forever (the 0.3.1
		// outage).
		$this->register_flush_scheduler();

		$this->wire_admin();
		$this->fire_booted_action();
		$this->wire_cli();
	}

	private function wire_cli(): void {
		( new \HelloLog\Cli\Registrar() )->register();
	}

	private function wire_admin(): void {
		// REST routes register on `rest_api_init`, which fires on REST
		// requests where `is_admin() === false`. The controller therefore
		// has to be wired up *outside* the admin gate; otherwise the
		// `POST /wp-json/hellolog/v1/settings` call from the Vue SPA returns
		// `rest_no_route` and the Save Token button looks broken.
		( new \HelloLog\Rest\SettingsController() )->register();

		if ( ! is_admin() ) {
			return;
		}
		// Single Tools-level admin page hosts both the Logs and Settings
		// views via the Vue SPA's top-bar. Ajax endpoints register
		// unconditionally so the SPA can render an empty state when the
		// token is missing rather than 404 on the read calls.
		( new AdminPage() )->register();
		( new TestConnectionHandler() )->register();
		( new ActivityLogAjax() )->register();
		( new AssetsLoader() )->register();
	}

	public function options(): Options {
		return $this->options;
	}

	public function catalog(): EventCatalog {
		return $this->catalog;
	}

	public function builder(): EventBuilder {
		return $this->builder;
	}

	public function dispatcher(): EventDispatcher {
		return $this->dispatcher;
	}

	public function sensors(): SensorManager {
		return $this->sensors;
	}

	private function load_textdomain(): void {
		load_plugin_textdomain(
			'hellolog',
			false,
			dirname( plugin_basename( HELLOLOG_FILE ) ) . '/languages'
		);
	}

	private function assemble_services(): void {
		$this->options = new Options();

		$this->catalog = new EventCatalog();
		CatalogSeeder::seed( $this->catalog );

		$this->builder    = new EventBuilder( $this->catalog, $this->options->anonymize_ip() );
		$this->dispatcher = $this->build_dispatcher();
		$this->sensors    = new SensorManager();

		// License gate: nothing attaches its hooks until the operator has
		// stored a valid-format key AND the plugin has confirmed at least
		// once that the backend accepts it. Without this the dispatcher
		// would happily queue thousands of events while the backend 401s
		// every flush — the WP queue fills up with "dead" rows and the
		// site does pointless work.
		if ( ! $this->options->is_active() ) {
			return;
		}

		$this->register_core_sensors();
		( new WooCommerceLoader() )->attach( $this->sensors, $this->dispatcher );
		( new FormsLoader() )->attach( $this->sensors, $this->dispatcher );
		( new SeoAcfLoader() )->attach( $this->sensors, $this->dispatcher );
		( new IntegrationsLoader() )->attach( $this->sensors, $this->dispatcher );
		( new LwLoader() )->attach( $this->sensors, $this->dispatcher );

		// Apply operator-stored sensor flags on top of the
		// "off by default" list. A sensor is disabled if either:
		// - the stored options say so explicitly (`key => true`), or
		// - it's in the off-by-default list AND the stored options
		// don't mention it at all (operator hasn't expressed an
		// opinion, so we keep our default).
		// Explicit `key => false` in the stored options always wins.
		$stored         = $this->options->sensor_filters();
		$off_by_default = [ 'core-request', 'core-failed-login' ];

		$disabled = [];
		foreach ( $stored as $key => $is_disabled ) {
			if ( $is_disabled ) {
				$disabled[] = $key;
			}
		}
		foreach ( $off_by_default as $key ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				$disabled[] = $key;
			}
		}
		$this->sensors->disable( $disabled );
	}

	private function register_core_sensors(): void {
		$this->sensors->register( new LoginLogoutSensor( $this->dispatcher ) );
		$this->sensors->register( new FailedLoginSensor( $this->dispatcher ) );
		$this->sensors->register( new UserProfileSensor( $this->dispatcher ) );
		$this->sensors->register( new ContentSensor( $this->dispatcher ) );
		$this->sensors->register( new CommentsSensor( $this->dispatcher ) );
		$this->sensors->register( new TaxonomySensor( $this->dispatcher ) );
		$this->sensors->register( new PluginsSensor( $this->dispatcher ) );
		$this->sensors->register( new ThemesSensor( $this->dispatcher ) );
		$this->sensors->register( new SettingsSensor( $this->dispatcher ) );
		$this->sensors->register( new SystemSensor( $this->dispatcher ) );
		$this->sensors->register( new FilesSensor( $this->dispatcher ) );
		$this->sensors->register( new DatabaseSensor( $this->dispatcher ) );
		$this->sensors->register( new MenusSensor( $this->dispatcher ) );
		$this->sensors->register( new WidgetsSensor( $this->dispatcher ) );
		$this->sensors->register( new MultisiteSensor( $this->dispatcher ) );
		$this->sensors->register( new RequestSensor( $this->dispatcher ) );
		$this->sensors->register( new TwoFactorSensor( $this->dispatcher ) );
		$this->sensors->register( new AppPasswordsSensor( $this->dispatcher ) );
	}

	private function build_dispatcher(): EventDispatcher {
		// Without an endpoint + token there is nowhere to deliver events;
		// in that case we no-op rather than fill the queue with rows that
		// will never drain.
		if ( '' === $this->options->endpoint_url() || '' === $this->options->token() ) {
			return new NullEventDispatcher();
		}

		return new QueueEventDispatcher( $this->builder, new QueueRepository() );
	}

	/**
	 * Wire the out-of-band queue-flush job. Always called (see
	 * {@see self::boot()}); the {@see ActionSchedulerBridge} decides, per
	 * request, whether a recurring action should exist.
	 */
	private function register_flush_scheduler(): void {
		$flusher = new QueueFlusher(
			new QueueRepository(),
			new PayloadBuilder(),
			new ApiClient( $this->options->endpoint_url(), $this->options->token() ),
			new RetryPolicy()
		);
		( new ActionSchedulerBridge( $flusher, $this->options, new FlushLock(), new AsLogPruner() ) )->register();
	}

	private function fire_booted_action(): void {
		/**
		 * Sensor modules subscribe here to register themselves with the
		 * manager. After all subscribers have run we boot the manager,
		 * which in turn wires each enabled sensor's WP hooks.
		 *
		 * @since 0.1.0
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'hellolog_booted', $this );

		$this->sensors->boot();
	}

	/** Prevent cloning of the singleton. */
	public function __clone() {}

	/** Prevent unserializing of the singleton. */
	public function __wakeup() {}
}
