<?php
/**
 * Removes secrets and personal data from text and structured evidence.
 *
 * Applied twice (defence in depth): when a result is stored, and again when a report
 * is exported. Redaction is by key name (secret, authorization, signature, email, ...),
 * by pattern (Paystack key formats, bearer tokens, e-mail addresses, card-like digit
 * runs) and by exact value for every key currently configured on the site.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Redaction utility.
 */
class Redactor {

	public const REDACTED = '[redacted]';

	/**
	 * Keys whose values are always removed, whatever they contain.
	 */
	private const SENSITIVE_KEY_PATTERN = '/(secret|password|passwd|authorization|auth_header|bearer|token|signature|api_?key|private|cookie|card|cvv|pan\b|email|phone|billing|shipping|customer|ip_address|order_key)/i';

	/**
	 * Exact secret values known at runtime (configured keys).
	 *
	 * @var string[]
	 */
	private $known_secrets = array();

	/**
	 * Constructor.
	 *
	 * @param string[] $known_secrets Secret values to scrub wherever they appear.
	 */
	public function __construct( array $known_secrets = array() ) {
		foreach ( $known_secrets as $secret ) {
			$secret = (string) $secret;
			if ( strlen( $secret ) >= 8 ) {
				$this->known_secrets[] = $secret;
			}
		}
	}

	/**
	 * Recursively redacts an array.
	 *
	 * @param array<mixed> $data Data.
	 * @return array<mixed>
	 */
	public function redact_array( array $data ): array {
		$clean = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && preg_match( self::SENSITIVE_KEY_PATTERN, $key ) ) {
				$clean[ $key ] = self::REDACTED;
			} elseif ( is_array( $value ) ) {
				$clean[ $key ] = $this->redact_array( $value );
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = $this->redact_string( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$clean[ $key ] = $value;
			} else {
				$clean[ $key ] = '[' . gettype( $value ) . ']';
			}
		}
		return $clean;
	}

	/**
	 * Redacts free text.
	 *
	 * @param string $text Text.
	 */
	public function redact_string( string $text ): string {
		foreach ( $this->known_secrets as $secret ) {
			$text = str_replace( $secret, self::REDACTED, $text );
		}

		// Paystack keys: sk_test_..., sk_live_..., pk_test_..., pk_live_... (and any other sk_/pk_ token).
		$text = (string) preg_replace( '/\b(sk|pk)_[A-Za-z0-9_]{6,}/', '$1_' . self::REDACTED, $text );
		$text = (string) preg_replace( '/\bBearer\s+\S+/i', 'Bearer ' . self::REDACTED, $text );
		$text = (string) preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $text );
		// 13-19 digit runs could be card numbers (PAN); never keep them.
		$text = (string) preg_replace( '/\b\d{13,19}\b/', '[number]', $text );

		return $text;
	}

	/**
	 * Masks a key for display: keeps the documented prefix and the last four characters.
	 *
	 * @param string $key Key.
	 */
	public static function mask_key( string $key ): string {
		$key = trim( $key );
		if ( '' === $key ) {
			return '';
		}
		$prefix = preg_match( '/^(sk|pk)_(test|live)_/', $key, $m ) ? $m[0] : '';
		$tail   = strlen( $key ) > 12 ? substr( $key, -4 ) : '';
		return $prefix . '…' . $tail;
	}
}
