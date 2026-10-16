<?php
/**
 * Shared behaviour for adapters backed by a WooCommerce gateway plugin.
 *
 * Settings are read from the gateway's own option ("woocommerce_{gateway_id}_settings")
 * with the gateway's documented defaults; the gateway object is never asked to save.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for WooCommerce gateway adapters.
 */
abstract class Gateway_Adapter implements Integration_Adapter {

	/**
	 * Gateway setting defaults (mirrors the gateway's own form-field defaults).
	 *
	 * @return array<string,string>
	 */
	abstract protected function setting_defaults(): array;

	/**
	 * Raw stored settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	protected function settings(): array {
		$stored = get_option( 'woocommerce_' . $this->gateway_id() . '_settings', array() );
		return array_merge( $this->setting_defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One setting value.
	 *
	 * @param string $key Setting key.
	 */
	protected function setting( string $key ): string {
		$settings = $this->settings();
		return isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
	}

	/**
	 * Whether the plugin is installed (active or not).
	 */
	public function is_installed(): bool {
		$basename = $this->plugin_basename();
		return null !== $basename && file_exists( WP_PLUGIN_DIR . '/' . $basename );
	}

	/**
	 * Whether the gateway is enabled in WooCommerce.
	 */
	public function is_enabled(): ?bool {
		return 'yes' === $this->setting( 'enabled' );
	}

	/**
	 * Active environment.
	 */
	public function mode(): string {
		return 'no' === $this->setting( 'testmode' ) ? 'live' : 'test';
	}

	/**
	 * Settings URL.
	 */
	public function settings_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . rawurlencode( (string) $this->gateway_id() ) );
	}

	/**
	 * The gateway instance WooCommerce built, if WooCommerce is loaded.
	 *
	 * Instantiating gateways is what registers their wc-api hooks, so handler checks
	 * call this first. It does not change any setting.
	 */
	protected function gateway_instance(): ?\WC_Payment_Gateway {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways[ (string) $this->gateway_id() ] ?? null;
		return $gateway instanceof \WC_Payment_Gateway ? $gateway : null;
	}

	/**
	 * Header data of the gateway plugin (Version, WC requires at least, WC tested up to).
	 *
	 * @return array<string,string>
	 */
	public function plugin_headers(): array {
		if ( ! $this->is_installed() ) {
			return array();
		}
		return get_file_data(
			WP_PLUGIN_DIR . '/' . $this->plugin_basename(),
			array(
				'version'      => 'Version',
				'requires_wc'  => 'WC requires at least',
				'tested_wc'    => 'WC tested up to',
				'requires_php' => 'Requires PHP',
				'requires_wp'  => 'Requires at least',
			)
		);
	}
}
