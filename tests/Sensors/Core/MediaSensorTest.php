<?php
/**
 * Tests for {@see HelloLog\Sensors\Core\MediaSensor}.
 *
 * @package HelloLog\Tests
 */

declare(strict_types=1);

namespace HelloLog\Tests\Sensors\Core;

use HelloLog\Events\EventBuilder;
use HelloLog\Events\EventCatalog;
use HelloLog\Events\EventDispatcher;
use HelloLog\Events\NullEventDispatcher;
use HelloLog\Events\RequestContext;
use HelloLog\Events\RequestSource;
use HelloLog\Events\SuppressionRules;
use HelloLog\Sensors\CatalogSeeder;
use HelloLog\Sensors\Core\ContentSensor;
use HelloLog\Sensors\Core\MediaSensor;
use HelloLog\Tests\Events\Fixtures\WpCalls;
use HelloLog\Tests\Sensors\Core\Fixtures\WpState;
use PHPUnit\Framework\TestCase;

/**
 * Pins what the media sensor logs of a file of the media library (upload,
 * edits of its texts and alternative text, permanent delete), the target
 * and links those events carry, and that the installer's zip uploads are
 * left out. Hooks are replayed in the order WordPress fires them.
 */
final class MediaSensorTest extends TestCase {

	public function test_alt_change_reports_old_and_new_text(): void {
		$this->assertSame(
			[
				[
					'field' => 'alt',
					'old'   => null,
					'new'   => 'Logo',
				],
			],
			MediaSensor::alt_change( null, 'Logo' )
		);
		$this->assertSame(
			[
				[
					'field' => 'alt',
					'old'   => 'Logo',
					'new'   => 'Company logo',
				],
			],
			MediaSensor::alt_change( 'Logo', 'Company logo' )
		);
	}

	public function test_alt_change_is_empty_when_nothing_changed(): void {
		$this->assertSame( [], MediaSensor::alt_change( 'Logo', 'Logo' ) );
		$this->assertSame( [], MediaSensor::alt_change( null, null ) );
	}

	/**
	 * An empty alternative text and none at all are the same to anybody
	 * looking at the image: the REST API stores an empty one sent with an
	 * upload, and clearing the field stores an empty one.
	 */
	public function test_an_empty_alt_text_is_no_alt_text(): void {
		$this->assertSame( [], MediaSensor::alt_change( null, '' ) );
		$this->assertSame( [], MediaSensor::alt_change( '', null ) );
		$this->assertSame(
			[
				[
					'field' => 'alt',
					'old'   => 'Logo',
					'new'   => null,
				],
			],
			MediaSensor::alt_change( 'Logo', '' )
		);
		$this->assertSame(
			[
				[
					'field' => 'alt',
					'old'   => null,
					'new'   => 'Logo',
				],
			],
			MediaSensor::alt_change( '', 'Logo' )
		);
	}

	public function test_a_long_alt_text_is_cut_to_the_wire_limit(): void {
		$changes = MediaSensor::alt_change( null, str_repeat( 'a', 2000 ) );

		$this->assertCount( 1, $changes );
		$this->assertSame( 1024, mb_strlen( (string) $changes[0]['new'] ) );
	}

	public function test_field_changes_compare_title_caption_and_description(): void {
		$this->assertSame(
			[
				[
					'field' => 'title',
					'old'   => 'IMG_0042',
					'new'   => 'Logo',
				],
				[
					'field' => 'caption',
					'old'   => '',
					'new'   => 'Our logo',
				],
				[
					'field' => 'description',
					'old'   => null,
					'new'   => null,
				],
			],
			MediaSensor::field_changes(
				[
					'post_title'   => 'IMG_0042',
					'post_excerpt' => '',
					'post_content' => 'First text',
				],
				[
					'post_title'   => 'Logo',
					'post_excerpt' => 'Our logo',
					'post_content' => 'Second text',
				]
			)
		);
	}

