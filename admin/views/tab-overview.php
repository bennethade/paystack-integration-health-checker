<?php
/**
 * Overview tab: summary of the latest full run, environment, run and export actions.
 *
 * Variables: see page.php.
 *
 * @package PaystackHealthChecker
 */

use PaystackHealthChecker\Admin\Admin_Controller;
use PaystackHealthChecker\Admin\Admin_Page;
use PaystackHealthChecker\Check_Definition;
use PaystackHealthChecker\Diagnostic_Manager;
use PaystackHealthChecker\Diagnostic_Result;
use PaystackHealthChecker\Health_Summary;
use PaystackHealthChecker\Security;
use PaystackHealthChecker\Settings;

defined( 'ABSPATH' ) || exit;

$pihc_plugin   = $pihc['plugin'];
$pihc_renderer = $pihc['renderer'];
$pihc_latest   = $pihc_plugin->history()->latest_run( Diagnostic_Manager::TRIGGER_FULL );
$pihc_context  = $pihc_plugin->context();
$pihc_adapter  = $pihc_context->adapter();
?>
<div class="pihc-overview">
	<div id="pihc-summary">
		<?php if ( null !== $pihc_latest ) : ?>
			<?php echo $pihc_renderer->summary( new Health_Summary( $pihc_latest['run']['counts'] ), $pihc_latest['run']['id'], $pihc_latest['run']['created_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in renderer. ?>
		<?php else : ?>
			<div class="pihc-summary pihc-summary--unknown">
				<div class="pihc-summary__overall">
					<span class="pihc-summary__label"><?php esc_html_e( 'Overall health', 'paystack-integration-health-checker' ); ?></span>
					<strong class="pihc-summary__value"><?php esc_html_e( 'Not checked yet', 'paystack-integration-health-checker' ); ?></strong>
					<span class="pihc-summary__score"><?php esc_html_e( 'Run diagnostics to see results.', 'paystack-integration-health-checker' ); ?></span>
				</div>
			</div>
		<?php endif; ?>
	</div>

	<div class="pihc-actions">
		<form class="pihc-ajax-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-target="#pihc-results" data-summary="#pihc-summary">
			<input type="hidden" name="action" value="pihc_run" />
			<?php wp_nonce_field( Security::NONCE_RUN, Admin_Controller::NONCE_FIELD, false ); ?>
			<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Run Diagnostics', 'paystack-integration-health-checker' ); ?></button>
			<span class="spinner" aria-hidden="true"></span>
		</form>
		<?php if ( null !== $pihc_latest ) : ?>
			<?php
			$pihc_export = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'pihc_export',
						'run_id' => $pihc_latest['run']['id'],
					),
					admin_url( 'admin-post.php' )
				),
				Security::NONCE_EXPORT,
				Admin_Controller::NONCE_FIELD
			);
			?>
			<a class="button" href="<?php echo esc_url( $pihc_export ); ?>"><?php esc_html_e( 'Download Diagnostic Report (JSON)', 'paystack-integration-health-checker' ); ?></a>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'format', 'text', $pihc_export ) ); ?>"><?php esc_html_e( 'Download as text', 'paystack-integration-health-checker' ); ?></a>
		<?php endif; ?>
	</div>

	<h2><?php esc_html_e( 'Environment', 'paystack-integration-health-checker' ); ?></h2>
	<table class="widefat striped pihc-env">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Integration diagnosed', 'paystack-integration-health-checker' ); ?></th>
				<td><?php echo esc_html( null !== $pihc_adapter ? $pihc_adapter->label() : __( 'None found', 'paystack-integration-health-checker' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Paystack environment', 'paystack-integration-health-checker' ); ?></th>
				<td><?php echo esc_html( null !== $pihc_adapter ? ( 'live' === $pihc_adapter->mode() ? __( 'Live', 'paystack-integration-health-checker' ) : __( 'Test', 'paystack-integration-health-checker' ) ) : '–' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Paystack configuration', 'paystack-integration-health-checker' ); ?></th>
				<td><?php echo esc_html( null === $pihc_adapter ? __( 'Not configured', 'paystack-integration-health-checker' ) : ( '' !== $pihc_adapter->secret_key() ? __( 'Secret key present for the active environment', 'paystack-integration-health-checker' ) : __( 'Secret key missing for the active environment', 'paystack-integration-health-checker' ) ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'WooCommerce', 'paystack-integration-health-checker' ); ?></th>
				<td>
					<?php
					if ( $pihc_context->woocommerce_active() ) {
						/* translators: %s: version */
						echo esc_html( sprintf( __( 'Active (version %s)', 'paystack-integration-health-checker' ), WC_VERSION ) );
					} elseif ( file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ) ) {
						esc_html_e( 'Installed, not active', 'paystack-integration-health-checker' );
					} else {
						esc_html_e( 'Not installed', 'paystack-integration-health-checker' );
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Last diagnostic run', 'paystack-integration-health-checker' ); ?></th>
				<td><?php echo esc_html( null !== $pihc_latest ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $pihc_latest['run']['created_at'] . ' UTC' ) ) : __( 'Never', 'paystack-integration-health-checker' ) ); ?></td>
			</tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Results by section', 'paystack-integration-health-checker' ); ?></h2>
	<div id="pihc-results">
		<?php if ( null === $pihc_latest ) : ?>
			<p><?php esc_html_e( 'No results yet.', 'paystack-integration-health-checker' ); ?></p>
		<?php else : ?>
			<?php
			$pihc_problems = array_filter(
				$pihc_latest['results'],
				static function ( $pihc_result ) {
					return $pihc_result->is( Diagnostic_Result::FAIL ) || $pihc_result->is( Diagnostic_Result::WARNING );
				}
			);
			?>
			<?php if ( $pihc_problems ) : ?>
				<h3><?php esc_html_e( 'Needs your attention', 'paystack-integration-health-checker' ); ?></h3>
				<?php echo $pihc_renderer->results( array_values( $pihc_problems ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in renderer. ?>
			<?php else : ?>
				<p><?php esc_html_e( 'No failures or warnings in the latest run.', 'paystack-integration-health-checker' ); ?></p>
			<?php endif; ?>
			<p>
				<?php
				foreach ( Admin_Page::tabs() as $pihc_slug => $pihc_label ) {
					if ( in_array( $pihc_slug, array( 'overview', 'history', 'tools' ), true ) ) {
						continue;
					}
					echo '<a class="button-link pihc-section-link" href="' . esc_url( Settings::admin_url( $pihc_slug ) ) . '">' . esc_html( $pihc_label ) . ' &rarr;</a> ';
				}
				?>
			</p>
		<?php endif; ?>
	</div>

	<h2><?php esc_html_e( 'How the overall status is calculated', 'paystack-integration-health-checker' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Any FAIL makes the overall status "Problems found"; otherwise any WARNING makes it "Needs attention"; only passes make it "Healthy". The score counts PASS as 1 and WARNING as 0.5, divided by the checks that were actually evaluated. SKIPPED checks never raise or lower the score; the percentage of evaluated checks is shown instead.', 'paystack-integration-health-checker' ); ?>
	</p>
</div>
