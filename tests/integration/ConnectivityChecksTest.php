<?php
/**
 * API connectivity and authentication diagnostics.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Tests;

use PaystackHealthChecker\Api_Response;
use PaystackHealthChecker\Diagnostic_Result;
use PaystackHealthChecker\Plugin;

/**
 * @covers \PaystackHealthChecker\Checks\Connectivity_Checks
 * @covers \PaystackHealthChecker\Paystack_Api_Client
 * @covers \PaystackHealthChecker\Api_Response
 */
class ConnectivityChecksTest extends PIHC_Test_Case {

	public function set_up() {
		parent::set_up();
		$this->use_official();
	}

	public function test_successful_authentication_passes_and_uses_the_documented_read_only_endpoint(): void {
		$this->mock_api( self::timeout_ok() );

		$result = $this->run_check( 'api.authentication' );

		$this->assertStatus( Diagnostic_Result::PASS, $result );
		$this->assertCount( 1, $this->requests );
		$request = $this->requests[0];
		$this->assertSame( 'https://api.paystack.co/integration/payment_session_timeout', $request['url'] );
		$this->assertSame( 'GET', $request['args']['method'] );
		$this->assertSame( 'Bearer ' . self::TEST_SECRET, $request['args']['headers']['Authorization'] );
		$this->assertTrue( $request['args']['sslverify'], 'TLS verification stays on.' );
		$this->assertSame( 15, $request['args']['timeout'] );
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertEmpty( $request['args']['body'] ?? null, 'Nothing is sent to Paystack beyond the request line.' );
		$this->assertSame( 200, $result->evidence()['http_status'] );
		$this->assertArrayHasKey( 'elapsed_ms', $result->evidence() );
	}

	public function test_secret_never_appears_in_the_result(): void {
		$this->mock_api(
			self::response(
				401,
				array(
					'status'  => false,
					'message' => 'Invalid key: ' . self::TEST_SECRET,
				)
			)
		);

		$result = $this->run_check( 'api.authentication' );

		$this->assertStringNotContainsString( self::TEST_SECRET, wp_json_encode( $result->to_array() ) );
		$this->assertStringNotContainsString( 'Bearer', wp_json_encode( $result->to_array() ) );
	}

	/**
	 * @dataProvider outcomes
	 *
	 * @param mixed  $response Mocked response.
	 * @param string $status   Expected status.
	 * @param string $category Expected classification.
	 */
	public function test_failures_are_classified( $response, string $status, string $category ): void {
		$this->mock_api( $response );

		$result = $this->run_check( 'api.authentication' );

		$this->assertStatus( $status, $result );
		$this->assertSame( $category, $result->evidence()['category'] );
	}

	public function outcomes(): array {
		return array(
			'invalid key (401)'    => array(
				self::response(
					401,
					array(
						'status'  => false,
						'message' => 'Invalid key',
					)
				),
				Diagnostic_Result::FAIL,
				Api_Response::UNAUTHORIZED,
			),
			'forbidden (403)'      => array(
				self::response(
					403,
					array(
						'status'  => false,
						'message' => 'Forbidden',
					)
				),
				Diagnostic_Result::FAIL,
				Api_Response::FORBIDDEN,
			),
			'rate limited (429)'   => array(
				self::response(
					429,
					array(
						'status'  => false,
						'message' => 'Rate limit exceeded!',
					),
					array( 'x-ratelimit-reset' => '42' )
				),
				Diagnostic_Result::WARNING,
				Api_Response::RATE_LIMITED,
			),
			'server error (502)'   => array( self::response( 502, 'Bad gateway' ), Diagnostic_Result::WARNING, Api_Response::SERVER_ERROR ),
			'timeout'              => array( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds' ), Diagnostic_Result::FAIL, Api_Response::TIMEOUT ),
			'dns failure'          => array( new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: api.paystack.co' ), Diagnostic_Result::FAIL, Api_Response::DNS ),
			'tls failure'          => array( new \WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem: unable to get local issuer certificate' ), Diagnostic_Result::FAIL, Api_Response::TLS ),
			'connection refused'   => array( new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to api.paystack.co port 443' ), Diagnostic_Result::FAIL, Api_Response::CONNECTION ),
			'html instead of json' => array( self::response( 200, '<html>Proxy error</html>' ), Diagnostic_Result::WARNING, Api_Response::INVALID_JSON ),
			'status false on 200'  => array(
				self::response(
					200,
					array(
						'status'  => false,
						'message' => 'Nope',
					)
				),
				Diagnostic_Result::WARNING,
				Api_Response::API_ERROR,
			),
			'missing data object'  => array( self::response( 200, array( 'status' => true ) ), Diagnostic_Result::WARNING, Api_Response::UNEXPECTED ),
		);
	}

	public function test_a_successful_envelope_without_the_documented_field_is_not_a_pass(): void {
		$this->mock_api(
			self::response(
				200,
				array(
					'status' => true,
					'data'   => array( 'something' => 'else' ),
				)
			)
		);

		$this->assertStatus( Diagnostic_Result::WARNING, $this->run_check( 'api.authentication' ) );
	}

	public function test_rate_limit_reset_header_is_reported(): void {
		$this->mock_api(
			self::response(
				429,
				array( 'status' => false ),
				array(
					'x-ratelimit-reset'     => '42',
					'x-ratelimit-remaining' => '0',
					'x-ratelimit-limit'     => '100',
				)
			)
		);

		$result = $this->run_check( 'api.authentication' );

		$this->assertSame( '42', $result->evidence()['x-ratelimit-reset'] );
		$this->assertSame( 'rate_limited', $result->kb() );
	}

	public function test_full_run_calls_the_api_once_and_never_initializes_a_transaction(): void {
		$this->mock_api( self::timeout_ok() );
		$this->mock_loopback( self::response( 200, '' ) );

		Plugin::instance()->manager()->run_all();

		$api = $this->requests_to( 'https://api.paystack.co' );
		$this->assertCount( 1, $api, 'The authentication probe is shared by checks.' );
		foreach ( $this->requests as $request ) {
			$this->assertStringNotContainsString( '/transaction/initialize', $request['url'] );
			$this->assertStringNotContainsString( '/charge', $request['url'] );
		}
	}

	public function test_live_mode_uses_only_the_live_key(): void {
		$this->use_official(
			array(
				'testmode'        => 'no',
				'live_secret_key' => self::LIVE_SECRET,
			)
		);
		$this->mock_api( self::timeout_ok() );

		$this->run_check( 'api.authentication' );

		$this->assertSame( 'Bearer ' . self::LIVE_SECRET, $this->requests[0]['args']['headers']['Authorization'] );
	}

	public function test_dns_check_reports_resolution(): void {
		$result = $this->run_check( 'api.dns' );

		$this->assertContains( $result->status(), array( Diagnostic_Result::PASS, Diagnostic_Result::FAIL ), 'Depends on the test machine having DNS; never SKIPPED.' );
	}
}
