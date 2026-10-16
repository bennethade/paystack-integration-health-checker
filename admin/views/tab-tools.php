<?php
/**
 * Tools & Documentation: knowledge base, status meanings, check catalogue, links.
 *
 * Variables: see page.php.
 *
 * @package PaystackHealthChecker
 */

use PaystackHealthChecker\Admin\Result_Renderer;
use PaystackHealthChecker\Diagnostic_Result;
use PaystackHealthChecker\Troubleshooting;

defined( 'ABSPATH' ) || exit;

$pihc_plugin = $pihc['plugin'];
?>
<h2><?php esc_html_e( 'What the statuses mean', 'paystack-integration-health-checker' ); ?></h2>
<dl class="pihc-statuses">
	<dt><?php echo Result_Renderer::badge( Diagnostic_Result::PASS ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dt>
	<dd><?php esc_html_e( 'The check ran and the requirement was satisfied.', 'paystack-integration-health-checker' ); ?></dd>
	<dt><?php echo Result_Renderer::badge( Diagnostic_Result::WARNING ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dt>
	<dd><?php esc_html_e( 'The check ran and found a potential problem: something that often causes failures, or evidence that is suggestive but not conclusive.', 'paystack-integration-health-checker' ); ?></dd>
	<dt><?php echo Result_Renderer::badge( Diagnostic_Result::FAIL ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dt>
	<dd><?php esc_html_e( 'The check ran and its evidence confirms a problem (for example, Paystack answered 401 to the configured key).', 'paystack-integration-health-checker' ); ?></dd>
	<dt><?php echo Result_Renderer::badge( Diagnostic_Result::SKIPPED ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dt>
	<dd><?php esc_html_e( 'The check could not be performed (missing prerequisite, not observable, or unsafe). It does not count toward the score.', 'paystack-integration-health-checker' ); ?></dd>
</dl>

<h2><?php esc_html_e( 'Troubleshooting guide', 'paystack-integration-health-checker' ); ?></h2>
<p class="description"><?php esc_html_e( 'Each topic lists possible causes and how to confirm which one applies. A diagnostic result only states a cause as confirmed when its own evidence proves it.', 'paystack-integration-health-checker' ); ?></p>
<?php foreach ( Troubleshooting::entries() as $pihc_id => $pihc_entry ) : ?>
	<details class="pihc-kb" id="kb-<?php echo esc_attr( $pihc_id ); ?>">
		<summary><?php echo esc_html( $pihc_entry['title'] ); ?></summary>
		<p><strong><?php esc_html_e( 'Symptoms:', 'paystack-integration-health-checker' ); ?></strong> <?php echo esc_html( $pihc_entry['symptoms'] ); ?></p>
		<p><strong><?php esc_html_e( 'Possible causes', 'paystack-integration-health-checker' ); ?></strong></p>
		<ul>
		<?php
		foreach ( $pihc_entry['causes'] as $pihc_item ) :
			?>
			<li><?php echo esc_html( $pihc_item ); ?></li><?php endforeach; ?></ul>
		<p><strong><?php esc_html_e( 'How to confirm', 'paystack-integration-health-checker' ); ?></strong></p>
		<ul>
		<?php
		foreach ( $pihc_entry['confirm'] as $pihc_item ) :
			?>
			<li><?php echo esc_html( $pihc_item ); ?></li><?php endforeach; ?></ul>
		<p><strong><?php esc_html_e( 'Fix', 'paystack-integration-health-checker' ); ?></strong></p>
		<ul>
		<?php
		foreach ( $pihc_entry['fix'] as $pihc_item ) :
			?>
			<li><?php echo esc_html( $pihc_item ); ?></li><?php endforeach; ?></ul>
	</details>
<?php endforeach; ?>

<h2><?php esc_html_e( 'All checks', 'paystack-integration-health-checker' ); ?></h2>
<table class="widefat striped">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'ID', 'paystack-integration-health-checker' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Check', 'paystack-integration-health-checker' ); ?></th>
			<th scope="col"><?php esc_html_e( 'In full run', 'paystack-integration-health-checker' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Network', 'paystack-integration-health-checker' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $pihc_plugin->registry()->all() as $pihc_check ) : ?>
			<tr>
				<td><code><?php echo esc_html( $pihc_check->id() ); ?></code></td>
				<td><strong><?php echo esc_html( $pihc_check->name() ); ?></strong><br /><?php echo esc_html( $pihc_check->description() ); ?></td>
				<td><?php echo esc_html( $pihc_check->in_full_run() ? __( 'Yes', 'paystack-integration-health-checker' ) : __( 'Manual only', 'paystack-integration-health-checker' ) ); ?></td>
				<td><?php echo esc_html( $pihc_check->is_network() ? __( 'Yes', 'paystack-integration-health-checker' ) : __( 'No', 'paystack-integration-health-checker' ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<h2><?php esc_html_e( 'Documentation', 'paystack-integration-health-checker' ); ?></h2>
<ul class="pihc-docs">
	<?php
	$pihc_docs = array(
		'https://paystack.com/docs/api/'                => __( 'Paystack API reference', 'paystack-integration-health-checker' ),
		'https://paystack.com/docs/api/authentication/' => __( 'API keys and authentication', 'paystack-integration-health-checker' ),
		'https://paystack.com/docs/payments/webhooks/'  => __( 'Webhooks (signature validation, retries, IP addresses)', 'paystack-integration-health-checker' ),
		'https://paystack.com/docs/payments/verify-payments/' => __( 'Verifying payments and transaction statuses', 'paystack-integration-health-checker' ),
		'https://paystack.com/docs/payments/test-payments/' => __( 'Test payments', 'paystack-integration-health-checker' ),
		'https://paystack.com/docs/api/rate-limits/'    => __( 'Rate limits', 'paystack-integration-health-checker' ),
	);
	foreach ( $pihc_docs as $pihc_url => $pihc_label ) {
		echo '<li><a href="' . esc_url( $pihc_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $pihc_label ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'paystack-integration-health-checker' ) . '</span></a></li>';
	}
	?>
</ul>
<p class="description"><?php esc_html_e( 'Independent project; not affiliated with or endorsed by Paystack.', 'paystack-integration-health-checker' ); ?></p>
