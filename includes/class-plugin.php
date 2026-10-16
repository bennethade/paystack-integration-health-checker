<?php
/**
 * Bootstrap and service composition.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

use PaystackHealthChecker\Admin\Admin_Controller;
use PaystackHealthChecker\Admin\Admin_Page;
use PaystackHealthChecker\Checks\Configuration_Checks;
use PaystackHealthChecker\Checks\Connectivity_Checks;
use PaystackHealthChecker\Checks\Environment_Checks;
use PaystackHealthChecker\Checks\Payment_Flow_Checks;
use PaystackHealthChecker\Checks\Webhook_Checks;
use PaystackHealthChecker\Checks\WooCommerce_Checks;
use PaystackHealthChecker\Integrations\Integration_Detector;

defined( 'ABSPATH' ) || exit;

/**
 * Composition root.
 */
final class Plugin {

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Cached services.
	 *
	 * @var array<string,object>
	 */
	private $services = array();

	/**
	 * Shared instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Declares WooCommerce feature compatibility (before_woocommerce_init).
	 *
	 * HPOS: orders are only read, through wc_get_orders()/wc_get_order().
	 * Checkout Blocks: the plugin adds nothing to cart or checkout.
	 */
	public static function declare_woocommerce_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PIHC_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PIHC_PLUGIN_FILE, true );
		}
	}

	/**
	 * Callback for plugins_loaded.
	 */
	public static function boot(): void {
		self::instance()->register_hooks();
	}

	/**
	 * Registers hooks. Diagnostics are reachable only through authenticated admin requests.
	 */
	public function register_hooks(): void {
		Installer::maybe_upgrade();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( Installer::PRUNE_HOOK, array( $this, 'prune_history' ) );

		// Admin hooks only fire in wp-admin, admin-ajax.php (wp_ajax_*, logged-in users only) and
		// admin-post.php; every handler re-checks capability and nonce itself.
		$page       = new Admin_Page( $this );
		$controller = new Admin_Controller( $this );

		add_action( 'admin_menu', array( $page, 'register_menu' ), 60 );
		add_action( 'admin_init', array( $page, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $page, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PIHC_PLUGIN_FILE ), array( $page, 'action_links' ) );

		$controller->register();
	}

	/**
	 * Loads translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'paystack-integration-health-checker', false, dirname( plugin_basename( PIHC_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Daily retention job.
	 */
	public function prune_history(): void {
		$settings = $this->settings();
		$this->history()->prune( $settings->retention_runs(), $settings->retention_days() );
	}

	/**
	 * Clears cached services (after settings change; in tests).
	 */
	public function reset(): void {
		$this->services = array();
	}

	/**
	 * Settings.
	 */
	public function settings(): Settings {
		return $this->service(
			'settings',
			static function () {
				return new Settings();
			}
		);
	}

	/**
	 * Integration detector.
	 */
	public function detector(): Integration_Detector {
		return $this->service(
			'detector',
			function () {
				return new Integration_Detector( $this->settings() );
			}
		);
	}

	/**
	 * Redactor seeded with every key known on the site.
	 */
	public function redactor(): Redactor {
		return $this->service(
			'redactor',
			function () {
				$keys = $this->settings()->all_manual_keys();
				foreach ( $this->detector()->adapters() as $adapter ) {
					foreach ( array( 'test', 'live' ) as $mode ) {
						$keys[] = $adapter->secret_key( $mode );
						$keys[] = $adapter->public_key( $mode );
					}
				}
				return new Redactor( array_filter( $keys ) );
			}
		);
	}

	/**
	 * Check registry (built once; extensible through "pihc_register_checks").
	 */
	public function registry(): Diagnostic_Registry {
		return $this->service(
			'registry',
			static function () {
				$registry = new Diagnostic_Registry();
				Environment_Checks::register( $registry );
				Configuration_Checks::register( $registry );
				Connectivity_Checks::register( $registry );
				Webhook_Checks::register( $registry );
				WooCommerce_Checks::register( $registry );
				Payment_Flow_Checks::register( $registry );

				/**
				 * Register additional diagnostic checks.
				 *
				 * @since 1.0.0
				 * @param Diagnostic_Registry $registry Registry.
				 */
				do_action( 'pihc_register_checks', $registry );
				return $registry;
			}
		);
	}

	/**
	 * History.
	 */
	public function history(): Diagnostic_History {
		return $this->service(
			'history',
			static function () {
				return new Diagnostic_History();
			}
		);
	}

	/**
	 * A fresh context for one run.
	 */
	public function context(): Diagnostic_Context {
		$redactor = $this->redactor();
		return new Diagnostic_Context(
			$this->settings(),
			$this->detector(),
			static function ( string $secret ) use ( $redactor ) {
				return new Paystack_Api_Client( $secret, $redactor );
			}
		);
	}

	/**
	 * Manager.
	 */
	public function manager(): Diagnostic_Manager {
		return $this->service(
			'manager',
			function () {
				return new Diagnostic_Manager( $this->registry(), array( $this, 'context' ), $this->history(), $this->redactor() );
			}
		);
	}

	/**
	 * Report exporter.
	 */
	public function exporter(): Report_Exporter {
		return $this->service(
			'exporter',
			function () {
				return new Report_Exporter( $this->redactor() );
			}
		);
	}

	/**
	 * Reference verifier.
	 */
	public function reference_verifier(): Reference_Verifier {
		return new Reference_Verifier( $this->context() );
	}

	/**
	 * Cached service.
	 *
	 * @param string   $id      ID.
	 * @param callable $factory Factory.
	 * @return mixed
	 */
	private function service( string $id, callable $factory ) {
		if ( ! isset( $this->services[ $id ] ) ) {
			$this->services[ $id ] = $factory();
		}
		return $this->services[ $id ];
	}
}
