<?php
/**
 * Stand-in for WooCommerce's `WC_Webhook`, which the shop configuration sensor
 * tells from the other objects the shop saves through the same hook.
 *
 * Loaded only by tests that run in a separate process: defining it makes
 * `class_exists( 'WC_Webhook' )` true for the rest of the process.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace {

	use HelloLog\Tests\Sensors\Integrations\Fixtures\ConfigState;

	if ( ! class_exists( 'WC_Webhook', false ) ) {

		/**
		 * Answers what the sensor may ask a webhook: its id, its stored values
		 * (`get_data()`, with the id in front like WooCommerce's) and the values
		 * a save is about to write (`get_changes()`). Asking it for its secret
		 * fails: the sensor must never read it.
		 */
		class WC_Webhook {

			/**
			 * @param int                  $id      Webhook id; 0 for a webhook being inserted.
			 * @param array<string, mixed> $data    The values as stored, without the id.
			 * @param array<string, mixed> $changes The values the save is about to write.
			 */
			public function __construct(
				private int $id,
				private array $data = [],
				private array $changes = []
			) {
			}

			public function get_id(): int {
				return $this->id;
			}

			/**
			 * @return array<string, mixed>
			 */
			public function get_data(): array {
				if ( ConfigState::$throws ) {
					throw new \RuntimeException( 'unreadable webhook' );
				}

				return array_merge( [ 'id' => $this->id ], $this->data );
			}

			/**
			 * @return array<string, mixed>
			 */
			public function get_changes(): array {
				if ( ConfigState::$throws ) {
					throw new \RuntimeException( 'unreadable webhook' );
				}

				return $this->changes;
			}

			/**
			 * @param string $context Not used.
			 * @throws \LogicException Always: the secret must never be read.
			 */
			public function get_secret( string $context = 'view' ): string {
				throw new \LogicException( 'The webhook secret was read.' );
			}
		}
	}
}
