<?php
/**
 * Adapter for "Paystack WooCommerce Payment Gateway" (wordpress.org slug: woo-paystack).
 *
 * Facts below were taken from the plugin's source, version 5.8.5:
 * - Gateway ID "paystack"; settings option "woocommerce_paystack_settings".
 * - Settings: enabled (default "no"), testmode (default "yes"), payment_page
 *   ("" or "inline" = popup, "redirect" = Paystack redirect), test/live secret and
 *   public keys.
 * - The popup checkout uses the public key; the redirect option calls the API server-side.
 * - Webhook handler: wc-api endpoint "Tbz_WC_Paystack_Webhook" (hook
 *   woocommerce_api_tbz_wc_paystack_webhook), registered in the gateway constructor.
 *   It exits silently when the x-paystack-signature header is missing or does not
 *   match HMAC-SHA512 of the raw body, and returns early for events other than
 *   charge.success.
 * - Supported currencies (filter woocommerce_paystack_supported_currencies):
 *   NGN, USD, ZAR, GHS, KES, XOF, EGP, RWF.
 * - Order meta "_paystack_txn_ref" holds the reference. The plugin does not write to the
 *   WooCommerce logger.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Official Paystack WooCommerce gateway adapter.
 */
class Official_Gateway_Adapter extends Gateway_Adapter {

	public const ID             = 'woo-paystack';
	public const WEBHOOK_ACTION = 'Tbz_WC_Paystack_Webhook';

	/**
	 * Default currency list from woo-paystack 5.8.5.
	 */
	private const DEFAULT_CURRENCIES = array( 'NGN', 'USD', 'ZAR', 'GHS', 'KES', 'XOF', 'EGP', 'RWF' );

	/**
	 * Adapter ID.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Label.
	 */
	public function label(): string {
		return __( 'Paystack WooCommerce Payment Gateway (official plugin)', 'paystack-integration-health-checker' );
	}

	/**
	 * Whether the plugin code is loaded.
	 */
	public function is_active(): bool {
		return class_exists( 'WC_Gateway_Paystack' );
	}

	/**
	 * Gateway ID.
	 */
	public function gateway_id(): ?string {
		return 'paystack';
	}

	/**
	 * Plugin basename.
	 */
	public function plugin_basename(): ?string {
		return 'woo-paystack/woo-paystack.php';
	}

	/**
	 * Defaults from the gateway's form fields.
	 *
	 * @return array<string,string>
	 */
	protected function setting_defaults(): array {
		return array(
			'enabled'      => 'no',
			'testmode'     => 'yes',
			'payment_page' => '',
		);
	}

	/**
	 * Secret key.
	 *
	 * @param string|null $mode Environment.
	 */
	public function secret_key( ?string $mode = null ): string {
		return $this->setting( ( $mode ?? $this->mode() ) . '_secret_key' );
	}

	/**
	 * Public key.
	 *
	 * @param string|null $mode Environment.
	 */
	public function public_key( ?string $mode = null ): string {
		return $this->setting( ( $mode ?? $this->mode() ) . '_public_key' );
	}

	/**
	 * The popup (default) checkout needs the public key; the redirect option does not.
	 */
	public function requires_public_key(): bool {
		return 'redirect' !== $this->setting( 'payment_page' );
	}

	/**
	 * Webhook URL.
	 */
	public function webhook_url(): ?string {
		return function_exists( 'WC' ) ? WC()->api_request_url( self::WEBHOOK_ACTION ) : null;
	}

	/**
	 * Webhook kind.
	 */
	public function webhook_kind(): string {
		return self::WEBHOOK_WC_API;
	}

	/**
	 * Whether the wc-api webhook action is hooked once gateways are loaded.
	 */
	public function webhook_handler_registered(): bool {
		$this->gateway_instance();
		return false !== has_action( 'woocommerce_api_' . strtolower( self::WEBHOOK_ACTION ) );
	}

	/**
	 * The official plugin does not write to the WooCommerce logger.
	 *
	 * @return string[]
	 */
	public function log_sources(): array {
		return array();
	}

	/**
	 * Marker used to find the plugin's fatal errors.
	 */
	public function log_marker(): string {
		return 'woo-paystack';
	}

	/**
	 * Currencies the gateway accepts, through its own filter.
	 *
	 * @return string[]
	 */
	public function supported_currencies(): ?array {
		/** This filter is documented in woo-paystack/includes/class-wc-gateway-paystack.php */
		$currencies = apply_filters( 'woocommerce_paystack_supported_currencies', self::DEFAULT_CURRENCIES ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading the gateway's own filter.
		return array_values( array_map( 'strtoupper', (array) $currencies ) );
	}

	/**
	 * Order meta key holding the reference.
	 */
	public function order_reference_meta_key(): ?string {
		return '_paystack_txn_ref';
	}

	/**
	 * Non-secret settings.
	 *
	 * @return array<string,scalar>
	 */
	public function configuration_summary(): array {
		$page = $this->setting( 'payment_page' );
		return array(
			'gateway_id'   => 'paystack',
			'enabled'      => $this->is_enabled() ? 'yes' : 'no',
			'mode'         => $this->mode(),
			'payment_page' => 'redirect' === $page ? 'redirect' : 'popup (inline)',
		);
	}
}
