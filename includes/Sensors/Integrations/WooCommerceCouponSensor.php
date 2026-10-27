<?php
/**
 * WooCommerce: coupons.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Events\ActionSchedulerContext;
use HelloLog\Events\ChangeSet;
use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\Core\ContentSensor;
use HelloLog\Sensors\Core\PostSnapshot;
use HelloLog\Sensors\StoreTargets;
use Throwable;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Coupons: creation (9200), edits (9201) and permanent delete (9202). Every
 * event names the coupon as its target (see {@see StoreTargets::coupon()}),
 * and the creation and edits link to its edit screen.
 *
 * An edit tells which of {@see self::COUPON_FIELDS} changed: the description
 * and the customers' e-mail addresses the coupon is limited to
 * ({@see self::CONTENT_FIELDS}) without their values, the products and
 * categories by their names. How often the coupon was used, and by whom, is
 * the shop's bookkeeping and never told. An edit is read from three places,
 * which the buffer merges into one event per coupon and request:
 *
 * - the shop's save of a coupon, from the values stored and those the save
 *   is about to write. Coupons have no save hook of their own: the shop
 *   saves them, its webhooks among others, through the hook of every data
 *   object, so only a `WC_Coupon` is read (a coupon has no type to ask for);
 * - WordPress's write of the post: the classic coupon screen writes the code
 *   (the title) and the description (the excerpt) through WordPress before
 *   the shop saves the coupon, so the shop's save never sees them;
 * - WordPress's status change, which the classic screen writes the same way
 *   (and which also tells a scheduled coupon going live).
 *
 * WooCommerce announces a coupon it inserts itself, but not one made on the
 * classic screen: there "Add coupon" makes a WordPress placeholder
 * (`auto-draft`), and its first save is the creation. So a coupon whose post
 * leaves `new` or `auto-draft` is created, once per request whichever way it
 * is told. The creation tells nothing more: no save of that coupon in the
 * same request is an edit, nor any save of a coupon WordPress still keeps as
 * a placeholder.
 *
 * Every handler runs inside the work of WordPress or WooCommerce on the
 * coupon (the shop's save of a coupon has no guard around its hooks), so
 * whatever fails while reading the coupon is reported, never thrown, and hook
 * arguments are taken loosely.
 */
final class WooCommerceCouponSensor extends AbstractSensor {

	/**
	 * The fields of a coupon whose edits are told, in the order they are.
	 *
	 * @var array<int, string>
	 */
	public const COUPON_FIELDS = [
		'code',
		'status',
		'amount',
		'discount_type',
		'date_expires',
		'individual_use',
		'usage_limit',
		'usage_limit_per_user',
		'limit_usage_to_x_items',
		'free_shipping',
		'product_ids',
		'excluded_product_ids',
		'product_categories',
		'excluded_product_categories',
		'minimum_amount',
		'maximum_amount',
		'email_restrictions',
		'exclude_sale_items',
		'description',
	];

	/**
	 * The fields of {@see self::COUPON_FIELDS} told as changed, without their
	 * values: a text of any length, and the customers' e-mail addresses (their
	 * personal data).
	 *
	 * @var array<int, string>
	 */
	public const CONTENT_FIELDS = [ 'email_restrictions', 'description' ];

	/** Post type of a coupon. */
	private const POST_TYPE = 'shop_coupon';

	/** WordPress's status of an editor placeholder. */
	private const PLACEHOLDER_STATUS = 'auto-draft';

	/**
	 * Old statuses of a post that is not made yet: an insert and the editor's
	 * placeholder.
	 *
	 * @var array<int, string>
	 */
	private const CREATION_STATUSES = [ 'new', 'auto-draft' ];

	/**
	 * New statuses that do not make a coupon: it stays unmade, or goes
	 * straight to the trash.
	 *
	 * @var array<int, string>
	 */
	private const UNMADE_STATUSES = [ 'new', 'auto-draft', 'trash' ];

	/**
	 * Fields that hold an amount of money: `10.00` and `10` are the same
	 * value.
	 *
	 * @var array<int, string>
	 */
	private const MONEY_FIELDS = [ 'amount', 'minimum_amount', 'maximum_amount' ];

