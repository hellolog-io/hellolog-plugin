<?php
/**
 * Turns sensor input into the canonical event payload shape.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the JSON payload that goes onto the outgoing queue and ultimately
 * to `POST /v1/wordpress-activity-audit-log/events`. Keeps sensor code
 * focused on "which event fired with which extra fields", not on payload
 * shape, timestamping, or context capture.
 *
 * The payload separates the ACTOR (`user`: who did it) from the TARGET
 * (`target`: the object it was done to) and adds the request `source`, an
 * old-to-new `changes` list, `links` and the template `vars`. Every optional
 * key is either well-formed or absent — never an empty container.
 *
 * Not final so tests can replace {@see self::current_context()}.
 */
class EventBuilder {

	/** Wire version of the payload. */
	private const SCHEMA = 2;

	private const MAX_TARGET_TYPE  = 32;
	private const MAX_TARGET_ID    = 64;
	private const MAX_TARGET_LABEL = 255;

	private const MAX_CHANGES      = 50;
	private const MAX_CHANGE_FIELD = 64;
	private const MAX_VARS         = 40;
	private const MAX_VAR_KEY      = 64;
	private const MAX_VAR_VALUE    = 512;

	/**
	 * Deepest array level of `metadata` that is still looked into; anything
	 * below becomes `…`. Keeps an array that refers to itself from recursing
	 * forever.
	 */
	private const MAX_METADATA_DEPTH = 32;

	/** Link keys that are sent, in the order they are emitted. */
	private const LINK_KEYS = [ 'edit', 'view', 'revision' ];

	private const ELLIPSIS = '…';

	/** Returns the source (see {@see RequestSource}) of the current request. */
	private \Closure $source;

	/**
	 * @param EventCatalog  $catalog      Event definitions by code.
	 * @param bool          $anonymize_ip Zero the last IP octet before it leaves the site.
	 * @param callable|null $source       Returns the request source string; defaults to
	 *                                    {@see RequestSource::current()}.
	 */
	public function __construct(
		private EventCatalog $catalog,
		private bool $anonymize_ip = false,
		?callable $source = null
	) {
		$this->source = \Closure::fromCallable( $source ?? [ RequestSource::class, 'current' ] );
	}

