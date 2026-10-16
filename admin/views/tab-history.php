<?php
/**
 * Diagnostic History: run list, run detail with per-check trend, export, deletion.
 *
 * Variables: see page.php.
 *
 * @package PaystackHealthChecker
 */

use PaystackHealthChecker\Admin\Admin_Controller;
use PaystackHealthChecker\Admin\Result_Renderer;
use PaystackHealthChecker\Diagnostic_Result;
use PaystackHealthChecker\Health_Summary;
use PaystackHealthChecker\Security;
use PaystackHealthChecker\Settings;

defined( 'ABSPATH' ) || exit;

$pihc_plugin   = $pihc['plugin'];
$pihc_history  = $pihc_plugin->history();
$pihc_renderer = $pihc['renderer'];
$pihc_detail   = $pihc['run_id'] > 0 ? $pihc_history->get_run( $pihc['run_id'] ) : null;
$pihc_triggers = array(
	'full'      => __( 'Full run', 'paystack-integration-health-checker' ),
	'single'    => __( 'Single check', 'paystack-integration-health-checker' ),
	'reference' => __( 'Reference verification', 'paystack-integration-health-checker' ),
);

$pihc_export_url = static function ( int $run_id, string $format ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'pihc_export',
				'run_id' => $run_id,
				'format' => $format,
			),
			admin_url( 'admin-post.php' )
		),
		Security::NONCE_EXPORT,
		Admin_Controller::NONCE_FIELD
	);
};
?>
<?php if ( null !== $pihc_detail ) : ?>
	<p><a href="<?php echo esc_url( Settings::admin_url( 'history' ) ); ?>">&larr; <?php esc_html_e( 'All runs', 'paystack-integration-health-checker' ); ?></a></p>
	<h2>
		<?php
		/* translators: %d: run ID */
		echo esc_html( sprintf( __( 'Run #%d', 'paystack-integration-health-checker' ), $pihc_detail['run']['id'] ) );
		?>
	</h2>
	<?php echo $pihc_renderer->summary( new Health_Summary( $pihc_detail['run']['counts'] ), $pihc_detail['run']['id'], $pihc_detail['run']['created_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in renderer. ?>
	<p>
		<a class="button" href="<?php echo esc_url( $pihc_export_url( $pihc_detail['run']['id'], 'json' ) ); ?>"><?php esc_html_e( 'Download JSON', 'paystack-integration-health-checker' ); ?></a>
		<a class="button" href="<?php echo esc_url( $pihc_export_url( $pihc_detail['run']['id'], 'text' ) ); ?>"><?php esc_html_e( 'Download text', 'paystack-integration-health-checker' ); ?></a>
	</p>

	<h3><?php esc_html_e( 'Comparison with previous runs', 'paystack-integration-health-checker' ); ?></h3>
	<table class="widefat striped pihc-trend">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Check', 'paystack-integration-health-checker' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Recent statuses (newest first)', 'paystack-integration-health-checker' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $pihc_detail['results'] as $pihc_result ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $pihc_result->check_name() ); ?></th>
					<td>
						<?php
						foreach ( $pihc_history->trend( $pihc_result->check_id(), 5 ) as $pihc_point ) {
							echo Result_Renderer::badge( $pihc_point['status'] ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'Results', 'paystack-integration-health-checker' ); ?></h3>
	<?php echo $pihc_renderer->results( $pihc_detail['results'], false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in renderer. ?>
<?php else : ?>
	<?php $pihc_runs = $pihc_history->list_runs( 50 ); ?>
	<p class="pihc-tab-intro">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: max runs, 2: max days */
				__( 'Stored history is limited to %1$d runs and %2$d days (change under Configuration). Only sanitized results are stored: no keys, authorization headers or customer data.', 'paystack-integration-health-checker' ),
				$pihc_plugin->settings()->retention_runs(),
				$pihc_plugin->settings()->retention_days()
			)
		);
		?>
	</p>
	<?php if ( array() === $pihc_runs ) : ?>
		<p><?php esc_html_e( 'No diagnostic runs stored.', 'paystack-integration-health-checker' ); ?></p>
	<?php else : ?>
		<table class="widefat striped pihc-runs">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Run', 'paystack-integration-health-checker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'paystack-integration-health-checker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'paystack-integration-health-checker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Overall', 'paystack-integration-health-checker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Results', 'paystack-integration-health-checker' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'paystack-integration-health-checker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $pihc_runs as $pihc_run ) : ?>
					<tr>
						<td>#<?php echo esc_html( (string) $pihc_run['id'] ); ?></td>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $pihc_run['created_at'] . ' UTC' ) ) ); ?></td>
						<td><?php echo esc_html( $pihc_triggers[ $pihc_run['trigger'] ] ?? $pihc_run['trigger'] ); ?></td>
						<td><?php echo esc_html( Health_Summary::label( $pihc_run['overall'] ) ); ?></td>
						<td>
							<?php
							foreach ( Diagnostic_Result::STATUSES as $pihc_status ) {
								echo esc_html( Result_Renderer::status_label( $pihc_status ) . ' ' . $pihc_run['counts'][ $pihc_status ] ) . ' &nbsp;';
							}
							?>
						</td>
						<td class="pihc-run-actions">
							<a href="<?php echo esc_url( add_query_arg( 'run', $pihc_run['id'], Settings::admin_url( 'history' ) ) ); ?>"><?php esc_html_e( 'View', 'paystack-integration-health-checker' ); ?></a> |
							<a href="<?php echo esc_url( $pihc_export_url( $pihc_run['id'], 'json' ) ); ?>"><?php esc_html_e( 'JSON', 'paystack-integration-health-checker' ); ?></a> |
							<a href="<?php echo esc_url( $pihc_export_url( $pihc_run['id'], 'text' ) ); ?>"><?php esc_html_e( 'Text', 'paystack-integration-health-checker' ); ?></a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pihc-inline-form" data-confirm="<?php esc_attr_e( 'Delete this run?', 'paystack-integration-health-checker' ); ?>">
								<input type="hidden" name="action" value="pihc_delete_run" />
								<input type="hidden" name="run_id" value="<?php echo esc_attr( (string) $pihc_run['id'] ); ?>" />
								<?php wp_nonce_field( Security::NONCE_DELETE, Admin_Controller::NONCE_FIELD, false ); ?>
								<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'paystack-integration-health-checker' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pihc-delete-all" data-confirm="<?php esc_attr_e( 'Delete ALL stored diagnostic history? This cannot be undone.', 'paystack-integration-health-checker' ); ?>">
			<input type="hidden" name="action" value="pihc_delete_all" />
			<?php wp_nonce_field( Security::NONCE_DELETE, Admin_Controller::NONCE_FIELD, false ); ?>
			<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete all history', 'paystack-integration-health-checker' ); ?></button>
		</form>
	<?php endif; ?>
<?php endif; ?>
