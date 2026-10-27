<?php
/**
 * WooCommerce: products and variations.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Events\ChangeSet;
use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\Core\ContentSensor;
use HelloLog\Sensors\Core\PostSnapshot;
use Throwable;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Products and variations: creation (9000), edits (9001) and permanent
 * delete (9002). Every event names the product as its target (see
 * {@see self::product_target()}); a variation is a target of its own,
 * labelled with its name (its parent's name and its attributes), and links
 * to its parent's edit screen.
 *
 * An edit tells which of {@see self::PRODUCT_FIELDS} changed, the
 * descriptions ({@see self::CONTENT_FIELDS}) without their values, terms,
 * the shipping class and images by their names. It is read from three
 * places, which the buffer merges into one event per product and request:
 *
 * - the shop's save of a product or variation, from the values stored and
 *   those the save is about to write;
 * - WordPress's write of the post: the classic product screen writes the
 *   name, slug and descriptions through WordPress before the shop saves the
 *   product, so the shop's save never sees them. The status is read from
 *   WordPress's status change, which also tells a scheduled product going
 *   live (no write of the post comes with that);
 * - the categories and tags WordPress sets on the product.
 *
 * WooCommerce announces a product it inserts itself, but not one made on the
 * classic screen: there "Add new" makes a WordPress placeholder
 * (`auto-draft`), and its first save is the creation. So a product whose post
 * leaves `new` or `auto-draft` is created, once per request whichever way it
 * is told. The creation tells nothing more: no save of that product in the
 * same request is an edit, nor any save of a product WordPress still keeps as
 * a placeholder, or of a variation of one.
 *
 * Every handler runs inside the work of WordPress or WooCommerce on the
 * product (the shop's save of a product has no guard around its hooks), so
 * whatever fails while reading the product is reported, never thrown, and
 * hook arguments are taken loosely.
 */
final class WooCommerceProductSensor extends AbstractSensor {

	/**
	 * The fields of a product whose edits are told, in the order they are.
	 *
	 * @var array<int, string>
	 */
	public const PRODUCT_FIELDS = [
		'name',
		'status',
		'slug',
		'sku',
		'regular_price',
		'sale_price',
		'date_on_sale_from',
		'date_on_sale_to',
		'manage_stock',
		'stock_quantity',
		'stock_status',
		'backorders',
		'low_stock_amount',
		'catalog_visibility',
		'featured',
		'tax_status',
		'tax_class',
		'weight',
		'length',
		'width',
		'height',
		'shipping_class_id',
		'category_ids',
		'tag_ids',
		'image_id',
		'gallery_image_ids',
		'virtual',
		'downloadable',
		'purchase_note',
		'short_description',
		'description',
	];

	/**
	 * The fields of {@see self::PRODUCT_FIELDS} told as changed, without
	 * their values: texts of any length.
	 *
	 * @var array<int, string>
	 */
	public const CONTENT_FIELDS = [ 'short_description', 'description' ];

	/** The title WooCommerce gives a product WordPress keeps as an editor placeholder. */
	public const PLACEHOLDER_TITLE = 'AUTO-DRAFT';

	/** Post type of a product. */
	private const PRODUCT_TYPE = 'product';

	/** Post type of a variation. */
	private const VARIATION_TYPE = 'product_variation';

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
	 * New statuses that do not make a product: it stays unmade, or goes
	 * straight to the trash.
	 *
	 * @var array<int, string>
	 */
	private const UNMADE_STATUSES = [ 'new', 'auto-draft', 'trash' ];

	/**
	 * Fields that hold a number: `10.00` and `10` are the same value.
	 *
	 * @var array<int, string>
	 */
	private const NUMBER_FIELDS = [ 'regular_price', 'sale_price', 'stock_quantity', 'low_stock_amount', 'weight', 'length', 'width', 'height' ];

	/**
	 * Fields that hold ids of terms => their taxonomy.
	 *
	 * @var array<string, string>
	 */
	private const TERM_FIELDS = [
		'shipping_class_id' => 'product_shipping_class',
		'category_ids'      => 'product_cat',
		'tag_ids'           => 'product_tag',
	];

	/**
	 * Fields that hold ids of files of the media library.
	 *
	 * @var array<int, string>
	 */
	private const IMAGE_FIELDS = [ 'image_id', 'gallery_image_ids' ];

