<?php
/**
 * Renders results and summaries as escaped HTML.
 *
 * Used by both page views and AJAX responses, so markup and escaping live in one place.
 * Status is always conveyed by text (PASS/WARNING/FAIL/SKIPPED) and an icon, never by
 * colour alone.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Admin;

use PaystackHealthChecker\Check_Definition;
use PaystackHealthChecker\Diagnostic_Result;
use PaystackHealthChecker\Health_Summary;
use PaystackHealthChecker\Security;
use PaystackHealthChecker\Settings;
use PaystackHealthChecker\Troubleshooting;

defined( 'ABSPATH' ) || exit;

/**
 * HTML renderer.
 */
class Result_Renderer {

	/**
	 * Status label.
	 *
	 * @param string $status Status.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			Diagnostic_Result::PASS    => __( 'PASS', 'paystack-integration-health-checker' ),
			Diagnostic_Result::WARNING => __( 'WARNING', 'paystack-integration-health-checker' ),
			Diagnostic_Result::FAIL    => __( 'FAIL', 'paystack-integration-health-checker' ),
			Diagnostic_Result::SKIPPED => __( 'SKIPPED', 'paystack-integration-health-checker' ),
		);
		return $labels[ $status ] ?? strtoupper( $status );
	}

	/**
	 * Status badge.
	 *
	 * @param string $status Status.
	 */
	public static function badge( string $status ): string {
		$icons = array(
			Diagnostic_Result::PASS    => '✓',
			Diagnostic_Result::WARNING => '!',
			Diagnostic_Result::FAIL    => '✕',
			Diagnostic_Result::SKIPPED => '–',
		);
		return '<span class="pihc-badge pihc-badge--' . esc_attr( $status ) . '"><span aria-hidden="true">' . esc_html( $icons[ $status ] ?? '?' ) . '</span> ' . esc_html( self::status_label( $status ) ) . '</span>';
	}

	/**
	 * A list of results.
	 *
	 * @param Diagnostic_Result[] $results Results.
	 * @param bool                $rerun   Show "Run this check" buttons.
	 */
	public function results( array $results, bool $rerun ): string {
		$html = '';
		foreach ( $results as $result ) {
			$html .= $this->result( $result, $rerun );
		}
		return $html;
	}

