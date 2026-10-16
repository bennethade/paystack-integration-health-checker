<?php
/**
 * Report export sanitization, SSRF guarantees, throttling, URL/host guards, settings sanitization.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Tests;

use PaystackHealthChecker\Plugin;
use PaystackHealthChecker\Report_Exporter;
use PaystackHealthChecker\Security;
use PaystackHealthChecker\Settings;

/**
 * @covers \PaystackHealthChecker\Report_Exporter
 * @covers \PaystackHealthChecker\Security
 * @covers \PaystackHealthChecker\Settings
 */
class SecurityAndExportTest extends PIHC_Test_Case {

	public function set_up() {
		parent::set_up();
		$this->use_official(
			array(
				'live_secret_key' => self::LIVE_SECRET,
				'live_public_key' => self::LIVE_PUBLIC,
			)
		);
		$this->mock_api(
			self::response(
				401,
				array(
					'status'  => false,
					'message' => 'Invalid key ' . self::TEST_SECRET,
				)
			)
		);
		$this->mock_loopback( self::response( 200, '' ) );
	}

	private function report( string $format ): string {
		$plugin = Plugin::instance();
		$run    = $plugin->manager()->run_all();
		$stored = $plugin->history()->get_run( $run['run_id'] );
		return $plugin->exporter()->render( $plugin->exporter()->build( $stored ), $format );
	}

	public function test_json_report_has_versions_results_and_skipped_section(): void {
		$report = json_decode( $this->report( Report_Exporter::FORMAT_JSON ), true );

		$this->assertSame( PIHC_VERSION, $report['report']['version'] );
		$this->assertSame( WC_VERSION, $report['environment']['woocommerce_version'] );
		$this->assertSame( PHP_VERSION, $report['environment']['php_version'] );
		$this->assertArrayHasKey( 'skipped_checks', $report );
		$this->assertNotEmpty( $report['results'] );
		$this->assertContains( $report['results'][0]['status'], array( 'PASS', 'WARNING', 'FAIL', 'SKIPPED' ) );
		$this->assertArrayHasKey( 'coverage', $report['run'] );
	}

	public function test_reports_never_contain_keys_headers_or_customer_data(): void {
		$this->create_order(
			array(
				'status'      => 'pending',
				'age_minutes' => 120,
			)
		);

		foreach ( array( Report_Exporter::FORMAT_JSON, Report_Exporter::FORMAT_TEXT ) as $format ) {
			$report = $this->report( $format );
			foreach ( array( self::TEST_SECRET, self::LIVE_SECRET, self::TEST_PUBLIC, self::LIVE_PUBLIC, 'Bearer sk_', 'customer@example.com' ) as $forbidden ) {
				$this->assertStringNotContainsString( $forbidden, $report, $format . ' report leaked ' . $forbidden );
			}
		}
	}

	public function test_text_report_marks_skipped_checks(): void {
		$this->use_manual(); // Webhook and WooCommerce gateway checks are skipped for manual integrations.

		$text = $this->report( Report_Exporter::FORMAT_TEXT );

		$this->assertStringContainsString( 'NOT PERFORMED (SKIPPED)', $text );
		$this->assertStringContainsString( '[SKIPPED]', $text );
	}

