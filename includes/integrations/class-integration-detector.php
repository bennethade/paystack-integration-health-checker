<?php
/**
 * Finds the Paystack integrations on this site and chooses the one to diagnose.
 *
 * Selection ("Integration to diagnose" setting):
 * - A specific adapter ID: that adapter, if it is active.
 * - "auto": the first active gateway adapter whose gateway is enabled, then the first
 *   active gateway adapter, then the manual adapter if keys are configured.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Integrations;

use PaystackHealthChecker\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Integration discovery.
 */
class Integration_Detector {

	/**
	 * Known adapters, in priority order.
	 *
	 * @var Integration_Adapter[]
	 */
	private $adapters;

	/**
	 * Checker settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings                   $settings Settings.
	 * @param Integration_Adapter[]|null $adapters Adapters (defaults to the built-in set, filterable).
	 */
	public function __construct( Settings $settings, ?array $adapters = null ) {
		$this->settings = $settings;
		if ( null === $adapters ) {
			$adapters = array(
				new Official_Gateway_Adapter(),
				new Pwcg_Adapter(),
				new Manual_Adapter( $settings ),
			);
			/**
			 * Filters the integration adapters the health checker knows about.
			 *
			 * @since 1.0.0
			 * @param Integration_Adapter[] $adapters Adapters in priority order.
			 */
			$adapters = (array) apply_filters( 'pihc_integration_adapters', $adapters );
		}
		$this->adapters = array_values(
			array_filter(
				$adapters,
				static function ( $adapter ) {
					return $adapter instanceof Integration_Adapter;
				}
			)
		);
	}

	/**
	 * All known adapters.
	 *
	 * @return Integration_Adapter[]
	 */
	public function adapters(): array {
		return $this->adapters;
	}

	/**
	 * An adapter by ID.
	 *
	 * @param string $id Adapter ID.
	 */
	public function get( string $id ): ?Integration_Adapter {
		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->id() === $id ) {
				return $adapter;
			}
		}
		return null;
	}

	/**
	 * Active WooCommerce gateway adapters (plugin code loaded).
	 *
	 * @return Integration_Adapter[]
	 */
	public function active_gateways(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$this->adapters,
				static function ( Integration_Adapter $adapter ) {
					return null !== $adapter->gateway_id() && $adapter->is_active();
				}
			)
		);
	}

	/**
	 * Active gateway adapters whose gateway is enabled.
	 *
	 * @return Integration_Adapter[]
	 */
	public function enabled_gateways(): array {
		return array_values(
			array_filter(
				$this->active_gateways(),
				static function ( Integration_Adapter $adapter ) {
					return true === $adapter->is_enabled();
				}
			)
		);
	}

	/**
	 * The integration to diagnose, or null when none is available.
	 */
	public function selected(): ?Integration_Adapter {
		$choice = $this->settings->integration();

		if ( 'auto' !== $choice ) {
			$adapter = $this->get( $choice );
			if ( null === $adapter ) {
				return null;
			}
			if ( null !== $adapter->gateway_id() ) {
				return class_exists( 'WooCommerce' ) && $adapter->is_active() ? $adapter : null;
			}
			return $adapter;
		}

		$enabled = $this->enabled_gateways();
		if ( $enabled ) {
			return $enabled[0];
		}
		$active = $this->active_gateways();
		if ( $active ) {
			return $active[0];
		}
		$manual = $this->get( Manual_Adapter::ID );
		return null !== $manual && $manual->is_active() ? $manual : null;
	}
}