	/**
	 * One result card (or a "not run yet" card when $result is null).
	 *
	 * @param Diagnostic_Result|null $result Result.
	 * @param bool                   $rerun  Show a re-run button.
	 * @param Check_Definition|null  $check  Definition (for name/description/not-run state).
	 */
	public function result( ?Diagnostic_Result $result, bool $rerun, ?Check_Definition $check = null ): string {
		$check_id = null !== $result ? $result->check_id() : ( null !== $check ? $check->id() : '' );
		$name     = null !== $result && '' !== $result->check_name() ? $result->check_name() : ( null !== $check ? $check->name() : $check_id );
		$dom_id   = 'pihc-check-' . sanitize_html_class( str_replace( '.', '-', $check_id ) );

		ob_start();
		?>
		<div class="pihc-card<?php echo null !== $result ? ' pihc-card--' . esc_attr( $result->status() ) : ' pihc-card--notrun'; ?>" id="<?php echo esc_attr( $dom_id ); ?>">
			<div class="pihc-card__head">
				<?php
				if ( null !== $result ) {
					echo self::badge( $result->status() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
				} else {
					echo '<span class="pihc-badge pihc-badge--notrun">' . esc_html__( 'NOT RUN', 'paystack-integration-health-checker' ) . '</span>';
				}
				?>
				<h3 class="pihc-card__title"><?php echo esc_html( $name ); ?></h3>
				<code class="pihc-card__id"><?php echo esc_html( $check_id ); ?></code>
			</div>

			<?php if ( null === $result ) : ?>
				<p class="pihc-card__summary"><?php echo esc_html( null !== $check ? $check->description() : '' ); ?></p>
			<?php else : ?>
				<p class="pihc-card__summary"><?php echo esc_html( $result->summary() ); ?></p>
				<p class="pihc-card__meta">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: date/time, 2: milliseconds */
							__( 'Checked %1$s · %2$s ms', 'paystack-integration-health-checker' ),
							wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $result->timestamp() ),
							null !== $result->duration_ms() ? (string) $result->duration_ms() : '–'
						)
					);
					?>
				</p>
				<?php if ( $result->next_steps() ) : ?>
					<div class="pihc-card__steps">
						<strong><?php esc_html_e( 'What to do next', 'paystack-integration-health-checker' ); ?></strong>
						<ul>
							<?php foreach ( $result->next_steps() as $step ) : ?>
								<li><?php echo esc_html( $step ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
				<?php if ( '' !== $result->explanation() || $result->evidence() ) : ?>
					<details class="pihc-card__details">
						<summary><?php esc_html_e( 'Technical details and evidence', 'paystack-integration-health-checker' ); ?></summary>
						<?php if ( '' !== $result->explanation() ) : ?>
							<p><?php echo esc_html( $result->explanation() ); ?></p>
						<?php endif; ?>
						<?php if ( $result->evidence() ) : ?>
							<?php echo $this->evidence_table( $result->evidence() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in evidence_table(). ?>
						<?php endif; ?>
					</details>
				<?php endif; ?>
				<?php echo $this->links( $result ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in links(). ?>
			<?php endif; ?>

			<?php if ( $rerun && '' !== $check_id && 'flow.reference_verification' !== $check_id ) : ?>
				<?php echo $this->rerun_form( $check_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in rerun_form(). ?>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Summary tiles.
	 *
	 * @param Health_Summary $summary  Summary.
	 * @param int            $run_id   Run ID.
	 * @param string         $run_date Run date (UTC, MySQL format).
	 */
	public function summary( Health_Summary $summary, int $run_id, string $run_date ): string {
		$overall = $summary->overall();
		$score   = $summary->score();
		ob_start();
		?>
		<div class="pihc-summary pihc-summary--<?php echo esc_attr( $overall ); ?>">
			<div class="pihc-summary__overall">
				<span class="pihc-summary__label"><?php esc_html_e( 'Overall health', 'paystack-integration-health-checker' ); ?></span>
				<strong class="pihc-summary__value"><?php echo esc_html( Health_Summary::label( $overall ) ); ?></strong>
				<span class="pihc-summary__score">
					<?php
					echo esc_html(
						null === $score
							? __( 'Score: n/a (nothing could be evaluated)', 'paystack-integration-health-checker' )
							/* translators: 1: score, 2: coverage percent */
							: sprintf( __( 'Score %1$d/100 · %2$d%% of checks evaluated', 'paystack-integration-health-checker' ), $score, $summary->coverage() )
					);
					?>
				</span>
			</div>
			<?php foreach ( Diagnostic_Result::STATUSES as $status ) : ?>
				<div class="pihc-tile pihc-tile--<?php echo esc_attr( $status ); ?>">
					<span class="pihc-tile__count"><?php echo esc_html( (string) $summary->count( $status ) ); ?></span>
					<span class="pihc-tile__label"><?php echo esc_html( self::status_label( $status ) ); ?></span>
				</div>
			<?php endforeach; ?>
			<p class="pihc-summary__when">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: run ID, 2: date */
						__( 'Run #%1$d · %2$s', 'paystack-integration-health-checker' ),
						$run_id,
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $run_date . ' UTC' ) )
					)
				);
				?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Evidence as a definition table.
	 *
	 * @param array<string,mixed> $evidence Evidence.
	 */
	public function evidence_table( array $evidence ): string {
		$html = '<table class="pihc-evidence widefat striped"><tbody>';
		foreach ( $evidence as $key => $value ) {
			$html .= '<tr><th scope="row">' . esc_html( (string) $key ) . '</th><td>' . $this->evidence_value( $value ) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	/**
	 * Formats an evidence value. Admin URLs in evidence become links.
	 *
	 * @param mixed $value Value.
	 */
	private function evidence_value( $value ): string {
		if ( is_array( $value ) ) {
			if ( array() === $value ) {
				return '<em>' . esc_html__( 'none', 'paystack-integration-health-checker' ) . '</em>';
			}
			$items = '';
			foreach ( $value as $key => $item ) {
				$items .= '<li>' . ( is_int( $key ) ? '' : '<code>' . esc_html( (string) $key ) . '</code>: ' ) . $this->evidence_value( $item ) . '</li>';
			}
			return '<ul class="pihc-evidence__list">' . $items . '</ul>';
		}
		if ( is_bool( $value ) ) {
			return esc_html( $value ? 'yes' : 'no' );
		}
		$text = (string) $value;
		if ( 0 === strpos( $text, admin_url() ) ) {
			return '<a href="' . esc_url( $text ) . '">' . esc_html__( 'Open settings', 'paystack-integration-health-checker' ) . '</a>';
		}
		return '<code>' . esc_html( $text ) . '</code>';
	}

	/**
	 * Troubleshooting and documentation links.
	 *
	 * @param Diagnostic_Result $result Result.
	 */
	private function links( Diagnostic_Result $result ): string {
		$links = array();
		if ( '' !== $result->kb() && null !== Troubleshooting::get( $result->kb() ) ) {
			$entry   = Troubleshooting::get( $result->kb() );
			$links[] = '<a href="' . esc_url( Settings::admin_url( 'tools' ) . '#kb-' . $result->kb() ) . '">' . esc_html(
				/* translators: %s: troubleshooting topic */
				sprintf( __( 'Troubleshooting: %s', 'paystack-integration-health-checker' ), $entry['title'] )
			) . '</a>';
		}
		if ( '' !== $result->doc_url() ) {
			$links[] = '<a href="' . esc_url( $result->doc_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'paystack-integration-health-checker' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'paystack-integration-health-checker' ) . '</span></a>';
		}
		return $links ? '<p class="pihc-card__links">' . implode( ' · ', $links ) . '</p>' : '';
	}

	/**
	 * "Run this check" form (AJAX with admin-post fallback).
	 *
	 * @param string $check_id Check ID.
	 */
	private function rerun_form( string $check_id ): string {
		$dom_id  = 'pihc-check-' . sanitize_html_class( str_replace( '.', '-', $check_id ) );
		$confirm = 'webhook.signed_test' === $check_id;
		ob_start();
		?>
		<form class="pihc-ajax-form pihc-card__rerun" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-target="#<?php echo esc_attr( $dom_id ); ?>" data-replace="outer">
			<input type="hidden" name="action" value="pihc_run_check" />
			<input type="hidden" name="check_id" value="<?php echo esc_attr( $check_id ); ?>" />
			<?php wp_nonce_field( Security::NONCE_RUN_CHECK, Admin_Controller::NONCE_FIELD, false ); ?>
			<?php if ( $confirm ) : ?>
				<label class="pihc-confirm">
					<input type="checkbox" name="confirm" value="1" required />
					<?php esc_html_e( 'I understand this sends a signed, non-payment test event to this site\'s webhook URL. It cannot change orders.', 'paystack-integration-health-checker' ); ?>
				</label>
			<?php endif; ?>
			<button type="submit" class="button">
				<?php echo esc_html( $confirm ? __( 'Send signed test event', 'paystack-integration-health-checker' ) : __( 'Run this check', 'paystack-integration-health-checker' ) ); ?>
			</button>
			<span class="spinner" aria-hidden="true"></span>
		</form>
		<?php
		return (string) ob_get_clean();
	}
}
