<?php
/**
 * A dispatcher that builds every event the moment it is dispatched.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core\Fixtures;

use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\RequestContext;
use HelloLog\Sensors\CatalogSeeder;

/**
 * Where an event comes from (its `source`), its actor and who set a
 * background run in motion (`metadata.initiated_by`) are decided when it is
 * dispatched, as the production dispatcher builds it. This one does the
 * same, for a signed-in admin (user 1, `admin`), with the source of the
 * request as it is at that moment.
 */
final class BuildingRecorder implements EventDispatcher {

	/**
	 * The payloads built, in dispatch order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $payloads = [];

	private EventBuilder $builder;

	public function __construct() {
		$catalog = new EventCatalog();
		CatalogSeeder::seed( $catalog );

		$this->builder = new class( $catalog ) extends EventBuilder {

			protected function current_context(): RequestContext {
				return new RequestContext( 1, 'admin', [ 'administrator' ], null, null, null );
			}
		};
	}

	public function dispatch( int $code, array $fields = [] ): void {
		$payload = $this->builder->build( $code, $fields );
		if ( null !== $payload ) {
			$this->payloads[] = $payload;
		}
	}
}