	public function test_field_changes_are_empty_when_nothing_changed(): void {
		$fields = [
			'post_title'   => 'Logo',
			'post_excerpt' => 'Our logo',
			'post_content' => 'Text',
		];

		$this->assertSame( [], MediaSensor::field_changes( $fields, $fields ) );
	}

	public function test_media_target_has_a_string_id_and_the_title_as_label(): void {
		$this->assertSame(
			[
				'type'  => 'media',
				'id'    => '42',
				'label' => 'Logo',
			],
			MediaSensor::media_target( 42, 'Logo' )
		);
	}

	public function test_the_sensor_key_is_stable(): void {
		$this->assertSame( 'core-media', ( new MediaSensor( new NullEventDispatcher() ) )->key() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_media_hooks_are_registered_with_their_arguments(): void {
		$this->run_hook( static fn( MediaSensor $sensor ) => $sensor->boot() );

		$expected = [
			'add_attachment'     => [ 10, 1 ],
			'attachment_updated' => [ 10, 3 ],
			'update_post_meta'   => [ 10, 4 ],
			'added_post_meta'    => [ 10, 4 ],
			'delete_attachment'  => [ 10, 2 ],
		];
		foreach ( $expected as $hook => [ $priority, $args ] ) {
			$this->assertSame(
				[
					'priority'      => $priority,
					'accepted_args' => $args,
				],
				WpState::$actions[ $hook ] ?? null,
				$hook
			);
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_upload_is_logged_with_its_target_and_edit_link(): void {
		$calls = $this->run_hook(
			static function ( MediaSensor $sensor, callable $file ): void {
				$sensor->boot();
				self::upload( $file() );
			}
		);

		$this->assertSame( [ 2010 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				'type'  => 'media',
				'id'    => '7',
				'label' => 'Logo',
			],
			$calls[0][1]['target']
		);
		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=7&action=edit' ], $calls[0][1]['links'] );
		$this->assertSame( 'Logo', $calls[0][1]['title'] );
		$this->assertSame(
			[
				'id'     => 7,
				'type'   => 'attachment',
				'status' => 'inherit',
			],
			$calls[0][1]['post']
		);
		$this->assertSame( 'image/png', $calls[0][1]['metadata']['mime_type'] );
		$this->assertArrayNotHasKey( 'changes', $calls[0][1] );

		$this->assertSame( [ 'Media "Logo" uploaded.' ], array_column( $this->survive_calls( $calls ), 'message' ) );
	}

	/**
	 * Core gives no edit link when nobody who may edit the file is logged in
	 * (a form plugin storing a visitor's upload), so it is built.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_upload_without_a_user_still_links_to_the_edit_screen(): void {
		$calls = $this->run_hook(
			static function ( MediaSensor $sensor, callable $file ): void {
				WpState::$can_edit = false;

				$sensor->boot();
				self::upload( $file() );
			}
		);

		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=7&action=edit' ], $calls[0][1]['links'] );
	}

	/**
	 * Saving a file's texts on its edit screen: `wp_insert_post()` fires
	 * `attachment_updated` with the file before and after. The description
	 * is text somebody wrote and is only said to have changed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_edit_of_the_texts_is_logged_with_its_changes(): void {
		$calls = $this->run_hook(
			static function ( MediaSensor $sensor, callable $file ): void {
				$sensor->boot();
				self::edit(
					$file(
						[
							'post_title'   => 'IMG_0042',
							'post_content' => 'First text',
						]
					),
					$file(
						[
							'post_excerpt' => 'Our logo',
							'post_content' => 'Second text',
						]
					)
				);
			}
		);

		$this->assertSame( [ 2011 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				[
					'field' => 'title',
					'old'   => 'IMG_0042',
					'new'   => 'Logo',
				],
				[
					'field' => 'caption',
					'old'   => '',
					'new'   => 'Our logo',
				],
				[
					'field' => 'description',
					'old'   => null,
					'new'   => null,
				],
			],
			$calls[0][1]['changes']
		);
		$this->assertSame( 'Logo', $calls[0][1]['target']['label'] );
		$this->assertSame( [ 'edit' => 'https://example.test/wp-admin/post.php?post=7&action=edit' ], $calls[0][1]['links'] );
		$this->assertStringNotContainsString( 'Second text', serialize( $calls ) );

		$this->assertSame( [ 'Media "Logo" edited.' ], array_column( $this->survive_calls( $calls ), 'message' ) );
	}

	/**
	 * A save that changed none of the compared fields (attaching the file to
	 * a post, moving it to the trash) is no edit.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_save_without_changed_texts_is_not_logged(): void {
		$calls = $this->run_hook(
			static function ( MediaSensor $sensor, callable $file ): void {
				$sensor->boot();
				self::edit( $file(), $file( [ 'post_parent' => 12 ] ) );
				self::edit( $file(), $file( [ 'post_status' => 'trash' ] ) );
			}
		);

		$this->assertSame( [], $calls );
	}

	/**
	 * The alternative text is post meta: `update_post_meta` fires before the
	 * write, so the stored value is still the old one, and `added_post_meta`
	 * after the first write of a file that had none.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_alt_text_changes_are_logged_from_both_meta_hooks(): void {
		$calls = $this->run_hook(
			static function ( MediaSensor $sensor, callable $file ): void {
				WpState::$posts[7] = $file();

				$sensor->boot();
				self::add_alt( 7, 'Logo' );
				self::update_alt( 7, 'Company logo' );
			}
		);

		$this->assertSame( [ 2011, 2011 ], array_column( $calls, 0 ) );
		$this->assertSame(
			[
				[
					'field' => 'alt',
					'old'   => null,
					'new'   => 'Logo',
				],
			],
			$calls[0][1]['changes']
		);
		$this->assertSame(
			[
				[
					'field' => 'alt',
					'old'   => 'Logo',
					'new'   => 'Company logo',
				],
			],
			$calls[1][1]['changes']
		);
		$this->assertSame( 'media', $calls[1][1]['target']['type'] );
		$this->assertSame( '7', $calls[1][1]['target']['id'] );
	}

	/**
	 * Other meta keys, an alt text kept on something that is no attachment,
	 * and an empty alt text stored for a file that had none are not edits.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_other_meta_writes_are_not_logged(): void {
		$calls = $this->run_hook(
			static function ( MediaSensor $sensor, callable $file ): void {
				WpState::$posts[7] = $file();
				WpState::$posts[8] = $file(
					[
						'ID'        => 8,
						'post_type' => 'post',
					]
				);

				$sensor->boot();
				self::fire( 'update_post_meta', 3, 7, '_wp_attachment_metadata', [ 'width' => 10 ] );
				self::fire( 'added_post_meta', 4, 7, '_edit_lock', '1700000000:1' );
				self::add_alt( 8, 'Logo' );
				self::add_alt( 7, '' );
			}
		);

		$this->assertSame( [], $calls );
	}

	/**
	 * Saving a file's edit screen writes its alt text before its texts: the
	 * two edits of one request are one event.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_edit_screen_save_is_one_event(): void {
		$calls = $this->run_hook(
			static function ( MediaSensor $sensor, callable $file ): void {
				WpState::$posts[7]                                 = $file( [ 'post_title' => 'IMG_0042' ] );
				WpState::$post_meta[7]['_wp_attachment_image_alt'] = 'Old';

				$sensor->boot();
				self::update_alt( 7, 'Logo' );
				self::edit( $file( [ 'post_title' => 'IMG_0042' ] ), $file() );
			}
		);

		$payloads = $this->survive_calls( $calls );
		$this->assertSame( [ 2011 ], array_column( $payloads, 'code' ) );
		$this->assertSame( [ 'alt', 'title' ], array_column( $payloads[0]['changes'], 'field' ) );
	}

	/**
	 * An upload through the REST API with an alt text: core stores the file
	 * (`add_attachment`), then writes the alt text (`added_post_meta`). What
	 * is kept is one upload carrying the alt text.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_upload_with_an_alt_text_is_one_upload(): void {
		$calls = $this->run_hook(
			static function ( MediaSensor $sensor, callable $file ): void {
				$sensor->boot();
				self::upload( $file() );
				self::add_alt( 7, 'Company logo' );
			}
		);

		$this->assertSame( [ 2010, 2011 ], array_column( $calls, 0 ) );

		$payloads = $this->survive_calls( $calls );
		$this->assertSame( [ 2010 ], array_column( $payloads, 'code' ) );
		$this->assertSame( 'Media "Logo" uploaded.', $payloads[0]['message'] );
		$this->assertSame(
			[
				[
					'field' => 'alt',
					'old'   => null,
					'new'   => 'Company logo',
				],
			],
			$payloads[0]['changes']
		);
	}

	/**
	 * `wp_delete_attachment()` fires `delete_attachment` while the file and
	 * its meta still exist. A deleted file is gone: nothing to open.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_permanent_delete_is_logged_without_links(): void {
		$calls = $this->run_hook(
			static function ( MediaSensor $sensor, callable $file ): void {
				$sensor->boot();
				self::fire( 'delete_attachment', 7, $file() );
			}
		);

		$this->assertSame( [ 2012 ], array_column( $calls, 0 ) );
		$this->assertArrayNotHasKey( 'links', $calls[0][1] );
		$this->assertSame(
			[
				'type'  => 'media',
				'id'    => '7',
				'label' => 'Logo',
			],
			$calls[0][1]['target']
		);

		$this->assertSame( [ 'Media "Logo" deleted.' ], array_column( $this->survive_calls( $calls ), 'message' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}> context, mime type, expected.
	 */
	public static function installer_packages(): array {
		return [
			'installer upload, no type'  => [ 'upgrader', '', true ],
			'zip'                        => [ 'upgrader', 'application/zip', true ],
			'zip from windows'           => [ 'upgrader', 'application/x-zip-compressed', true ],
			'old zip type'               => [ 'upgrader', 'application/x-zip', true ],
			'multipart zip'              => [ 'upgrader', 'multipart/x-zip', true ],
			'image claiming the context' => [ 'upgrader', 'image/png', false ],
			'pdf claiming the context'   => [ 'upgrader', 'application/pdf', false ],
			'zip of another context'     => [ 'site-icon', 'application/zip', false ],
			'no context'                 => [ '', '', false ],
			'wrong case context'         => [ 'Upgrader', '', false ],
		];
	}

	/**
	 * Core stores the installer's zip with no type (it skips the type check)
	 * and the context `upgrader`; anything that is not a zip is no package,
	 * whatever context it claims.
	 *
	 * @dataProvider installer_packages
	 */
	public function test_is_installer_package( string $context, string $mime_type, bool $expected ): void {
		$this->assertSame( $expected, MediaSensor::is_installer_package( $context, $mime_type ) );
	}

	/**
	 * The plugin and theme installer keeps an uploaded zip as an attachment
	 * (no type, the context `upgrader`, stored before `add_attachment` fires
	 * and still there when `delete_attachment` fires) and deletes it once the
	 * install is done, in the same request: neither is a file of the media
	 * library. Only somebody who may upload plugins or themes gets there.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_installers_upload_and_its_clean_up_are_not_logged(): void {
		$calls = $this->run_hook(
			static function ( MediaSensor $sensor, callable $file ): void {
				$zip = self::installer_zip( $file );

				$sensor->boot();
				foreach ( [ 'upload_plugins', 'upload_themes' ] as $cap ) {
					WpState::$caps = [ 'upload_files', $cap ];
					self::upload( $zip );
					self::edit( $zip, $file( [ 'post_title' => 'renamed.zip', 'post_mime_type' => '' ] ) );
					self::fire( 'delete_attachment', 7, $zip );
				}
			}
		);

		$this->assertSame( [], $calls );
	}

	/**
	 * A failed install leaves the zip to the clean-up core schedules two
	 * hours later, which runs in cron with nobody logged in; while that
	 * clean-up is still scheduled, the zip is the installer's too.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_installers_scheduled_clean_up_is_not_logged(): void {
		$calls = $this->run_hook(
			static function ( MediaSensor $sensor, callable $file ): void {
				$zip = self::installer_zip( $file );

				$sensor->boot();
				WpState::$doing_action = 'upgrader_scheduled_cleanup';
				self::fire( 'delete_attachment', 7, $zip );

				WpState::$doing_action                            = null;
				WpState::$scheduled['upgrader_scheduled_cleanup'] = [ [ 7 ] ];
				self::fire( 'delete_attachment', 7, $zip );
			}
		);

		$this->assertSame( [], $calls );
	}

	/**
	 * Any upload through the media screens can carry a context of its own
	 * choosing, so the context alone hides nothing: a zip of somebody who may
	 * not install plugins or themes, outside the installer's clean-up, is a
	 * file of the library on every path.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_forged_installer_context_is_logged_on_every_path(): void {
		$calls = $this->run_hook(
			static function ( MediaSensor $sensor, callable $file, EventDispatcher $dispatcher ): void {
				$zip = $file(
					[
						'post_title'     => 'archive.zip',
						'post_mime_type' => 'application/zip',
					]
				);
				WpState::$post_meta[7]['_wp_attachment_context'] = 'upgrader';
				WpState::$caps                                   = [ 'upload_files' ];
				WpState::$scheduled['upgrader_scheduled_cleanup'] = [ [ 8 ] ];

				$sensor->boot();
				( new ContentSensor( $dispatcher ) )->boot();

				self::upload( $zip );
				self::edit( $zip, $file( [ 'post_title' => 'renamed.zip', 'post_mime_type' => 'application/zip' ] ) );
				self::add_alt( 7, 'Archive' );

				WpState::$posts[7] = $file( [ 'post_mime_type' => 'application/zip', 'post_status' => 'trash' ] );
				self::fire( 'trashed_post', 7, 'inherit' );
				WpState::$posts[7] = $zip;
				self::fire( 'untrash_post', 7, 'inherit' );
				self::fire( 'untrashed_post', 7, 'inherit' );

				self::fire( 'delete_attachment', 7, $zip );
			}
		);

		$this->assertSame( [ 2010, 2011, 2011, 2003, 2004, 2012 ], array_column( $calls, 0 ) );
	}

	/**
	 * Somebody who may install plugins uploading an image that claims the
	 * installer's context: an image is no package.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_image_claiming_the_installer_context_is_logged(): void {
		$calls = $this->run_hook(
			static function ( MediaSensor $sensor, callable $file ): void {
				WpState::$post_meta[7]['_wp_attachment_context'] = 'upgrader';
				WpState::$caps                                   = [ 'upload_files', 'upload_plugins', 'upload_themes' ];

				$sensor->boot();
				self::upload( $file() );
				self::fire( 'delete_attachment', 7, $file() );
			}
		);

		$this->assertSame( [ 2010, 2012 ], array_column( $calls, 0 ) );
	}

	/**
	 * Files made in other contexts (a cropped site icon, a custom header) are
	 * files of the library like any upload.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_files_of_other_contexts_are_logged(): void {
		$calls = $this->run_hook(
			static function ( MediaSensor $sensor, callable $file ): void {
				WpState::$post_meta[7]['_wp_attachment_context'] = 'site-icon';

				$sensor->boot();
				self::upload( $file() );
				self::fire( 'delete_attachment', 7, $file() );
			}
		);

		$this->assertSame( [ 2010, 2012 ], array_column( $calls, 0 ) );
	}

	/**
	 * Reading the file can fail (a filter on its meta that throws): the
	 * failure is reported the way the dispatcher reports its own, never
	 * thrown at the upload, edit or delete.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failing_read_is_reported_and_never_thrown(): void {
		require_once __DIR__ . '/../../Events/Fixtures/WpCalls.php';
		require_once __DIR__ . '/../../Events/Fixtures/wordpress-functions.php';

		$calls = $this->run_hook(
			static function ( MediaSensor $sensor, callable $file ): void {
				WpState::$posts[7]         = $file();
				WpState::$post_meta_throws = true;

				$sensor->boot();
				self::upload( $file() );
				self::edit( $file( [ 'post_title' => 'IMG_0042' ] ), $file() );
				self::fire( 'update_post_meta', 3, 7, '_wp_attachment_image_alt', 'Logo' );
				self::fire( 'delete_attachment', 7, $file() );
			}
		);

		$this->assertSame( [], $calls );
		$this->assertSame( array_fill( 0, 4, 'hellolog_dispatch_failed' ), array_column( WpCalls::$actions, 0 ) );
		$this->assertSame( [ 2010, 2011, 2011, 2012 ], array_map( static fn( array $action ): int => $action[1][1], WpCalls::$actions ) );
		$this->assertSame( [ 'post_id' => 7 ], WpCalls::$actions[0][1][2] );
		$this->assertSame(
			[
				'post_id' => 7,
				'field'   => 'alt',
			],
			WpCalls::$actions[2][1][2]
		);
	}

	/**
	 * Uploaded, edited, moved to the trash and back (with `MEDIA_TRASH`) and
	 * deleted: every event of one file names the same target, whichever
	 * sensor logs it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_one_files_history_stays_on_one_target(): void {
		$calls = $this->run_hook(
			static function ( MediaSensor $sensor, callable $file, EventDispatcher $dispatcher ): void {
				$sensor->boot();
				( new ContentSensor( $dispatcher ) )->boot();

				self::upload( $file( [ 'post_title' => 'IMG_0042' ] ) );
				self::edit( $file( [ 'post_title' => 'IMG_0042' ] ), $file() );

				WpState::$posts[7] = $file( [ 'post_status' => 'trash' ] );
				self::fire( 'trashed_post', 7, 'inherit' );

				WpState::$posts[7] = $file();
				self::fire( 'untrash_post', 7, 'inherit' );
				self::fire( 'untrashed_post', 7, 'inherit' );

				self::fire( 'delete_attachment', 7, $file() );
			}
		);

		$this->assertSame( [ 2010, 2011, 2003, 2004, 2012 ], array_column( $calls, 0 ) );
		$this->assertSame(
			array_fill( 0, 5, 'media:7' ),
			array_map( static fn( array $call ): string => $call[1]['target']['type'] . ':' . $call[1]['target']['id'], $calls )
		);
	}

	/**
	 * Run hook calls against a recording dispatcher, with the WordPress
	 * stand-ins loaded. Only call this from a test of its own process.
	 *
	 * @param callable(MediaSensor, callable(array<string, mixed>): \WP_Post, EventDispatcher): void $hooks What to call.
	 * @return array<int, array{0: int, 1: array<string, mixed>}> Every dispatch as `[ code, fields ]`.
	 */
	private function run_hook( callable $hooks ): array {
		require_once __DIR__ . '/Fixtures/wordpress-user-functions.php';
		require_once __DIR__ . '/Fixtures/wordpress-content-functions.php';

		$dispatcher = new class() implements EventDispatcher {

			/** @var array<int, array{0: int, 1: array<string, mixed>}> */
			public array $calls = [];

			public function dispatch( int $code, array $fields = [] ): void {
				$this->calls[] = [ $code, $fields ];
			}
		};

		$file = static fn( array $over = [] ): \WP_Post => new \WP_Post(
			array_merge(
				[
					'ID'             => 7,
					'post_title'     => 'Logo',
					'post_excerpt'   => '',
					'post_content'   => '',
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => 'image/png',
					'post_parent'    => 0,
				],
				$over
			)
		);

		$hooks( new MediaSensor( $dispatcher ), $file, $dispatcher );

		return $dispatcher->calls;
	}

	/**
	 * Fire a hook the way `do_action()` does: every callback registered on
	 * it, by priority, with as many arguments as it accepts.
	 *
	 * @param string $hook    Hook name.
	 * @param mixed  ...$args Arguments of the hook.
	 */
	private static function fire( string $hook, ...$args ): void {
		$callbacks = WpState::$callbacks[ $hook ] ?? [];
		usort( $callbacks, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );
		foreach ( $callbacks as $registered ) {
			( $registered['callback'] )( ...array_slice( $args, 0, $registered['accepted_args'] ) );
		}
	}

	/**
	 * The zip the plugin and theme installer stores: no type (core skips the
	 * type check for it), private, the context `upgrader`.
	 *
	 * @param callable(array<string, mixed>): \WP_Post $file Builds a file.
	 */
	private static function installer_zip( callable $file ): \WP_Post {
		WpState::$post_meta[7]['_wp_attachment_context'] = 'upgrader';

		return $file(
			[
				'post_title'     => 'plugin.zip',
				'post_mime_type' => '',
				'post_status'    => 'private',
			]
		);
	}

	/**
	 * `wp_insert_attachment()` storing a new file: the file exists (and its
	 * context meta is stored) before `add_attachment` fires.
	 *
	 * @param \WP_Post $file The file as stored.
	 */
	private static function upload( \WP_Post $file ): void {
		WpState::$posts[ (int) $file->ID ] = $file;
		self::fire( 'add_attachment', (int) $file->ID );
	}

	/**
	 * `wp_insert_post()` saving an existing file: `attachment_updated` with
	 * the file after and before the save.
	 *
	 * @param \WP_Post $before The file before the save.
	 * @param \WP_Post $after  The file as saved.
	 */
	private static function edit( \WP_Post $before, \WP_Post $after ): void {
		WpState::$posts[ (int) $after->ID ] = $after;
		self::fire( 'attachment_updated', (int) $after->ID, $after, $before );
	}

	/**
	 * `update_post_meta()` of the alt text of a file that has one: the hook
	 * fires before the write, then the value is stored.
	 *
	 * @param int    $id  Attachment id.
	 * @param string $alt The new alt text.
	 */
	private static function update_alt( int $id, string $alt ): void {
		self::fire( 'update_post_meta', 3, $id, '_wp_attachment_image_alt', $alt );
		WpState::$post_meta[ $id ]['_wp_attachment_image_alt'] = $alt;
	}

	/**
	 * `update_post_meta()` of the alt text of a file that had none: core adds
	 * the row, then fires `added_post_meta`.
	 *
	 * @param int    $id  Attachment id.
	 * @param string $alt The alt text.
	 */
	private static function add_alt( int $id, string $alt ): void {
		WpState::$post_meta[ $id ]['_wp_attachment_image_alt'] = $alt;
		self::fire( 'added_post_meta', 3, $id, '_wp_attachment_image_alt', $alt );
	}

	/**
	 * Build the dispatches of one request as an admin would on the dashboard
	 * and run the default per-request rules over them.
	 *
	 * @param array<int, array{0: int, 1: array<string, mixed>}> $calls Dispatches as `[ code, fields ]`.
	 * @return array<int, array<string, mixed>> The payloads that would be written.
	 */
	private function survive_calls( array $calls ): array {
		$catalog = new EventCatalog();
		CatalogSeeder::seed( $catalog );

		$builder = new class( $catalog ) extends EventBuilder {

			public function __construct( EventCatalog $catalog ) {
				parent::__construct( $catalog, false, static fn(): string => RequestSource::ADMIN );
			}

			protected function current_context(): RequestContext {
				return new RequestContext( 1, 'admin', [ 'administrator' ], null, null, null );
			}
		};

		$payloads = [];
		foreach ( $calls as [ $code, $fields ] ) {
			$payload = $builder->build( $code, $fields );
			$this->assertIsArray( $payload );
			$payloads[] = $payload;
		}

		return SuppressionRules::defaults()->apply( $payloads );
	}
}
