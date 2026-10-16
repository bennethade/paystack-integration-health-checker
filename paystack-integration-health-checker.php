<?php
/**
 * Plugin Name:          Paystack Integration Health Checker
 * Description:          Diagnoses Paystack payment integrations: configuration, API connectivity and authentication, webhooks, WooCommerce compatibility and payment flow. Read-only: it never changes orders or gateway settings. Independent project &mdash; not affiliated with or endorsed by Paystack.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Author:               Benneth Nyekefamo
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          paystack-integration-health-checker
 * Domain Path:          /languages
 * WC requires at least: 9.0
 * WC tested up to:      11.2
 *
 * WooCommerce is optional: without it the plugin still runs its general Paystack
 * diagnostics, and WooCommerce-specific checks report SKIPPED.
 *
 * @package PaystackHealthChecker
 */

defined( 'ABSPATH' ) || exit;

define( 'PIHC_VERSION', '1.0.0' );
define( 'PIHC_PLUGIN_FILE', __FILE__ );
define( 'PIHC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIHC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PIHC_PLUGIN_DIR . 'includes/class-autoloader.php';
\PaystackHealthChecker\Autoloader::register();

register_activation_hook( __FILE__, array( \PaystackHealthChecker\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \PaystackHealthChecker\Installer::class, 'deactivate' ) );

add_action( 'before_woocommerce_init', array( \PaystackHealthChecker\Plugin::class, 'declare_woocommerce_compatibility' ) );
add_action( 'plugins_loaded', array( \PaystackHealthChecker\Plugin::class, 'boot' ), 20 );
