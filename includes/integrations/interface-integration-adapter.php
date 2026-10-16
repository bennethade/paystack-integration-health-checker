<?php
/**
 * Read-only view of one Paystack integration on this site.
 *
 * Adapters translate a specific integration's storage and conventions (option names,
 * gateway IDs, webhook endpoints) into one shape the checks can use. Adding support for
 * another Paystack plugin means adding an adapter, not changing checks.
 *
 * Adapters must never modify the integration's settings.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Integration adapter contract.
 */
interface Integration_Adapter {

	public const WEBHOOK_WC_API = 'wc-api';
	public const WEBHOOK_REST   = 'rest';
	public const WEBHOOK_NONE   = 'none';

	/**
	 * Stable adapter ID, e.g. "woo-paystack".
	 */
	public function id(): string;

	/**
	 * Human-readable name.
	 */
	public function label(): string;

	/**
	 * Whether the integration's code is loaded on this request.
	 */
	public function is_active(): bool;

	/**
	 * Whether the plugin is installed (active or not).
	 */
	public function is_installed(): bool;

	/**
	 * WooCommerce gateway ID, or null for non-WooCommerce integrations.
	 */
	public function gateway_id(): ?string;

	/**
	 * Plugin basename (folder/file.php), or null.
	 */
	public function plugin_basename(): ?string;

	/**
	 * Whether the merchant enabled the gateway (null when not applicable).
	 */
	public function is_enabled(): ?bool;

	/**
	 * Active environment: "test" or "live".
	 */
	public function mode(): string;

	/**
	 * Secret key for an environment (defaults to the active one). Never display this.
	 *
	 * @param string|null $mode Environment.
	 */
	public function secret_key( ?string $mode = null ): string;

	/**
	 * Public key for an environment (defaults to the active one).
	 *
	 * @param string|null $mode Environment.
	 */
	public function public_key( ?string $mode = null ): string;

	/**
	 * Whether this integration's current configuration needs a public key.
	 */
	public function requires_public_key(): bool;

	/**
	 * Webhook URL to configure in the Paystack dashboard, or null if unknown.
	 */
	public function webhook_url(): ?string;

	/**
	 * How the webhook endpoint is implemented (WEBHOOK_* constant).
	 */
	public function webhook_kind(): string;

	/**
	 * Whether the webhook handler is registered on this request.
	 */
	public function webhook_handler_registered(): bool;

	/**
	 * Admin URL where the integration is configured.
	 */
	public function settings_url(): string;

	/**
	 * WooCommerce log sources written by this integration.
	 *
	 * @return string[]
	 */
	public function log_sources(): array;

	/**
	 * Text identifying this integration inside generic logs (e.g. fatal-errors).
	 */
	public function log_marker(): string;

	/**
	 * Currencies the integration itself accepts, or null if unknown.
	 *
	 * @return string[]|null
	 */
	public function supported_currencies(): ?array;

	/**
	 * Order meta key where the integration stores the Paystack reference, if known.
	 */
	public function order_reference_meta_key(): ?string;

	/**
	 * Non-secret configuration values worth showing as evidence.
	 *
	 * @return array<string,scalar>
	 */
	public function configuration_summary(): array;
}
