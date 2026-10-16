<?php
/**
 * Pure unit tests: result model, health summary, redaction, error classification,
 * check definitions and registry. No WordPress loaded.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Tests\Unit;

use PaystackHealthChecker\Api_Response;
use PaystackHealthChecker\Check_Definition;
use PaystackHealthChecker\Diagnostic_Registry;
use PaystackHealthChecker\Diagnostic_Result;
use PaystackHealthChecker\Health_Summary;
use PaystackHealthChecker\Paystack_Api_Client;
use PaystackHealthChecker\Redactor;
use PaystackHealthChecker\Reference_Verifier;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PaystackHealthChecker\Diagnostic_Result
 * @covers \PaystackHealthChecker\Health_Summary
 * @covers \PaystackHealthChecker\Redactor
 * @covers \PaystackHealthChecker\Check_Definition
 * @covers \PaystackHealthChecker\Diagnostic_Registry
 */
class CoreModelTest extends TestCase {

	private const SECRET = 'sk_live_fedcba9876543210fedcba9876543210fedcba98';

	/**
	 * @dataProvider summaries
	 *
	 * @param array    $counts  Counts.
	 * @param string   $overall Expected overall.
	 * @param int|null $score   Expected score.
	 */
	public function test_overall_status_and_score( array $counts, string $overall, ?int $score ): void {
		$summary = new Health_Summary( $counts );

		$this->assertSame( $overall, $summary->overall() );
		$this->assertSame( $score, $summary->score() );
	}

	public function summaries(): array {
		return array(
			'all pass'                      => array( array( 'pass' => 10 ), Health_Summary::HEALTHY, 100 ),
			'one warning'                   => array(
				array(
					'pass'    => 9,
					'warning' => 1,
				),
				Health_Summary::NEEDS_ATTENTION,
				95,
			),
			'one failure among many passes' => array(
				array(
					'pass' => 50,
					'fail' => 1,
				),
				Health_Summary::CRITICAL,
				98,
			),
			'everything skipped'            => array( array( 'skipped' => 12 ), Health_Summary::UNKNOWN, null ),
			'skips do not inflate'          => array(
				array(
					'pass'    => 1,
					'fail'    => 1,
					'skipped' => 30,
				),
				Health_Summary::CRITICAL,
				50,
			),
			'nothing at all'                => array( array(), Health_Summary::UNKNOWN, null ),
		);
	}

	public function test_failure_cannot_be_averaged_away(): void {
		$summary = new Health_Summary(
			array(
				'pass' => 1000,
				'fail' => 1,
			)
		);

		$this->assertSame( Health_Summary::CRITICAL, $summary->overall() );
	}

	public function test_coverage_reports_evaluated_share(): void {
		$summary = new Health_Summary(
			array(
				'pass'    => 3,
				'warning' => 1,
				'skipped' => 4,
			)
		);

		$this->assertSame( 50, $summary->coverage() );
		$this->assertSame( 4, $summary->evaluated() );
	}

	public function test_summary_from_results(): void {
		$summary = Health_Summary::from_results(
			array(
				Diagnostic_Result::pass( 'a' ),
				Diagnostic_Result::fail( 'b' ),
				Diagnostic_Result::skipped( 'c' ),
			)
		);

		$this->assertSame(
			array(
				'pass'    => 1,
				'warning' => 0,
				'fail'    => 1,
				'skipped' => 1,
			),
			$summary->counts()
		);
	}

	public function test_result_rejects_unknown_status(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Diagnostic_Result( 'ok', 'nope' );
	}

	public function test_result_round_trips_through_array_form(): void {
		$result = Diagnostic_Result::warning(
			'Summary',
			array(
				'explanation' => 'Why',
				'evidence'    => array( 'http_status' => 429 ),
				'next_steps'  => array( 'Wait' ),
				'kb'          => 'rate_limited',
			)
		)->with_meta( 'api.authentication', 'API', 'connectivity', 1700000000, 12, 'https://example.org' );

		$copy = Diagnostic_Result::from_array( $result->to_array() );

		$this->assertSame( $result->to_array(), $copy->to_array() );
		$this->assertSame( 12, $copy->duration_ms() );
	}

	public function test_redactor_removes_keys_tokens_emails_and_card_numbers(): void {
		$redactor = new Redactor( array( 'custom-secret-value-123' ) );

		$text = $redactor->redact_string( 'key ' . self::SECRET . ' pk_test_abcdef123456 Bearer abc.def.ghi ada@example.com 4084084084084081 custom-secret-value-123 ref PWCG-1-abc' );

		$this->assertStringNotContainsString( self::SECRET, $text );
		$this->assertStringNotContainsString( 'pk_test_abcdef123456', $text );
		$this->assertStringNotContainsString( 'abc.def.ghi', $text );
		$this->assertStringNotContainsString( 'ada@example.com', $text );
		$this->assertStringNotContainsString( '4084084084084081', $text );
		$this->assertStringNotContainsString( 'custom-secret-value-123', $text );
		$this->assertStringContainsString( 'PWCG-1-abc', $text, 'References are kept.' );
	}

