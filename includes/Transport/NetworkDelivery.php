<?php
/**
 * Sends one event from every other site of the network, at once.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Transport;

use HelloLog\Events\BufferedDispatcher;
use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\SuppressionRules;
use HelloLog\Queue\QueueRepository;
use HelloLog\Scheduler\FlushLock;
use HelloLog\Settings\Options;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivated for a whole network, helloLOG stops on every site at once, and
 * each site has its own queue, its own key and its own settings. This builds
 * the event on every other active site that has a key (the current site sends
 * its own), the way that site would (its IP anonymisation), queues it there
 * and sends it at once, with a {@see FinalDelivery} bound to that site and
 * its key. Sites are visited one after the other until
 * {@see self::BUDGET} seconds are spent, the same bound as network
 * activation; the sites left are skipped, without an error. The request is
 * back on its own site afterwards. A site that fails is reported through the
 * `hellolog_dispatch_failed` action and the next one is visited.
 */
final class NetworkDelivery {

	/** Longest time spent visiting sites, in seconds, before another is started. */
	public const BUDGET = 5.0;

	/** @var callable(): float */
	private $clock;

	/** @var SuppressionRules|callable(): SuppressionRules */
	private $rules;

	/**
	 * @param EventCatalog              $catalog The event definitions.
	 * @param SuppressionRules|callable $rules   The rules in force, or what returns them.
	 * @param float                     $budget  Longest time spent visiting sites, in seconds.
	 * @param ?callable(): float        $clock   The time in seconds; `microtime( true )` when not given.
	 */
	public function __construct(
		private EventCatalog $catalog,
		SuppressionRules|callable $rules,
		private float $budget = self::BUDGET,
		?callable $clock = null
	) {
		$this->rules = $rules;
		$this->clock = $clock ?? static fn(): float => microtime( true );
	}

	/**
	 * Dispatch and send the event from every other active site with a key.
	 *
	 * @param int                  $code   Event code.
	 * @param array<string, mixed> $fields Event fields.
	 */
	public function deliver( int $code, array $fields ): void {
		if ( ! function_exists( 'get_sites' ) || ! function_exists( 'switch_to_blog' ) || ! function_exists( 'restore_current_blog' ) ) {
			return;
		}

		$start = ( $this->clock )();
		$here  = (int) get_current_blog_id();
		$sites = get_sites(
			[
				'fields'     => 'ids',
				'number'     => 0,
				'network_id' => get_current_network_id(),
				'archived'   => 0,
				'deleted'    => 0,
				'spam'       => 0,
			]
		);

		foreach ( $sites as $blog_id ) {
			$blog_id = (int) $blog_id;
			if ( $here === $blog_id ) {
				continue;
			}
			if ( ( $this->clock )() - $start >= $this->budget ) {
				return;
			}

			switch_to_blog( $blog_id );
			try {
				$this->deliver_here( $code, $fields );
			} catch ( Throwable $e ) {
				$this->report( $e, $code );
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Dispatch and send the event from the current site, when it has a key.
	 *
	 * @param int                  $code   Event code.
	 * @param array<string, mixed> $fields Event fields.
	 */
	private function deliver_here( int $code, array $fields ): void {
		$options = new Options();
		if ( ! $options->is_configured() ) {
			return;
		}

		$repository = new QueueRepository();
		$dispatcher = new BufferedDispatcher(
			new EventBuilder( $this->catalog, $options->anonymize_ip() ),
			static fn( string $json, ?int $blog_id = null ): int => $repository->insert( $json, $blog_id ),
			$this->rules
		);
		$flusher    = new QueueFlusher(
			$repository,
			new PayloadBuilder(),
			new ApiClient( $options->endpoint_url(), $options->token() ),
			new RetryPolicy()
		);
		( new FinalDelivery( $dispatcher, [ $flusher, 'run' ], new FlushLock( FinalDelivery::LOCK_WAIT ) ) )->deliver( $code, $fields );
	}

	/**
	 * Report a site that failed. A listener that throws is swallowed too.
	 *
	 * @param Throwable $e    What went wrong.
	 * @param int       $code Event code.
	 */
	private function report( Throwable $e, int $code ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		try {
			/** This action is documented in includes/Events/BufferedDispatcher.php */
			do_action( 'hellolog_dispatch_failed', $e, $code, [] );
		} catch ( Throwable $callback_error ) {
			return;
		}
	}
}
