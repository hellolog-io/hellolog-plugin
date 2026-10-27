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
use HelloLog\Events\ActionSchedulerContext;
use HelloLog\Events\BufferedDispatcher;
use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\NullEventDispatcher;
use HelloLog\Events\SuppressionRules;
use HelloLog\Queue\QueueRepository;
use HelloLog\Queue\QueueRetention;
use HelloLog\Scheduler\ActionSchedulerBridge;
use HelloLog\Scheduler\AsLogPruner;
use HelloLog\Scheduler\FlushLock;
use HelloLog\Scheduler\VerifyScheduleBridge;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Core\CommentsSensor;
use HelloLog\Sensors\Core\ContentSensor;
use HelloLog\Sensors\Core\DatabaseSensor;
use HelloLog\Sensors\Core\FilesSensor;
use HelloLog\Sensors\Core\FailedLoginSensor;
use HelloLog\Sensors\Core\LoginLogoutSensor;
use HelloLog\Sensors\Core\MediaSensor;
use HelloLog\Sensors\Core\PluginsSensor;
use HelloLog\Sensors\Core\AppPasswordsSensor;
use HelloLog\Sensors\Core\MenusSensor;
use HelloLog\Sensors\Core\MultisiteSensor;
use HelloLog\Sensors\Core\RequestSensor;
use HelloLog\Sensors\Core\SelfAuditSensor;
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
use HelloLog\Transport\FinalDelivery;
use HelloLog\Transport\PayloadBuilder;
use HelloLog\Transport\QueueFlusher;
use HelloLog\Transport\RetryPolicy;
use HelloLog\Transport\TokenVerifier;
use HelloLog\Updater\GitHubUpdater;

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

	private ?SuppressionRules $rules = null;

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

		// Unconditional and ahead of every sensor: the request source of an
		// event ("was this a queued job?") must be right even for sensors
		// that attach later in this request, and hooking is cheap.
		ActionSchedulerContext::register();

		$this->assemble_services();

		// ALWAYS register the flush scheduler — even when inactive. The bridge
		// keeps a recurring action alive only while `is_active()`, and tears
		// down any orphan otherwise. Registering this unconditionally, right
		// after `$this->options` exists, is what lets a deconfigured install
		// self-heal instead of ticking an unhandled action forever (the 0.3.1
		// outage).
		$this->register_flush_scheduler();

		// Same unconditional-registration rule for the daily token recheck —
		// see {@see self::register_verify_scheduler()}.
		$this->register_verify_scheduler();

		// Also unconditional: `pre_set_site_transient_update_plugins` is what
		// WP core's own cron-driven update check filters, so gating this
		// behind `is_admin()` (like the rest of {@see self::wire_admin()})
		// would mean the Plugins screen only ever shows a stale cached
		// answer instead of one refreshed by cron.
		( new GitHubUpdater() )->register();

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

	/**
	 * The rules that thin out each request's events before they are queued:
	 * the built-in table, passed through the `hellolog_suppression_rules`
	 * filter. The dispatcher asks for them at its first flush, so a loader or
	 * sensor that attaches during boot can still hook the filter in time.
	 */
	public function rules(): SuppressionRules {
		if ( null === $this->rules ) {
			$rules = SuppressionRules::defaults();

			/**
			 * Add suppression, merge or drop-empty rules, usually with
			 * `$rules->with( new SuppressionRules( ... ) )`.
			 *
			 * @since 0.5.0
			 *
			 * @param SuppressionRules $rules The rules in force. A callback must
			 *                                return a SuppressionRules; anything
			 *                                else is ignored.
			 */
			$filtered    = apply_filters( 'hellolog_suppression_rules', $rules );
			$this->rules = $filtered instanceof SuppressionRules ? $filtered : $rules;
		}

		return $this->rules;
	}

	public function sensors(): SensorManager {
		return $this->sensors;
	}

	/**
	 * Log and send helloLOG's own deactivation. Its deactivation hook calls
	 * this first, before the background jobs are unscheduled. The
	 * deactivation is logged for helloLOG like any plugin's (5002), whether or
	 * not the plugin sensor is on, and sent right away with everything the
	 * request has collected, then once more at the very end of the request,
	 * see {@see FinalDelivery}: no job will send the queue once the plugin
	 * stops. Nothing happens before boot or while the plugin is not active
	 * (no verified API key: nothing queued would be accepted).
	 *
	 * @param bool $network_wide Whether helloLOG is deactivated on every site of the network.
	 */
	public function deliver_own_deactivation( bool $network_wide = false ): void {
		if ( ! $this->booted || ! $this->options->is_active() ) {
			return;
		}

		$delivery = new FinalDelivery( $this->dispatcher, [ $this->build_flusher(), 'run' ], new FlushLock( FinalDelivery::LOCK_WAIT ) );
		$delivery->deliver( 5002, PluginsSensor::deactivation_fields( plugin_basename( HELLOLOG_FILE ), $network_wide ) );
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

		// Apply operator-stored sensor flags on top of the "off by default"
		// list: explicit flags win, a sensor the settings do not mention
		// keeps its default, see SensorManager::disabled_by().
		$this->sensors->disable( SensorManager::disabled_by( $this->options->sensor_filters() ) );
	}

	private function register_core_sensors(): void {
		$this->sensors->register( new LoginLogoutSensor( $this->dispatcher ) );
		$this->sensors->register( new FailedLoginSensor( $this->dispatcher ) );
		$this->sensors->register( new UserProfileSensor( $this->dispatcher ) );
		$this->sensors->register( new ContentSensor( $this->dispatcher ) );
		$this->sensors->register( new MediaSensor( $this->dispatcher ) );
		$this->sensors->register( new CommentsSensor( $this->dispatcher ) );
		$this->sensors->register( new TaxonomySensor( $this->dispatcher ) );
		$this->sensors->register( new PluginsSensor( $this->dispatcher ) );
		$this->sensors->register( new ThemesSensor( $this->dispatcher ) );
		$this->sensors->register( new SettingsSensor( $this->dispatcher ) );
		$this->sensors->register( new SelfAuditSensor( $this->dispatcher ) );
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

		$repository = new QueueRepository();
		$dispatcher = new BufferedDispatcher(
			$this->builder,
			// Into the queue of the site the event happened on: on a network the
			// request may have switched sites since.
			static fn( string $json, ?int $blog_id = null ): int => $repository->insert( $json, $blog_id ),
			[ $this, 'rules' ]
		);

		// Events are only collected during the request; this is what writes
		// them. Priority 1 so they are written before other shutdown callbacks run.
		add_action( 'shutdown', [ $dispatcher, 'flush' ], 1 );

		// Whatever is dispatched after that (later shutdown callbacks) is
		// flushed here, and from then on events are written at once.
		add_action( 'shutdown', [ $dispatcher, 'flush_final' ], PHP_INT_MAX );

		return $dispatcher;
	}

	/**
	 * Wire the out-of-band queue-flush job. Always called (see
	 * {@see self::boot()}); the {@see ActionSchedulerBridge} decides, per
	 * request, whether a recurring action should exist.
	 */
	private function register_flush_scheduler(): void {
		( new ActionSchedulerBridge(
			$this->build_flusher(),
			$this->options,
			new FlushLock(),
			new AsLogPruner(),
			new QueueRetention( new QueueRepository() )
		) )->register();
	}

	/**
	 * What sends the queue to the backend, one batch per run.
	 */
	private function build_flusher(): QueueFlusher {
		return new QueueFlusher(
			new QueueRepository(),
			new PayloadBuilder(),
			new ApiClient( $this->options->endpoint_url(), $this->options->token() ),
			new RetryPolicy()
		);
	}

	/**
	 * Wire the daily backend token recheck. Always called (see
	 * {@see self::boot()}); the {@see VerifyScheduleBridge} decides, per
	 * request, whether a recurring action should exist — keyed on a token
	 * being configured, not on it currently being verified (see
	 * {@see \HelloLog\Scheduler\VerifySchedule::reconcile()}).
	 */
	private function register_verify_scheduler(): void {
		$verifier = new TokenVerifier( $this->options->endpoint_url(), $this->options->token() );
		( new VerifyScheduleBridge( $this->options, $verifier ) )->register();
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
