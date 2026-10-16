<?php
/**
 * Adapter for "Gateway for Paystack on WooCommerce" (paystack-woocommerce-gateway).
 *
 * - Gateway ID "pwcg_paystack"; settings option "woocommerce_pwcg_paystack_settings".
 * - Secret keys may come from PWCG_TEST_SECRET_KEY / PWCG_LIVE_SECRET_KEY constants,
 *   which take precedence. Hosted checkout only: no public key is used.
 * - Webhook: REST route POST /pwcg/v1/webhook. Unsigned or wrongly signed requests get
 *   HTTP 401; signed events other than charge.success get HTTP 200 with
 *   {"reason":"unsupported_event","processed":false}.
 * - Logs to the WooCommerce logger with source "pwcg-paystack".
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter for the paystack-woocommerce-gateway plugin.
 */
class Pwcg_Adapter extends Gateway_Adapter {

	public const ID         = 'pwcg';
	public const REST_ROUTE = '/pwcg/v1/webhook';

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
		return __( 'Gateway for Paystack on WooCommerce', 'paystack-integration-health-checker' );
	}

	/**
	 * Whether the plugin code is loaded.
	 */
	public function is_active(): bool {
		return class_exists( '\PaystackWooGateway\Plugin' );
	}

	/**
	 * Gateway ID.
	 */
	public function gateway_id(): ?string {
		return 'pwcg_paystack';
	}

	/**
	 * Plugin basename.
	 */
	public function plugin_basename(): ?string {
		return 'paystack-woocommerce-gateway/paystack-woocommerce-gateway.php';
	}

	/**
	 * Defaults from the gateway's form fields.
	 *
	 * @return array<string,string>
	 */
	protected function setting_defaults(): array {
		return array(
			'enabled'  => 'no',
			'testmode' => 'yes',
		);
	}

	/**
	 * Secret key; wp-config constants take precedence, as in the gateway itself.
	 *
	 * @param string|null $mode Environment.
	 */
	public function secret_key( ?string $mode = null ): string {
		$mode     = $mode ?? $this->mode();
		$constant = 'live' === $mode ? 'PWCG_LIVE_SECRET_KEY' : 'PWCG_TEST_SECRET_KEY';
		if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== constant( $constant ) ) {
			return trim( (string) constant( $constant ) );
		}
		return $this->setting( $mode . '_secret_key' );
	}

	/**
	 * Not used by this integration.
	 *
	 * @param string|null $mode Environment.
	 */
	public function public_key( ?string $mode = null ): string {
		unset( $mode );
		return '';
	}

	/**
	 * Hosted checkout needs no public key.
	 */
	public function requires_public_key(): bool {
		return false;
	}

	/**
	 * Webhook URL.
	 */
	public function webhook_url(): ?string {
		return rest_url( ltrim( self::REST_ROUTE, '/' ) );
	}

	/**
	 * Webhook kind.
	 */
	public function webhook_kind(): string {
		return self::WEBHOOK_REST;
	}

	/**
	 * Whether the REST route is registered.
	 */
	public function webhook_handler_registered(): bool {
		return array_key_exists( self::REST_ROUTE, rest_get_server()->get_routes() );
	}

	/**
	 * Log sources.
	 *
	 * @return string[]
	 */
	public function log_sources(): array {
		return array( 'pwcg-paystack' );
	}

	/**
	 * Marker used to find the plugin's fatal errors.
	 */
	public function log_marker(): string {
		return 'paystack-woocommerce-gateway';
	}

	/**
	 * Currencies accepted by the gateway.
	 *
	 * @return string[]|null
	 */
	public function supported_currencies(): ?array {
		return class_exists( '\PaystackWooGateway\Currency' ) ? \PaystackWooGateway\Currency::supported_codes() : null;
	}

	/**
	 * Order meta key holding the reference.
	 */
	public function order_reference_meta_key(): ?string {
		return '_pwcg_reference';
	}

	/**
	 * Non-secret settings.
	 *
	 * @return array<string,scalar>
	 */
	public function configuration_summary(): array {
		return array(
			'gateway_id'  => 'pwcg_paystack',
			'enabled'     => $this->is_enabled() ? 'yes' : 'no',
			'mode'        => $this->mode(),
			'checkout'    => 'hosted redirect',
			'key_storage' => defined( 'test' === $this->mode() ? 'PWCG_TEST_SECRET_KEY' : 'PWCG_LIVE_SECRET_KEY' ) ? 'wp-config constant' : 'database option',
		);
	}
}
