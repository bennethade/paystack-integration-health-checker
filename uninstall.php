<?php
/**
 * Uninstall: removes the checker's own data (settings, history tables, throttle transients,
 * scheduled job). Diagnostic history is troubleshooting data, not a financial record, so it
 * is removed. Nothing belonging to Paystack gateways or WooCommerce is touched.
 *
 * @package PaystackHealthChecker
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

wp_clear_scheduled_hook( 'pihc_prune_history' );

delete_option( 'pihc_settings' );
delete_option( 'pihc_db_version' );

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Removing the plugin's own tables and transients.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'pihc_results' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'pihc_runs' ) );
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( '_transient_pihc_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_pihc_rl_' ) . '%'
	)
);
// phpcs:enable