	/**
	 * Taxonomies WordPress sets on a product => the field they are.
	 *
	 * @var array<string, string>
	 */
	private const TAXONOMY_FIELDS = [
		'product_cat' => 'category_ids',
		'product_tag' => 'tag_ids',
	];

	/**
	 * Fields of a product's post WordPress writes => the product field they
	 * are.
	 *
	 * @var array<string, string>
	 */
	private const POST_FIELDS = [
		'post_title'   => 'name',
		'post_name'    => 'slug',
		'post_excerpt' => 'short_description',
		'post_content' => 'description',
	];

	/**
	 * The same for a variation: its description is a field of the shop's own
	 * (its save tells it), and its excerpt is the list of its attributes
	 * WooCommerce writes there.
	 *
	 * @var array<string, string>
	 */
	private const VARIATION_POST_FIELDS = [
		'post_title' => 'name',
		'post_name'  => 'slug',
	];

	/**
	 * The products created in this request: `site:product id` => true. A
	 * product id is unique on its own site only.
	 *
	 * @var array<string, true>
	 */
	private array $created = [];

	/**
	 * Term relationships `wp_set_object_terms()` added in the call that is
	 * running: `site:product id:taxonomy` => term taxonomy ids. Taken by the
	 * `set_object_terms` that ends the call.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $added = [];

	public function key(): string {
		return 'woocommerce-product';
	}

	public function should_load(): bool {
		return class_exists( 'WooCommerce', false );
	}

	public function boot(): void {
		add_action( 'woocommerce_new_product', [ $this, 'on_created' ], 10, 2 );
		// Variations fire this hook too.
		add_action( 'woocommerce_before_product_object_save', [ $this, 'on_before_save' ], 10, 1 );
		add_action( 'transition_post_status', [ $this, 'on_post_status' ], 10, 3 );
		add_action( 'post_updated', [ $this, 'on_post_updated' ], 10, 3 );
		add_action( 'added_term_relationship', [ $this, 'on_term_added' ], 10, 3 );
		add_action( 'set_object_terms', [ $this, 'on_terms_set' ], 10, 6 );
		add_action( 'before_delete_post', [ $this, 'on_delete_post' ], 10, 2 );
	}

	/**
	 * The object an event about a product or variation is about, labelled
	 * with its name; a product without a real name (none, or WooCommerce's
	 * placeholder) is labelled `#<id>`.
	 *
	 * @param int    $id   Product or variation id.
	 * @param string $name Its name.
	 * @return array{type: string, id: string, label: string}
	 */
	public static function product_target( int $id, string $name ): array {
		return [
			'type'  => 'product',
			'id'    => (string) $id,
			'label' => '' === $name || self::PLACEHOLDER_TITLE === $name ? '#' . $id : $name,
		];
	}

