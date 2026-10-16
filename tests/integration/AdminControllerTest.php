<?php
/**
 * Authorization, nonce, throttling and input validation of every admin action,
 * exercised through WordPress' real admin-ajax handling.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Tests;

use PaystackHealthChecker\Plugin;
use PaystackHealthChecker\Security;
use PaystackHealthChecker\Settings;

/**
 * @covers \PaystackHealthChecker\Admin\Admin_Controller
 * @group ajax
 */
class AdminControllerTest extends \WP_Ajax_UnitTestCase {

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		update_option(
			'woocommerce_paystack_settings',
			array(
				'enabled'         => 'yes',
				'testmode'        => 'yes',
				'payment_page'    => 'redirect',
				'test_secret_key' => PIHC_Test_Case::TEST_SECRET,
			)
		);
		update_option( Settings::OPTION, array( 'integration' => 'woo-paystack' ) );
		update_option( 'woocommerce_currency', 'NGN' );
		Plugin::instance()->reset();
		WC()->payment_gateways()->init();
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );

		// woo-paystack 5.8.5 adds an admin note on admin_init using Note::set_layout(), deprecated in
		// WooCommerce 10.8. The WordPress test framework fails on unexpected deprecations, and the
		// notice is unrelated to this plugin, so the third-party hook is detached for these tests.
		remove_action( 'admin_init', 'tbz_wc_paystack_testmode_notice' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		Plugin::instance()->reset();
		parent::tear_down();
	}

	/**
	 * Mocks Paystack and loopback responses.
	 *
	 * @param mixed  $preempt Preempt.
	 * @param array  $args    Args.
	 * @param string $url     URL.
	 */
	public function mock_http( $preempt, $args, $url ) {
		$body = 0 === strpos( $url, 'https://api.paystack.co/' )
			? wp_json_encode(
				array(
					'status'  => true,
					'message' => 'ok',
					'data'    => array( 'payment_session_timeout' => 30 ),
				)
			)
			: '';
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Calls an AJAX action and returns [ http_status, decoded response ].
	 *
	 * @param string $action Action.
	 * @param array  $post   POST data.
	 */
	private function call( string $action, array $post = array() ): array {
		$_POST    = $post;
		$_REQUEST = $post;
		try {
			$this->_handleAjax( $action );
		} catch ( \WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( \WPAjaxDieStopException $e ) {
			unset( $e );
		}
		$response             = json_decode( (string) $this->_last_response, true );
		$this->_last_response = '';
		// Success responses carry no status; errors repeat the HTTP status in the body.
		$status = ! empty( $response['success'] ) ? 200 : (int) ( $response['data']['status'] ?? 0 );
		return array( $status, $response );
	}

	private function as_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_full_run_succeeds_for_an_authorized_user_with_a_valid_nonce(): void {
		$this->as_admin();

		list( $status, $response ) = $this->call( 'pihc_run', array( '_pihc_nonce' => wp_create_nonce( Security::NONCE_RUN ) ) );

		$this->assertSame( 200, $status );
		$this->assertTrue( $response['success'] );
		$this->assertStringContainsString( 'pihc-card', $response['data']['html'] );
		$this->assertStringContainsString( 'Overall health', $response['data']['summary_html'] );
		$this->assertStringNotContainsString( PIHC_Test_Case::TEST_SECRET, (string) wp_json_encode( $response ) );
		$this->assertSame( 1, Plugin::instance()->history()->count_runs() );
	}

	/**
	 * @dataProvider unauthorized_roles
	 */
	public function test_users_without_the_capability_are_rejected( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

		list( $status, $response ) = $this->call( 'pihc_run', array( '_pihc_nonce' => wp_create_nonce( Security::NONCE_RUN ) ) );

		$this->assertSame( 403, $status );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 0, Plugin::instance()->history()->count_runs(), 'Nothing ran.' );
	}

	public function unauthorized_roles(): array {
		return array(
			'subscriber'  => array( 'subscriber' ),
			'customer'    => array( 'customer' ),
			'editor'      => array( 'editor' ),
			'contributor' => array( 'contributor' ),
		);
	}

	public function test_shop_manager_is_allowed_when_woocommerce_is_active(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		list( $status ) = $this->call( 'pihc_run', array( '_pihc_nonce' => wp_create_nonce( Security::NONCE_RUN ) ) );

		$this->assertSame( 200, $status );
	}

	public function test_logged_out_requests_have_no_handler(): void {
		$this->assertFalse( has_action( 'wp_ajax_nopriv_pihc_run' ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_pihc_verify_reference' ) );
	}

	/**
	 * @dataProvider bad_nonces
	 *
	 * @param string $action Action.
	 * @param string $nonce  Nonce to send.
	 */
	public function test_missing_or_wrong_nonces_are_rejected( string $action, string $nonce ): void {
		$this->as_admin();

		list( $status, $response ) = $this->call(
			$action,
			array(
				'_pihc_nonce' => 'other' === $nonce ? wp_create_nonce( 'some_other_action' ) : $nonce,
				'check_id'    => 'env.php_version',
				'reference'   => 'REF-1',
				'run_id'      => '1',
			)
		);

		$this->assertSame( 403, $status );
		$this->assertFalse( $response['success'] );
	}

	public function bad_nonces(): array {
		return array(
			'run: missing'            => array( 'pihc_run', '' ),
			'run: forged'             => array( 'pihc_run', 'abc123' ),
			'run: other action nonce' => array( 'pihc_run', 'other' ),
			'check: missing'          => array( 'pihc_run_check', '' ),
			'reference: missing'      => array( 'pihc_verify_reference', '' ),
			'delete all: missing'     => array( 'pihc_delete_all', '' ),
			'delete run: other'       => array( 'pihc_delete_run', 'other' ),
		);
	}

	public function test_repeated_full_runs_are_throttled(): void {
		$this->as_admin();
		$nonce = wp_create_nonce( Security::NONCE_RUN );

		for ( $i = 0; $i < 3; $i++ ) {
			list( $status ) = $this->call( 'pihc_run', array( '_pihc_nonce' => $nonce ) );
			$this->assertSame( 200, $status );
		}
		list( $status, $response ) = $this->call( 'pihc_run', array( '_pihc_nonce' => $nonce ) );

		$this->assertSame( 429, $status );
		$this->assertStringContainsString( 'wait', $response['data']['message'] );
		$this->assertSame( 3, Plugin::instance()->history()->count_runs() );
	}

	public function test_single_check_rerun(): void {
		$this->as_admin();

		list( $status, $response ) = $this->call(
			'pihc_run_check',
			array(
				'_pihc_nonce' => wp_create_nonce( Security::NONCE_RUN_CHECK ),
				'check_id'    => 'config.secret_key',
			)
		);

		$this->assertSame( 200, $status );
		$this->assertStringContainsString( 'pihc-check-config-secret_key', $response['data']['html'] );
	}

	public function test_unknown_check_is_rejected(): void {
		$this->as_admin();

		list( $status ) = $this->call(
			'pihc_run_check',
			array(
				'_pihc_nonce' => wp_create_nonce( Security::NONCE_RUN_CHECK ),
				'check_id'    => '../../wp-config',
			)
		);

		$this->assertSame( 400, $status );
	}

	public function test_signed_test_event_requires_explicit_confirmation(): void {
		$this->as_admin();

		list( $status, $response ) = $this->call(
			'pihc_run_check',
			array(
				'_pihc_nonce' => wp_create_nonce( Security::NONCE_RUN_CHECK ),
				'check_id'    => 'webhook.signed_test',
			)
		);

		$this->assertSame( 400, $status );
		$this->assertStringContainsString( 'confirm', $response['data']['message'] );
	}

	public function test_reference_input_is_validated(): void {
		$this->as_admin();

		list( $status ) = $this->call(
			'pihc_verify_reference',
			array(
				'_pihc_nonce' => wp_create_nonce( Security::NONCE_REFERENCE ),
				'reference'   => 'http://169.254.169.254/',
			)
		);

		$this->assertSame( 400, $status );
	}

	public function test_reference_verification_succeeds(): void {
		$this->as_admin();

		list( $status, $response ) = $this->call(
			'pihc_verify_reference',
			array(
				'_pihc_nonce' => wp_create_nonce( Security::NONCE_REFERENCE ),
				'reference'   => 'REF-ABC-1',
			)
		);

		$this->assertSame( 200, $status );
		$this->assertStringContainsString( 'Transaction reference verification', $response['data']['html'] );
	}

	public function test_delete_all_history_with_valid_nonce(): void {
		$this->as_admin();
		Plugin::instance()->manager()->run_single( 'env.php_version' );

		list( $status ) = $this->call( 'pihc_delete_all', array( '_pihc_nonce' => wp_create_nonce( Security::NONCE_DELETE ) ) );

		$this->assertSame( 200, $status );
		$this->assertSame( 0, Plugin::instance()->history()->count_runs() );
	}

	public function test_export_requires_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		list( $status ) = $this->call( 'pihc_export', array( '_pihc_nonce' => wp_create_nonce( Security::NONCE_EXPORT ) ) );

		$this->assertSame( 403, $status );
	}
}
