<?php
/**
 * Handles every privileged request: running checks, verifying references, exporting
 * and deleting history.
 *
 * Each action is available as admin-ajax (JavaScript) and admin-post (no-JS fallback)
 * under the same action name. Every handler:
 *   1. requires the checker capability (403 otherwise),
 *   2. verifies a nonce specific to the action,
 *   3. validates and sanitizes input,
 *   4. applies per-user throttling to network-bound or expensive actions.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Admin;

use PaystackHealthChecker\Diagnostic_Manager;
use PaystackHealthChecker\Plugin;
use PaystackHealthChecker\Reference_Verifier;
use PaystackHealthChecker\Report_Exporter;
use PaystackHealthChecker\Security;
use PaystackHealthChecker\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Admin request controller.
 */
class Admin_Controller {

	public const NONCE_FIELD = '_pihc_nonce';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Whether the current request is AJAX (overridable in tests).
	 *
	 * @var bool|null
	 */
	public $ajax = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registers AJAX and admin-post handlers (logged-in users only; no nopriv hooks).
	 */
	public function register(): void {
		$actions = array(
			'pihc_run'              => 'run_all',
			'pihc_run_check'        => 'run_check',
			'pihc_verify_reference' => 'verify_reference',
			'pihc_export'           => 'export',
			'pihc_delete_run'       => 'delete_run',
			'pihc_delete_all'       => 'delete_all',
		);
		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
			add_action( 'admin_post_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * Runs the full suite.
	 */
	public function run_all(): void {
		$this->guard( Security::NONCE_RUN, 'full_run' );

		$run      = $this->plugin->manager()->run_all();
		$renderer = new Result_Renderer();

		$this->respond(
			array(
				'message'      => __( 'Diagnostics finished.', 'paystack-integration-health-checker' ),
				'summary_html' => $renderer->summary( $run['summary'], $run['run_id'], gmdate( 'Y-m-d H:i:s' ) ),
				'html'         => $renderer->results( $run['results'], true ),
				'run_id'       => $run['run_id'],
			),
			Settings::admin_url( 'overview' )
		);
	}

	/**
	 * Runs one check.
	 */
	public function run_check(): void {
		$this->guard( Security::NONCE_RUN_CHECK, 'run_check' );

		$check_id = isset( $_POST['check_id'] ) ? sanitize_text_field( wp_unslash( $_POST['check_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$check    = $this->plugin->registry()->get( $check_id );
		if ( null === $check ) {
			$this->fail( __( 'Unknown check.', 'paystack-integration-health-checker' ), 400 );
		}

		// The signed test event must be confirmed explicitly.
		if ( 'webhook.signed_test' === $check_id && empty( $_POST['confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
			$this->fail( __( 'Please confirm that you want to send the signed test event.', 'paystack-integration-health-checker' ), 400 );
		}

		$run = $this->plugin->manager()->run_single( $check_id );

		$this->respond(
			array(
				'message' => __( 'Check finished.', 'paystack-integration-health-checker' ),
				'html'    => ( new Result_Renderer() )->result( $run['results'][0], true, $check ),
				'run_id'  => $run['run_id'],
			),
			Settings::admin_url( $this->tab_for_category( $check->category() ) )
		);
	}

	/**
	 * Verifies a transaction reference (Payment Diagnostics).
	 */
	public function verify_reference(): void {
		$this->guard( Security::NONCE_REFERENCE, 'reference' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$reference = isset( $_POST['reference'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['reference'] ) ) ) : '';
		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		// phpcs:enable

		if ( ! Reference_Verifier::is_valid_reference( $reference ) ) {
			$this->fail( __( 'Enter a valid Paystack reference (letters, digits, "-", "." and "=" only).', 'paystack-integration-health-checker' ), 400 );
		}
		if ( $order_id > 0 && ! current_user_can( 'edit_shop_orders' ) ) {
			$this->fail( __( 'You are not allowed to inspect orders.', 'paystack-integration-health-checker' ), 403 );
		}

		$result = $this->plugin->reference_verifier()->verify( $reference, $order_id );
		$run    = $this->plugin->manager()->record( $result );

		$this->respond(
			array(
				'message' => __( 'Verification finished.', 'paystack-integration-health-checker' ),
				'html'    => ( new Result_Renderer() )->result( $run['results'][0], false ),
				'run_id'  => $run['run_id'],
			),
			Settings::admin_url( 'payments' )
		);
	}

	/**
	 * Streams a sanitized report download.
	 */
	public function export(): void {
		$this->guard( Security::NONCE_EXPORT );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified in guard().
		$run_id = isset( $_REQUEST['run_id'] ) ? absint( $_REQUEST['run_id'] ) : 0;
		$format = isset( $_REQUEST['format'] ) && Report_Exporter::FORMAT_TEXT === $_REQUEST['format'] ? Report_Exporter::FORMAT_TEXT : Report_Exporter::FORMAT_JSON;
		// phpcs:enable

		$stored = $run_id > 0 ? $this->plugin->history()->get_run( $run_id ) : $this->plugin->history()->latest_run( Diagnostic_Manager::TRIGGER_FULL );
		if ( null === $stored ) {
			$this->fail( __( 'No diagnostic run to export. Run diagnostics first.', 'paystack-integration-health-checker' ), 404 );
		}

		$exporter = $this->plugin->exporter();
		$body     = $exporter->render( $exporter->build( $stored ), $format );

		nocache_headers();
		header( 'Content-Type: ' . ( Report_Exporter::FORMAT_TEXT === $format ? 'text/plain' : 'application/json' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . Report_Exporter::filename( (int) $stored['run']['id'], $format ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- File download body (JSON/text), not HTML.
		exit;
	}

	/**
	 * Deletes one run.
	 */
	public function delete_run(): void {
		$this->guard( Security::NONCE_DELETE );
		$run_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$this->plugin->history()->delete_run( $run_id );
		$this->respond( array( 'message' => __( 'Run deleted.', 'paystack-integration-health-checker' ) ), Settings::admin_url( 'history' ) );
	}

	/**
	 * Deletes all history.
	 */
	public function delete_all(): void {
		$this->guard( Security::NONCE_DELETE );
		$this->plugin->history()->delete_all();
		$this->respond( array( 'message' => __( 'All diagnostic history was deleted.', 'paystack-integration-health-checker' ) ), Settings::admin_url( 'history' ) );
	}

	/**
	 * Capability, nonce and throttle checks. Terminates the request on failure.
	 *
	 * @param string $nonce_action Nonce action.
	 * @param string $throttle     Throttle key ('' for none).
	 */
	public function guard( string $nonce_action, string $throttle = '' ): void {
		if ( ! Security::current_user_can_use() ) {
			$this->fail( __( 'You are not allowed to use the Paystack health checker.', 'paystack-integration-health-checker' ), 403 );
		}

		$nonce = isset( $_REQUEST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			$this->fail( __( 'Your session expired or the request was not valid. Reload the page and try again.', 'paystack-integration-health-checker' ), 403 );
		}

		if ( '' !== $throttle ) {
			$wait = Security::throttle( $throttle, get_current_user_id() );
			if ( $wait > 0 ) {
				/* translators: %d: seconds */
				$this->fail( sprintf( __( 'Too many requests. Please wait %d seconds and try again.', 'paystack-integration-health-checker' ), $wait ), 429 );
			}
		}
	}

	/**
	 * Sends a success response (JSON for AJAX, redirect otherwise).
	 *
	 * @param array<string,mixed> $data     Payload.
	 * @param string              $redirect Redirect for non-AJAX requests.
	 */
	private function respond( array $data, string $redirect ): void {
		if ( $this->is_ajax() ) {
			wp_send_json_success( $data );
		}
		$args = array( 'pihc_notice' => 'done' );
		if ( isset( $data['run_id'] ) ) {
			$args['run'] = (int) $data['run_id'];
		}
		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/**
	 * Sends an error and stops.
	 *
	 * @param string $message Message (plugin-authored).
	 * @param int    $status  HTTP status.
	 */
	private function fail( string $message, int $status ): void {
		if ( $this->is_ajax() ) {
			// The status is repeated in the body: wp_send_json_error() cannot set it once output has started.
			wp_send_json_error(
				array(
					'message' => $message,
					'status'  => $status,
				),
				$status
			);
		}
		wp_die( esc_html( $message ), esc_html__( 'Paystack Integration Health Checker', 'paystack-integration-health-checker' ), array( 'response' => (int) $status ) );
	}

	/**
	 * Whether this is an AJAX request.
	 */
	private function is_ajax(): bool {
		return null !== $this->ajax ? $this->ajax : wp_doing_ajax();
	}

	/**
	 * Admin tab for a check category.
	 *
	 * @param string $category Category.
	 */
	private function tab_for_category( string $category ): string {
		$map = array(
			'environment'   => 'configuration',
			'configuration' => 'configuration',
			'connectivity'  => 'connectivity',
			'webhooks'      => 'webhooks',
			'woocommerce'   => 'woocommerce',
			'payments'      => 'payments',
		);
		return $map[ $category ] ?? 'overview';
	}
}
