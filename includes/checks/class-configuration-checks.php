<?php
/**
 * Configuration checks: integration detection, keys, environment, site URL, WordPress settings.
 *
 * Key prefixes are documented by Paystack (https://paystack.com/docs/api/authentication/):
 * test keys start with sk_test_ / pk_test_, live keys with sk_live_ / pk_live_. Format
 * checks are local and labelled as such; only the API authentication check confirms a
 * key actually works.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Checks;

use PaystackHealthChecker\Check_Definition;
use PaystackHealthChecker\Diagnostic_Context;
use PaystackHealthChecker\Diagnostic_Registry;
use PaystackHealthChecker\Diagnostic_Result;
use PaystackHealthChecker\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Configuration checks.
 */
final class Configuration_Checks {

	public const DOC_KEYS = 'https://paystack.com/docs/api/authentication/';

	/**
	 * Registers the checks.
	 *
	 * @param Diagnostic_Registry $registry Registry.
	 */
	public static function register( Diagnostic_Registry $registry ): void {
		$checks = array(
			array( 'config.integration', __( 'Paystack integration detected', 'paystack-integration-health-checker' ), __( 'Finds supported Paystack integrations and which one is being diagnosed; warns when several Paystack gateways are enabled.', 'paystack-integration-health-checker' ), 'integration', array() ),
			array( 'config.secret_key', __( 'Secret key configured', 'paystack-integration-health-checker' ), __( 'A secret key exists for the active environment (shown masked).', 'paystack-integration-health-checker' ), 'secret_key', array( Diagnostic_Context::REQUIRES_INTEGRATION ) ),
			array( 'config.key_format', __( 'Secret key format (local check)', 'paystack-integration-health-checker' ), __( 'The secret key has the documented sk_test_/sk_live_ prefix. Local check only; it does not prove the key works.', 'paystack-integration-health-checker' ), 'key_format', array( Diagnostic_Context::REQUIRES_SECRET_KEY ) ),
			array( 'config.environment_match', __( 'Environment matches credentials', 'paystack-integration-health-checker' ), __( 'Test mode uses test keys and live mode uses live keys.', 'paystack-integration-health-checker' ), 'environment_match', array( Diagnostic_Context::REQUIRES_SECRET_KEY ) ),
			array( 'config.public_key', __( 'Public key', 'paystack-integration-health-checker' ), __( 'A public key is configured when the integration needs one (Paystack Popup/Inline), and no secret key was pasted into a public key field.', 'paystack-integration-health-checker' ), 'public_key', array( Diagnostic_Context::REQUIRES_INTEGRATION ) ),
			array( 'config.site_url', __( 'Site URL and HTTPS', 'paystack-integration-health-checker' ), __( 'The site uses HTTPS (required for live payments and webhooks) and its home and site URLs agree.', 'paystack-integration-health-checker' ), 'site_url', array() ),
			array( 'config.wordpress_settings', __( 'WordPress settings affecting payments', 'paystack-integration-health-checker' ), __( 'Settings that commonly break payment callbacks: WP-Cron disabled, PHP errors displayed in responses, maintenance mode.', 'paystack-integration-health-checker' ), 'wordpress_settings', array() ),
		);

		foreach ( $checks as $check ) {
			$registry->add(
				new Check_Definition(
					array(
						'id'          => $check[0],
						'name'        => $check[1],
						'category'    => Check_Definition::CATEGORY_CONFIGURATION,
						'description' => $check[2],
						'callback'    => array( __CLASS__, $check[3] ),
						'requires'    => $check[4],
						'doc_url'     => self::DOC_KEYS,
					)
				)
			);
		}
	}