	/**
	 * Build a wire-shape event from a code plus sensor-supplied extras.
	 *
	 * @param int                  $code     Catalog code for the event kind.
	 * @param array<string, mixed> $fields   Optional extras:
	 *                                       - actor (assoc id int, username string, roles list of strings): who
	 *                                         did it; replaces the current user entirely. Other keys and
	 *                                         values of another type are dropped. A null or empty actor
	 *                                         counts as not passed.
	 *                                       - user (assoc): deprecated alias of `actor`.
	 *                                       - target (assoc type, id, label): the object it was done to; dropped
	 *                                         when `type` is over 32 or `id` over 64 characters. The label is
	 *                                         masked like a var.
	 *                                       - changes (list of field, old, new): what changed.
	 *                                       - links (assoc edit, view, revision): absolute http(s) URLs.
	 *                                       - vars (assoc): template values; wins over the scanned fields. Keys
	 *                                         over 64 characters are dropped.
	 *                                       - post (assoc id int, type string, status string): other keys and
	 *                                         values of another type are dropped.
	 *                                       - title (string): the `post_title` of the metadata, masked like a var.
	 *                                       - message (string), client_event_id (string).
	 *                                       - metadata (assoc): every string in it, at any depth, is cut to
	 *                                         {@see ChangeSet::MAX_VALUE_LENGTH} characters.
	 *                                       Every other scalar whose key is a `{placeholder}` of the
	 *                                       code's message template becomes a template var, and the
	 *                                       English message is rendered from those vars.
	 * @return array<string, mixed>|null  null if the code is unknown.
	 */
	public function build( int $code, array $fields = [] ): ?array {
		$def = $this->catalog->get( $code );
		if ( null === $def ) {
			return null;
		}

		$ctx        = $this->current_context();
		$source     = (string) ( $this->source )();
		$background = RequestSource::is_background( $source );

		$payload = [
			'schema'      => self::SCHEMA,
			'code'        => $def->code,
			'occurred_at' => gmdate( 'Y-m-d\TH:i:s.v\Z' ),
			'severity'    => $def->severity,
			'object'      => $def->object,
			'event_type'  => $def->event_type,
			'source'      => $source,
		];

		$user = $this->resolve_actor( $ctx, $this->explicit_actor( $fields ), $background );
		if ( null !== $user ) {
			$payload['user'] = $user;
		}
		if ( null !== $ctx->client_ip ) {
			$payload['client_ip'] = $ctx->client_ip;
		}
		if ( null !== $ctx->user_agent ) {
			$payload['user_agent'] = $ctx->user_agent;
		}
		if ( null !== $ctx->session_id ) {
			$payload['session_id'] = $ctx->session_id;
		}
		$post = $this->clean_post( $fields['post'] ?? null );
		if ( null !== $post ) {
			$payload['post'] = $post;
		}

		$target = $this->clean_target( $fields['target'] ?? null );
		if ( null !== $target ) {
			$payload['target'] = $target;
		}
		$changes = $this->clean_changes( $fields['changes'] ?? null );
		if ( [] !== $changes ) {
			$payload['changes'] = $changes;
		}
		$links = $this->clean_links( $fields['links'] ?? null );
		if ( [] !== $links ) {
			$payload['links'] = $links;
		}

		$texts              = $this->var_texts( $def->message_template, $fields );
		$message            = $fields['message'] ?? $this->render_message( $def->message_template, $texts );
		$payload['message'] = (string) $message;

		$vars = $this->clean_vars( $texts );
		if ( [] !== $vars ) {
			$payload['vars'] = $vars;
		}

		$metadata = ( isset( $fields['metadata'] ) && is_array( $fields['metadata'] ) ) ? $fields['metadata'] : [];

		// Surface the post id and title in `metadata` so the read API (which
		// only returns metadata + scalar columns) can link to the edit screen.
		if ( ! empty( $payload['post']['id'] ) && ! isset( $metadata['post_id'] ) ) {
			$metadata['post_id'] = (int) $payload['post']['id'];
		}
		if ( ! empty( $payload['post']['type'] ) && ! isset( $metadata['post_type'] ) ) {
			$metadata['post_type'] = (string) $payload['post']['type'];
		}
		if ( isset( $fields['title'] ) && '' !== $fields['title'] && ! isset( $metadata['post_title'] ) ) {
			$metadata['post_title'] = (string) $fields['title'];
		}
		// A title is text somebody typed: it is masked the way vars are.
		if ( isset( $metadata['post_title'] ) && is_string( $metadata['post_title'] ) ) {
			$metadata['post_title'] = ChangeSet::redact_text( $metadata['post_title'] );
		}

		// A background run has no actor of its own; keep who set it in motion.
		if ( $background && null !== $ctx->username && '' !== $ctx->username && ! isset( $metadata['initiated_by'] ) ) {
			$metadata['initiated_by'] = $ctx->username;
		}

		if ( ! empty( $metadata ) ) {
			$payload['metadata'] = $this->clip_metadata( $metadata );
		}
		if ( isset( $fields['client_event_id'] ) && is_string( $fields['client_event_id'] ) ) {
			$payload['client_event_id'] = $fields['client_event_id'];
		}

		return $payload;
	}

	/**
	 * Who and where the current request comes from. A seam for tests, which
	 * subclass the builder to return a fixed context.
	 */
	protected function current_context(): RequestContext {
		return RequestContext::capture( $this->anonymize_ip );
	}

	/**
	 * The actor a sensor passed explicitly: `actor`, or its deprecated alias
	 * `user`, cleaned by {@see self::clean_actor()}. An actor that is null,
	 * not an array or holds nothing usable counts as not passed, so the next
	 * alias, or the normal policy, applies.
	 *
	 * @param array<string, mixed> $fields Sensor-supplied fields.
	 * @return array<string, mixed>|null
	 */
	private function explicit_actor( array $fields ): ?array {
		foreach ( [ 'actor', 'user' ] as $key ) {
			if ( isset( $fields[ $key ] ) && is_array( $fields[ $key ] ) ) {
				$actor = $this->clean_actor( $fields[ $key ] );
				if ( null !== $actor ) {
					return $actor;
				}
			}
		}

		return null;
	}

	/**
	 * The `user` of the payload.
	 *
	 * An explicit actor is used exactly as given, in every mode: it replaces
	 * the current user entirely and is never completed from it, so an event
	 * never carries an identity made of two people. Without one, an
	 * interactive request is attributed to the current user and a background
	 * run (cron, Action Scheduler) has no person behind it, hence no `user`.
	 *
	 * @param array<string, mixed>|null $explicit
	 * @return array<string, mixed>|null
	 */
	private function resolve_actor( RequestContext $ctx, ?array $explicit, bool $background ): ?array {
		if ( null !== $explicit ) {
			return $explicit;
		}
		if ( $background || null === $ctx->user_id ) {
			return null;
		}

		return $this->clean_actor(
			[
				'id'       => $ctx->user_id,
				'username' => $ctx->username,
				'roles'    => $ctx->roles,
			]
		);
	}

