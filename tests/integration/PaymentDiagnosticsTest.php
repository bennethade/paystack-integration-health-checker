<?php
/**
 * Payment flow checks and reference verification (read-only).
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Tests;

use PaystackHealthChecker\Diagnostic_Result;
use PaystackHealthChecker\Plugin;
use PaystackHealthChecker\Reference_Verifier;

/**
 * @covers \PaystackHealthChecker\Reference_Verifier
 * @covers \PaystackHealthChecker\Checks\Payment_Flow_Checks
 */
class PaymentDiagnosticsTest extends PIHC_Test_Case {

	public function set_up() {
		parent::set_up();
		$this->use_official();
	}

	/**
	 * Verify response built from Paystack's documented sample, including fields that
	 * must never be stored (customer e-mail, authorization, IP).
	 *
	 * @param string $reference Reference.
	 * @param array  $overrides Overrides.
	 */
	private static function verify_response( string $reference, array $overrides = array() ): array {
		return self::response(
			200,
			array(
				'status'  => true,
				'message' => 'Verification successful',
				'data'    => array_merge(
					array(
						'id'               => 4099260516,
						'domain'           => 'test',
						'status'           => 'success',
						'reference'        => $reference,
						'amount'           => 1500000,
						'requested_amount' => 1500000,
						'currency'         => 'NGN',
						'channel'          => 'card',
						'gateway_response' => 'Successful',
						'paid_at'          => '2026-10-16T09:15:02.000Z',
						'ip_address'       => '197.210.54.33',
						'authorization'    => array(
							'authorization_code' => 'AUTH_uh8bcl3zbn',
							'last4'              => '4081',
							'bin'                => '408408',
						),
						'customer'         => array(
							'email'         => 'ada@example.com',
							'customer_code' => 'CUS_1rkzaqsv4rrhqo6',
						),
					),
					$overrides
				),
			)
		);
	}

