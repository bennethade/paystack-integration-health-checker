<?php
/**
 * Non-sensitive environment snapshot stored with every run and included in reports.
 *
 * Contains versions, the integration label and mode, and booleans. No keys, no user or
 * customer data, and the site host rather than the full URL.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Environment snapshot builder.
 */
final class Environment {

	/**
	 * Builds the snapshot.
	 *
	 * @param Diagnostic_Context $context Context.
	 * @return array<string,scalar|null>
	 */
	public static function snapshot( Diagnostic_Context $context ): array {
		$adapter = $context->adapter();

		return array(
			'checker_version'     => defined( 'PIHC_VERSION' ) ? PIHC_VERSION : 'dev',
			'php_version'         => PHP_VERSION,
			'wordpress_version'   => get_bloginfo( 'version' ),
			'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'woocommerce_active'  => $context->woocommerce_active(),
			'hpos_enabled'        => self::hpos_enabled(),
			'site_host'           => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'site_https'          => 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
			'multisite'           => is_multisite(),
			'integration'         => null !== $adapter ? $adapter->label() : null,
			'integration_id'      => null !== $adapter ? $adapter->id() : null,
			'environment'         => null !== $adapter ? $adapter->mode() : null,
			'key_configured'      => null !== $adapter && '' !== $adapter->secret_key(),
		);
	}

	/**
	 * Whether HPOS is authoritative.
	 */
	public static function hpos_enabled(): ?bool {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return null;
		}
		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
