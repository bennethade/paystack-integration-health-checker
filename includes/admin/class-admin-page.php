<?php
/**
 * Admin page: menu registration, settings registration, assets and tab rendering.
 *
 * Placed under WooCommerce when it is active, otherwise under Tools.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Admin;

use PaystackHealthChecker\Plugin;
use PaystackHealthChecker\Security;
use PaystackHealthChecker\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page.
 */
class Admin_Page {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Tabs: slug => label.
	 *
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		return array(
			'overview'      => __( 'Overview', 'paystack-integration-health-checker' ),
			'configuration' => __( 'Configuration', 'paystack-integration-health-checker' ),
			'connectivity'  => __( 'API Connectivity', 'paystack-integration-health-checker' ),
			'webhooks'      => __( 'Webhook Diagnostics', 'paystack-integration-health-checker' ),
			'woocommerce'   => __( 'WooCommerce Compatibility', 'paystack-integration-health-checker' ),
			'payments'      => __( 'Payment Diagnostics', 'paystack-integration-health-checker' ),
			'history'       => __( 'Diagnostic History', 'paystack-integration-health-checker' ),
			'tools'         => __( 'Tools & Documentation', 'paystack-integration-health-checker' ),
		);
	}

	/**
	 * Registers the menu item.
	 */
	public function register_menu(): void {
		$title = __( 'Paystack Health Check', 'paystack-integration-health-checker' );
		if ( class_exists( 'WooCommerce' ) ) {
			$this->hook = (string) add_submenu_page( 'woocommerce', __( 'Paystack Integration Health Checker', 'paystack-integration-health-checker' ), $title, Security::capability(), Settings::PAGE_SLUG, array( $this, 'render' ) );
		} else {
			$this->hook = (string) add_management_page( __( 'Paystack Integration Health Checker', 'paystack-integration-health-checker' ), $title, Security::capability(), Settings::PAGE_SLUG, array( $this, 'render' ) );
		}
	}

	/**
	 * Registers the checker settings with the Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			Settings::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
		// Saving through options.php requires this capability (defaults to manage_options).
		add_filter(
			'option_page_capability_' . Settings::GROUP,
			static function () {
				return Security::capability();
			}
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ): array {
		$links   = (array) $links;
		$links[] = '<a href="' . esc_url( Settings::admin_url() ) . '">' . esc_html__( 'Run diagnostics', 'paystack-integration-health-checker' ) . '</a>';
		return $links;
	}

	/**
	 * Enqueues assets on the plugin page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style( 'pihc-admin', PIHC_PLUGIN_URL . 'admin/css/admin.css', array(), PIHC_VERSION );
		wp_enqueue_script( 'pihc-admin', PIHC_PLUGIN_URL . 'admin/js/admin.js', array(), PIHC_VERSION, true );
		wp_localize_script(
			'pihc-admin',
			'pihcAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'running' => __( 'Running…', 'paystack-integration-health-checker' ),
					'failed'  => __( 'The request failed. Please try again.', 'paystack-integration-health-checker' ),
				),
			)
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! Security::current_user_can_use() ) {
			wp_die( esc_html__( 'You are not allowed to use the Paystack health checker.', 'paystack-integration-health-checker' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view parameters.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$run_id = isset( $_GET['run'] ) ? absint( $_GET['run'] ) : 0;
		$notice = isset( $_GET['pihc_notice'] ) ? sanitize_key( wp_unslash( $_GET['pihc_notice'] ) ) : '';
		// phpcs:enable
		if ( ! array_key_exists( $tab, self::tabs() ) ) {
			$tab = 'overview';
		}

		$view = array(
			'plugin'   => $this->plugin,
			'renderer' => new Result_Renderer(),
			'tab'      => $tab,
			'run_id'   => $run_id,
			'notice'   => $notice,
		);

		$this->view( 'page', $view );
	}

	/**
	 * Includes a view template with variables.
	 *
	 * @param string              $name View name (admin/views/{name}.php).
	 * @param array<string,mixed> $vars Variables.
	 */
	public static function view( string $name, array $vars ): void {
		if ( ! preg_match( '/^[a-z\-]+$/', $name ) ) {
			return;
		}
		$file = PIHC_PLUGIN_DIR . 'admin/views/' . $name . '.php';
		if ( is_readable( $file ) ) {
			// Each view documents the variables it expects.
			( static function ( $pihc_view_file, $pihc ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $pihc is used by the included view.
				include $pihc_view_file;
			} )( $file, $vars );
		}
	}
}
