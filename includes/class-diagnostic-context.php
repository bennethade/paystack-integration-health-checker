<?php
/**
 * Shared, read-only state for one diagnostic run.
 *
 * Gives checks access to settings, the selected integration, the API client and a
 * per-run memo so related checks reuse one API call or one loopback request instead
 * of repeating it.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

use PaystackHealthChecker\Integrations\Integration_Adapter;
use PaystackHealthChecker\Integrations\Integration_Detector;

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostic context.
 */
class Diagnostic_Context {

	public const REQUIRES_WOOCOMMERCE = 'woocommerce';
	public const REQUIRES_INTEGRATION = 'integration';
	public const REQUIRES_SECRET_KEY  = 'secret_key';
	public const REQUIRES_GATEWAY     = 'gateway';
	public const REQUIRES_WEBHOOK_URL = 'webhook_url';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Integration detector.
	 *
	 * @var Integration_Detector
	 */
	private $detector;

	/**
	 * Factory for API clients: fn( string $secret ): Paystack_Api_Client.
	 *
	 * @var callable
	 */
	private $api_factory;

	/**
	 * Per-run memo.
	 *
	 * @var array<string,mixed>
	 */
	private $memo = array();

	/**
	 * Selected adapter, resolved lazily.
	 *
	 * @var Integration_Adapter|null|false
	 */
	private $adapter = false;

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings    Settings.
	 * @param Integration_Detector $detector    Detector.
	 * @param callable             $api_factory API client factory.
	 */
	public function __construct( Settings $settings, Integration_Detector $detector, callable $api_factory ) {
		$this->settings    = $settings;
		$this->detector    = $detector;
		$this->api_factory = $api_factory;
	}

	/**
	 * Settings.
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Detector.
	 */
	public function detector(): Integration_Detector {
		return $this->detector;
	}

	/**
	 * The integration being diagnosed, or null.
	 */
	public function adapter(): ?Integration_Adapter {
		if ( false === $this->adapter ) {
			$this->adapter = $this->detector->selected();
		}
		return $this->adapter;
	}

	/**
	 * Whether WooCommerce is loaded.
	 */
	public function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
	}

	/**
	 * API client authenticated with the selected integration's active secret key.
	 */
	public function api(): Paystack_Api_Client {
		$adapter = $this->adapter();
		return call_user_func( $this->api_factory, null !== $adapter ? $adapter->secret_key() : '' );
	}

	/**
	 * Returns a memoized value, computing it once per run.
	 *
	 * @param string   $key     Memo key.
	 * @param callable $compute Producer.
	 * @return mixed
	 */
	public function remember( string $key, callable $compute ) {
		if ( ! array_key_exists( $key, $this->memo ) ) {
			$this->memo[ $key ] = $compute();
		}
		return $this->memo[ $key ];
	}

	/**
	 * Authentication probe shared by every check that needs it (one API call per run).
	 */
	public function authentication_probe(): Api_Response {
		return $this->remember(
			'api.authentication',
			function () {
				return $this->api()->fetch_payment_session_timeout();
			}
		);
	}

	/**
	 * Explains the first unmet prerequisite, or returns null when all are met.
	 *
	 * @param string[] $requires Prerequisite IDs.
	 */
	public function unmet_prerequisite( array $requires ): ?string {
		foreach ( $requires as $requirement ) {
			$reason = $this->check_prerequisite( $requirement );
			if ( null !== $reason ) {
				return $reason;
			}
		}
		return null;
	}

	/**
	 * Evaluates one prerequisite.
	 *
	 * @param string $requirement Prerequisite ID.
	 */
	private function check_prerequisite( string $requirement ): ?string {
		switch ( $requirement ) {
			case self::REQUIRES_WOOCOMMERCE:
				return $this->woocommerce_active() ? null : __( 'WooCommerce is not active, so this WooCommerce check does not apply.', 'paystack-integration-health-checker' );

			case self::REQUIRES_INTEGRATION:
				return null !== $this->adapter() ? null : __( 'No Paystack integration was found to diagnose. Activate a supported Paystack gateway or enter keys under Configuration.', 'paystack-integration-health-checker' );

			case self::REQUIRES_SECRET_KEY:
				$adapter = $this->adapter();
				if ( null === $adapter ) {
					return __( 'No Paystack integration was found to diagnose.', 'paystack-integration-health-checker' );
				}
				return '' !== $adapter->secret_key() ? null : __( 'No secret key is configured for the active environment, so this check cannot run.', 'paystack-integration-health-checker' );

			case self::REQUIRES_GATEWAY:
				$adapter = $this->adapter();
				if ( ! $this->woocommerce_active() ) {
					return __( 'WooCommerce is not active.', 'paystack-integration-health-checker' );
				}
				return null !== $adapter && null !== $adapter->gateway_id() ? null : __( 'The integration being diagnosed is not a WooCommerce gateway.', 'paystack-integration-health-checker' );

			case self::REQUIRES_WEBHOOK_URL:
				$adapter = $this->adapter();
				return null !== $adapter && null !== $adapter->webhook_url() ? null : __( 'The webhook endpoint of this integration is not known to the health checker (for example, a custom integration).', 'paystack-integration-health-checker' );

			default:
				return null;
		}//end switch
	}
}