	/**
	 * The `user` as sent: `id` (an integer), `username` (a string) and
	 * `roles` (a list of strings), each only when it holds a value of its
	 * type; null when nothing is left. The receiving end refuses a whole
	 * batch over one key it does not know, so every other key is dropped.
	 *
	 * @param array<string, mixed> $user Actor as given.
	 * @return array{id?: int, username?: string, roles?: array<int, string>}|null
	 */
	private function clean_actor( array $user ): ?array {
		$clean = [];

		$id = $this->int_value( $user['id'] ?? null );
		if ( null !== $id ) {
			$clean['id'] = $id;
		}
		$username = $this->text_value( $user['username'] ?? null );
		if ( '' !== $username ) {
			$clean['username'] = $username;
		}
		$roles = $this->string_list( $user['roles'] ?? null );
		if ( [] !== $roles ) {
			$clean['roles'] = $roles;
		}

		return [] === $clean ? null : $clean;
	}

	/**
	 * The `post` as sent: `id` (an integer), `type` and `status` (strings),
	 * each only when it holds a value of its type; null when nothing is
	 * left. Every other key is dropped, as for the actor.
	 *
	 * @param mixed $post Sensor-supplied `post`.
	 * @return array{id?: int, type?: string, status?: string}|null
	 */
	private function clean_post( $post ): ?array {
		if ( ! is_array( $post ) ) {
			return null;
		}

		$clean = [];

		$id = $this->int_value( $post['id'] ?? null );
		if ( null !== $id ) {
			$clean['id'] = $id;
		}
		foreach ( [ 'type', 'status' ] as $key ) {
			if ( isset( $post[ $key ] ) && is_string( $post[ $key ] ) && '' !== $post[ $key ] ) {
				$clean[ $key ] = $post[ $key ];
			}
		}

		return [] === $clean ? null : $clean;
	}

	/**
	 * An integer, or a string of digits read as one; null for anything else.
	 *
	 * @param mixed $value Any value.
	 */
	private function int_value( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}

