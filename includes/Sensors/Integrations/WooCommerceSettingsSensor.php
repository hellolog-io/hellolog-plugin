<?php
/**
 * WooCommerce: settings options changes.
 *
 * @package HelloLog
 */

declare(strict_types=1);

namespace HelloLog\Sensors\Integrations;

use HelloLog\Events\ChangeSet;
use HelloLog\Sensors\AbstractSensor;
use HelloLog\Sensors\OptionChangePolicy;
use HelloLog\Sensors\SensitiveOption;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Logs option mutations from WC's General / Shipping / Tax / Payments
 * screens. Listens to `updated_option` and filters by the `woocommerce_`
 * prefix so we don't echo every core option. Only a person saving a setting
 * is logged, see {@see OptionChangePolicy}.
 *
 * A setting that holds keyed values (a payment gateway's settings) tells
 * which of its keys changed, see {@see self::setting_changes()}. The bank
 * accounts of the bank transfer (BACS) gateway are one critical event of
 * their own (9401) that names each account by the last four digits of its
 * number, see {@see self::bacs_changes()}.
 *
 * The bank accounts are logged whoever writes them: the shop itself only
 * writes them for a person on its settings screen or REST API, so a write
 * from a visitor's request or a background run (an exploit, a backdoor
 * swapping the IBAN) is exactly what the alert is for. Such an event carries
 * its source and no actor, like any other.
 *
 * The first save of a payment option creates it, which only `added_option`
 * tells: those first writes are logged too (with no old value), outside the
 * shop's installer. Other shop options are added on the fly by the shop
 * itself, so their first write is not logged.
 */
final class WooCommerceSettingsSensor extends AbstractSensor {

	/** The option that holds the bank accounts of the bank transfer gateway. */
	public const BACS_ACCOUNTS = 'woocommerce_bacs_accounts';

	/** Fired by the shop once its payment gateways are loaded. */
	private const GATEWAYS_LOADED = 'wc_payment_gateways_initialized';

	/**
	 * The fields of a bank account, in the order the gateway's screen shows
	 * them. The changes of an account follow this order.
	 *
	 * @var array<int, string>
	 */
	private const ACCOUNT_FIELDS = [ 'account_name', 'account_number', 'bank_name', 'sort_code', 'iban', 'bic' ];

	/**
	 * The fields of a bank account that are bank codes: compared the way the
	 * accounts are matched, without spaces, dashes or case, and never shown.
	 *
	 * @var array<int, string>
	 */
	private const CODE_FIELDS = [ 'account_number', 'sort_code', 'iban', 'bic' ];

	/** How an account with neither a number nor an IBAN is named. */
	private const NO_NUMBER = 'no number';

	public function key(): string {
		return 'woocommerce-settings';
	}

	public function should_load(): bool {
		return class_exists( 'WooCommerce', false );
	}

	public function boot(): void {
		add_action( 'updated_option', [ $this, 'on_update' ], 10, 3 );
		add_action( 'added_option', [ $this, 'on_add' ], 10, 2 );
	}

