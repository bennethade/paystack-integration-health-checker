<?php
/**
 * Webhook diagnostics, including contract tests against the real pwcg handler.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Tests;

use PaystackHealthChecker\Checks\Webhook_Checks;
use PaystackHealthChecker\Diagnostic_Result;
use PaystackHealthChecker\Integrations\Integration_Adapter;

/**
 * @covers \PaystackHealthChecker\Checks\Webhook_Checks
 */
class WebhookChecksTest extends PIHC_Test_Case {

	public function test_webhook_url_for_official_gateway_is_the_wc_api_endpoint(): void {
		update_option( 'home', 'https://example.org' );
		$this->use_official();

		$result = $this->run_check( 'webhook.url' );

		$this->assertStatus( Diagnostic_Result::PASS, $result );
		$this->assertStringContainsString( 'wc-api=Tbz_WC_Paystack_Webhook', $result->evidence()['webhook_url'] );
	}

	public function test_http_webhook_url_fails_in_live_mode(): void {
		update_option( 'home', 'http://example.org' );
		$this->use_pwcg(
			array(
				'testmode'        => 'no',
				'live_secret_key' => self::LIVE_SECRET,
			)
		);

		$this->assertStatus( Diagnostic_Result::FAIL, $this->run_check( 'webhook.url' ) );
	}

