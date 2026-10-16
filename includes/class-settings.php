<?php
/**
 * The health checker's own settings (option "pihc_settings").
 *
 * The checker reads gateway settings through adapters and never writes them. Its own
 * settings only cover: which integration to diagnose, optional manual keys for sites
 * without a supported gateway, and history retention.
 *
 * Manual keys can (and preferably should) be defined in wp-config.php instead:
 *   PIHC_TEST_SECRET_KEY, PIHC_LIVE_SECRET_KEY, PIHC_TEST_PUBLIC_KEY, PIHC_LIVE_PUBLIC_KEY
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Settings accessor and sanitizer.
 */
class Settings {

	public const OPTION    = 'pihc_settings';
	public const PAGE_SLUG = 'pihc-health-checker';
	public const GROUP     = 'pihc_settings_group';

	public const DEFAULT_RETENTION_RUNS = 50;
	public const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Values.
	 *
	 * @var array<string,mixed>
	 */
	private $values;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>|null $values Values (null loads the option).
	 */
	public function __construct( ?array $values = null ) {
		if ( null === $values ) {
			$stored = get_option( self::OPTION, array() );
			$values = is_array( $stored ) ? $stored : array();
		}
		$this->values = array_merge( self::defaults(), $values );
	}

	/**
	 * Defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'integration'            => 'auto',
			'manual_mode'            => 'test',
			'manual_test_secret_key' => '',
			'manual_live_secret_key' => '',
			'manual_test_public_key' => '',
			'manual_live_public_key' => '',
			'manual_uses_popup'      => false,
			'retention_runs'         => self::DEFAULT_RETENTION_RUNS,
			'retention_days'         => self::DEFAULT_RETENTION_DAYS,
		);
	}

	/**
	 * Admin page URL for a tab.
	 *
	 * @param string $tab Tab slug.
	 */
	public static function admin_url( string $tab = 'overview' ): string {
		$parent = class_exists( 'WooCommerce' ) ? 'admin.php' : 'tools.php';
		return admin_url( $parent . '?page=' . self::PAGE_SLUG . '&tab=' . rawurlencode( $tab ) );
	}

	/**
	 * Integration to diagnose ("auto" or an adapter ID).
	 */
	public function integration(): string {
		$value = (string) $this->values['integration'];
		return preg_match( '/^[a-z0-9_-]{1,40}$/', $value ) ? $value : 'auto';
	}

	/**
	 * Manual mode.
	 */
	public function manual_mode(): string {
		return 'live' === $this->values['manual_mode'] ? 'live' : 'test';
	}

	/**
	 * Whether the manual integration uses Paystack Popup/Inline (needs a public key).
	 */
	public function manual_uses_popup(): bool {
		return (bool) $this->values['manual_uses_popup'];
	}

	/**
	 * Manual key, constant first.
	 *
	 * @param string $type "secret" or "public".
	 * @param string $mode "test" or "live".
	 */
	public function manual_key( string $type, string $mode ): string {
		$constant = self::constant_name( $type, $mode );
		if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== constant( $constant ) ) {
			return trim( (string) constant( $constant ) );
		}
		return trim( (string) ( $this->values[ 'manual_' . $mode . '_' . $type . '_key' ] ?? '' ) );
	}

	/**
	 * Whether a manual key comes from wp-config.php.
	 *
	 * @param string $type "secret" or "public".
	 * @param string $mode "test" or "live".
	 */
	public function manual_key_from_constant( string $type, string $mode ): bool {
		$constant = self::constant_name( $type, $mode );
		return defined( $constant ) && is_string( constant( $constant ) ) && '' !== constant( $constant );
	}

	/**
	 * Maximum stored runs.
	 */
	public function retention_runs(): int {
		return max( 5, min( 500, (int) $this->values['retention_runs'] ) );
	}

	/**
	 * Maximum age of stored runs in days.
	 */
	public function retention_days(): int {
		return max( 1, min( 365, (int) $this->values['retention_days'] ) );
	}

	/**
	 * Every key value known to the checker, for exact-value redaction.
	 *
	 * @return string[]
	 */
	public function all_manual_keys(): array {
		$keys = array();
		foreach ( array( 'secret', 'public' ) as $type ) {
			foreach ( array( 'test', 'live' ) as $mode ) {
				$keys[] = $this->manual_key( $type, $mode );
			}
		}
		return array_values( array_filter( $keys ) );
	}

	/**
	 * Sanitize callback for register_setting(). Blank key fields keep the stored value,
	 * so saved secrets never need to be rendered back into the form.
	 *
	 * @param mixed $input Submitted values.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$previous = ( new self() )->values;
		$clean    = self::defaults();

		$integration          = sanitize_key( (string) ( $input['integration'] ?? 'auto' ) );
		$clean['integration'] = '' === $integration ? 'auto' : $integration;
		$clean['manual_mode'] = 'live' === ( $input['manual_mode'] ?? 'test' ) ? 'live' : 'test';

		$clean['manual_uses_popup'] = ! empty( $input['manual_uses_popup'] );

		foreach ( array( 'secret', 'public' ) as $type ) {
			foreach ( array( 'test', 'live' ) as $mode ) {
				$field = 'manual_' . $mode . '_' . $type . '_key';
				$value = isset( $input[ $field ] ) ? trim( sanitize_text_field( wp_unslash( (string) $input[ $field ] ) ) ) : '';
				if ( ! empty( $input[ 'clear_' . $field ] ) ) {
					$clean[ $field ] = '';
				} elseif ( '' === $value ) {
					$clean[ $field ] = (string) ( $previous[ $field ] ?? '' );
				} elseif ( preg_match( '/^[A-Za-z0-9_]{8,200}$/', $value ) ) {
					// Format is checked by diagnostics, not here: an unexpected prefix is reported, not silently dropped.
					$clean[ $field ] = $value;
				} else {
					$clean[ $field ] = (string) ( $previous[ $field ] ?? '' );
					add_settings_error( self::OPTION, 'pihc_invalid_' . $field, __( 'A key contained unexpected characters and was not saved.', 'paystack-integration-health-checker' ) );
				}
			}
		}

		$clean['retention_runs'] = max( 5, min( 500, absint( $input['retention_runs'] ?? self::DEFAULT_RETENTION_RUNS ) ) );
		$clean['retention_days'] = max( 1, min( 365, absint( $input['retention_days'] ?? self::DEFAULT_RETENTION_DAYS ) ) );

		return $clean;
	}

	/**
	 * Constant name for a manual key.
	 *
	 * @param string $type "secret" or "public".
	 * @param string $mode "test" or "live".
	 */
	private static function constant_name( string $type, string $mode ): string {
		return 'PIHC_' . strtoupper( 'live' === $mode ? 'live' : 'test' ) . '_' . strtoupper( 'public' === $type ? 'public' : 'secret' ) . '_KEY';
	}
}
