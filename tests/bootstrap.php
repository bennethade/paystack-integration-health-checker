<?php
/**
 * Integration bootstrap: WordPress test suite + (optionally) WooCommerce, the official
 * Paystack gateway, the pwcg gateway and this plugin, loaded from wp-content/plugins.
 *
 * Environment variables:
 *   WP_PHPUNIT__TESTS_CONFIG  wp-tests-config.php (default .wp-test/wp-tests-config.php)
 *   PIHC_TESTS_WOOCOMMERCE    "0" loads only the health checker (no WooCommerce)
 *
 * @package PaystackHealthChecker
 */

$pihc_root = dirname( __DIR__ );

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $pihc_root . '/.wp-test/wp-tests-config.php' );
}
if ( ! file_exists( (string) getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) ) {
	fwrite( STDERR, "Missing test environment. Run: php bin/install-test-env.php\n" );
	exit( 1 );
}

require_once $pihc_root . '/vendor/autoload.php';

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $pihc_root . '/vendor/yoast/phpunit-polyfills' );
define( 'PIHC_TESTS_WOOCOMMERCE', '0' !== getenv( 'PIHC_TESTS_WOOCOMMERCE' ) );

$pihc_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
require_once $pihc_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$plugins = array( 'paystack-integration-health-checker/paystack-integration-health-checker.php' );
		if ( PIHC_TESTS_WOOCOMMERCE ) {
			$plugins = array_merge(
				array( 'woocommerce/woocommerce.php', 'woo-paystack/woo-paystack.php' ),
				file_exists( WP_PLUGIN_DIR . '/paystack-woocommerce-gateway/paystack-woocommerce-gateway.php' ) ? array( 'paystack-woocommerce-gateway/paystack-woocommerce-gateway.php' ) : array(),
				$plugins
			);
		}
		update_option( 'active_plugins', $plugins );
		foreach ( $plugins as $plugin ) {
			require_once WP_PLUGIN_DIR . '/' . $plugin;
		}
	}
);

tests_add_filter(
	'init',
	static function () {
		if ( PIHC_TESTS_WOOCOMMERCE ) {
			define( 'WC_REMOVE_ALL_DATA', true );
			WC_Install::install();
			update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
			update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
			wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
			$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reload roles WooCommerce added.
			wp_roles();
		}
		\PaystackHealthChecker\Installer::create_tables();
	},
	20
);

// No test may reach the network: anything a test did not mock fails loudly.
tests_add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		return new WP_Error( 'pihc_tests_unmocked_http', 'Unmocked HTTP request blocked in tests: ' . $url );
	},
	PHP_INT_MAX,
	3
);

require $pihc_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/integration/class-pihc-test-case.php';

if ( ! isset( $GLOBALS['__PHPUNIT_BOOTSTRAP'] ) ) {
	fwrite( STDOUT, PIHC_TESTS_WOOCOMMERCE ? sprintf( "WooCommerce %s loaded (with woo-paystack%s)\n", WC_VERSION, class_exists( '\PaystackWooGateway\Plugin' ) ? ' and pwcg' : '' ) : "WooCommerce NOT loaded\n" );
}