	public function test_exporter_redacts_even_if_stored_data_contained_a_secret(): void {
		$exporter = Plugin::instance()->exporter();
		$stored   = array(
			'run'     => array(
				'id'          => 1,
				'created_at'  => '2026-10-16 00:00:00',
				'trigger'     => 'full',
				'overall'     => 'critical',
				'score'       => 0,
				'counts'      => array(
					'pass'    => 0,
					'warning' => 0,
					'fail'    => 1,
					'skipped' => 0,
				),
				'environment' => array( 'note' => 'key ' . self::LIVE_SECRET ),
			),
			'results' => array( \PaystackHealthChecker\Diagnostic_Result::fail( 'Leaked ' . self::TEST_SECRET, array( 'evidence' => array( 'x' => self::LIVE_PUBLIC ) ) ) ),
		);

		$json = $exporter->render( $exporter->build( $stored ), Report_Exporter::FORMAT_JSON );

		foreach ( array( self::TEST_SECRET, self::LIVE_SECRET, self::LIVE_PUBLIC ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
	}

	public function test_all_outbound_requests_go_only_to_paystack_or_this_site(): void {
		$plugin = Plugin::instance();
		$plugin->manager()->run_all();
		foreach ( $plugin->registry()->all() as $check ) {
			$plugin->manager()->run_single( $check->id() );
		}
		$plugin->reference_verifier()->verify( 'REF-1', 0 );

		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		$this->assertNotEmpty( $this->requests );
		foreach ( $this->requests as $request ) {
			$host = wp_parse_url( $request['url'], PHP_URL_HOST );
			$this->assertContains( $host, array( 'api.paystack.co', $home ), 'Unexpected outbound request to ' . $request['url'] );
			if ( 'api.paystack.co' === $host ) {
				$this->assertSame( 'GET', $request['args']['method'], 'Only read-only API calls.' );
			}
		}
	}

	/**
	 * @dataProvider own_site_urls
	 */
	public function test_own_site_url_guard( string $url, bool $expected ): void {
		update_option( 'home', 'https://example.org' );

		$this->assertSame( $expected, Security::is_own_site_url( $url ) );
	}

	public function own_site_urls(): array {
		return array(
			'own webhook'          => array( 'https://example.org/wp-json/pwcg/v1/webhook', true ),
			'own wc-api over http' => array( 'http://example.org/?wc-api=x', true ),
			'other host'           => array( 'https://evil.example/', false ),
			'look-alike host'      => array( 'https://example.org.evil.example/', false ),
			'credentials'          => array( 'https://user:pass@example.org/', false ),
			'different port'       => array( 'https://example.org:8080/', false ),
			'cloud metadata'       => array( 'http://169.254.169.254/latest/meta-data/', false ),
			'file scheme'          => array( 'file:///etc/passwd', false ),
		);
	}

	/**
	 * @dataProvider hosts
	 *
	 * @param string    $host     Host.
	 * @param bool|null $expected Expected.
	 */
	public function test_private_host_detection( string $host, $expected ): void {
		$this->assertSame( $expected, Security::host_is_private( $host ) );
	}

	public function hosts(): array {
		return array(
			'localhost'       => array( 'localhost', true ),
			'loopback ip'     => array( '127.0.0.1', true ),
			'rfc1918'         => array( '10.1.2.3', true ),
			'rfc1918 192.168' => array( '192.168.1.10', true ),
			'link-local'      => array( '169.254.169.254', true ),
			'.test domain'    => array( 'shop.test', true ),
			'public ip'       => array( '8.8.8.8', false ),
		);
	}

	public function test_throttle_limits_repeated_full_runs_per_user(): void {
		$now = time();
		$this->assertSame( 0, Security::throttle( 'full_run', 7, $now ) );
		$this->assertSame( 0, Security::throttle( 'full_run', 7, $now ) );
		$this->assertSame( 0, Security::throttle( 'full_run', 7, $now ) );

		$this->assertGreaterThan( 0, Security::throttle( 'full_run', 7, $now ) );
		$this->assertSame( 0, Security::throttle( 'full_run', 8, $now ), 'Limits are per user.' );
		$this->assertSame( 0, Security::throttle( 'full_run', 7, $now + 61 ), 'The window slides.' );
	}

	public function test_capability_depends_on_woocommerce_and_is_filterable(): void {
		$this->assertSame( 'manage_woocommerce', Security::capability() );

		add_filter(
			'pihc_capability',
			static function () {
				return 'manage_options';
			}
		);
		$this->assertSame( 'manage_options', Security::capability() );
	}

	public function test_settings_sanitization_keeps_blank_keys_and_clamps_values(): void {
		update_option( Settings::OPTION, array( 'manual_test_secret_key' => self::TEST_SECRET ) );

		$clean = Settings::sanitize(
			array(
				'integration'            => 'Manual<script>',
				'manual_mode'            => 'production',
				'manual_test_secret_key' => '',
				'manual_live_secret_key' => 'sk_live_bad key with spaces',
				'retention_runs'         => '100000',
				'retention_days'         => '-4',
			)
		);

		$this->assertSame( 'manualscript', $clean['integration'] );
		$this->assertSame( 'test', $clean['manual_mode'] );
		$this->assertSame( self::TEST_SECRET, $clean['manual_test_secret_key'], 'Blank keeps the saved key.' );
		$this->assertSame( '', $clean['manual_live_secret_key'], 'Malformed input is not saved.' );
		$this->assertSame( 500, $clean['retention_runs'] );
		$this->assertSame( 4, $clean['retention_days'] );
	}

	public function test_settings_clear_flag_removes_a_saved_key(): void {
		update_option( Settings::OPTION, array( 'manual_test_secret_key' => self::TEST_SECRET ) );

		$clean = Settings::sanitize( array( 'clear_manual_test_secret_key' => '1' ) );

		$this->assertSame( '', $clean['manual_test_secret_key'] );
	}
}