	/**
	 * Fields that hold a limit, where none, `0` and an empty value all mean
	 * "no limit" (the classic coupon screen writes `0` for an empty limit the
	 * shop stores as none).
	 *
	 * @var array<int, string>
	 */
	private const LIMIT_FIELDS = [ 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items' ];

	/**
	 * Fields that hold ids of products or variations.
	 *
	 * @var array<int, string>
	 */
	private const PRODUCT_FIELDS = [ 'product_ids', 'excluded_product_ids' ];

	/**
	 * Fields that hold ids of product categories.
	 *
	 * @var array<int, string>
	 */
	private const CATEGORY_FIELDS = [ 'product_categories', 'excluded_product_categories' ];

	/** Taxonomy of a product category. */
	private const CATEGORY_TAXONOMY = 'product_cat';

	/**
	 * Fields that hold a list of texts, which the shop filters without
	 * renumbering it: only the texts count, not their keys.
	 *
	 * @var array<int, string>
	 */
	private const LIST_FIELDS = [ 'email_restrictions' ];

	/**
	 * Post types of a product and of a variation.
	 *
	 * @var array<int, string>
	 */
	private const PRODUCT_TYPES = [ 'product', 'product_variation' ];

	/**
	 * Fields of a coupon's post WordPress writes => the coupon field they are.
	 *
	 * @var array<string, string>
	 */
	private const POST_FIELDS = [
		'post_title'   => 'code',
		'post_excerpt' => 'description',
	];

	/**
	 * The coupons created in this request: `site:coupon id` => how many
	 * Action Scheduler jobs were running (see
	 * {@see ActionSchedulerContext::depth()}). A coupon id is unique on its
	 * own site only. What a job created is made when the job ends, see
	 * {@see self::flush_job_state()}.
	 *
	 * @var array<string, int>
	 */
	private array $created = [];

	public function key(): string {
		return 'woocommerce-coupon';
	}

	public function should_load(): bool {
		return class_exists( 'WooCommerce', false );
	}

	public function boot(): void {
		add_action( 'woocommerce_new_coupon', [ $this, 'on_created' ], 10, 2 );
		// Coupons have no save hook of their own; webhooks fire this one too.
		add_action( 'woocommerce_before_data_object_save', [ $this, 'on_before_save' ], 10, 1 );
		add_action( 'transition_post_status', [ $this, 'on_post_status' ], 10, 3 );
		add_action( 'post_updated', [ $this, 'on_post_updated' ], 10, 3 );
		add_action( 'before_delete_post', [ $this, 'on_delete_post' ], 10, 2 );
		// When a job ends, before its context closes at PHP_INT_MAX.
		add_action( 'action_scheduler_after_execute', [ $this, 'flush_job_state' ], PHP_INT_MAX - 1, 0 );
		add_action( 'action_scheduler_failed_execution', [ $this, 'flush_job_state' ], PHP_INT_MAX - 1, 0 );
	}

	/**
	 * The Action Scheduler job that is ending made the coupons it created: a
	 * later job of the same run that edits one logs the edit. Nothing
	 * outside a job.
	 */
	public function flush_job_state(): void {
		$depth = ActionSchedulerContext::depth();
		if ( $depth > 0 ) {
			$this->created = array_filter( $this->created, static fn( int $at ): bool => $at < $depth );
		}
	}

	/**
	 * WooCommerce inserted a coupon.
	 *
	 * @param mixed $coupon_id Coupon id.
	 * @param mixed $coupon    The coupon, if the hook passed it.
	 */
	public function on_created( $coupon_id, $coupon = null ): void {
		try {
			$coupon_id = self::id_of( $coupon_id );
			if ( $coupon_id <= 0 || $this->is_created( $coupon_id ) ) {
				return;
			}

			$this->emit_created( $coupon_id, is_object( $coupon ) ? self::code_of( $coupon ) : self::post_title( $coupon_id ) );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9200, [ 'coupon_id' => self::id_of( $coupon_id ) ] );
		}
	}

