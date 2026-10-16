<?php
/**
 * Page wrapper: title, notices, tabs, current tab.
 *
 * Variables: $pihc['plugin'], $pihc['renderer'], $pihc['tab'], $pihc['run_id'], $pihc['notice'].
 *
 * @package PaystackHealthChecker
 */

use PaystackHealthChecker\Admin\Admin_Page;
use PaystackHealthChecker\Settings;

defined( 'ABSPATH' ) || exit;

$pihc_tab = $pihc['tab'];
?>
<div class="wrap pihc">
	<h1><?php esc_html_e( 'Paystack Integration Health Checker', 'paystack-integration-health-checker' ); ?></h1>
	<p class="pihc-intro">
		<?php esc_html_e( 'Read-only diagnostics for your Paystack integration. The checker never changes orders, gateway settings or store settings, and never shows your secret keys.', 'paystack-integration-health-checker' ); ?>
	</p>

	<?php if ( 'done' === $pihc['notice'] ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Done. Results are shown below.', 'paystack-integration-health-checker' ); ?></p></div>
	<?php endif; ?>
	<?php settings_errors( Settings::OPTION ); ?>

	<div class="pihc-live" role="status" aria-live="polite"></div>

	<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Health checker sections', 'paystack-integration-health-checker' ); ?>">
		<?php foreach ( Admin_Page::tabs() as $pihc_slug => $pihc_label ) : ?>
			<a href="<?php echo esc_url( Settings::admin_url( $pihc_slug ) ); ?>" class="nav-tab<?php echo $pihc_slug === $pihc_tab ? ' nav-tab-active' : ''; ?>"<?php echo $pihc_slug === $pihc_tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $pihc_label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<div class="pihc-tab">
		<?php
		$pihc_views = array(
			'overview'      => 'tab-overview',
			'configuration' => 'tab-checks',
			'connectivity'  => 'tab-checks',
			'webhooks'      => 'tab-checks',
			'woocommerce'   => 'tab-checks',
			'payments'      => 'tab-payments',
			'history'       => 'tab-history',
			'tools'         => 'tab-tools',
		);
		Admin_Page::view( $pihc_views[ $pihc_tab ], $pihc );
		?>
	</div>
</div>
