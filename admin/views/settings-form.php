<?php
/**
 * Health checker settings (Settings API form). Saved keys are never printed back.
 *
 * Variables: see page.php.
 *
 * @package PaystackHealthChecker
 */

use PaystackHealthChecker\Redactor;
use PaystackHealthChecker\Settings;

defined( 'ABSPATH' ) || exit;

$pihc_plugin   = $pihc['plugin'];
$pihc_settings = $pihc_plugin->settings();
$pihc_choices  = array( 'auto' => __( 'Automatic (first enabled Paystack gateway)', 'paystack-integration-health-checker' ) );
foreach ( $pihc_plugin->detector()->adapters() as $pihc_adapter ) {
	$pihc_choices[ $pihc_adapter->id() ] = $pihc_adapter->label();
}
?>
<h2 id="pihc-settings"><?php esc_html_e( 'Health checker settings', 'paystack-integration-health-checker' ); ?></h2>
<p class="description"><?php esc_html_e( 'These settings belong to the health checker only. Gateway settings are read, never changed.', 'paystack-integration-health-checker' ); ?></p>

<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
	<?php settings_fields( Settings::GROUP ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="pihc-integration"><?php esc_html_e( 'Integration to diagnose', 'paystack-integration-health-checker' ); ?></label></th>
			<td>
				<select id="pihc-integration" name="<?php echo esc_attr( Settings::OPTION ); ?>[integration]">
					<?php foreach ( $pihc_choices as $pihc_value => $pihc_label ) : ?>
						<option value="<?php echo esc_attr( $pihc_value ); ?>" <?php selected( $pihc_settings->integration(), $pihc_value ); ?>><?php echo esc_html( $pihc_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Manual configuration', 'paystack-integration-health-checker' ); ?></th>
			<td>
				<p class="description"><?php esc_html_e( 'For custom integrations or sites without WooCommerce. Prefer defining PIHC_TEST_SECRET_KEY / PIHC_LIVE_SECRET_KEY (and *_PUBLIC_KEY) in wp-config.php. Leave a key field empty to keep the saved value.', 'paystack-integration-health-checker' ); ?></p>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Manual environment', 'paystack-integration-health-checker' ); ?></legend>
					<label><input type="radio" name="<?php echo esc_attr( Settings::OPTION ); ?>[manual_mode]" value="test" <?php checked( 'test', $pihc_settings->manual_mode() ); ?> /> <?php esc_html_e( 'Test', 'paystack-integration-health-checker' ); ?></label>
					&nbsp;
					<label><input type="radio" name="<?php echo esc_attr( Settings::OPTION ); ?>[manual_mode]" value="live" <?php checked( 'live', $pihc_settings->manual_mode() ); ?> /> <?php esc_html_e( 'Live', 'paystack-integration-health-checker' ); ?></label>
				</fieldset>
				<p><label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[manual_uses_popup]" value="1" <?php checked( $pihc_settings->manual_uses_popup() ); ?> /> <?php esc_html_e( 'My integration uses Paystack Popup/Inline (needs a public key)', 'paystack-integration-health-checker' ); ?></label></p>
				<?php foreach ( array( 'test', 'live' ) as $pihc_mode ) : ?>
					<?php foreach ( array( 'secret', 'public' ) as $pihc_type ) : ?>
						<?php
						$pihc_field = 'manual_' . $pihc_mode . '_' . $pihc_type . '_key';
						$pihc_key   = $pihc_settings->manual_key( $pihc_type, $pihc_mode );
						$pihc_const = $pihc_settings->manual_key_from_constant( $pihc_type, $pihc_mode );
						?>
						<p>
							<label for="pihc-<?php echo esc_attr( $pihc_field ); ?>">
								<?php
								/* translators: 1: test/live, 2: secret/public */
								echo esc_html( sprintf( __( '%1$s %2$s key', 'paystack-integration-health-checker' ), ucfirst( $pihc_mode ), $pihc_type ) );
								?>
							</label><br />
							<input type="password" class="regular-text" autocomplete="new-password" id="pihc-<?php echo esc_attr( $pihc_field ); ?>" name="<?php echo esc_attr( Settings::OPTION ); ?>[<?php echo esc_attr( $pihc_field ); ?>]" value="" <?php disabled( $pihc_const ); ?>
								placeholder="<?php echo esc_attr( $pihc_const ? __( 'Defined in wp-config.php', 'paystack-integration-health-checker' ) : ( '' !== $pihc_key ? Redactor::mask_key( $pihc_key ) : __( 'Not set', 'paystack-integration-health-checker' ) ) ); ?>" />
							<?php if ( ! $pihc_const && '' !== $pihc_key ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[clear_<?php echo esc_attr( $pihc_field ); ?>]" value="1" /> <?php esc_html_e( 'Remove saved key', 'paystack-integration-health-checker' ); ?></label>
							<?php endif; ?>
						</p>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'History retention', 'paystack-integration-health-checker' ); ?></th>
			<td>
				<label><?php esc_html_e( 'Keep at most', 'paystack-integration-health-checker' ); ?> <input type="number" min="5" max="500" class="small-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[retention_runs]" value="<?php echo esc_attr( (string) $pihc_settings->retention_runs() ); ?>" /> <?php esc_html_e( 'runs', 'paystack-integration-health-checker' ); ?></label>
				&nbsp;
				<label><?php esc_html_e( 'for at most', 'paystack-integration-health-checker' ); ?> <input type="number" min="1" max="365" class="small-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[retention_days]" value="<?php echo esc_attr( (string) $pihc_settings->retention_days() ); ?>" /> <?php esc_html_e( 'days', 'paystack-integration-health-checker' ); ?></label>
			</td>
		</tr>
	</table>
	<?php submit_button( __( 'Save health checker settings', 'paystack-integration-health-checker' ) ); ?>
</form>