	/**
	 * Integration detection.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function integration( Diagnostic_Context $context ): Diagnostic_Result {
		$detector = $context->detector();
		$found    = array();
		foreach ( $detector->adapters() as $adapter ) {
			if ( null === $adapter->gateway_id() ) {
				continue;
			}
			if ( $adapter->is_active() && $context->woocommerce_active() ) {
				$found[ $adapter->id() ] = $adapter->is_enabled() ? 'active, enabled' : 'active, disabled';
			} elseif ( $adapter->is_installed() ) {
				$found[ $adapter->id() ] = 'installed, not active';
			}
		}

		$selected = $context->adapter();
		$evidence = array(
			'selection_setting' => $context->settings()->integration(),
			'diagnosing'        => null !== $selected ? $selected->label() : 'none',
			'gateways_found'    => $found ? $found : 'none',
		);

		if ( null === $selected ) {
			if ( 'auto' !== $context->settings()->integration() ) {
				return Diagnostic_Result::fail(
					__( 'The integration selected in the health checker settings is not active.', 'paystack-integration-health-checker' ),
					array(
						'evidence'   => $evidence,
						'next_steps' => array( __( 'Activate that plugin, or set "Integration to diagnose" to Automatic in the Configuration tab.', 'paystack-integration-health-checker' ) ),
					)
				);
			}
			return Diagnostic_Result::fail(
				__( 'No Paystack integration was found.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'Neither a supported Paystack WooCommerce gateway is active nor are keys entered in the health checker, so there is nothing to diagnose.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array(
						__( 'Install and activate a Paystack gateway for WooCommerce, or', 'paystack-integration-health-checker' ),
						__( 'enter your keys under Configuration > Health checker settings to diagnose a custom integration.', 'paystack-integration-health-checker' ),
					),
					'kb'          => 'missing_credentials',
				)
			);
		}//end if

		$enabled = $detector->enabled_gateways();
		if ( count( $enabled ) > 1 ) {
			return Diagnostic_Result::warning(
				__( 'More than one Paystack gateway is enabled.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'Paystack sends webhooks for each environment to a single URL, so only one of these gateways can receive them. Orders paid through the other may never be confirmed by webhook.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array( __( 'Disable the Paystack gateway you do not use, or make sure the dashboard webhook URL belongs to the gateway that processes your orders.', 'paystack-integration-health-checker' ) ),
					'kb'          => 'plugin_conflict',
				)
			);
		}

		return Diagnostic_Result::pass(
			/* translators: %s: integration name */
			sprintf( __( 'Diagnosing: %s.', 'paystack-integration-health-checker' ), $selected->label() ),
			array( 'evidence' => array_merge( $evidence, $selected->configuration_summary() ) )
		);
	}

	/**
	 * Secret key presence.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function secret_key( Diagnostic_Context $context ): Diagnostic_Result {
		$adapter = $context->adapter();
		$mode    = $adapter->mode();
		$key     = $adapter->secret_key();

		if ( '' === $key ) {
			return Diagnostic_Result::fail(
				/* translators: %s: test or live */
				sprintf( __( 'No secret key is configured for the %s environment.', 'paystack-integration-health-checker' ), $mode ),
				array(
					'explanation' => __( 'Every Paystack API call (initialize, verify) and webhook signature check needs the secret key of the active environment.', 'paystack-integration-health-checker' ),
					'evidence'    => array(
						'environment'  => $mode,
						'configure_at' => $adapter->settings_url(),
					),
					'next_steps'  => array(
						/* translators: %s: test or live */
						sprintf( __( 'Copy the %s secret key from Paystack Dashboard > Settings > API Keys & Webhooks.', 'paystack-integration-health-checker' ), $mode ),
						__( 'Paste it into the integration settings (link in the evidence) and run the checks again.', 'paystack-integration-health-checker' ),
					),
					'kb'          => 'missing_credentials',
				)
			);
		}

		return Diagnostic_Result::pass(
			/* translators: %s: test or live */
			sprintf( __( 'A %s secret key is configured.', 'paystack-integration-health-checker' ), $mode ),
			array(
				'evidence' => array(
					'environment' => $mode,
					'key_hint'    => Redactor::mask_key( $key ),
				),
			)
		);
	}

	/**
	 * Secret key format (local).
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function key_format( Diagnostic_Context $context ): Diagnostic_Result {
		$key      = $context->adapter()->secret_key();
		$evidence = array(
			'key_hint'   => Redactor::mask_key( $key ),
			'check_type' => 'local format check (not an authentication test)',
			'length'     => strlen( $key ),
			'has_space'  => (bool) preg_match( '/\s/', $key ),
			'documented' => 'sk_test_ / sk_live_ (secret), pk_test_ / pk_live_ (public)',
		);

		if ( 0 === strpos( $key, 'pk_' ) ) {
			return Diagnostic_Result::fail(
				__( 'A public key (pk_...) is saved where the secret key belongs.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'Paystack documents pk_ as the public-key prefix. Public keys cannot authenticate server-side API requests.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array( __( 'Replace it with the secret key (sk_...) from the Paystack dashboard.', 'paystack-integration-health-checker' ) ),
					'kb'          => 'key_format',
				)
			);
		}

		if ( preg_match( '/\s/', $key ) || ! preg_match( '/^sk_(test|live)_[A-Za-z0-9]+$/', $key ) ) {
			return Diagnostic_Result::warning(
				__( 'The secret key does not match the documented sk_test_/sk_live_ format.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'This is a local check. An unexpected format usually means a copy/paste mistake, but only the API authentication check can prove whether the key works.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array( __( 'Run "API authentication". If it fails, copy the key again from the Paystack dashboard.', 'paystack-integration-health-checker' ) ),
					'kb'          => 'key_format',
				)
			);
		}

		return Diagnostic_Result::pass(
			__( 'The secret key has the documented format.', 'paystack-integration-health-checker' ),
			array(
				'explanation' => __( 'Format only; see "API authentication" for whether Paystack accepts the key.', 'paystack-integration-health-checker' ),
				'evidence'    => $evidence,
			)
		);
	}

	/**
	 * Environment vs key prefixes.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function environment_match( Diagnostic_Context $context ): Diagnostic_Result {
		$adapter = $context->adapter();
		$mode    = $adapter->mode();
		$secret  = self::key_environment( $adapter->secret_key() );
		$public  = '' !== $adapter->public_key() ? self::key_environment( $adapter->public_key() ) : 'not set';

		$evidence = array(
			'selected_environment' => $mode,
			'key_environment'      => $secret,
			'public_environment'   => $public,
		);

		$mismatches = array();
		if ( in_array( $secret, array( 'test', 'live' ), true ) && $secret !== $mode ) {
			/* translators: 1: key environment, 2: selected environment */
			$mismatches[] = sprintf( __( 'the secret key is a %1$s key but %2$s mode is selected', 'paystack-integration-health-checker' ), $secret, $mode );
		}
		if ( in_array( $public, array( 'test', 'live' ), true ) && $public !== $mode ) {
			/* translators: 1: key environment, 2: selected environment */
			$mismatches[] = sprintf( __( 'the public key is a %1$s key but %2$s mode is selected', 'paystack-integration-health-checker' ), $public, $mode );
		}

		if ( $mismatches ) {
			return Diagnostic_Result::fail(
				/* translators: %s: list of mismatches */
				sprintf( __( 'Environment mismatch: %s.', 'paystack-integration-health-checker' ), implode( '; ', $mismatches ) ),
				array(
					'explanation' => __( 'Test and live environments have separate keys. Mixing them sends transactions to the wrong environment and makes webhook signatures fail.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array( __( 'Save keys for the selected environment in the matching fields, or switch the mode.', 'paystack-integration-health-checker' ) ),
					'kb'          => 'wrong_environment',
				)
			);
		}

		if ( 'unknown' === $secret ) {
			return Diagnostic_Result::warning(
				__( 'The environment of the secret key cannot be determined from its prefix.', 'paystack-integration-health-checker' ),
				array(
					'evidence'   => $evidence,
					'next_steps' => array( __( 'See "Secret key format" and "API authentication".', 'paystack-integration-health-checker' ) ),
					'kb'         => 'key_format',
				)
			);
		}

		return Diagnostic_Result::pass(
			/* translators: %s: test or live */
			sprintf( __( 'Keys match the selected %s environment.', 'paystack-integration-health-checker' ), $mode ),
			array( 'evidence' => $evidence )
		);
	}

	/**
	 * Public key requirements.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function public_key( Diagnostic_Context $context ): Diagnostic_Result {
		$adapter = $context->adapter();
		$key     = $adapter->public_key();
		$needed  = $adapter->requires_public_key();

		$evidence = array(
			'required_by_configuration' => $needed ? 'yes' : 'no',
			'public_key_hint'           => '' !== $key ? Redactor::mask_key( $key ) : 'not set',
		);

		if ( 0 === strpos( $key, 'sk_' ) ) {
			return Diagnostic_Result::fail(
				__( 'A secret key is saved in a public key field.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'Public keys are sent to customers\' browsers. A secret key there is exposed to anyone who views the checkout page.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array(
						__( 'Replace it with the public key (pk_...).', 'paystack-integration-health-checker' ),
						__( 'Treat the exposed secret key as compromised: generate a new one in the Paystack dashboard.', 'paystack-integration-health-checker' ),
					),
					'kb'          => 'key_format',
				)
			);
		}

		if ( ! $needed ) {
			return Diagnostic_Result::pass(
				__( 'This configuration does not need a public key.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'Server-side redirect checkouts only use the secret key.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
				)
			);
		}

		if ( '' === $key ) {
			return Diagnostic_Result::fail(
				__( 'The integration uses Paystack Popup/Inline but no public key is configured.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'The popup checkout is opened in the browser with the public key; without it customers cannot pay.', 'paystack-integration-health-checker' ),
					'evidence'    => array_merge( $evidence, array( 'configure_at' => $adapter->settings_url() ) ),
					'next_steps'  => array( __( 'Copy the public key for the active environment from the Paystack dashboard into the integration settings.', 'paystack-integration-health-checker' ) ),
					'kb'          => 'missing_credentials',
				)
			);
		}

		if ( ! preg_match( '/^pk_(test|live)_[A-Za-z0-9]+$/', $key ) ) {
			return Diagnostic_Result::warning(
				__( 'The public key does not match the documented pk_test_/pk_live_ format.', 'paystack-integration-health-checker' ),
				array(
					'evidence' => $evidence,
					'kb'       => 'key_format',
				)
			);
		}

		return Diagnostic_Result::pass( __( 'A public key with the documented format is configured.', 'paystack-integration-health-checker' ), array( 'evidence' => $evidence ) );
	}

	/**
	 * Site URL and HTTPS.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function site_url( Diagnostic_Context $context ): Diagnostic_Result {
		$home     = (string) home_url();
		$site     = (string) site_url();
		$scheme   = (string) wp_parse_url( $home, PHP_URL_SCHEME );
		$adapter  = $context->adapter();
		$mode     = null !== $adapter ? $adapter->mode() : 'unknown';
		$evidence = array(
			'home_url'    => $home,
			'site_url'    => $site,
			'environment' => $mode,
		);

		if ( wp_parse_url( $home, PHP_URL_HOST ) !== wp_parse_url( $site, PHP_URL_HOST ) ) {
			return Diagnostic_Result::warning(
				__( 'The Home URL and Site URL use different hosts.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'Callback and webhook URLs are built from the Home URL. If visitors use another host, cookies and redirects after payment may break.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array( __( 'Check Settings > General.', 'paystack-integration-health-checker' ) ),
				)
			);
		}

		if ( 'https' !== $scheme ) {
			$details = array(
				'explanation' => __( 'Paystack requires HTTPS for live integrations, and webhooks carry payment confirmations that should never travel unencrypted.', 'paystack-integration-health-checker' ),
				'evidence'    => $evidence,
				'next_steps'  => array( __( 'Install a TLS certificate and switch the Home and Site URLs to https.', 'paystack-integration-health-checker' ) ),
				'kb'          => 'site_not_public',
			);
			return 'live' === $mode
				? Diagnostic_Result::fail( __( 'The site is not served over HTTPS while live payments are enabled.', 'paystack-integration-health-checker' ), $details )
				: Diagnostic_Result::warning( __( 'The site is not served over HTTPS (acceptable only for local testing).', 'paystack-integration-health-checker' ), $details );
		}

		return Diagnostic_Result::pass( __( 'The site uses HTTPS.', 'paystack-integration-health-checker' ), array( 'evidence' => $evidence ) );
	}

	/**
	 * WordPress settings that affect payment processing.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function wordpress_settings( Diagnostic_Context $context ): Diagnostic_Result {
		unset( $context );
		$findings = array();
		$evidence = array(
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'yes' : 'no',
			'wp_debug_display' => defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ) ? 'yes' : 'no',
			'maintenance_mode' => file_exists( ABSPATH . '.maintenance' ) ? 'yes' : 'no',
		);

		if ( 'yes' === $evidence['wp_cron_disabled'] ) {
			$findings[] = __( 'WP-Cron is disabled. Scheduled tasks (such as payment re-verification and WooCommerce\'s cancellation of unpaid orders) run only if a real cron job calls wp-cron.php; this check cannot see whether one exists.', 'paystack-integration-health-checker' );
		}
		if ( 'yes' === $evidence['wp_debug_display'] ) {
			$findings[] = __( 'PHP errors are displayed in responses (WP_DEBUG with WP_DEBUG_DISPLAY). A notice printed during a webhook or callback can corrupt the response.', 'paystack-integration-health-checker' );
		}
		if ( 'yes' === $evidence['maintenance_mode'] ) {
			$findings[] = __( 'Maintenance mode is active; webhooks and customer returns receive the maintenance page.', 'paystack-integration-health-checker' );
		}

		if ( $findings ) {
			return Diagnostic_Result::warning(
				__( 'Some WordPress settings can interfere with payment callbacks.', 'paystack-integration-health-checker' ),
				array(
					'evidence'   => $evidence,
					'next_steps' => $findings,
				)
			);
		}

		return Diagnostic_Result::pass( __( 'No WordPress settings that commonly break payment callbacks were found.', 'paystack-integration-health-checker' ), array( 'evidence' => $evidence ) );
	}

	/**
	 * Environment encoded in a key prefix.
	 *
	 * @param string $key Key.
	 * @return string "test", "live" or "unknown".
	 */
	public static function key_environment( string $key ): string {
		if ( preg_match( '/^(sk|pk)_(test|live)_/', $key, $m ) ) {
			return $m[2];
		}
		return 'unknown';
	}
}