	public function test_handlers_are_detected_as_registered(): void {
		$this->use_official();
		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'webhook.handler_registered' ) );

		$this->use_pwcg();
		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'webhook.handler_registered' ) );
	}

	public function test_manual_integration_skips_webhook_endpoint_checks(): void {
		$this->use_manual();

		$this->assertStatus( Diagnostic_Result::SKIPPED, $this->run_check( 'webhook.endpoint_reachable' ) );
		$this->assertCount( 0, $this->requests );
	}

	public function test_rest_handler_rejecting_unsigned_probe_is_reachable_and_enforcing(): void {
		$this->use_pwcg();
		$this->mock_loopback( self::response( 401, array( 'reason' => 'missing_signature' ) ) );

		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'webhook.endpoint_reachable' ) );
		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'webhook.signature_enforced' ) );

		$probe = $this->requests[0];
		$this->assertSame( 'POST', $probe['args']['method'] );
		$this->assertArrayNotHasKey( 'x-paystack-signature', $probe['args']['headers'], 'The probe is unsigned.' );
		$this->assertStringNotContainsString( 'charge.success', $probe['args']['body'] );
	}

	public function test_handler_accepting_unsigned_requests_fails_signature_check(): void {
		$this->use_pwcg();
		$this->mock_loopback( self::response( 200, array( 'ok' => true ) ) );

		$this->assertStatus( Diagnostic_Result::FAIL, $this->run_check( 'webhook.signature_enforced' ) );
	}

	/**
	 * @dataProvider probe_outcomes
	 *
	 * @param string $kind     Adapter.
	 * @param mixed  $response Loopback response.
	 * @param string $expected Expected status.
	 */
	public function test_reachability_interpretation( string $kind, $response, string $expected ): void {
		'official' === $kind ? $this->use_official() : $this->use_pwcg();
		$this->mock_loopback( $response );

		$this->assertStatus( $expected, $this->run_check( 'webhook.endpoint_reachable' ) );
	}

	public function probe_outcomes(): array {
		return array(
			'official handler: 200 empty body'         => array( 'official', self::response( 200, '' ), Diagnostic_Result::PASS ),
			'official: wc-api 400 = no handler'        => array( 'official', self::response( 400, '-1' ), Diagnostic_Result::FAIL ),
			'official: 403 from a security layer'      => array( 'official', self::response( 403, 'Forbidden' ), Diagnostic_Result::WARNING ),
			'rest: route missing'                      => array( 'pwcg', self::response( 404, array( 'code' => 'rest_no_route' ) ), Diagnostic_Result::FAIL ),
			'handler fatal error'                      => array( 'pwcg', self::response( 500, 'Critical error' ), Diagnostic_Result::FAIL ),
			'redirect'                                 => array( 'pwcg', self::response( 301, '', array( 'location' => 'https://example.org/wp-json/pwcg/v1/webhook/' ) ), Diagnostic_Result::WARNING ),
			'loopback blocked is not proof of failure' => array( 'pwcg', new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ), Diagnostic_Result::WARNING ),
		);
	}

	public function test_official_signature_enforcement_is_reported_as_not_observable(): void {
		$this->use_official();

		$result = $this->run_check( 'webhook.signature_enforced' );

		$this->assertStatus( Diagnostic_Result::SKIPPED, $result );
		$this->assertStringContainsString( 'HMAC-SHA512', $result->explanation() );
	}

	public function test_signed_test_event_is_correctly_signed_and_not_a_payment_event(): void {
		$this->use_pwcg();
		$this->mock_loopback(
			self::response(
				200,
				array(
					'reason'    => 'unsupported_event',
					'processed' => false,
				)
			)
		);

		$result = $this->run_check( 'webhook.signed_test' );

		$this->assertStatus( Diagnostic_Result::PASS, $result );
		$request = $this->requests[0];
		$body    = $request['args']['body'];
		$this->assertSame( hash_hmac( 'sha512', $body, self::TEST_SECRET ), $request['args']['headers']['x-paystack-signature'] );
		$this->assertSame( Webhook_Checks::TEST_EVENT, json_decode( $body, true )['event'] );
		$this->assertStringNotContainsString( 'charge.', $body );
	}

	public function test_signed_test_event_is_not_part_of_the_full_run(): void {
		$check = \PaystackHealthChecker\Plugin::instance()->registry()->get( 'webhook.signed_test' );

		$this->assertFalse( $check->in_full_run() );
	}

	/**
	 * @dataProvider signed_outcomes
	 *
	 * @param string $kind     Webhook kind.
	 * @param int    $status   HTTP status.
	 * @param string $body     Body.
	 * @param mixed  $expected Expected acceptance.
	 */
	public function test_signed_event_interpretation( string $kind, int $status, string $body, $expected ): void {
		$probe = array(
			'ok'         => true,
			'status'     => $status,
			'body'       => $body,
			'elapsed_ms' => 5,
			'error'      => '',
			'location'   => '',
		);

		$this->assertSame( $expected, Webhook_Checks::signed_event_accepted( $kind, $probe ) );
	}

	public function signed_outcomes(): array {
		return array(
			'rest accepted'           => array( Integration_Adapter::WEBHOOK_REST, 200, '{"reason":"unsupported_event","processed":false}', true ),
			'rest rejected'           => array( Integration_Adapter::WEBHOOK_REST, 401, '{"reason":"invalid_signature"}', false ),
			'wc-api accepted (-1)'    => array( Integration_Adapter::WEBHOOK_WC_API, 200, '-1', true ),
			'wc-api rejected (empty)' => array( Integration_Adapter::WEBHOOK_WC_API, 200, '', false ),
			'unknown'                 => array( Integration_Adapter::WEBHOOK_WC_API, 302, '', null ),
		);
	}

	public function test_loopback_requests_only_go_to_this_site(): void {
		$this->use_pwcg();
		add_filter(
			'rest_url',
			static function () {
				return 'https://attacker.example/collect';
			}
		);

		$reach  = $this->run_check( 'webhook.endpoint_reachable' );
		$signed = $this->run_check( 'webhook.signed_test' );

		$this->assertStatus( Diagnostic_Result::SKIPPED, $reach );
		$this->assertStatus( Diagnostic_Result::SKIPPED, $signed );
		$this->assertCount( 0, $this->requests, 'Nothing was sent to a foreign host.' );
	}

	public function test_localhost_site_cannot_receive_webhooks(): void {
		update_option( 'home', 'http://localhost' );
		$this->use_pwcg();

		$result = $this->run_check( 'webhook.public_reachability' );

		$this->assertStatus( Diagnostic_Result::FAIL, $result );
		$this->assertSame( 'site_not_public', $result->kb() );
	}

	/**
	 * Contract test: the real pwcg handler answers the checker's probes exactly as the
	 * checker's interpretation assumes (dispatched in-process through the REST server).
	 */
	public function test_contract_with_the_real_pwcg_handler(): void {
		if ( ! class_exists( '\PaystackWooGateway\Plugin' ) ) {
			$this->markTestSkipped( 'paystack-woocommerce-gateway is not installed next to this project.' );
		}
		$this->use_pwcg();
		\PaystackWooGateway\Plugin::instance()->reset();

		$unsigned = new \WP_REST_Request( 'POST', '/pwcg/v1/webhook' );
		$unsigned->set_header( 'content-type', 'application/json' );
		$unsigned->set_body( (string) wp_json_encode( array( 'event' => 'pihc.reachability_probe' ) ) );
		$unsigned_response = rest_get_server()->dispatch( $unsigned );

		$body   = (string) wp_json_encode(
			array(
				'event' => Webhook_Checks::TEST_EVENT,
				'data'  => array( 'note' => 'test' ),
			)
		);
		$signed = new \WP_REST_Request( 'POST', '/pwcg/v1/webhook' );
		$signed->set_header( 'content-type', 'application/json' );
		$signed->set_header( 'x-paystack-signature', hash_hmac( 'sha512', $body, self::TEST_SECRET ) );
		$signed->set_body( $body );
		$signed_response = rest_get_server()->dispatch( $signed );

		$this->assertSame( 401, $unsigned_response->get_status() );
		$this->assertSame( 200, $signed_response->get_status() );
		$this->assertTrue(
			Webhook_Checks::signed_event_accepted(
				Integration_Adapter::WEBHOOK_REST,
				array(
					'ok'         => true,
					'status'     => $signed_response->get_status(),
					'body'       => (string) wp_json_encode( $signed_response->get_data() ),
					'elapsed_ms' => 1,
					'error'      => '',
					'location'   => '',
				)
			)
		);
	}

	public function test_webhook_log_errors_are_reported_with_redacted_excerpts(): void {
		$this->use_pwcg();
		$file = trailingslashit( WC_LOG_DIR ) . 'pwcg-paystack-' . gmdate( 'Y-m-d' ) . '-' . wp_generate_password( 8, false ) . '.log';
		file_put_contents( $file, gmdate( 'c' ) . ' WARNING Webhook rejected: invalid signature. key=' . self::TEST_SECRET . " customer=ada@example.com\n" . gmdate( 'c' ) . " INFO Payment completed.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		// Other tests write real gateway log entries in the same second; make this file the newest.
		touch( $file, time() + 5 ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		try {
			$result = $this->run_check( 'webhook.local_errors' );
		} finally {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$this->assertStatus( Diagnostic_Result::WARNING, $result );
		$json = wp_json_encode( $result->to_array() );
		$this->assertStringContainsString( 'invalid signature', $json );
		$this->assertStringNotContainsString( self::TEST_SECRET, $json );
		$this->assertStringNotContainsString( 'ada@example.com', $json );
	}
}
