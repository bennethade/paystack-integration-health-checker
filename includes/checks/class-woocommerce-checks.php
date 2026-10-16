<?php
/**
 * WooCommerce compatibility checks.
 *
 * Gateway compatibility is read from WooCommerce itself, never inferred from the gateway
 * appearing in the payment methods list:
 * - HPOS / Checkout Blocks declarations: WooCommerce's FeaturesController, the registry
 *   that FeaturesUtil::declare_compatibility() writes to.
 * - Checkout Blocks support: whether the gateway registered a payment method type in
 *   WooCommerce Blocks' PaymentMethodRegistry.
 * These are WooCommerce internals (Automattic\WooCommerce\Internal / Blocks); they are
 * accessed read-only and guarded so a future WooCommerce change yields SKIPPED, not errors.
 *
 * Nothing here changes store, checkout, order or gateway settings.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Checks;

use PaystackHealthChecker\Check_Definition;
use PaystackHealthChecker\Diagnostic_Context;
use PaystackHealthChecker\Diagnostic_Registry;
use PaystackHealthChecker\Diagnostic_Result;
use PaystackHealthChecker\Environment;
use PaystackHealthChecker\Log_Inspector;
use PaystackHealthChecker\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce checks.
 */
final class WooCommerce_Checks {

	public const CHECKER_MIN_WC = '9.0';

	/**
	 * Currencies listed in Paystack's API documentation ("Supported currency", verified 2026-10-16).
	 */
	public const DOCUMENTED_CURRENCIES = array( 'NGN', 'USD', 'GHS', 'ZAR', 'KES', 'XOF' );

	/**
	 * Registers the checks.
	 *
	 * @param Diagnostic_Registry $registry Registry.
	 */
	public static function register( Diagnostic_Registry $registry ): void {
		$wc      = array( Diagnostic_Context::REQUIRES_WOOCOMMERCE );
		$gateway = array( Diagnostic_Context::REQUIRES_INTEGRATION, Diagnostic_Context::REQUIRES_GATEWAY );
		$checks  = array(
			array( 'wc.active', __( 'WooCommerce status', 'paystack-integration-health-checker' ), __( 'Whether WooCommerce is installed and active, and its version.', 'paystack-integration-health-checker' ), 'active', array() ),
			array( 'wc.version', __( 'WooCommerce version supported by the gateway', 'paystack-integration-health-checker' ), __( 'The installed WooCommerce version is within the range the Paystack gateway declares (WC requires at least / WC tested up to).', 'paystack-integration-health-checker' ), 'version', $gateway ),
			array( 'wc.gateway_status', __( 'Gateway enabled and available', 'paystack-integration-health-checker' ), __( 'The Paystack gateway is enabled and WooCommerce considers it available at checkout.', 'paystack-integration-health-checker' ), 'gateway_status', $gateway ),
			array( 'wc.currency', __( 'Store currency supported', 'paystack-integration-health-checker' ), __( 'The store currency is accepted by the gateway and listed in Paystack\'s API documentation.', 'paystack-integration-health-checker' ), 'currency', $gateway ),
			array( 'wc.checkout_pages', __( 'Checkout configuration', 'paystack-integration-health-checker' ), __( 'Cart and checkout pages exist, are published and contain checkout content.', 'paystack-integration-health-checker' ), 'checkout_pages', $wc ),
			array( 'wc.hpos', __( 'HPOS compatibility of the gateway', 'paystack-integration-health-checker' ), __( 'Whether the gateway declared compatibility with High-Performance Order Storage, as recorded by WooCommerce.', 'paystack-integration-health-checker' ), 'hpos', $gateway ),
			array( 'wc.blocks', __( 'Checkout Blocks support of the gateway', 'paystack-integration-health-checker' ), __( 'Whether the checkout uses the Checkout block and, if so, whether the gateway registered a block integration.', 'paystack-integration-health-checker' ), 'blocks', $gateway ),
			array( 'wc.checker_compat', __( 'Health checker\'s own compatibility', 'paystack-integration-health-checker' ), __( 'This plugin\'s own HPOS/Blocks declarations (separate from the gateway\'s).', 'paystack-integration-health-checker' ), 'checker_compat', $wc ),
			array( 'wc.logs', __( 'Integration errors in WooCommerce logs', 'paystack-integration-health-checker' ), __( 'Errors from the gateway\'s log source and fatal errors mentioning the gateway in the last 7 days.', 'paystack-integration-health-checker' ), 'logs', $gateway ),
		);

		foreach ( $checks as $check ) {
			$registry->add(
				new Check_Definition(
					array(
						'id'          => $check[0],
						'name'        => $check[1],
						'category'    => Check_Definition::CATEGORY_WOOCOMMERCE,
						'description' => $check[2],
						'callback'    => array( __CLASS__, $check[3] ),
						'requires'    => $check[4],
						'doc_url'     => 'https://developer.woocommerce.com/docs/',
					)
				)
			);
		}
	}

