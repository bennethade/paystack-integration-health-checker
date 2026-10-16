<?php
/**
 * Adapter for "manual" diagnostics: keys supplied to the health checker itself.
 *
 * Used when Paystack is integrated without a supported WooCommerce gateway (custom
 * code, another plugin, or no WooCommerce at all). Keys come from PIHC_* constants in
 * wp-config.php (preferred) or the checker's settings. The webhook endpoint of a custom
 * integration is unknown, so webhook endpoint checks are skipped for this adapter.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Integrations;

use PaystackHealthChecker\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Manual configuration adapter.
 */
class Manual_Adapter implements Integration_Adapter {

	public const ID = 'manual';

	/**
	 * Checker settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Checker settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

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
		return __( 'Manual configuration (keys entered in the health checker)', 'paystack-integration-health-checker' );
	}

	/**
	 * Active when a key is configured for the selected mode.
	 */
	public function is_active(): bool {
		return '' !== $this->secret_key();
	}

	/**
	 * Always "installed" (part of this plugin).
	 */
	public function is_installed(): bool {
		return true;
	}

	/**
	 * Not a gateway.
	 */
	public function gateway_id(): ?string {
		return null;
	}

	/**
	 * Not a separate plugin.
	 */
	public function plugin_basename(): ?string {
		return null;
	}

	/**
	 * Not applicable.
	 */
	public function is_enabled(): ?bool {
		return null;
	}

	/**
	 * Mode chosen in the checker's settings.
	 */
	public function mode(): string {
		return $this->settings->manual_mode();
	}

	/**
	 * Secret key.
	 *
	 * @param string|null $mode Environment.
	 */
	public function secret_key( ?string $mode = null ): string {
		return $this->settings->manual_key( 'secret', $mode ?? $this->mode() );
	}

	/**
	 * Public key.
	 *
	 * @param string|null $mode Environment.
	 */
	public function public_key( ?string $mode = null ): string {
		return $this->settings->manual_key( 'public', $mode ?? $this->mode() );
	}

	/**
	 * Whether the administrator said the integration uses Paystack Popup/Inline.
	 */
	public function requires_public_key(): bool {
		return $this->settings->manual_uses_popup();
	}

	/**
	 * Unknown for custom integrations.
	 */
	public function webhook_url(): ?string {
		return null;
	}

	/**
	 * Unknown.
	 */
	public function webhook_kind(): string {
		return self::WEBHOOK_NONE;
	}

	/**
	 * Unknown, reported as false; checks skip webhook endpoint tests for this adapter.
	 */
	public function webhook_handler_registered(): bool {
		return false;
	}

	/**
	 * Checker settings page.
	 */
	public function settings_url(): string {
		return Settings::admin_url( 'configuration' ) . '#pihc-settings';
	}

	/**
	 * No known log sources.
	 *
	 * @return string[]
	 */
	public function log_sources(): array {
		return array();
	}

	/**
	 * No marker.
	 */
	public function log_marker(): string {
		return '';
	}

	/**
	 * Unknown.
	 *
	 * @return string[]|null
	 */
	public function supported_currencies(): ?array {
		return null;
	}

	/**
	 * Unknown.
	 */
	public function order_reference_meta_key(): ?string {
		return null;
	}

	/**
	 * Non-secret settings.
	 *
	 * @return array<string,scalar>
	 */
	public function configuration_summary(): array {
		return array(
			'mode'        => $this->mode(),
			'uses_popup'  => $this->requires_public_key() ? 'yes' : 'no',
			'key_storage' => $this->settings->manual_key_from_constant( 'secret', $this->mode() ) ? 'wp-config constant' : 'health checker settings',
		);
	}
}
