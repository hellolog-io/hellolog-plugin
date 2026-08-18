<?php
/**
 * The bbPress sensor.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Sensors\AbstractSensor;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The bbPress plugin uses dedicated CPTs (`forum`, `topic`, `reply`). We log creation
 * + deletion of any of them as one normalized "bbpress object" event;
 * granular per-type filtering can land later if requested.
 */
final class BbPressSensor extends AbstractSensor {

	private const TYPES = [ 'forum', 'topic', 'reply' ];

	public function key(): string {
		return 'bbpress';
	}

	public function should_load(): bool {
		return class_exists( 'bbPress', false ) || function_exists( 'bbp_get_version' );
	}

	public function boot(): void {
		add_action( 'transition_post_status', [ $this, 'on_status' ], 10, 3 );
		add_action( 'before_delete_post', [ $this, 'on_delete' ], 10, 2 );
	}

	public function on_status( string $new_status, string $old_status, WP_Post $post ): void {
		if ( ! in_array( $post->post_type, self::TYPES, true ) ) {
			return;
		}
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		$this->emit_post( 8000, $post );
	}

	/**
	 * @param mixed $post
	 */
	public function on_delete( int $post_id, $post ): void {
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::TYPES, true ) ) {
			return;
		}
		$this->emit_post( 8001, $post );
	}

	private function emit_post( int $code, WP_Post $post ): void {
		$this->emit(
			$code,
			[
				'title'    => (string) $post->post_title,
				'post'     => [
					'id'   => (int) $post->ID,
					'type' => (string) $post->post_type,
				],
				'metadata' => [
					'post_type' => (string) $post->post_type,
				],
			]
		);
	}
}