		return is_string( $value ) && ctype_digit( $value ) ? (int) $value : null;
	}

	/**
	 * A string, or an integer as text; an empty string for anything else.
	 *
	 * @param mixed $value Any value.
	 */
	private function text_value( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		return is_int( $value ) ? (string) $value : '';
	}

	/**
	 * The non-empty strings of an array, as a list; an empty list for
	 * anything that is not an array.
	 *
	 * @param mixed $value Any value.
	 * @return array<int, string>
	 */
	private function string_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_values( array_filter( $value, static fn( $item ): bool => is_string( $item ) && '' !== $item ) );
	}

	/**
	 * The object the event is about: type and id are required, the id is
	 * always a string, the label is optional.
	 *
	 * A type over 32 or an id over 64 characters cannot be cut without
	 * pointing at another object, so the whole target is dropped. Only the
	 * label is shortened, after it is masked the way vars are (a label is
	 * often a name somebody typed, or a setting's value).
	 *
	 * @param mixed $target Sensor-supplied `target`.
	 * @return array{type: string, id: string, label?: string}|null
	 */
	private function clean_target( $target ): ?array {
		if ( ! is_array( $target ) ) {
			return null;
		}

		$type = $this->scalar_text( $target['type'] ?? null );
		$id   = $this->scalar_text( $target['id'] ?? null );
		if ( '' === $type || '' === $id ) {
			return null;
		}

		if ( mb_strlen( $type, 'UTF-8' ) > self::MAX_TARGET_TYPE || mb_strlen( $id, 'UTF-8' ) > self::MAX_TARGET_ID ) {
			return null;
		}

		$clean = [
			'type' => $type,
			'id'   => $id,
		];

		$label = $this->scalar_text( $target['label'] ?? null );
		if ( '' !== $label ) {
			$clean['label'] = $this->clamp( ChangeSet::redact_text( $label ), self::MAX_TARGET_LABEL );
		}

		return $clean;
	}

	/**
	 * A 0-indexed list of exact `field`/`old`/`new` items. Items that are not
	 * arrays or have no usable field name are dropped before the limit is
	 * applied. Values go through {@see ChangeSet::stringify()}, so they are
	 * strings or null and at most {@see ChangeSet::MAX_VALUE_LENGTH} long.
	 *
	 * @param mixed $changes Sensor-supplied `changes`.
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	private function clean_changes( $changes ): array {
		if ( ! is_array( $changes ) ) {
			return [];
		}

		$clean = [];
		foreach ( $changes as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$field = $this->scalar_text( $item['field'] ?? null );
			if ( '' === $field ) {
				continue;
			}

			$clean[] = [
				'field' => $this->clamp( $field, self::MAX_CHANGE_FIELD ),
				'old'   => ChangeSet::stringify( $item['old'] ?? null ),
				'new'   => ChangeSet::stringify( $item['new'] ?? null ),
			];
		}

		return array_slice( $clean, 0, self::MAX_CHANGES );
	}

	/**
	 * Only `edit`, `view` and `revision`, and only absolute http(s) URLs.
	 * `false` (what core returns for "no such link"), `WP_Error`, arrays and
	 * relative URLs are dropped.
	 *
	 * @param mixed $links Sensor-supplied `links`.
	 * @return array<string, string>
	 */
	private function clean_links( $links ): array {
		if ( ! is_array( $links ) ) {
			return [];
		}

		$clean = [];
		foreach ( self::LINK_KEYS as $key ) {
			$url = $links[ $key ] ?? null;
			if ( is_string( $url ) && 1 === preg_match( '#^https?://#i', $url ) ) {
				$clean[ $key ] = $url;
			}
		}

		return $clean;
	}

	/**
	 * Template values as text: every scalar field whose `{key}` appears in the
	 * message template, overlaid with the explicit `vars`. Keys are non-empty strings,
	 * values go through {@see ChangeSet::stringify()} (a bool reads `yes` or
	 * `no`); a value that has no text (null) is left out.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, string>
	 */
	private function var_texts( string $template, array $fields ): array {
		$vars = [];

		if ( '' !== $template ) {
			foreach ( $fields as $key => $value ) {
				if ( is_string( $key ) && '' !== $key && is_scalar( $value ) && str_contains( $template, '{' . $key . '}' ) ) {
					$vars[ $key ] = $value;
				}
			}
		}
		if ( isset( $fields['vars'] ) && is_array( $fields['vars'] ) ) {
			foreach ( $fields['vars'] as $key => $value ) {
				if ( is_string( $key ) && '' !== $key ) {
					$vars[ $key ] = $value;
				}
			}
		}

		$texts = [];
		foreach ( $vars as $key => $value ) {
			$text = ChangeSet::stringify( $value );
			if ( null !== $text ) {
				$texts[ $key ] = $text;
			}
		}

		return $texts;
	}

	/**
	 * The `vars` of the payload: keys of at most 64 characters (a longer key
	 * is dropped, not cut), values cut to 512 characters, at most 40
	 * entries.
	 *
	 * @param array<string, string> $texts Output of {@see self::var_texts()}.
	 * @return array<string, string>
	 */
	private function clean_vars( array $texts ): array {
		$clean = [];
		foreach ( $texts as $key => $text ) {
			if ( mb_strlen( $key, 'UTF-8' ) > self::MAX_VAR_KEY ) {
				continue;
			}
			$clean[ $key ] = $this->clamp( $text, self::MAX_VAR_VALUE );
		}

		return array_slice( $clean, 0, self::MAX_VARS, true );
	}

	/**
	 * The metadata with every string in it, at any depth, cut to
	 * {@see ChangeSet::MAX_VALUE_LENGTH} characters the way change values
	 * are. It runs after anything in the metadata is masked, so a secret is
	 * never cut in a way that hides it from the masking. Other values are
	 * left as they are.
	 *
	 * @param array<int|string, mixed> $metadata Metadata, or an array inside it.
	 * @param int                      $depth    Array nesting level of `$metadata`.
	 * @return array<int|string, mixed>
	 */
	private function clip_metadata( array $metadata, int $depth = 0 ): array {
		$clipped = [];
		foreach ( $metadata as $key => $value ) {
			if ( is_string( $value ) ) {
				$value = ChangeSet::truncate( $value );
			} elseif ( is_array( $value ) ) {
				$value = $depth < self::MAX_METADATA_DEPTH ? $this->clip_metadata( $value, $depth + 1 ) : self::ELLIPSIS;
			}
			$clipped[ $key ] = $value;
		}

		return $clipped;
	}

	/**
	 * Text of a scalar; an empty string for null, arrays, objects and the like.
	 *
	 * @param mixed $value Any value.
	 */
	private function scalar_text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Cut to `$max` characters, the ellipsis included.
	 */
	private function clamp( string $text, int $max ): string {
		if ( mb_strlen( $text, 'UTF-8' ) <= $max ) {
			return $text;
		}

		return mb_substr( $text, 0, $max - 1, 'UTF-8' ) . self::ELLIPSIS;
	}

	/**
	 * Naive `{key}` placeholder substitution from the template vars. The
	 * catalog ships short English templates; localized rendering happens on
	 * the read side.
	 *
	 * @param array<string, string> $texts Output of {@see self::var_texts()}.
	 */
	private function render_message( string $template, array $texts ): string {
		if ( '' === $template ) {
			return '';
		}
		$replacements = [];
		foreach ( $texts as $k => $v ) {
			$replacements[ '{' . $k . '}' ] = $v;
		}
		return strtr( $template, $replacements );
	}
}