	public function test_redactor_removes_sensitive_keys_recursively(): void {
		$redactor = new Redactor();

		$clean = $redactor->redact_array(
			array(
				'secret_key' => 'x',
				'nested'     => array(
					'Authorization' => 'Bearer y',
					'customer'      => array( 'email' => 'z' ),
					'http_status'   => 401,
				),
				'object'     => new \stdClass(),
			)
		);

		$this->assertSame( Redactor::REDACTED, $clean['secret_key'] );
		$this->assertSame( Redactor::REDACTED, $clean['nested']['Authorization'] );
		$this->assertSame( Redactor::REDACTED, $clean['nested']['customer'] );
		$this->assertSame( 401, $clean['nested']['http_status'] );
		$this->assertSame( '[object]', $clean['object'] );
	}

	public function test_masked_keys_keep_only_prefix_and_last_four(): void {
		$this->assertSame( 'sk_live_…ba98', Redactor::mask_key( self::SECRET ) );
		$this->assertSame( '…', Redactor::mask_key( 'short' ) );
		$this->assertSame( '', Redactor::mask_key( '' ) );
	}

	/**
	 * @dataProvider transport_errors
	 */
	public function test_transport_error_classification( string $message, string $expected ): void {
		$this->assertSame( $expected, Paystack_Api_Client::classify_transport_error( $message ) );
	}

	public function transport_errors(): array {
		return array(
			'curl dns'        => array( 'cURL error 6: Could not resolve host: api.paystack.co', Api_Response::DNS ),
			'curl timeout'    => array( 'cURL error 28: Operation timed out after 15000 milliseconds', Api_Response::TIMEOUT ),
			'curl cert'       => array( 'cURL error 60: SSL certificate problem: certificate has expired', Api_Response::TLS ),
			'curl handshake'  => array( 'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL', Api_Response::TLS ),
			'curl refused'    => array( 'cURL error 7: Failed to connect to api.paystack.co port 443: Connection refused', Api_Response::CONNECTION ),
			'streams dns'     => array( 'php_network_getaddresses: getaddrinfo failed: Name or service not known', Api_Response::DNS ),
			'streams timeout' => array( 'Connection timed out', Api_Response::TIMEOUT ),
			'unknown'         => array( 'Something else happened', Api_Response::TRANSPORT ),
		);
	}

	public function test_reference_format_follows_paystack_rules(): void {
		$this->assertTrue( Reference_Verifier::is_valid_reference( 'T123-abc.x=y' ) );
		$this->assertFalse( Reference_Verifier::is_valid_reference( '' ) );
		$this->assertFalse( Reference_Verifier::is_valid_reference( "abc\n" ) );
		$this->assertFalse( Reference_Verifier::is_valid_reference( 'a/b' ) );
		$this->assertFalse( Reference_Verifier::is_valid_reference( str_repeat( 'a', 101 ) ) );
	}

	public function test_safe_transaction_keeps_only_whitelisted_scalar_fields(): void {
		$safe = Reference_Verifier::safe_transaction(
			array(
				'id'            => 4099260516,
				'status'        => 'success',
				'amount'        => 100,
				'customer'      => array( 'email' => 'a@b.c' ),
				'authorization' => array( 'authorization_code' => 'AUTH_x' ),
				'ip_address'    => '1.2.3.4',
				'metadata'      => array( 'x' => 1 ),
			)
		);

		$this->assertSame(
			array(
				'id'     => '4099260516',
				'status' => 'success',
				'amount' => 100,
			),
			$safe
		);
	}

	public function test_check_definitions_are_validated(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Check_Definition(
			array(
				'id'       => 'no-dot',
				'name'     => 'x',
				'category' => 'configuration',
				'callback' => 'strlen',
			)
		);
	}

	public function test_registry_rejects_duplicates_and_filters(): void {
		$registry = new Diagnostic_Registry();
		$make     = static function ( string $id, string $category, bool $full ) {
			return new Check_Definition(
				array(
					'id'          => $id,
					'name'        => $id,
					'category'    => $category,
					'callback'    => 'strlen',
					'in_full_run' => $full,
				)
			);
		};
		$registry->add( $make( 'a.one', 'webhooks', true ) );
		$registry->add( $make( 'a.two', 'webhooks', false ) );
		$registry->add( $make( 'b.one', 'payments', true ) );

		$this->assertCount( 2, $registry->in_category( 'webhooks' ) );
		$this->assertCount( 2, $registry->full_run() );

		$this->expectException( \InvalidArgumentException::class );
		$registry->add( $make( 'a.one', 'webhooks', true ) );
	}
}
