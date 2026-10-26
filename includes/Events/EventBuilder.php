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
	private const MAX_VAR_VALUE    = 512;

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
	 *                                       - actor (assoc id, username, roles): who did it; replaces the current user.
	 *                                       - user (assoc): deprecated alias of `actor`.
	 *                                       - target (assoc type, id, label): the object it was done to.
	 *                                       - changes (list of field, old, new): what changed.
	 *                                       - links (assoc edit, view, revision): absolute http(s) URLs.
	 *                                       - vars (assoc): template values; wins over the scanned fields.
	 *                                       - post (assoc), title, message (string), metadata (assoc),
	 *                                         client_event_id (string).
	 *                                       Every other scalar whose key is a `{placeholder}` of the
	 *                                       code's message template becomes a template var.
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
		if ( isset( $fields['post'] ) && is_array( $fields['post'] ) ) {
			$payload['post'] = $fields['post'];
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

		$message            = $fields['message'] ?? $this->render_message( $def->message_template, $fields );
		$payload['message'] = (string) $message;

		$vars = $this->collect_vars( $def->message_template, $fields );
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

		// A background run has no actor of its own; keep who set it in motion.
		if ( $background && null !== $ctx->username && '' !== $ctx->username && ! isset( $metadata['initiated_by'] ) ) {
			$metadata['initiated_by'] = $ctx->username;
		}

		if ( ! empty( $metadata ) ) {
			$payload['metadata'] = $metadata;
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
	 * `user`. An empty array counts as not passed.
	 *
	 * @param array<string, mixed> $fields Sensor-supplied fields.
	 * @return array<string, mixed>|null
	 */
	private function explicit_actor( array $fields ): ?array {
		foreach ( [ 'actor', 'user' ] as $key ) {
			if ( isset( $fields[ $key ] ) && is_array( $fields[ $key ] ) && [] !== $fields[ $key ] ) {
				return $fields[ $key ];
			}
		}

		return null;
	}

	/**
	 * The `user` of the payload.
	 *
	 * Interactive requests fill whatever the actor leaves out from the current
	 * user. A background run (cron, Action Scheduler) has no person behind it:
	 * without an explicit actor there is no `user`, and an explicit one stands
	 * alone — it is never mixed with a user that merely was logged in.
	 *
	 * @param array<string, mixed>|null $explicit
	 * @return array<string, mixed>|null
	 */
	private function resolve_actor( RequestContext $ctx, ?array $explicit, bool $background ): ?array {
		if ( $background ) {
			return null === $explicit ? null : $this->compact_actor( $explicit );
		}

		return $this->merge_user( $ctx, $explicit );
	}

	/**
	 * @param array<string, mixed>|null $override
	 * @return array<string, mixed>|null
	 */
	private function merge_user( RequestContext $ctx, ?array $override ): ?array {
		if ( null === $override && null === $ctx->user_id ) {
			return null;
		}

		$user = [
			'id'       => $ctx->user_id,
			'username' => $ctx->username,
			'roles'    => $ctx->roles,
		];
		if ( is_array( $override ) ) {
			$user = array_merge( $user, $override );
		}
		return $this->compact_actor( $user );
	}

	/**
	 * Drop empty entries to keep the payload compact; null when nothing is left.
	 *
	 * @param array<string, mixed> $user
	 * @return array<string, mixed>|null
	 */
	private function compact_actor( array $user ): ?array {
		$user = array_filter( $user, static fn( $v ) => null !== $v && '' !== $v && [] !== $v );

		return [] === $user ? null : $user;
	}

	/**
	 * The object the event is about: type and id are required, the id is
	 * always a string, the label is optional.
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

		$clean = [
			'type' => $this->clamp( $type, self::MAX_TARGET_TYPE ),
			'id'   => $this->clamp( $id, self::MAX_TARGET_ID ),
		];

		$label = $this->scalar_text( $target['label'] ?? null );
		if ( '' !== $label ) {
			$clean['label'] = $this->clamp( $label, self::MAX_TARGET_LABEL );
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
	 * Template values: every scalar field whose `{key}` appears in the message
	 * template (the same scan as {@see self::render_message()}), overlaid with
	 * the explicit `vars`. Keys are non-empty strings, values are strings.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, string>
	 */
	private function collect_vars( string $template, array $fields ): array {
		$vars = [];

		if ( '' !== $template ) {
			foreach ( $fields as $key => $value ) {
				if ( is_string( $key ) && is_scalar( $value ) && str_contains( $template, '{' . $key . '}' ) ) {
					$vars[ $key ] = $value;
				}
			}
		}
		if ( isset( $fields['vars'] ) && is_array( $fields['vars'] ) ) {
			foreach ( $fields['vars'] as $key => $value ) {
				if ( is_string( $key ) ) {
					$vars[ $key ] = $value;
				}
			}
		}

		$clean = [];
		foreach ( $vars as $key => $value ) {
			$text = ChangeSet::stringify( $value );
			if ( '' === $key || null === $text ) {
				continue;
			}
			$clean[ $key ] = $this->clamp( $text, self::MAX_VAR_VALUE );
		}

		return array_slice( $clean, 0, self::MAX_VARS, true );
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
	 * Naive `{key}` placeholder substitution. The catalog ships short
	 * English templates; localized rendering happens on the read side.
	 *
	 * @param array<string, mixed> $fields
	 */
	private function render_message( string $template, array $fields ): string {
		if ( '' === $template ) {
			return '';
		}
		$replacements = [];
		foreach ( $fields as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$replacements[ '{' . $k . '}' ] = (string) $v;
			}
		}
		return strtr( $template, $replacements );
	}
}