	/**
	 * WooCommerce inserted a product.
	 *
	 * @param mixed $product_id Product id.
	 * @param mixed $product    The product, if the hook passed it.
	 */
	public function on_created( $product_id, $product = null ): void {
		try {
			$product_id = self::id_of( $product_id );
			if ( $product_id <= 0 || $this->is_created( $product_id ) ) {
				return;
			}

			$product = $this->product_object( $product_id, $product );
			$this->emit_created( $product_id, null === $product ? '' : self::name_of( $product ), self::PRODUCT_TYPE );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9000, [ 'product_id' => self::id_of( $product_id ) ] );
		}
	}

	/**
	 * A product or variation is about to be saved by the shop: tell which of
	 * its fields change, from the values it has stored (`get_data()`) and
	 * those the save is about to write (`get_changes()`). A product is
	 * whatever can be asked for those.
	 *
	 * A product without an id is being inserted; one created in this
	 * request, or kept as a placeholder, is being made: none is an edit. A
	 * save that changes none of the fields is not dispatched.
	 *
	 * @param mixed $product The product WooCommerce is about to save.
	 */
	public function on_before_save( $product ): void {
		$product_id = 0;
		try {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || ! method_exists( $product, 'get_data' ) || ! method_exists( $product, 'get_changes' ) ) {
				return;
			}
			$product_id = (int) $product->get_id();
			if ( $product_id <= 0 ) {
				return;
			}

			$pending = self::product_values( $product->get_changes() );
			if ( [] === $pending ) {
				return;
			}

			$parent_id = method_exists( $product, 'get_parent_id' ) ? max( 0, (int) $product->get_parent_id() ) : 0;
			if ( $this->in_creation( $product_id, $parent_id ) ) {
				return;
			}

			$data = $product->get_data();
			$data = is_array( $data ) ? $data : [];
			if ( self::PLACEHOLDER_STATUS === ( $data['status'] ?? null ) ) {
				return;
			}

			$stored  = self::product_values( $data );
			$changes = $this->field_changes( $stored, self::real_changes( $stored, $pending ) );
			if ( [] === $changes ) {
				return;
			}

			$this->emit(
				9001,
				array_merge(
					[ 'changes' => $changes ],
					$this->product_fields( $product_id, self::name_of( $product ), $parent_id, $parent_id > 0 ? self::VARIATION_TYPE : self::PRODUCT_TYPE )
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9001, [ 'product_id' => $product_id ] );
		}
	}

	/**
	 * The status of a product's or variation's post changed. A product that
	 * leaves `new` or `auto-draft` for a status that makes it is created; any
	 * other status change of a product that is not being made is an edit of
	 * its status.
	 *
	 * @param mixed $new_status New post status.
	 * @param mixed $old_status Previous post status.
	 * @param mixed $post       The post.
	 */
	public function on_post_status( $new_status, $old_status, $post ): void {
		$product_id = 0;
		try {
			if ( ! $post instanceof WP_Post ) {
				return;
			}
			$product_id = (int) $post->ID;
			$type       = (string) $post->post_type;
			$new        = self::text_of( $new_status );
			$old        = self::text_of( $old_status );
			if ( $product_id <= 0 || ! self::is_product_type( $type ) || $new === $old ) {
				return;
			}

			if ( in_array( $old, self::CREATION_STATUSES, true ) ) {
				if ( self::PRODUCT_TYPE === $type && ! in_array( $new, self::UNMADE_STATUSES, true ) && ! $this->is_created( $product_id ) ) {
					$this->emit_created( $product_id, (string) $post->post_title, $type );
				}
				return;
			}

			$parent_id = max( 0, (int) $post->post_parent );
			if ( $this->in_creation( $product_id, $parent_id ) ) {
				return;
			}

			$this->emit(
				9001,
				array_merge(
					[ 'changes' => [ self::change( 'status', $old, $new ) ] ],
					$this->product_fields( $product_id, (string) $post->post_title, $parent_id, $type )
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9001, [ 'product_id' => $product_id ] );
		}
	}

	/**
	 * WordPress wrote a product's or variation's post: the name, slug and
	 * descriptions it changed are an edit (the status is told by its status
	 * change). The first write of a placeholder is its creation, and the
	 * placeholder title WooCommerce gives a product sent back to
	 * `auto-draft` is no rename.
	 *
	 * @param mixed $post_id     Post id.
	 * @param mixed $post_after  The post as written.
	 * @param mixed $post_before The post before.
	 */
	public function on_post_updated( $post_id, $post_after, $post_before ): void {
		$product_id = 0;
		try {
			$product_id = self::id_of( $post_id );
			if ( $product_id <= 0 || ! $post_after instanceof WP_Post || ! $post_before instanceof WP_Post ) {
				return;
			}
			$type = (string) $post_after->post_type;
			if ( ! self::is_product_type( $type ) || in_array( (string) $post_before->post_status, self::CREATION_STATUSES, true ) ) {
				return;
			}

			$parent_id = max( 0, (int) $post_after->post_parent );
			if ( $this->in_creation( $product_id, $parent_id ) ) {
				return;
			}

			$placeholder = self::is_placeholder( $post_after );
			$changes     = self::post_changes( $post_before, $post_after, $type, $placeholder );
			if ( [] === $changes ) {
				return;
			}

			$name = (string) ( $placeholder ? $post_before->post_title : $post_after->post_title );
			$this->emit(
				9001,
				array_merge(
					[ 'changes' => $changes ],
					$this->product_fields( $product_id, $name, $parent_id, $type )
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9001, [ 'product_id' => $product_id ] );
		}
	}

	/**
	 * `wp_set_object_terms()` gave an object a category or tag it did not
	 * have. Noted for the `set_object_terms` that ends the call: when
	 * appending, core passes it no old terms, so these tell what the call
	 * changed.
	 *
	 * @param mixed $object_id Object id.
	 * @param mixed $tt_id     Term taxonomy id.
	 * @param mixed $taxonomy  Taxonomy name.
	 */
	public function on_term_added( $object_id, $tt_id, $taxonomy ): void {
		$product_id = 0;
		try {
			$product_id = self::id_of( $object_id );
			$taxonomy   = self::text_of( $taxonomy );
			$tt_id      = self::id_of( $tt_id );
			if ( $product_id > 0 && $tt_id > 0 && isset( self::TAXONOMY_FIELDS[ $taxonomy ] ) ) {
				$this->added[ self::state_key( $product_id ) . ':' . $taxonomy ][] = $tt_id;
			}
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9001, [ 'product_id' => $product_id ] );
		}
	}

	/**
	 * The categories or tags of a product were set: a change of them is an
	 * edit, named by its field (`category_ids`, `tag_ids`), with the term
	 * names before and after.
	 *
	 * When appending, core passes no old terms (and every term given, the
	 * ones the product had included): the old terms are the product's terms
	 * now, less the relationships this call added, and a call that added
	 * none changed nothing.
	 *
	 * @param mixed $object_id  Object id.
	 * @param mixed $terms      The terms as given; not used.
	 * @param mixed $tt_ids     Term taxonomy ids of every term given.
	 * @param mixed $taxonomy   Taxonomy name.
	 * @param mixed $append     Whether the terms were added to the product's terms.
	 * @param mixed $old_tt_ids Term taxonomy ids the product had before; empty when appending.
	 */
	public function on_terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = [] ): void {
		$product_id = 0;
		try {
			$taxonomy = self::text_of( $taxonomy );
			$field    = self::TAXONOMY_FIELDS[ $taxonomy ] ?? null;
			if ( null === $field ) {
				return;
			}

			$product_id = self::id_of( $object_id );
			$key        = self::state_key( $product_id ) . ':' . $taxonomy;
			$added      = self::ids( $this->added[ $key ] ?? [] );
			unset( $this->added[ $key ] );

			$append = true === $append || 1 === $append || '1' === $append;
			if ( $product_id <= 0 || $this->is_created( $product_id ) ) {
				return;
			}

			$old = self::ids( $old_tt_ids );
			$new = self::ids( $tt_ids );
			if ( $append ? [] === $added : $old === $new ) {
				return;
			}

			$post = function_exists( 'get_post' ) ? get_post( $product_id ) : null;
			if ( ! $post instanceof WP_Post || self::PRODUCT_TYPE !== (string) $post->post_type || self::PLACEHOLDER_STATUS === (string) $post->post_status ) {
				return;
			}

			if ( $append ) {
				$after = self::post_terms( $product_id, $taxonomy );
				if ( null === $after ) {
					return;
				}
				$after += self::term_names( array_values( array_diff( $added, array_keys( $after ) ) ), $taxonomy );
				$before = array_diff_key( $after, array_flip( $added ) );
			} else {
				$before = self::term_names( $old, $taxonomy );
				$after  = self::term_names( $new, $taxonomy );
			}

			$changes = PostSnapshot::term_changes( [ $field => $before ], [ $field => $after ] );
			if ( [] === $changes ) {
				return;
			}

			$this->emit(
				9001,
				array_merge(
					[ 'changes' => $changes ],
					$this->product_fields( $product_id, (string) $post->post_title, 0, self::PRODUCT_TYPE )
				)
			);
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9001, [ 'product_id' => $product_id ] );
		}
	}

	/**
	 * A product or variation is about to be deleted for good. WordPress's own
	 * cleanup of stale placeholders deletes nothing anybody made: a product
	 * it deletes that is still a placeholder, and the variations of one, are
	 * not told (see {@see ContentSensor::is_loggable_delete()}).
	 *
	 * @param mixed $post_id Post id.
	 * @param mixed $post    The post.
	 */
	public function on_delete_post( $post_id, $post = null ): void {
		$product_id = 0;
		try {
			$product_id = self::id_of( $post_id );
			if ( $product_id <= 0 ) {
				return;
			}
			if ( ! $post instanceof WP_Post ) {
				$post = function_exists( 'get_post' ) ? get_post( $product_id ) : null;
				if ( ! $post instanceof WP_Post ) {
					return;
				}
			}

			$type = (string) $post->post_type;
			if ( ! self::is_product_type( $type ) ) {
				return;
			}

			$parent_id = self::VARIATION_TYPE === $type ? max( 0, (int) $post->post_parent ) : 0;
			$status    = $parent_id > 0 ? self::post_status( $parent_id ) : (string) $post->post_status;
			// Core's only caller of `wp_delete_auto_drafts()` is this cron action.
			$cleanup = function_exists( 'doing_action' ) && doing_action( 'wp_scheduled_auto_draft_delete' );
			if ( ! ContentSensor::is_loggable_delete( $status, $cleanup ) ) {
				return;
			}

			$this->emit( 9002, $this->product_fields( $product_id, (string) $post->post_title, $parent_id, $type, false ) );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, 9002, [ 'product_id' => $product_id ] );
		}
	}

	/**
	 * Note the product as created in this request and dispatch its creation.
	 *
	 * @param int    $product_id Product id.
	 * @param string $name       Its name.
	 * @param string $type       Its post type.
	 */
	private function emit_created( int $product_id, string $name, string $type ): void {
		$this->created[ self::state_key( $product_id ) ] = true;
		$this->emit( 9000, $this->product_fields( $product_id, $name, 0, $type ) );
	}

	/**
	 * Whether the product was created in this request.
	 *
	 * @param int $product_id Product id.
	 */
	private function is_created( int $product_id ): bool {
		return isset( $this->created[ self::state_key( $product_id ) ] );
	}

	/**
	 * Whether a save of a product or variation is part of making it: the
	 * product was created in this request, or the variation's parent was, or
	 * WordPress still keeps the parent as a placeholder.
	 *
	 * @param int $product_id Product or variation id.
	 * @param int $parent_id  Id of the variation's parent; 0 for a product.
	 */
	private function in_creation( int $product_id, int $parent_id ): bool {
		if ( $this->is_created( $product_id ) ) {
			return true;
		}

		return $parent_id > 0 && ( $this->is_created( $parent_id ) || self::PLACEHOLDER_STATUS === self::post_status( $parent_id ) );
	}

	/**
	 * The `name`, `target`, `post`, `metadata` and, unless the product is
	 * gone, `links` of an event about a product. The edit screen of a
	 * variation is its parent's; when core gives no link (nobody who may edit
	 * the product is logged in) it is built.
	 *
	 * @param int    $product_id Product or variation id.
	 * @param string $name       Its name.
	 * @param int    $parent_id  Id of the variation's parent; 0 for a product.
	 * @param string $type       Its post type.
	 * @param bool   $with_link  Whether to link the edit screen.
	 * @return array<string, mixed>
	 */
	private function product_fields( int $product_id, string $name, int $parent_id, string $type, bool $with_link = true ): array {
		$target = self::product_target( $product_id, $name );
		$fields = [
			'name'     => $target['label'],
			'target'   => $target,
			'post'     => [
				'id'   => $product_id,
				'type' => $type,
			],
			'metadata' => [ 'product_id' => $product_id ] + ( $parent_id > 0 ? [ 'parent_id' => $parent_id ] : [] ),
		];

		$edit = $with_link ? self::edit_link( $parent_id > 0 ? $parent_id : $product_id ) : '';
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
	 * The product the hook passed or, failing that, the one WooCommerce finds
	 * for the id; null when there is none.
	 *
	 * @param int   $product_id Product id.
	 * @param mixed $product    Product object the hook passed, if any.
	 */
	private function product_object( int $product_id, $product ): ?object {
		if ( is_object( $product ) ) {
			return $product;
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$found = wc_get_product( $product_id );

		return is_object( $found ) ? $found : null;
	}

	/**
	 * The name of a product (for a variation, its parent's name and its
	 * attributes); '' when it has none to tell.
	 *
	 * @param object $product The product.
	 */
	private static function name_of( object $product ): string {
		$name = method_exists( $product, 'get_name' ) ? $product->get_name() : '';

		return is_scalar( $name ) ? (string) $name : '';
	}

	/**
	 * The values of a product (stored or pending) that hold one of
	 * {@see self::PRODUCT_FIELDS}: only those are read, so its attributes and
	 * meta are never turned into text.
	 *
	 * @param mixed $values Values of the product by key.
	 * @return array<string, mixed>
	 */
	private static function product_values( $values ): array {
		return is_array( $values ) ? array_intersect_key( $values, array_flip( self::PRODUCT_FIELDS ) ) : [];
	}

	/**
	 * The pending values without those that only write the stored value in
	 * another form: an empty value where there was none (or the other way
	 * round), or a number of {@see self::NUMBER_FIELDS} written differently
	 * (`10` for `10.00`).
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
			if ( in_array( $key, self::NUMBER_FIELDS, true ) && is_numeric( $old ) && is_numeric( $value ) && (float) $old === (float) $value ) {
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
	 * The changes between the stored and the pending values, in the order of
	 * {@see self::PRODUCT_FIELDS}. Terms, the shipping class and images are
	 * compared by their ids and told by their names (see
	 * {@see PostSnapshot::term_changes()}); everything else by
	 * {@see WcObjectDiff::changes()}.
	 *
	 * @param array<string, mixed> $stored  Stored values.
	 * @param array<string, mixed> $pending Values the save is about to write, as they really change.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	private function field_changes( array $stored, array $pending ): array {
		$items = [];
		foreach ( array_merge( array_keys( self::TERM_FIELDS ), self::IMAGE_FIELDS ) as $field ) {
			if ( array_key_exists( $field, $pending ) ) {
				$item = PostSnapshot::term_changes(
					[ $field => self::names( $field, $stored[ $field ] ?? null ) ],
					[ $field => self::names( $field, $pending[ $field ] ) ]
				)[0] ?? null;
				if ( null !== $item ) {
					$items[ $field ] = $item;
				}
			}
			unset( $stored[ $field ], $pending[ $field ] );
		}

		foreach ( WcObjectDiff::changes( $stored, $pending, self::PRODUCT_FIELDS, self::CONTENT_FIELDS ) as $item ) {
			$items[ $item['field'] ] = $item;
		}

		$changes = [];
		foreach ( self::PRODUCT_FIELDS as $field ) {
			if ( isset( $items[ $field ] ) ) {
				$changes[] = $items[ $field ];
			}
		}

		return $changes;
	}

	/**
	 * The objects an id field holds, by their names: id => name. A term is
	 * named by its name, a file of the media library by its title; an id that
	 * names nothing is `#<id>`.
	 *
	 * @param string $field Field of {@see self::TERM_FIELDS} or {@see self::IMAGE_FIELDS}.
	 * @param mixed  $value One id or a list of them.
	 * @return array<int, string>
	 */
	private static function names( string $field, $value ): array {
		$names = [];
		foreach ( self::ids( is_array( $value ) ? $value : [ $value ] ) as $id ) {
			$name = isset( self::TERM_FIELDS[ $field ] ) ? self::term_name( $id, self::TERM_FIELDS[ $field ] ) : self::image_title( $id );

			$names[ $id ] = '' !== $name ? $name : '#' . $id;
		}

		return $names;
	}

	/**
	 * The name of a term given by its id; '' when there is no such term.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy name.
	 */
	private static function term_name( int $term_id, string $taxonomy ): string {
		$term = function_exists( 'get_term' ) ? get_term( $term_id, $taxonomy ) : null;

		return is_object( $term ) && isset( $term->name ) && is_scalar( $term->name ) ? (string) $term->name : '';
	}

	/**
	 * The title of a file of the media library; '' when the id is none.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	private static function image_title( int $attachment_id ): string {
		$post = function_exists( 'get_post' ) ? get_post( $attachment_id ) : null;

		return $post instanceof WP_Post && 'attachment' === (string) $post->post_type ? (string) $post->post_title : '';
	}

	/**
	 * What WordPress's write of a post changed of the product's fields: the
	 * name, slug and descriptions (of a variation only the name and slug).
	 * The slug of a post in the trash is read without the suffix WordPress
	 * gives it there; a placeholder title is no rename.
	 *
	 * @param WP_Post $before      The post before.
	 * @param WP_Post $after       The post as written.
	 * @param string  $type        Its post type.
	 * @param bool    $placeholder Whether the post now bears WooCommerce's placeholder title.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	private static function post_changes( WP_Post $before, WP_Post $after, string $type, bool $placeholder ): array {
		$map    = self::VARIATION_TYPE === $type ? self::VARIATION_POST_FIELDS : self::POST_FIELDS;
		$old    = self::post_values( $before, $map );
		$new    = self::post_values( $after, $map );
		$fields = array_values( array_intersect( self::PRODUCT_FIELDS, $map ) );
		if ( $placeholder ) {
			$fields = array_values( array_diff( $fields, [ 'name' ] ) );
		}

		return ChangeSet::diff( $old, $new, $fields, self::CONTENT_FIELDS );
	}

	/**
	 * The product fields a post holds, as text.
	 *
	 * @param WP_Post               $post The post.
	 * @param array<string, string> $map  Post field => product field.
	 * @return array<string, string>
	 */
	private static function post_values( WP_Post $post, array $map ): array {
		$raw = [ 'post_status' => $post->post_status ];
		foreach ( array_keys( $map ) as $post_field ) {
			$raw[ $post_field ] = $post->$post_field;
		}
		$snapshot = PostSnapshot::from_values( $raw, [] );

		$values = [];
		foreach ( $map as $post_field => $field ) {
			$value            = $snapshot[ $post_field ] ?? '';
			$values[ $field ] = is_scalar( $value ) ? (string) $value : '';
		}

		return $values;
	}

	/**
	 * Whether a post is a product WooCommerce renamed to its placeholder
	 * because it went back to `auto-draft`.
	 *
	 * @param WP_Post $post The post as written.
	 */
	private static function is_placeholder( WP_Post $post ): bool {
		return self::PLACEHOLDER_STATUS === (string) $post->post_status && self::PLACEHOLDER_TITLE === (string) $post->post_title;
	}

	/**
	 * The categories or tags a product has: term taxonomy id => name; null
	 * when they could not be read.
	 *
	 * @param int    $product_id Product id.
	 * @param string $taxonomy   Taxonomy name.
	 * @return array<int, string>|null
	 */
	private static function post_terms( int $product_id, string $taxonomy ): ?array {
		// False is a product without terms there; anything else but a list (an
		// error) is terms that could not be read.
		$found = function_exists( 'get_the_terms' ) ? get_the_terms( $product_id, $taxonomy ) : null;
		if ( false !== $found && ! is_array( $found ) ) {
			return null;
		}

		$terms = [];
		foreach ( is_array( $found ) ? $found : [] as $term ) {
			if ( is_object( $term ) && isset( $term->term_taxonomy_id ) ) {
				$terms[ (int) $term->term_taxonomy_id ] = (string) ( $term->name ?? '' );
			}
		}

		return $terms;
	}

	/**
	 * The names of terms given by their term taxonomy id (what core passes to
	 * `set_object_terms`). A term that can not be found is named by its id
	 * (`#12`).
	 *
	 * @param array<int, int> $tt_ids   Term taxonomy ids.
	 * @param string          $taxonomy Taxonomy name.
	 * @return array<int, string> Term taxonomy id => name.
	 */
	private static function term_names( array $tt_ids, string $taxonomy ): array {
		$names = [];
		foreach ( $tt_ids as $tt_id ) {
			$term            = function_exists( 'get_term_by' ) ? get_term_by( 'term_taxonomy_id', $tt_id, $taxonomy ) : null;
			$names[ $tt_id ] = is_object( $term ) && isset( $term->name ) ? (string) $term->name : '#' . $tt_id;
		}

		return $names;
	}

	/**
	 * The status of a post; '' when there is no such post.
	 *
	 * @param int $post_id Post id.
	 */
	private static function post_status( int $post_id ): string {
		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;

		return $post instanceof WP_Post ? (string) $post->post_status : '';
	}

	/**
	 * Whether a post type is a product's or a variation's.
	 *
	 * @param string $type Post type.
	 */
	private static function is_product_type( string $type ): bool {
		return self::PRODUCT_TYPE === $type || self::VARIATION_TYPE === $type;
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
	 * A text a hook passes (a status, a taxonomy): a string as it is, ''
	 * for anything else.
	 *
	 * @param mixed $value What the hook passed.
	 */
	private static function text_of( $value ): string {
		return is_string( $value ) ? $value : '';
	}

	/**
	 * The key of a product's per-request state: `site:product id`.
	 *
	 * @param int $product_id Product id.
	 */
	private static function state_key( int $product_id ): string {
		$site = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

		return $site . ':' . $product_id;
	}
}
