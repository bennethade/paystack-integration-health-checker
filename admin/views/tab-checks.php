<?php
/**
 * Generic tab listing the checks of one or more categories with their latest results.
 *
 * Variables: see page.php.
 *
 * @package PaystackHealthChecker
 */

use PaystackHealthChecker\Admin\Admin_Page;
use PaystackHealthChecker\Check_Definition;

defined( 'ABSPATH' ) || exit;

$pihc_plugin = $pihc['plugin'];
$pihc_latest = $pihc_plugin->history()->latest_results_by_check();

$pihc_sections = array(
	'configuration' => array(
		Check_Definition::CATEGORY_CONFIGURATION => __( 'Paystack configuration', 'paystack-integration-health-checker' ),
		Check_Definition::CATEGORY_ENVIRONMENT   => __( 'Server environment', 'paystack-integration-health-checker' ),
	),
	'connectivity'  => array( Check_Definition::CATEGORY_CONNECTIVITY => __( 'Paystack API', 'paystack-integration-health-checker' ) ),
	'webhooks'      => array( Check_Definition::CATEGORY_WEBHOOKS => __( 'Webhooks', 'paystack-integration-health-checker' ) ),
	'woocommerce'   => array( Check_Definition::CATEGORY_WOOCOMMERCE => __( 'WooCommerce', 'paystack-integration-health-checker' ) ),
);

$pihc_intros = array(
	'configuration' => __( 'Checks of the keys, environment and site settings the integration depends on. Key format checks are local; "API authentication" (API Connectivity tab) is the authoritative test of a key.', 'paystack-integration-health-checker' ),
	'connectivity'  => __( 'One read-only request to Paystack\'s documented "Fetch Timeout" endpoint confirms both that this server can reach Paystack and that the secret key is accepted. No payment is created.', 'paystack-integration-health-checker' ),
	'webhooks'      => __( 'Webhook checks run from this server against its own webhook URL. A successful response shows the handler runs here; it does not guarantee Paystack can reach it or that real events are processed correctly. Paystack\'s dashboard shows the outcome of each real delivery. These checks never send payment events.', 'paystack-integration-health-checker' ),
	'woocommerce'   => __( 'Compatibility of the Paystack gateway with your WooCommerce setup, read from WooCommerce itself. The health checker\'s own compatibility is listed separately. Nothing here changes your store settings.', 'paystack-integration-health-checker' ),
);
?>
<p class="pihc-tab-intro"><?php echo esc_html( $pihc_intros[ $pihc['tab'] ] ); ?></p>

<?php foreach ( $pihc_sections[ $pihc['tab'] ] as $pihc_category => $pihc_heading ) : ?>
	<h2><?php echo esc_html( $pihc_heading ); ?></h2>
	<?php foreach ( $pihc_plugin->registry()->in_category( $pihc_category ) as $pihc_check ) : ?>
		<?php echo $pihc['renderer']->result( $pihc_latest[ $pihc_check->id() ] ?? null, true, $pihc_check ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in renderer. ?>
	<?php endforeach; ?>
<?php endforeach; ?>

<?php
if ( 'configuration' === $pihc['tab'] ) {
	Admin_Page::view( 'settings-form', $pihc );
}