	/**
	 * An option was updated.
	 *
	 * @param mixed $option    Option name.
	 * @param mixed $old_value Value before the write.
	 * @param mixed $value     Value after it.
	 */
	public function on_update( $option, $old_value, $value ): void {
		$name = '';
		// Reading the values of a write can fail (an object whose text throws);
		// that must never reach the request that saved the setting.
		try {
			$name = is_scalar( $option ) ? (string) $option : '';
			if ( ! str_starts_with( $name, 'woocommerce_' ) || $old_value === $value ) {
				return;
			}
			if ( self::BACS_ACCOUNTS === $name ) {
				$this->log_bank_accounts( $old_value, $value );
				return;
			}
			if ( ! OptionChangePolicy::should_log_current( $name ) ) {
				return;
			}

			$this->log_setting( $name, $old_value, $value );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, self::code_for( $name ), [ 'option' => $name ] );
		}
	}

	/**
	 * An option was written for the first time. Logged for payment options
	 * only, and not while the shop's installer adds its defaults (on an
	 * upgrade too). The policy is asked before the gateway registry: most
	 * first writes are not a person's and need no registry walk.
	 *
	 * @param mixed $option Option name.
	 * @param mixed $value  Its first value.
	 */
	public function on_add( $option, $value ): void {
		$name = '';
		try {
			$name = is_scalar( $option ) ? (string) $option : '';
			if ( ! str_starts_with( $name, 'woocommerce_' ) || self::is_installing() ) {
				return;
			}
			if ( self::BACS_ACCOUNTS === $name ) {
				$this->log_bank_accounts( null, $value );
				return;
			}
			if ( ! OptionChangePolicy::should_log_current( $name ) || ! self::is_gateway_option( $name ) ) {
				return;
			}

			$this->log_setting( $name, null, $value );
		} catch ( Throwable $e ) {
			$this->report_failure( $e, self::code_for( $name ), [ 'option' => $name ] );
		}
	}

	/**
	 * What a write to a setting tells, old to new.
	 *
	 * A setting that holds keyed values on both sides (a missing or empty side
	 * counts as none) tells each key that changed as a field of its own,
	 * compared by {@see ChangeSet::diff()}: a credential-like key reads
	 * `[redacted]`, a structure under a key is one value with the secrets
	 * inside it masked, and a key that is empty on both sides (missing, `''`
	 * or `[]`) is not a change. A key with a leading underscore is the
	 * gateway's own bookkeeping (PayPal Standard's `_should_load`, which the
	 * shop writes itself) and is left out. Everything else is one change named after the
	 * option, see {@see OptionChangePolicy::changes()}: a list (which is a set,
	 * so a reorder is no change), a plain value, a setting whose own name
	 * looks like a credential (masked whole), and a setting that is itself a
	 * name/value pair holding a secret (telling its keys one by one would show
	 * the secret's value).
	 *
	 * @param string $option Option name.
	 * @param mixed  $old    Value before the write; null when the option did not exist.
	 * @param mixed  $new    Value after it.
	 *
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function setting_changes( string $option, $old, $new ): array {
		if ( SensitiveOption::is_sensitive( $option ) || ! self::are_keyed( $old, $new ) ) {
			return OptionChangePolicy::changes( $option, $old, $new );
		}

		return self::key_changes( self::without_bookkeeping( $old ), self::without_bookkeeping( $new ) );
	}

	/**
	 * What changed in the bank accounts of the bank transfer gateway.
	 *
	 * The accounts are matched across the two lists, so a reorder is no
	 * change: first an unchanged account, then one with the same account
	 * number, then one with the same IBAN (both compared without spaces,
	 * dashes or case, and only through a hash of the number), and last an
	 * account with neither number to one that has none either.
	 *
	 * An account is named `account:…1234`, by the last four digits of the
	 * number it was matched by (its account number, else its IBAN); a name
	 * that several accounts share gets their place among them (`…1234 #2`),
	 * and an account with neither number is `account:no number`. The full
	 * number never appears.
	 *
	 * - An added account: `account:…1234`, null → `added`.
	 * - A removed one: `account:…1234`, `present` → null.
	 * - A changed one: one change per field, `account:…1234.bank_name`. The
	 *   bank and the holder's name are told old → new; the account number,
	 *   sort code, IBAN and BIC are credentials, told as `[redacted]` on both
	 *   sides, and compared without spaces, dashes or case, so writing one
	 *   another way is no change.
	 *
	 * Removed accounts come first, then the changes of the kept ones, then
	 * the added ones, each in list order: a payload keeps a limited number of
	 * changes, and many added accounts must not push out the change of an
	 * existing one. Entries that are not arrays are ignored.
	 *
	 * @param array<int|string, mixed> $old Accounts before the write.
	 * @param array<int|string, mixed> $new Accounts after it.
	 *
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	public static function bacs_changes( array $old, array $new ): array {
		$before = self::accounts( $old );
		$after  = self::accounts( $new );
		$pairs  = self::pair_accounts( $before, $after );

		$paired = [];
		foreach ( $pairs as [ $index ] ) {
			$paired[ $index ] = true;
		}

		// Every account of either list once, each with the digits it is named
		// by: removed, kept, added.
		$entries = [];
		foreach ( $before as $index => $account ) {
			if ( ! isset( $paired[ $index ] ) ) {
				$entries[] = [ self::preferred_tail( $account ), $account, null ];
			}
		}
		foreach ( $after as $index => $account ) {
			if ( isset( $pairs[ $index ] ) ) {
				[ $old_index, $via ] = $pairs[ $index ];
				$tail                = '' === $via ? self::preferred_tail( $account ) : $account['tails'][ $via ];
				$entries[]           = [ $tail, $before[ $old_index ], $account ];
			}
		}
		foreach ( $after as $index => $account ) {
			if ( ! isset( $pairs[ $index ] ) ) {
				$entries[] = [ self::preferred_tail( $account ), null, $account ];
			}
		}

		$shared = [];
		foreach ( $entries as [ $tail ] ) {
			$shared[ $tail ] = ( $shared[ $tail ] ?? 0 ) + 1;
		}

		$changes = [];
		$place   = [];
		foreach ( $entries as [ $tail, $was, $is ] ) {
			$name = 'account:' . ( '' === $tail ? self::NO_NUMBER : '…' . $tail );
			if ( $shared[ $tail ] > 1 ) {
				$place[ $tail ] = ( $place[ $tail ] ?? 0 ) + 1;
				$name          .= ' #' . $place[ $tail ];
			}

			if ( null === $is ) {
				$changes[] = self::change( $name, 'present', null );
			} elseif ( null === $was ) {
				$changes[] = self::change( $name, null, 'added' );
			} else {
				foreach ( self::account_changes( $was['fields'], $is['fields'] ) as $change ) {
					$changes[] = self::change( $name . '.' . $change['field'], $change['old'], $change['new'] );
				}
			}
		}

		return $changes;
	}

	/**
	 * Log a write to the bank accounts as 9401, whoever made it (see the
	 * class description). No old/new texts in the metadata: the accounts are
	 * only ever told through the masked changes.
	 *
	 * @param mixed $old_value Accounts before the write; null when the option did not exist.
	 * @param mixed $value     Accounts after it.
	 */
	private function log_bank_accounts( $old_value, $value ): void {
		$changes = self::bacs_changes( is_array( $old_value ) ? $old_value : [], is_array( $value ) ? $value : [] );
		if ( [] === $changes ) {
			return;
		}

		$this->emit(
			9401,
			[
				'target'   => OptionChangePolicy::target( self::BACS_ACCOUNTS ),
				'changes'  => $changes,
				'metadata' => array_merge( [ 'option' => self::BACS_ACCOUNTS ], OptionChangePolicy::request_metadata() ),
			]
		);
	}

	/**
	 * Log a write to a setting a person saved as 9400.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Value before the write; null when the option did not exist.
	 * @param mixed  $value     Value after it.
	 */
	private function log_setting( string $option, $old_value, $value ): void {
		$changes = self::setting_changes( $option, $old_value, $value );
		if ( [] === $changes ) {
			return;
		}

		$this->emit(
			9400,
			[
				'option'   => $option,
				'target'   => OptionChangePolicy::target( $option ),
				'changes'  => $changes,
				'metadata' => array_merge(
					[
						'option' => $option,
						'old'    => null === $old_value ? null : SensitiveOption::value( $option, $old_value ),
						'new'    => SensitiveOption::value( $option, $value ),
					],
					OptionChangePolicy::request_metadata()
				),
			]
		);
	}

	/**
	 * The event a write to the option is logged as.
	 *
	 * @param string $option Option name.
	 */
	private static function code_for( string $option ): int {
		return self::BACS_ACCOUNTS === $option ? 9401 : 9400;
	}

	/**
	 * Whether the shop's installer is running: it adds every default setting,
	 * on activation and on every upgrade, and marks the whole request.
	 */
	private static function is_installing(): bool {
		return defined( 'WC_INSTALLING' ) && WC_INSTALLING;
	}

	/**
	 * Whether the option holds the settings of a payment gateway.
	 *
	 * The gateways name their option themselves (`get_option_key()`), and
	 * that is only asked once the shop has loaded them: loading them here
	 * would build every gateway, and a gateway being built may write its own
	 * settings (a write that is the shop's, not a person's). While they are
	 * being loaded the registry is not complete and is not asked either.
	 *
	 * @param string $option Option name.
	 */
	private static function is_gateway_option( string $option ): bool {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'did_action' ) || did_action( self::GATEWAYS_LOADED ) < 1 ) {
			return false;
		}
		if ( function_exists( 'doing_action' ) && doing_action( self::GATEWAYS_LOADED ) ) {
			return false;
		}

		$shop     = WC();
		$registry = is_object( $shop ) && method_exists( $shop, 'payment_gateways' ) ? $shop->payment_gateways() : null;
		$gateways = is_object( $registry ) && method_exists( $registry, 'payment_gateways' ) ? $registry->payment_gateways() : [];
		foreach ( is_array( $gateways ) ? $gateways : [] as $gateway ) {
			if ( is_object( $gateway ) && method_exists( $gateway, 'get_option_key' ) && $gateway->get_option_key() === $option ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether both sides of a write are keyed values or nothing, and at
	 * least one of them holds keys.
	 *
	 * @param mixed $old Value before the write.
	 * @param mixed $new Value after it.
	 */
	private static function are_keyed( $old, $new ): bool {
		$keyed = false;
		foreach ( [ $old, $new ] as $side ) {
			if ( self::is_blank( $side ) ) {
				continue;
			}
			if ( ! is_array( $side ) || self::is_list( $side ) || self::is_secret_pair( $side ) ) {
				return false;
			}
			$keyed = true;
		}

		return $keyed;
	}

	/**
	 * The keys that changed between two keyed values, `$leading` first, then
	 * every other key in the order of the old value and then the new one. A
	 * key empty on both sides is skipped.
	 *
	 * @param array<int|string, mixed> $old     Value before the write.
	 * @param array<int|string, mixed> $new     Value after it.
	 * @param array<int, string>       $leading Keys to compare first.
	 *
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	private static function key_changes( array $old, array $new, array $leading = [] ): array {
		$fields = [];
		foreach ( array_merge( $leading, array_keys( $old + $new ) ) as $key ) {
			if ( ! self::is_blank( $old[ $key ] ?? null ) || ! self::is_blank( $new[ $key ] ?? null ) ) {
				$fields[] = $key;
			}
		}

		return ChangeSet::diff( $old, $new, $fields );
	}

	/**
	 * The changes between two versions of one bank account. The bank codes
	 * are compared by the hash of their plain form (see
	 * {@see self::identify()}), so only a different code is a change; they
	 * are reported masked whatever they hold.
	 *
	 * @param array<int|string, mixed> $old The account before the write.
	 * @param array<int|string, mixed> $new The account after it.
	 *
	 * @return array<int, array{field: string, old: ?string, new: ?string}>
	 */
	private static function account_changes( array $old, array $new ): array {
		return self::key_changes( self::comparable( $old ), self::comparable( $new ), self::ACCOUNT_FIELDS );
	}

	/**
	 * An account with each bank code that is text or a number replaced by
	 * the hash of its plain form (empty when it has none).
	 *
	 * @param array<int|string, mixed> $account Account as stored.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function comparable( array $account ): array {
		foreach ( self::CODE_FIELDS as $field ) {
			if ( isset( $account[ $field ] ) && ( is_string( $account[ $field ] ) || is_int( $account[ $field ] ) ) ) {
				$account[ $field ] = self::identify( $account[ $field ] )[0];
			}
		}

		return $account;
	}

	/**
	 * A setting's keyed values without the keys that start with `_`; an
	 * empty array for a side that holds none.
	 *
	 * @param mixed $value One side of a write.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function without_bookkeeping( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$kept = [];
		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, '_' ) ) {
				$kept[ $key ] = $item;
			}
		}

		return $kept;
	}

	/**
	 * The accounts of a list, each with the hashes of its account number and
	 * IBAN (empty when it has none) and their last four digits.
	 *
	 * @param array<int|string, mixed> $list Accounts as stored.
	 *
	 * @return array<int, array{fields: array<int|string, mixed>, keys: array{number: string, iban: string}, tails: array{number: string, iban: string}}>
	 */
	private static function accounts( array $list ): array {
		$accounts = [];
		foreach ( $list as $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}

			[ $number_key, $number_tail ] = self::identify( $account['account_number'] ?? null );
			[ $iban_key, $iban_tail ]     = self::identify( $account['iban'] ?? null );

			$accounts[] = [
				'fields' => $account,
				'keys'   => [
					'number' => $number_key,
					'iban'   => $iban_key,
				],
				'tails'  => [
					'number' => $number_tail,
					'iban'   => $iban_tail,
				],
			];
		}

		return $accounts;
	}

	/**
	 * Match the accounts of the new list to those of the old one, each at
	 * most once: unchanged accounts first, then by account number, then by
	 * IBAN, then accounts without either number to each other, in order.
	 *
	 * @param array<int, array{fields: array<int|string, mixed>, keys: array{number: string, iban: string}, tails: array{number: string, iban: string}}> $before Old accounts.
	 * @param array<int, array{fields: array<int|string, mixed>, keys: array{number: string, iban: string}, tails: array{number: string, iban: string}}> $after  New accounts.
	 *
	 * @return array<int, array{0: int, 1: string}> New index => [ old index, the number both share: `number`, `iban` or '' ].
	 */
	private static function pair_accounts( array $before, array $after ): array {
		$same_number = static fn( string $kind ): \Closure => static fn( array $was, array $is ): bool => '' !== $is['keys'][ $kind ] && $is['keys'][ $kind ] === $was['keys'][ $kind ];

		$passes = [
			[ '', static fn( array $was, array $is ): bool => [] === self::account_changes( $was['fields'], $is['fields'] ) ],
			[ 'number', $same_number( 'number' ) ],
			[ 'iban', $same_number( 'iban' ) ],
			[ '', static fn( array $was, array $is ): bool => self::has_no_number( $was ) && self::has_no_number( $is ) ],
		];

		$pairs = [];
		$taken = [];
		foreach ( $passes as [ $via, $matches ] ) {
			foreach ( $after as $index => $account ) {
				if ( isset( $pairs[ $index ] ) ) {
					continue;
				}
				foreach ( $before as $old_index => $old_account ) {
					if ( ! isset( $taken[ $old_index ] ) && $matches( $old_account, $account ) ) {
						$pairs[ $index ]     = [ $old_index, $via ];
						$taken[ $old_index ] = true;
						break;
					}
				}
			}
		}

		return $pairs;
	}

	/**
	 * The hash of a bank code written without spaces, dashes or case, and
	 * its last four digits (its last four characters when it has no digits).
	 * Both empty when there is no code. The hash is only ever compared,
	 * never sent.
	 *
	 * @param mixed $number Account number, IBAN, sort code or BIC as stored.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function identify( $number ): array {
		if ( ! is_string( $number ) && ! is_int( $number ) ) {
			return [ '', '' ];
		}

		// Not strtoupper(): before PHP 8.2 it follows the locale.
		$plain = strtr(
			(string) preg_replace( '/[^A-Za-z0-9]+/', '', (string) $number ),
			'abcdefghijklmnopqrstuvwxyz',
			'ABCDEFGHIJKLMNOPQRSTUVWXYZ'
		);
		if ( '' === $plain ) {
			return [ '', '' ];
		}

		$digits = (string) preg_replace( '/[^0-9]+/', '', $plain );

		return [ hash( 'sha256', $plain ), substr( '' === $digits ? $plain : $digits, -4 ) ];
	}

	/**
	 * The digits an account is named by on its own: those of its account
	 * number, else those of its IBAN, else none.
	 *
	 * @param array{tails: array{number: string, iban: string}} $account Account.
	 */
	private static function preferred_tail( array $account ): string {
		return '' !== $account['tails']['number'] ? $account['tails']['number'] : $account['tails']['iban'];
	}

	/**
	 * @param array{keys: array{number: string, iban: string}} $account Account.
	 */
	private static function has_no_number( array $account ): bool {
		return '' === $account['keys']['number'] && '' === $account['keys']['iban'];
	}

	/**
	 * Whether a value counts as nothing: missing, null, `''` or `[]`.
	 *
	 * @param mixed $value Any value.
	 */
	private static function is_blank( $value ): bool {
		return null === $value || '' === $value || [] === $value;
	}

	/**
	 * Whether the keys are exactly 0..n-1 in order. `array_is_list()` needs
	 * PHP 8.1.
	 *
	 * @param array<int|string, mixed> $data Array to inspect.
	 */
	private static function is_list( array $data ): bool {
		$expected = 0;
		foreach ( $data as $key => $unused ) {
			if ( $key !== $expected++ ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether an array is a name/value pair that holds a secret: a `value`
	 * next to a credential-like `name`, `key` or `id`, the way
	 * {@see ChangeSet} reads it.
	 *
	 * @param array<int|string, mixed> $data Array to inspect.
	 */
	private static function is_secret_pair( array $data ): bool {
		if ( ! array_key_exists( 'value', $data ) ) {
			return false;
		}
		foreach ( [ 'name', 'key', 'id' ] as $name_key ) {
			if ( isset( $data[ $name_key ] ) && is_string( $data[ $name_key ] ) && SensitiveOption::is_sensitive( $data[ $name_key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{field: string, old: ?string, new: ?string}
	 */
	private static function change( string $field, ?string $old, ?string $new ): array {
		return [
			'field' => $field,
			'old'   => $old,
			'new'   => $new,
		];
	}
}