	/**
	 * WooCommerce status.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function active( Diagnostic_Context $context ): Diagnostic_Result {
		if ( $context->woocommerce_active() ) {
			return Diagnostic_Result::pass(
				/* translators: %s: version */
				sprintf( __( 'WooCommerce %s is active.', 'paystack-integration-health-checker' ), WC_VERSION ),
				array(
					'evidence' => array(
						'version'      => WC_VERSION,
						'hpos_enabled' => Environment::hpos_enabled() ? 'yes' : 'no',
					),
				)
			);
		}

		$installed = file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' );
		if ( $installed ) {
			return Diagnostic_Result::warning(
				__( 'WooCommerce is installed but not active.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'WooCommerce payment checks are skipped. If you take payments through WooCommerce, activate it.', 'paystack-integration-health-checker' ),
					'next_steps'  => array( __( 'Activate WooCommerce on the Plugins screen if you use it.', 'paystack-integration-health-checker' ) ),
				)
			);
		}

		return Diagnostic_Result::skipped(
			__( 'WooCommerce is not installed; WooCommerce checks do not apply.', 'paystack-integration-health-checker' ),
			array( 'explanation' => __( 'General Paystack checks (configuration, API, environment) still run using the keys entered in the health checker.', 'paystack-integration-health-checker' ) )
		);
	}

	/**
	 * WooCommerce version vs gateway headers.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function version( Diagnostic_Context $context ): Diagnostic_Result {
		$adapter = $context->adapter();
		$headers = method_exists( $adapter, 'plugin_headers' ) ? $adapter->plugin_headers() : array();
		$min     = (string) ( $headers['requires_wc'] ?? '' );
		$tested  = (string) ( $headers['tested_wc'] ?? '' );

		$evidence = array(
			'woocommerce_version'     => WC_VERSION,
			'gateway_version'         => $headers['version'] ?? 'unknown',
			'gateway_requires_wc'     => '' !== $min ? $min : 'not declared',
			'gateway_tested_up_to_wc' => '' !== $tested ? $tested : 'not declared',
			'checker_requires_wc'     => self::CHECKER_MIN_WC,
		);

		if ( '' !== $min && version_compare( WC_VERSION, $min, '<' ) ) {
			return Diagnostic_Result::fail(
				/* translators: 1: installed, 2: required */
				sprintf( __( 'WooCommerce %1$s is older than the %2$s the gateway requires.', 'paystack-integration-health-checker' ), WC_VERSION, $min ),
				array(
					'evidence'   => $evidence,
					'next_steps' => array( __( 'Update WooCommerce (after taking a backup).', 'paystack-integration-health-checker' ) ),
					'kb'         => 'unsupported_wc_feature',
				)
			);
		}

		if ( '' !== $tested && version_compare( self::major_minor( WC_VERSION ), self::major_minor( $tested ), '>' ) ) {
			return Diagnostic_Result::warning(
				/* translators: 1: installed, 2: tested */
				sprintf( __( 'WooCommerce %1$s is newer than the version the gateway was tested with (%2$s).', 'paystack-integration-health-checker' ), WC_VERSION, $tested ),
				array(
					'explanation' => __( 'This often works, but it is not confirmed by the gateway\'s author. Watch for checkout or order-status problems and update the gateway when a new version is available.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'kb'          => 'unsupported_wc_feature',
				)
			);
		}

		return Diagnostic_Result::pass( __( 'The WooCommerce version is within the gateway\'s declared range.', 'paystack-integration-health-checker' ), array( 'evidence' => $evidence ) );
	}

	/**
	 * Gateway enabled/available.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function gateway_status( Diagnostic_Context $context ): Diagnostic_Result {
		$adapter  = $context->adapter();
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = $gateways[ (string) $adapter->gateway_id() ] ?? null;

		$evidence = array(
			'gateway_id' => $adapter->gateway_id(),
			'registered' => $gateway ? 'yes' : 'no',
			'enabled'    => $adapter->is_enabled() ? 'yes' : 'no',
			'settings'   => $adapter->settings_url(),
		);

		if ( ! $gateway instanceof \WC_Payment_Gateway ) {
			return Diagnostic_Result::fail(
				__( 'The gateway is not registered with WooCommerce.', 'paystack-integration-health-checker' ),
				array(
					'evidence' => $evidence,
					'kb'       => 'plugin_conflict',
				)
			);
		}

		if ( ! $adapter->is_enabled() ) {
			return Diagnostic_Result::warning(
				__( 'The Paystack gateway is disabled, so customers cannot choose it.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'This may be intentional (for example while testing).', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array( __( 'Enable it in WooCommerce > Settings > Payments when you are ready.', 'paystack-integration-health-checker' ) ),
				)
			);
		}

		$available             = (bool) $gateway->is_available();
		$evidence['available'] = $available ? 'yes' : 'no';
		if ( ! $available ) {
			return Diagnostic_Result::fail(
				__( 'The gateway is enabled but WooCommerce hides it at checkout.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'The gateway\'s own availability rules failed. Common reasons: unsupported store currency or missing keys. See the currency and configuration checks.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'kb'          => 'missing_credentials',
				)
			);
		}

		return Diagnostic_Result::pass( __( 'The gateway is enabled and available at checkout.', 'paystack-integration-health-checker' ), array( 'evidence' => $evidence ) );
	}

	/**
	 * Store currency.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function currency( Diagnostic_Context $context ): Diagnostic_Result {
		$currency = strtoupper( get_woocommerce_currency() );
		$gateway  = $context->adapter()->supported_currencies();
		$evidence = array(
			'store_currency'     => $currency,
			'gateway_accepts'    => null !== $gateway ? implode( ', ', $gateway ) : 'unknown',
			'paystack_docs_list' => implode( ', ', self::DOCUMENTED_CURRENCIES ),
			'settings'           => admin_url( 'admin.php?page=wc-settings&tab=general' ),
		);

		if ( null !== $gateway && ! in_array( $currency, $gateway, true ) ) {
			return Diagnostic_Result::fail(
				/* translators: %s: currency */
				sprintf( __( 'The gateway does not accept the store currency (%s).', 'paystack-integration-health-checker' ), $currency ),
				array(
					'explanation' => __( 'The gateway hides itself at checkout for currencies it does not support.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array( __( 'Change the store currency in WooCommerce > Settings > General, if your Paystack account supports the new currency.', 'paystack-integration-health-checker' ) ),
				)
			);
		}

		if ( ! in_array( $currency, self::DOCUMENTED_CURRENCIES, true ) ) {
			return Diagnostic_Result::warning(
				/* translators: %s: currency */
				sprintf( __( '%s is accepted by the gateway but not listed in Paystack\'s API currency table.', 'paystack-integration-health-checker' ), $currency ),
				array(
					'explanation' => __( 'Availability can depend on your Paystack account and country. This is not confirmed by the documentation the checker relies on.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array( __( 'Confirm with Paystack that this currency is enabled for your account, or run a test-mode payment in it.', 'paystack-integration-health-checker' ) ),
				)
			);
		}

		return Diagnostic_Result::pass(
			/* translators: %s: currency */
			sprintf( __( '%s is supported.', 'paystack-integration-health-checker' ), $currency ),
			array(
				'explanation' => __( 'Your Paystack account must also have the currency enabled; that cannot be checked from here.', 'paystack-integration-health-checker' ),
				'evidence'    => $evidence,
			)
		);
	}

	/**
	 * Cart and checkout pages.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function checkout_pages( Diagnostic_Context $context ): Diagnostic_Result {
		unset( $context );
		$problems = array();
		$evidence = array();

		foreach ( array( 'cart', 'checkout' ) as $page ) {
			$page_id = wc_get_page_id( $page );
			$post    = $page_id > 0 ? get_post( $page_id ) : null;
			if ( ! $post ) {
				/* translators: %s: page name */
				$problems[]        = sprintf( __( 'No %s page is set.', 'paystack-integration-health-checker' ), $page );
				$evidence[ $page ] = 'not set';
				continue;
			}
			$type              = has_block( 'woocommerce/' . $page, $post ) ? 'block' : ( has_shortcode( $post->post_content, 'woocommerce_' . $page ) ? 'shortcode' : 'none' );
			$evidence[ $page ] = $post->post_status . ', ' . $type;
			if ( 'publish' !== $post->post_status ) {
				/* translators: %s: page name */
				$problems[] = sprintf( __( 'The %s page is not published.', 'paystack-integration-health-checker' ), $page );
			}
			if ( 'none' === $type ) {
				/* translators: %s: page name */
				$problems[] = sprintf( __( 'The %s page contains neither the block nor the shortcode.', 'paystack-integration-health-checker' ), $page );
			}
		}

		if ( $problems ) {
			return Diagnostic_Result::fail(
				__( 'Checkout configuration is incomplete.', 'paystack-integration-health-checker' ),
				array(
					'evidence'   => $evidence,
					'next_steps' => array_merge( $problems, array( __( 'Fix the pages under WooCommerce > Settings > Advanced > Page setup.', 'paystack-integration-health-checker' ) ) ),
				)
			);
		}

		return Diagnostic_Result::pass( __( 'Cart and checkout pages are set up.', 'paystack-integration-health-checker' ), array( 'evidence' => $evidence ) );
	}

	/**
	 * Gateway HPOS compatibility.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function hpos( Diagnostic_Context $context ): Diagnostic_Result {
		$status = self::declared_compatibility( 'custom_order_tables', (string) $context->adapter()->plugin_basename() );
		$hpos   = Environment::hpos_enabled();
		if ( null === $status ) {
			return Diagnostic_Result::skipped( __( 'WooCommerce\'s feature compatibility data is not available.', 'paystack-integration-health-checker' ) );
		}

		$evidence = array(
			'hpos_enabled'        => $hpos ? 'yes' : 'no',
			'gateway_declaration' => $status,
			'source'              => 'WooCommerce FeaturesController',
		);

		if ( 'compatible' === $status ) {
			return Diagnostic_Result::pass( __( 'The gateway declares HPOS compatibility.', 'paystack-integration-health-checker' ), array( 'evidence' => $evidence ) );
		}

		$details = array(
			'explanation' => 'incompatible' === $status
				? __( 'The gateway explicitly declared itself incompatible with HPOS.', 'paystack-integration-health-checker' )
				: __( 'The gateway has not declared HPOS compatibility; WooCommerce treats undeclared plugins as incompatible.', 'paystack-integration-health-checker' ),
			'evidence'    => $evidence,
			'next_steps'  => array( __( 'Update the gateway. Until it declares compatibility, keep HPOS disabled (or in compatibility mode).', 'paystack-integration-health-checker' ) ),
			'kb'          => 'unsupported_wc_feature',
		);

		return $hpos
			? Diagnostic_Result::fail( __( 'HPOS is enabled but the gateway is not declared compatible.', 'paystack-integration-health-checker' ), $details )
			: Diagnostic_Result::warning( __( 'The gateway is not declared HPOS-compatible (HPOS is currently off).', 'paystack-integration-health-checker' ), $details );
	}

	/**
	 * Gateway Checkout Blocks support.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function blocks( Diagnostic_Context $context ): Diagnostic_Result {
		$adapter     = $context->adapter();
		$checkout_id = wc_get_page_id( 'checkout' );
		$uses_block  = $checkout_id > 0 && has_block( 'woocommerce/checkout', get_post( $checkout_id ) );
		$registered  = self::blocks_registered( (string) $adapter->gateway_id() );
		$declared    = self::declared_compatibility( 'cart_checkout_blocks', (string) $adapter->plugin_basename() );

		$evidence = array(
			'checkout_uses_block'       => $uses_block ? 'yes' : 'no (classic/shortcode)',
			'block_integration'         => null === $registered ? 'unknown' : ( $registered ? 'registered' : 'not registered' ),
			'cart_checkout_blocks_flag' => $declared ?? 'unknown',
		);

		if ( null === $registered ) {
			return Diagnostic_Result::skipped( __( 'WooCommerce Blocks\' payment registry is not available.', 'paystack-integration-health-checker' ), array( 'evidence' => $evidence ) );
		}

		if ( $uses_block && ! $registered ) {
			return Diagnostic_Result::fail(
				__( 'The checkout uses the Checkout block, but the gateway has no block integration.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'Customers will not see this payment method in the Checkout block.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array( __( 'Update the gateway, or switch the checkout page to the classic [woocommerce_checkout] shortcode.', 'paystack-integration-health-checker' ) ),
					'kb'          => 'unsupported_wc_feature',
				)
			);
		}

		if ( $registered && 'compatible' !== $declared ) {
			return Diagnostic_Result::warning(
				__( 'The gateway registers a Checkout block integration but does not declare cart_checkout_blocks compatibility.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'The payment method should appear in the Checkout block, but WooCommerce may list the plugin as incompatible in the editor because the declaration is missing. Confirm with a test-mode payment through the Checkout block.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'kb'          => 'unsupported_wc_feature',
				)
			);
		}

		return Diagnostic_Result::pass(
			$uses_block
				? __( 'The checkout uses the Checkout block and the gateway supports it.', 'paystack-integration-health-checker' )
				: __( 'The checkout uses the classic shortcode, so Checkout block support is not required.', 'paystack-integration-health-checker' ),
			array( 'evidence' => $evidence )
		);
	}

	/**
	 * The checker's own declarations.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function checker_compat( Diagnostic_Context $context ): Diagnostic_Result {
		unset( $context );
		$basename = plugin_basename( PIHC_PLUGIN_FILE );
		$hpos     = self::declared_compatibility( 'custom_order_tables', $basename );
		$blocks   = self::declared_compatibility( 'cart_checkout_blocks', $basename );

		$evidence = array(
			'custom_order_tables'  => $hpos ?? 'unknown',
			'cart_checkout_blocks' => $blocks ?? 'unknown',
			'order_access'         => 'read-only via wc_get_orders() / wc_get_order()',
		);

		if ( 'compatible' === $hpos && 'compatible' === $blocks ) {
			return Diagnostic_Result::pass( __( 'The health checker is declared compatible with HPOS and Checkout Blocks.', 'paystack-integration-health-checker' ), array( 'evidence' => $evidence ) );
		}
		return Diagnostic_Result::warning(
			__( 'WooCommerce did not record the health checker\'s compatibility declarations.', 'paystack-integration-health-checker' ),
			array(
				'explanation' => __( 'This affects only how WooCommerce lists this plugin, not the diagnostics. It happens when the plugin is loaded from outside wp-content/plugins.', 'paystack-integration-health-checker' ),
				'evidence'    => $evidence,
			)
		);
	}

	/**
	 * Gateway log errors.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function logs( Diagnostic_Context $context ): Diagnostic_Result {
		$adapter   = $context->adapter();
		$inspector = new Log_Inspector( new Redactor( array( $adapter->secret_key(), $adapter->public_key() ) ) );
		if ( Log_Inspector::HANDLER_UNSUPPORTED === $inspector->handler() ) {
			return Diagnostic_Result::skipped( __( 'WooCommerce logs are not available through a supported handler.', 'paystack-integration-health-checker' ) );
		}

		$own   = $adapter->log_sources() ? $inspector->scan( $adapter->log_sources(), 7 ) : null;
		$fatal = $inspector->scan( array( 'fatal-errors' ), 7, $adapter->log_marker() );

		$errors   = (int) ( $own['errors'] ?? 0 ) + (int) $fatal['errors'];
		$evidence = array(
			'window'         => 'last 7 days',
			'handler'        => $inspector->handler(),
			'gateway_source' => $adapter->log_sources() ? implode( ', ', $adapter->log_sources() ) : 'none (gateway does not log)',
			'gateway_errors' => $own['errors'] ?? 'n/a',
			'fatal_errors'   => $fatal['errors'],
			'excerpts'       => array_merge( $own['excerpts'] ?? array(), $fatal['excerpts'] ),
		);

		if ( $errors > 0 ) {
			return Diagnostic_Result::warning(
				/* translators: %d: count */
				sprintf( __( '%d integration errors were logged in the last 7 days.', 'paystack-integration-health-checker' ), $errors ),
				array(
					'explanation' => __( 'The excerpts show what was logged; they do not by themselves identify the root cause.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array( __( 'Open WooCommerce > Status > Logs for the full entries.', 'paystack-integration-health-checker' ) ),
					'kb'          => 'order_not_updating',
				)
			);
		}

		return Diagnostic_Result::pass(
			__( 'No integration errors were logged in the last 7 days.', 'paystack-integration-health-checker' ),
			array(
				'explanation' => null === $own ? __( 'The gateway does not write to the WooCommerce log; only fatal errors mentioning it were checked.', 'paystack-integration-health-checker' ) : '',
				'evidence'    => $evidence,
			)
		);
	}

	/**
	 * Compatibility recorded by WooCommerce for a plugin and feature.
	 *
	 * @param string $feature  Feature ID.
	 * @param string $basename Plugin basename.
	 * @return string|null "compatible", "incompatible", "undeclared", or null if unavailable.
	 */
	public static function declared_compatibility( string $feature, string $basename ): ?string {
		if ( '' === $basename || ! function_exists( 'wc_get_container' ) || ! class_exists( \Automattic\WooCommerce\Internal\Features\FeaturesController::class ) ) {
			return null;
		}
		try {
			$info = wc_get_container()->get( \Automattic\WooCommerce\Internal\Features\FeaturesController::class )->get_compatible_plugins_for_feature( $feature, false );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( in_array( $basename, (array) ( $info['compatible'] ?? array() ), true ) ) {
			return 'compatible';
		}
		if ( in_array( $basename, (array) ( $info['incompatible'] ?? array() ), true ) ) {
			return 'incompatible';
		}
		return 'undeclared';
	}

	/**
	 * Whether a gateway registered a Checkout block payment method type.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return bool|null Null when the registry is unavailable.
	 */
	public static function blocks_registered( string $gateway_id ): ?bool {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Package::class ) || ! class_exists( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class ) ) {
			return null;
		}
		try {
			$registry = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry::class );
			if ( array() === $registry->get_all_registered() && ! did_action( 'woocommerce_blocks_payment_method_type_registration' ) ) {
				/** This action is documented in WooCommerce Blocks (Payments\Api::initialize_payment_method_registry). */
				do_action( 'woocommerce_blocks_payment_method_type_registration', $registry ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			}
			return $registry->is_registered( $gateway_id );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * "11.2.1" -> "11.2".
	 *
	 * @param string $version Version.
	 */
	private static function major_minor( string $version ): string {
		$parts = explode( '.', $version );
		return ( $parts[0] ?? '0' ) . '.' . ( $parts[1] ?? '0' );
	}
}
