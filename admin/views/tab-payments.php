<?php
/**
 * Payment Diagnostics: stage map, order-evidence checks and reference verification.
 *
 * Variables: see page.php.
 *
 * @package PaystackHealthChecker
 */

use PaystackHealthChecker\Admin\Admin_Controller;
use PaystackHealthChecker\Admin\Result_Renderer;
use PaystackHealthChecker\Check_Definition;
use PaystackHealthChecker\Diagnostic_Result;
use PaystackHealthChecker\Reference_Verifier;
use PaystackHealthChecker\Security;

defined( 'ABSPATH' ) || exit;

$pihc_plugin   = $pihc['plugin'];
$pihc_renderer = $pihc['renderer'];
$pihc_latest   = $pihc_plugin->history()->latest_results_by_check();
$pihc_adapter  = $pihc_plugin->context()->adapter();

$pihc_status_of = static function ( array $check_ids ) use ( $pihc_latest ) {
	$order = array( Diagnostic_Result::FAIL, Diagnostic_Result::WARNING, Diagnostic_Result::PASS, Diagnostic_Result::SKIPPED );
	$worst = null;
	foreach ( $check_ids as $check_id ) {
		if ( isset( $pihc_latest[ $check_id ] ) ) {
			$status = $pihc_latest[ $check_id ]->status();
			if ( null === $worst || array_search( $status, $order, true ) < array_search( $worst, $order, true ) ) {
				$worst = $status;
			}
		}
	}
	return $worst;
};

$pihc_stages = array(
	array( __( 'Checkout initiation', 'paystack-integration-health-checker' ), __( 'Gateway enabled and available; checkout pages; currency.', 'paystack-integration-health-checker' ), array( 'wc.gateway_status', 'wc.checkout_pages', 'wc.currency' ), __( 'Verified by checks', 'paystack-integration-health-checker' ) ),
	array( __( 'Transaction initialization', 'paystack-integration-health-checker' ), __( 'Keys and API access. Actual initialization needs a real (test) checkout; the checker never initializes transactions.', 'paystack-integration-health-checker' ), array( 'config.secret_key', 'api.authentication' ), __( 'Prerequisites verified; needs a test payment', 'paystack-integration-health-checker' ) ),
	array( __( 'Customer redirect / checkout handoff', 'paystack-integration-health-checker' ), __( 'Happens in the customer\'s browser. Public key needed for Popup/Inline.', 'paystack-integration-health-checker' ), array( 'config.public_key' ), __( 'Needs a test payment', 'paystack-integration-health-checker' ) ),
	array( __( 'Transaction verification', 'paystack-integration-health-checker' ), __( 'Server-side Verify API call. Test it below with a real test reference.', 'paystack-integration-health-checker' ), array( 'api.authentication', 'flow.reference_verification' ), __( 'Verifiable with a reference', 'paystack-integration-health-checker' ) ),
	array( __( 'Webhook reception', 'paystack-integration-health-checker' ), __( 'Handler registration and loopback response. Real delivery is shown in Paystack\'s dashboard.', 'paystack-integration-health-checker' ), array( 'webhook.handler_registered', 'webhook.endpoint_reachable', 'webhook.public_reachability' ), __( 'Partially verifiable', 'paystack-integration-health-checker' ) ),
	array( __( 'Order / payment-state update', 'paystack-integration-health-checker' ), __( 'Evidence from recent orders; reference verification with an order ID compares both sides.', 'paystack-integration-health-checker' ), array( 'flow.recent_orders', 'flow.stuck_orders', 'flow.paid_without_transaction' ), __( 'Verified from order evidence', 'paystack-integration-health-checker' ) ),
	array( __( 'Duplicate-notification handling', 'paystack-integration-health-checker' ), __( 'Internal to the gateway; only visible in its order notes and logs. Not verifiable without instrumenting the gateway.', 'paystack-integration-health-checker' ), array(), __( 'Not verifiable by the checker', 'paystack-integration-health-checker' ) ),
	array( __( 'Error logging and recovery', 'paystack-integration-health-checker' ), __( 'WooCommerce logs written by the gateway and fatal errors.', 'paystack-integration-health-checker' ), array( 'wc.logs', 'webhook.local_errors' ), __( 'Verified where the gateway logs', 'paystack-integration-health-checker' ) ),
);
?>
<p class="pihc-tab-intro"><?php esc_html_e( 'A Paystack payment passes through several stages. The table shows which the health checker can verify on its own and which need a real test-mode payment.', 'paystack-integration-health-checker' ); ?></p>

