<?php
/**
 * Decides which option values may leave the site in an event payload.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors;

use HelloLog\Events\ChangeSet;

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
	 * Credential-like fragments, matched on the normalized name (lower case,
	 * words joined by `_`, see {@see self::normalize()}).
	 *
	 * `auth` covers auth_key, oauth_*, authorization, authorize_url,
	 * authorised_*, authentication… but must not swallow `author`/`authors`
	 * (post_author, comment_author_email, author_id): those are ordinary
	 * content fields. The lookahead skips `auth` only when it is the whole
	 * word `author(s)`; `authorization`, `authorise` and the like keep
	 * matching because a letter follows `author`/`authors`.
	 *
	 * Bank and card details and signatures count too (`iban`,
	 * `account_number`, `sort_code`, `routing_number`, `card_number`,
	 * `bank_account`, `swift`, `signature`), and so do `connection_string` and
	 * `bearer`. Short abbreviations (`bic`, `cvv`, `cvv2`, `cvc`, `otp`, `jwt`,
	 * `dsn`, `pem`, `cert`) sit inside ordinary words (bicycle, potpourri,
	 * concert), so they only match as a whole `_`-separated word. `pin` is
	 * left out on purpose: it is part of shipping and pinterest.
	 */
	private const PATTERN = '/key|secret|token|pass(word)?|pwd|credential|private|license|licence|salt|iban|account_number|sort_code|routing_number|card_number|bank_account|connection_string|bearer|swift|signature|(?<![a-z0-9])(?:bic|cvv2?|cvc|otp|jwt|dsn|pem|cert)(?![a-z0-9])|auth(?!ors?(?![a-z]))/';

	/**
	 * A name the regex engine gives up on (backtrack limit) counts as
	 * credential-like: the other answer would report its value unmasked.
	 *
	 * @param string $option Option name, array key or field name.
	 */
	public static function is_sensitive( string $option ): bool {
		$name = self::normalize( $option );

		return null === $name || 0 !== preg_match( self::PATTERN, $name );
	}

	/**
	 * One spelling for every way a name is written: `accountNumber`,
	 * `account-number`, `Account Number` and `ACCOUNT_NUMBER` all become
	 * `account_number`. Null when the regex engine gives up on the name.
	 *
	 * @param string $name Option name, array key or header name.
	 */
	private static function normalize( string $name ): ?string {
		$name = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $name );
		if ( null === $name ) {
			return null;
		}
		$name = str_replace( [ '-', ' ' ], '_', $name );

		// Not strtolower(): before PHP 8.2 it follows the locale, which turns an
		// `I` into a dotless `i` in some of them.
		return strtr( $name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}

	/**
	 * Loggable representation of an option value: masked for credential-like
	 * names, the scalar as a string otherwise (with `user:password@` URL parts
	 * and credential-like keys of a JSON text masked, see
	 * {@see ChangeSet::stringify()}), `<complex>` for arrays/objects.
	 *
	 * @param mixed $value
	 */
	public static function value( string $option, $value ): string {
		if ( self::is_sensitive( $option ) ) {
			return self::REDACTED;
		}
		if ( is_string( $value ) ) {
			return ChangeSet::redact_text( $value );
		}

		return is_scalar( $value ) ? (string) $value : '<complex>';
	}
}