	/**
	 * A data object is about to be saved by the shop: when it is a coupon,
	 * tell which of its fields change, from the values it has stored
	 * (`get_data()`) and those the save is about to write (`get_changes()`).
	 *
	 * A coupon without an id is being inserted; one created in this request,
	 * or kept as a placeholder, is being made: none is an edit. A save that
	 * changes none of the fields is not dispatched.
	 *
	 * @param mixed $coupon The object WooCommerce is about to save.
	 */
	public function on_before_save( $coupon ): void {
		$coupon_id = 0;
		try {
			// Never ask the object for its type: a coupon has none.
			if ( ! $coupon instanceof \WC_Coupon ) {
				return;
			}
			$coupon_id = self::id_of( $coupon->get_id() );
			if ( $coupon_id <= 0 ) {
				return;
			}

			$pending = self::coupon_values( $coupon->get_changes() );
			if ( [] === $pending || $this->is_created( $coupon_id ) ) {
				return;
			}

			$data = $coupon->get_data();
			$data = is_array( $data ) ? $data : [];
			if ( self::PLACEHOLDER_STATUS === ( $data['status'] ?? null ) ) {
				return;
			}

			$stored  = self::coupon_values( $data );
			$changes = self::field_changes( $stored, self::real_changes( $stored, $pending ) );
			if ( [] === $changes ) {
				return;
			}

			$this->emit(
				9201,
				array_merge(
					[ 'changes' => $changes ],
					self::coupon_fields( $coupon_id, self::code_of( $coupon ) )
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9201, [ 'coupon_id' => $coupon_id ] );
		}
	}

	/**
	 * The status of a coupon's post changed. A coupon that leaves `new` or
	 * `auto-draft` for a status that makes it is created; any other status
	 * change of a coupon that is not being made is an edit of its status.
	 *
	 * @param mixed $new_status New post status.
	 * @param mixed $old_status Previous post status.
	 * @param mixed $post       The post.
	 */
	public function on_post_status( $new_status, $old_status, $post ): void {
		$coupon_id = 0;
		try {
			if ( ! $post instanceof WP_Post ) {
				return;
			}
			$coupon_id = (int) $post->ID;
			$new       = self::text_of( $new_status );
			$old       = self::text_of( $old_status );
			if ( $coupon_id <= 0 || self::POST_TYPE !== (string) $post->post_type || $new === $old ) {
				return;
			}

			if ( in_array( $old, self::CREATION_STATUSES, true ) ) {
				if ( ! in_array( $new, self::UNMADE_STATUSES, true ) && ! $this->is_created( $coupon_id ) ) {
					$this->emit_created( $coupon_id, (string) $post->post_title );
				}
				return;
			}
			if ( $this->is_created( $coupon_id ) ) {
				return;
			}

			$this->emit(
				9201,
				array_merge(
					[ 'changes' => [ self::change( 'status', $old, $new ) ] ],
					self::coupon_fields( $coupon_id, (string) $post->post_title )
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9201, [ 'coupon_id' => $coupon_id ] );
		}
	}

	/**
	 * WordPress wrote a coupon's post: the code and description it changed
	 * are an edit (the status is told by its status change), under the code
	 * the coupon has now. The first write of a placeholder is its creation.
	 *
	 * @param mixed $post_id     Post id.
	 * @param mixed $post_after  The post as written.
	 * @param mixed $post_before The post before.
	 */
	public function on_post_updated( $post_id, $post_after, $post_before ): void {
		$coupon_id = 0;
		try {
			$coupon_id = self::id_of( $post_id );
			if ( $coupon_id <= 0 || ! $post_after instanceof WP_Post || ! $post_before instanceof WP_Post ) {
				return;
			}
			if ( self::POST_TYPE !== (string) $post_after->post_type || in_array( (string) $post_before->post_status, self::CREATION_STATUSES, true ) ) {
				return;
			}
			if ( $this->is_created( $coupon_id ) ) {
				return;
			}

			$changes = ChangeSet::diff(
				self::post_values( $post_before ),
				self::post_values( $post_after ),
				array_values( array_intersect( self::COUPON_FIELDS, self::POST_FIELDS ) ),
				self::CONTENT_FIELDS
			);
			if ( [] === $changes ) {
				return;
			}

			$this->emit(
				9201,
				array_merge(
					[ 'changes' => $changes ],
					self::coupon_fields( $coupon_id, (string) $post_after->post_title )
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9201, [ 'coupon_id' => $coupon_id ] );
		}
	}

	/**
	 * A coupon is about to be deleted for good. WordPress's own cleanup of
	 * stale placeholders deletes nothing anybody made: a coupon it deletes
	 * that is still a placeholder is not told (see
	 * {@see ContentSensor::is_loggable_delete()}); any other placeholder is
	 * labelled with its id, its title being WordPress's placeholder.
	 *
	 * @param mixed $post_id Post id.
	 * @param mixed $post    The post.
	 */
	public function on_delete_post( $post_id, $post = null ): void {
		$coupon_id = 0;
		try {
			$coupon_id = self::id_of( $post_id );
			if ( $coupon_id <= 0 ) {
				return;
			}
			if ( ! $post instanceof WP_Post ) {
				$post = function_exists( 'get_post' ) ? get_post( $coupon_id ) : null;
				if ( ! $post instanceof WP_Post ) {
					return;
				}
			}
			if ( self::POST_TYPE !== (string) $post->post_type ) {
				return;
			}

			$status = (string) $post->post_status;
			// Core's only caller of `wp_delete_auto_drafts()` is this cron action.
			$cleanup = function_exists( 'doing_action' ) && doing_action( 'wp_scheduled_auto_draft_delete' );
			if ( ! ContentSensor::is_loggable_delete( $status, $cleanup ) ) {
				return;
			}

			$code = self::PLACEHOLDER_STATUS === $status ? '' : (string) $post->post_title;
			$this->emit( 9202, self::coupon_fields( $coupon_id, $code, false ) );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9202, [ 'coupon_id' => $coupon_id ] );
		}
	}

	/**
	 * Note the coupon as created in this request and dispatch its creation.
	 *
	 * @param int    $coupon_id Coupon id.
	 * @param string $code      Its code.
	 */
	private function emit_created( int $coupon_id, string $code ): void {
		$this->created[ self::state_key( $coupon_id ) ] = ActionSchedulerContext::depth();
		$this->emit( 9200, self::coupon_fields( $coupon_id, $code ) );
	}

	/**
	 * Whether the coupon was created in this request.
	 *
	 * @param int $coupon_id Coupon id.
	 */
	private function is_created( int $coupon_id ): bool {
		return isset( $this->created[ self::state_key( $coupon_id ) ] );
	}

	/**
	 * The `code`, `target`, `post`, `metadata` and, unless the coupon is
	 * gone, `links` of an event about a coupon. When core gives no link
	 * (nobody who may edit the coupon is logged in) it is built.
	 *
	 * @param int    $coupon_id Coupon id.
	 * @param string $code      Its code.
	 * @param bool   $with_link Whether to link the edit screen.
	 * @return array<string, mixed>
	 */
	private static function coupon_fields( int $coupon_id, string $code, bool $with_link = true ): array {
		$target = StoreTargets::coupon( $coupon_id, $code );
		$fields = [
			'code'     => $target['label'],
			'target'   => $target,
			'post'     => [
				'id'   => $coupon_id,
				'type' => self::POST_TYPE,
			],
			'metadata' => [ 'coupon_id' => $coupon_id ],
		];

		$edit = $with_link ? self::edit_link( $coupon_id ) : '';
		if ( '' !== $edit ) {
			$fields['links'] = [ 'edit' => $edit ];
		}

		return $fields;
	}

	/**
	 * The edit screen of a post: core's link, else one built the same way;
	 * empty outside WordPress.
	 *
	 * @param int $post_id Post id.
	 */
	private static function edit_link( int $post_id ): string {
		$edit = function_exists( 'get_edit_post_link' ) ? get_edit_post_link( $post_id, 'raw' ) : null;
		if ( is_string( $edit ) && '' !== $edit ) {
			return $edit;
		}

		return function_exists( 'admin_url' ) ? (string) admin_url( 'post.php?post=' . $post_id . '&action=edit' ) : '';
	}

	/**
	 * The code of a coupon (the pending one when a save changes it); '' when
	 * it has none to tell.
	 *
	 * @param object $coupon The coupon.
	 */
	private static function code_of( object $coupon ): string {
		$code = method_exists( $coupon, 'get_code' ) ? $coupon->get_code() : '';

		return is_scalar( $code ) ? (string) $code : '';
	}

	/**
	 * The title of a coupon's post (its code); '' when there is no such
	 * coupon.
	 *
	 * @param int $coupon_id Coupon id.
	 */
	private static function post_title( int $coupon_id ): string {
		$post = function_exists( 'get_post' ) ? get_post( $coupon_id ) : null;

		return $post instanceof WP_Post && self::POST_TYPE === (string) $post->post_type ? (string) $post->post_title : '';
	}

	/**
	 * The values of a coupon (stored or pending) that hold one of
	 * {@see self::COUPON_FIELDS}: only those are read, so its usage, the
	 * customers who used it and its meta are never turned into text.
	 *
	 * @param mixed $values Values of the coupon by key.
	 * @return array<string, mixed>
	 */
	private static function coupon_values( $values ): array {
		return is_array( $values ) ? array_intersect_key( $values, array_flip( self::COUPON_FIELDS ) ) : [];
	}

	/**
	 * The pending values without those that only write the stored value in
	 * another form: an empty value where there was none (or the other way
	 * round), or an amount written differently (`10` for `10.00`). A limit
	 * is read as its number or none, see {@see self::field_changes()}.
	 *
	 * @param array<string, mixed> $stored  Stored values.
	 * @param array<string, mixed> $pending Values the save is about to write.
	 * @return array<string, mixed>
	 */
	private static function real_changes( array $stored, array $pending ): array {
		$real = [];
		foreach ( $pending as $key => $value ) {
			$old = $stored[ $key ] ?? null;
			if ( self::is_empty( $old ) && self::is_empty( $value ) ) {
				continue;
			}
			if ( in_array( $key, self::MONEY_FIELDS, true ) && is_numeric( $old ) && is_numeric( $value ) && (float) $old === (float) $value ) {
				continue;
			}

			$real[ $key ] = $value;
		}

		return $real;
	}

	/**
	 * Whether a value is none: null, an empty string or an empty list.
	 *
	 * @param mixed $value Any value.
	 */
	private static function is_empty( $value ): bool {
		return null === $value || '' === $value || [] === $value;
	}

	/**
	 * A limit as text; null for none (no value, an empty one, or `0`).
	 *
	 * @param mixed $value Limit.
	 */
	private static function limit_text( $value ): ?string {
		return is_numeric( $value ) && (int) $value > 0 ? (string) (int) $value : null;
	}

	/**
	 * The texts of a list, sorted and without their keys (a list the shop
	 * filtered keeps the gaps of what it left out); anything but text is
	 * left out.
	 *
	 * @param mixed $value A list of texts.
	 * @return array<int, string>
	 */
	private static function texts( $value ): array {
		$texts = [];
		foreach ( is_array( $value ) ? $value : [] as $text ) {
			if ( is_string( $text ) && '' !== $text ) {
				$texts[] = $text;
			}
		}
		sort( $texts );

		return $texts;
	}

	/**
	 * The changes between the stored and the pending values, in the order of
	 * {@see self::COUPON_FIELDS}. Products and categories are compared by
	 * their ids and, only when those differ, told by their names (see
	 * {@see PostSnapshot::term_changes()}), limits as their number or none,
	 * lists of texts by their texts alone; everything else by
	 * {@see WcObjectDiff::changes()}.
	 *
	 * @param array<string, mixed> $stored  Stored values.
	 * @param array<string, mixed> $pending Values the save is about to write, as they really change.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	private static function field_changes( array $stored, array $pending ): array {
		$items = [];
		foreach ( array_merge( self::PRODUCT_FIELDS, self::CATEGORY_FIELDS ) as $field ) {
			$old = self::ids( $stored[ $field ] ?? null );
			$new = self::ids( $pending[ $field ] ?? null );
			// Named only when they changed: reading them costs a lookup each.
			if ( array_key_exists( $field, $pending ) && $old !== $new ) {
				$item = PostSnapshot::term_changes(
					[ $field => self::names( $field, $old ) ],
					[ $field => self::names( $field, $new ) ]
				)[0] ?? null;
				if ( null !== $item ) {
					$items[ $field ] = $item;
				}
			}
			unset( $stored[ $field ], $pending[ $field ] );
		}

		foreach ( self::LIMIT_FIELDS as $field ) {
			if ( array_key_exists( $field, $stored ) ) {
				$stored[ $field ] = self::limit_text( $stored[ $field ] );
			}
			if ( array_key_exists( $field, $pending ) ) {
				$pending[ $field ] = self::limit_text( $pending[ $field ] );
			}
		}
		foreach ( self::LIST_FIELDS as $field ) {
			if ( array_key_exists( $field, $stored ) ) {
				$stored[ $field ] = self::texts( $stored[ $field ] );
			}
			if ( array_key_exists( $field, $pending ) ) {
				$pending[ $field ] = self::texts( $pending[ $field ] );
			}
		}

		foreach ( WcObjectDiff::changes( $stored, $pending, self::COUPON_FIELDS, self::CONTENT_FIELDS ) as $item ) {
			$items[ $item['field'] ] = $item;
		}

		$changes = [];
		foreach ( self::COUPON_FIELDS as $field ) {
			if ( isset( $items[ $field ] ) ) {
				$changes[] = $items[ $field ];
			}
		}

		return $changes;
	}

	/**
	 * The objects an id field holds, by their names: id => name. A product
	 * or variation is named by its title, a category by its name; an id that
	 * names nothing is `#<id>`.
	 *
	 * @param string $field Field of {@see self::PRODUCT_FIELDS} or {@see self::CATEGORY_FIELDS}.
	 * @param mixed  $value A list of ids.
	 * @return array<int, string>
	 */
	private static function names( string $field, $value ): array {
		$names = [];
		foreach ( self::ids( $value ) as $id ) {
			$name = in_array( $field, self::CATEGORY_FIELDS, true ) ? self::category_name( $id ) : self::product_title( $id );

			$names[ $id ] = '' !== $name ? $name : '#' . $id;
		}

		return $names;
	}

	/**
	 * The name of a product category; '' when there is no such category.
	 *
	 * @param int $term_id Term id.
	 */
	private static function category_name( int $term_id ): string {
		$term = function_exists( 'get_term' ) ? get_term( $term_id, self::CATEGORY_TAXONOMY ) : null;

		return is_object( $term ) && isset( $term->name ) && is_scalar( $term->name ) ? (string) $term->name : '';
	}

	/**
	 * The title of a product or variation (a variation's is its parent's name
	 * and its attributes); '' when the id is none.
	 *
	 * @param int $product_id Product or variation id.
	 */
	private static function product_title( int $product_id ): string {
		$post = function_exists( 'get_post' ) ? get_post( $product_id ) : null;

		return $post instanceof WP_Post && in_array( (string) $post->post_type, self::PRODUCT_TYPES, true ) ? (string) $post->post_title : '';
	}

	/**
	 * The coupon fields a post holds, as text. The title keeps the code as it
	 * was typed: it is read the way the shop stores the code, in lower case
	 * (the shop writes it back that way when it saves the coupon).
	 *
	 * @param WP_Post $post The post.
	 * @return array<string, string>
	 */
	private static function post_values( WP_Post $post ): array {
		$values = [];
		foreach ( self::POST_FIELDS as $post_field => $field ) {
			$value            = $post->$post_field;
			$values[ $field ] = is_scalar( $value ) ? (string) $value : '';
		}
		$values['code'] = StoreTargets::coupon_code( $values['code'] );

		return $values;
	}

	/**
	 * @return array{field: string, old: ?string, new: ?string}
	 */
	private static function change( string $field, string $old, string $new ): array {
		return [
			'field' => $field,
			'old'   => $old,
			'new'   => $new,
		];
	}

	/**
	 * Ids as core and the shop pass them (ints or strings of digits), each
	 * once, sorted; anything else is left out.
	 *
	 * @param mixed $ids Ids.
	 * @return array<int, int>
	 */
	private static function ids( $ids ): array {
		$clean = [];
		foreach ( is_array( $ids ) ? $ids : [] as $id ) {
			$id = self::id_of( $id );
			if ( $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}
		sort( $clean );

		return $clean;
	}

	/**
	 * An id as a hook passes it: an integer, or a string of digits; 0 for
	 * anything else.
	 *
	 * @param mixed $value What the hook passed.
	 */
	private static function id_of( $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}

		return is_string( $value ) && ctype_digit( $value ) ? (int) $value : 0;
	}

	/**
	 * A text a hook passes (a status): a string as it is, '' for anything
	 * else.
	 *
	 * @param mixed $value What the hook passed.
	 */
	private static function text_of( $value ): string {
		return is_string( $value ) ? $value : '';
	}

	/**
	 * The key of a coupon's per-request state: `site:coupon id`.
	 *
	 * @param int $coupon_id Coupon id.
	 */
	private static function state_key( int $coupon_id ): string {
		return self::blog_id() . ':' . $coupon_id;
	}

	/**
	 * The current site; 0 outside WordPress.
	 */
	private static function blog_id(): int {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	}
}
