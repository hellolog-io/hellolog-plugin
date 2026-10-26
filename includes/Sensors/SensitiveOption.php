<?php
/**
 * Decides which option values may leave the site in an event payload.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors;

defined( 'ABSPATH' ) || exit;

/**
 * Prefix-based option sensors (`woocommerce_*`, `lw_*`) also see options
 * that third-party extensions register under the same prefix — API keys,
 * client secrets, SMTP passwords. The change itself is still worth logging;
 * only the value is replaced when the option name looks like a credential.
 */
final class SensitiveOption {

	public const REDACTED = '[redacted]';

	/**
	 * Credential-like fragments, matched anywhere in the name.
	 *
	 * `auth` covers auth_key, oauth_*, authorization, authorize_url,
	 * authorised_*, authentication… but must not swallow `author`/`authors`
	 * (post_author, comment_author_email, author_id): those are ordinary
	 * content fields. The lookahead skips `auth` only when it is the whole
	 * word `author(s)`; `authorization`, `authorise` and the like keep
	 * matching because a letter follows `author`/`authors`.
	 */
	private const PATTERN = '/key|secret|token|pass(word)?|pwd|credential|private|license|licence|salt|auth(?!ors?(?![a-z]))/i';

	public static function is_sensitive( string $option ): bool {
		return 1 === preg_match( self::PATTERN, $option );
	}

	/**
	 * Loggable representation of an option value: masked for credential-like
	 * names, the scalar as a string otherwise, `<complex>` for arrays/objects.
	 *
	 * @param mixed $value
	 */
	public static function value( string $option, $value ): string {
		if ( self::is_sensitive( $option ) ) {
			return self::REDACTED;
		}
		return is_scalar( $value ) ? (string) $value : '<complex>';
	}
}