<table class="widefat striped pihc-stages">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Stage', 'paystack-integration-health-checker' ); ?></th>
			<th scope="col"><?php esc_html_e( 'What is checked', 'paystack-integration-health-checker' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Verifiability', 'paystack-integration-health-checker' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Latest status', 'paystack-integration-health-checker' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $pihc_stages as $pihc_index => $pihc_stage ) : ?>
			<?php $pihc_status = $pihc_status_of( $pihc_stage[2] ); ?>
			<tr>
				<th scope="row"><?php echo esc_html( ( $pihc_index + 1 ) . '. ' . $pihc_stage[0] ); ?></th>
				<td><?php echo esc_html( $pihc_stage[1] ); ?><?php echo $pihc_stage[2] ? '<br /><code>' . esc_html( implode( ', ', $pihc_stage[2] ) ) . '</code>' : ''; ?></td>
				<td><?php echo esc_html( $pihc_stage[3] ); ?></td>
				<td><?php echo null !== $pihc_status ? Result_Renderer::badge( $pihc_status ) : esc_html__( 'Not checked', 'paystack-integration-health-checker' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge(). ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<h2><?php esc_html_e( 'Evidence from recent orders', 'paystack-integration-health-checker' ); ?></h2>
<?php foreach ( $pihc_plugin->registry()->in_category( Check_Definition::CATEGORY_PAYMENTS ) as $pihc_check ) : ?>
	<?php echo $pihc_renderer->result( $pihc_latest[ $pihc_check->id() ] ?? null, true, $pihc_check ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in renderer. ?>
<?php endforeach; ?>

<h2 id="pihc-reference"><?php esc_html_e( 'Verify a test transaction', 'paystack-integration-health-checker' ); ?></h2>
<ol class="pihc-steps">
	<li>
		<?php
		if ( null !== $pihc_adapter && 'live' === $pihc_adapter->mode() ) {
			echo '<strong>' . esc_html__( 'The integration is in LIVE mode.', 'paystack-integration-health-checker' ) . '</strong> ';
			esc_html_e( 'Verification is read-only and safe, but for testing switch the gateway to test mode and use Paystack\'s test cards.', 'paystack-integration-health-checker' );
		} else {
			esc_html_e( 'Confirm the integration is in test mode (Configuration tab) and place an order using one of Paystack\'s documented test cards.', 'paystack-integration-health-checker' );
		}
		?>
		<a href="https://paystack.com/docs/payments/test-payments/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Paystack test payments', 'paystack-integration-health-checker' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'paystack-integration-health-checker' ); ?></span></a>
	</li>
	<li><?php esc_html_e( 'Copy the transaction reference from the Paystack dashboard (Transactions) or from the order notes.', 'paystack-integration-health-checker' ); ?></li>
	<li><?php esc_html_e( 'Enter it below, optionally with the WooCommerce order ID to compare both sides. The order is only read, never changed.', 'paystack-integration-health-checker' ); ?></li>
</ol>

<form class="pihc-ajax-form pihc-reference-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-target="#pihc-reference-result">
	<input type="hidden" name="action" value="pihc_verify_reference" />
	<?php wp_nonce_field( Security::NONCE_REFERENCE, Admin_Controller::NONCE_FIELD, false ); ?>
	<p>
		<label for="pihc-reference-input"><?php esc_html_e( 'Transaction reference', 'paystack-integration-health-checker' ); ?></label><br />
		<input type="text" id="pihc-reference-input" name="reference" class="regular-text" required maxlength="100" pattern="[A-Za-z0-9.=\-]+" autocomplete="off" />
	</p>
	<?php if ( class_exists( 'WooCommerce' ) ) : ?>
		<p>
			<label for="pihc-order-input"><?php esc_html_e( 'Order ID (optional)', 'paystack-integration-health-checker' ); ?></label><br />
			<input type="number" id="pihc-order-input" name="order_id" class="small-text" min="1" step="1" />
		</p>
	<?php endif; ?>
	<button type="submit" class="button button-primary"><?php esc_html_e( 'Verify with Paystack', 'paystack-integration-health-checker' ); ?></button>
	<span class="spinner" aria-hidden="true"></span>
</form>
<div id="pihc-reference-result">
	<?php if ( isset( $pihc_latest[ Reference_Verifier::CHECK_ID ] ) ) : ?>
		<?php echo $pihc_renderer->result( $pihc_latest[ Reference_Verifier::CHECK_ID ], false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in renderer. ?>
	<?php endif; ?>
</div>