	/**
	 * Captures the order's state and the hooks fired, to prove nothing was written.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function snapshot( \WC_Order $order ): array {
		$fresh = wc_get_order( $order->get_id() );
		return array(
			'status'   => $fresh->get_status(),
			'txn'      => $fresh->get_transaction_id(),
			'modified' => (string) $fresh->get_date_modified(),
			'meta'     => wp_list_pluck( $fresh->get_meta_data(), 'value', 'key' ),
			'notes'    => count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ),
		);
	}

	private function verify( string $reference, int $order_id = 0 ): Diagnostic_Result {
		$result = Plugin::instance()->reference_verifier()->verify( $reference, $order_id );
		Plugin::instance()->manager()->record( $result );
		return Plugin::instance()->history()->latest_run( 'reference' )['results'][0];
	}

	public function test_successful_payment_matching_a_paid_order_passes(): void {
		$order = $this->create_order(
			array(
				'status' => 'processing',
				'txn'    => '4099260516',
				'meta'   => array( '_paystack_txn_ref' => 'REF-OK-1' ),
			)
		);
		$this->mock_api( self::verify_response( 'REF-OK-1' ) );

		$result = $this->verify( 'REF-OK-1', $order->get_id() );

		$this->assertStatus( Diagnostic_Result::PASS, $result );
		$this->assertSame( 'https://api.paystack.co/transaction/verify/REF-OK-1', $this->requests[0]['url'] );
	}

	public function test_payment_not_reflected_in_order_fails_without_changing_the_order(): void {
		$order  = $this->create_order( array( 'status' => 'pending' ) );
		$before = $this->snapshot( $order );
		$hooks  = 0;
		$count  = static function () use ( &$hooks ) {
			++$hooks;
		};
		foreach ( array( 'woocommerce_order_status_changed', 'woocommerce_update_order', 'woocommerce_payment_complete', 'woocommerce_new_order_note_data' ) as $hook ) {
			add_action( $hook, $count );
		}
		$this->mock_api( self::verify_response( 'REF-STUCK-1' ) );

		$result = $this->verify( 'REF-STUCK-1', $order->get_id() );

		$this->assertStatus( Diagnostic_Result::FAIL, $result );
		$this->assertSame( 'order_not_updating', $result->kb() );
		$this->assertSame( $before, $this->snapshot( $order ), 'The order was not modified.' );
		$this->assertSame( 0, $hooks, 'No order hooks fired.' );
	}

	public function test_amount_mismatch_fails(): void {
		$order = $this->create_order( array( 'status' => 'processing' ) );
		$this->mock_api(
			self::verify_response(
				'REF-AMT',
				array(
					'amount'           => 100,
					'requested_amount' => 100,
				)
			)
		);

		$result = $this->verify( 'REF-AMT', $order->get_id() );

		$this->assertStatus( Diagnostic_Result::FAIL, $result );
		$this->assertSame( 'amount_currency_mismatch', $result->kb() );
	}

	public function test_currency_mismatch_fails(): void {
		$order = $this->create_order( array( 'status' => 'processing' ) );
		$this->mock_api( self::verify_response( 'REF-CCY', array( 'currency' => 'USD' ) ) );

		$this->assertStatus( Diagnostic_Result::FAIL, $this->verify( 'REF-CCY', $order->get_id() ) );
	}

	public function test_customer_bearing_fees_is_not_a_mismatch(): void {
		$order = $this->create_order( array( 'status' => 'processing' ) );
		$this->mock_api( self::verify_response( 'REF-FEES', array( 'amount' => 1525000 ) ) );

		$this->assertStatus( Diagnostic_Result::PASS, $this->verify( 'REF-FEES', $order->get_id() ) );
	}

	public function test_transaction_from_the_other_environment_is_flagged(): void {
		$this->mock_api( self::verify_response( 'REF-ENV', array( 'domain' => 'live' ) ) );

		$result = $this->verify( 'REF-ENV' );

		$this->assertStatus( Diagnostic_Result::WARNING, $result );
		$this->assertSame( 'wrong_environment', $result->kb() );
	}

	public function test_unknown_reference_fails_with_guidance(): void {
		$this->mock_api(
			self::response(
				404,
				array(
					'status'  => false,
					'message' => 'Transaction reference not found',
				)
			)
		);

		$result = $this->verify( 'REF-NOPE' );

		$this->assertStatus( Diagnostic_Result::FAIL, $result );
		$this->assertSame( 'wrong_reference', $result->kb() );
	}

	public function test_failed_transaction_is_a_warning_not_a_pass(): void {
		$this->mock_api( self::verify_response( 'REF-FAILED', array( 'status' => 'failed' ) ) );

		$this->assertStatus( Diagnostic_Result::WARNING, $this->verify( 'REF-FAILED' ) );
	}

	public function test_invalid_reference_is_rejected_without_calling_paystack(): void {
		$result = Plugin::instance()->reference_verifier()->verify( '../../integration', 0 );

		$this->assertStatus( Diagnostic_Result::SKIPPED, $result );
		$this->assertCount( 0, $this->requests );
	}

	public function test_customer_card_and_authorization_data_are_never_stored(): void {
		$order = $this->create_order( array( 'status' => 'processing' ) );
		$this->mock_api( self::verify_response( 'REF-PII' ) );

		$this->verify( 'REF-PII', $order->get_id() );
		global $wpdb;
		$stored = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT details FROM %i ORDER BY id DESC LIMIT 1', $wpdb->prefix . 'pihc_results' ) ); // phpcs:ignore WordPress.DB

		$this->assertStringContainsString( 'REF-PII', $stored );
		foreach ( array( 'ada@example.com', 'AUTH_uh8bcl3zbn', '408408', 'CUS_1rkzaqsv4rrhqo6', '197.210.54.33', 'customer@example.com', self::TEST_SECRET ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $stored );
		}
	}

	public function test_api_failure_during_verification_is_classified(): void {
		$this->mock_api( new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );

		$this->assertStatus( Diagnostic_Result::FAIL, $this->verify( 'REF-TIMEOUT' ) );
	}

	public function test_subunit_conversion_is_exact(): void {
		$this->assertSame( 1999, Reference_Verifier::to_subunits( '19.99' ) );
		$this->assertSame( 1500000, Reference_Verifier::to_subunits( '15000.00' ) );
		$this->assertNull( Reference_Verifier::to_subunits( '10.005' ) );
	}

	public function test_no_orders_means_flow_evidence_is_skipped(): void {
		$this->assertStatus( Diagnostic_Result::SKIPPED, $this->run_check( 'flow.recent_orders' ) );
	}

	public function test_only_unpaid_orders_is_a_warning(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->create_order( array( 'status' => 'pending' ) );
		}

		$this->assertStatus( Diagnostic_Result::WARNING, $this->run_check( 'flow.recent_orders' ) );
	}

	public function test_paid_orders_pass_and_counts_are_reported(): void {
		$this->create_order(
			array(
				'status' => 'processing',
				'txn'    => '1',
			)
		);
		$this->create_order(
			array(
				'status' => 'completed',
				'txn'    => '2',
			)
		);
		$this->create_order( array( 'status' => 'pending' ) );
		$this->create_order(
			array(
				'status'  => 'processing',
				'gateway' => 'bacs',
			)
		);

		$result = $this->run_check( 'flow.recent_orders' );

		$this->assertStatus( Diagnostic_Result::PASS, $result );
		$this->assertSame( 3, $result->evidence()['orders_examined'], 'Other gateways are excluded.' );
	}

	public function test_stuck_pending_orders_are_reported(): void {
		$this->create_order(
			array(
				'status'      => 'pending',
				'age_minutes' => 120,
			)
		);
		$this->create_order(
			array(
				'status'      => 'pending',
				'age_minutes' => 5,
			)
		);

		$result = $this->run_check( 'flow.stuck_orders' );

		$this->assertStatus( Diagnostic_Result::WARNING, $result );
		$this->assertSame( 1, $result->evidence()['count'] );
	}

	public function test_paid_order_without_transaction_id_is_reported(): void {
		$this->create_order(
			array(
				'status' => 'processing',
				'txn'    => '',
			)
		);

		$this->assertStatus( Diagnostic_Result::WARNING, $this->run_check( 'flow.paid_without_transaction' ) );
	}
}
