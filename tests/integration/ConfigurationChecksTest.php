<?php
/**
 * Configuration and environment checks.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Tests;

use PaystackHealthChecker\Diagnostic_Result;
use PaystackHealthChecker\Settings;

/**
 * @covers \PaystackHealthChecker\Checks\Configuration_Checks
 * @covers \PaystackHealthChecker\Checks\Environment_Checks
 * @covers \PaystackHealthChecker\Integrations\Integration_Detector
 */
class ConfigurationChecksTest extends PIHC_Test_Case {

	public function test_checks_needing_a_key_are_skipped_not_failed_when_the_key_is_missing(): void {
		$this->use_official( array( 'test_secret_key' => '' ) );

		$format = $this->run_check( 'config.key_format' );
		$api    = $this->run_check( 'api.authentication' );

		$this->assertStatus( Diagnostic_Result::SKIPPED, $format );
		$this->assertStatus( Diagnostic_Result::SKIPPED, $api );
		$this->assertStringContainsString( 'No secret key', $format->summary() );
		$this->assertCount( 0, $this->requests, 'No API call without a key.' );
	}

	public function test_auto_detection_diagnoses_an_active_gateway_even_when_disabled(): void {
		update_option( Settings::OPTION, array( 'integration' => 'auto' ) );
		$this->refresh();

		$result = $this->run_check( 'config.integration' );

		$this->assertStatus( Diagnostic_Result::PASS, $result );
		$this->assertStatus( Diagnostic_Result::WARNING, $this->run_check( 'wc.gateway_status' ) );
	}

	public function test_auto_detection_picks_the_enabled_gateway(): void {
		update_option(
			'woocommerce_paystack_settings',
			array(
				'enabled'         => 'no',
				'test_secret_key' => self::TEST_SECRET,
			)
		);
		update_option(
			'woocommerce_pwcg_paystack_settings',
			array(
				'enabled'         => 'yes',
				'test_secret_key' => self::TEST_SECRET,
			)
		);
		update_option( Settings::OPTION, array( 'integration' => 'auto' ) );
		$this->refresh();

		$result = $this->run_check( 'config.integration' );

		$this->assertStatus( Diagnostic_Result::PASS, $result );
		$this->assertStringContainsString( 'Gateway for Paystack on WooCommerce', $result->summary() );
	}

	public function test_two_enabled_paystack_gateways_are_flagged(): void {
		update_option(
			'woocommerce_paystack_settings',
			array(
				'enabled'         => 'yes',
				'test_secret_key' => self::TEST_SECRET,
			)
		);
		update_option(
			'woocommerce_pwcg_paystack_settings',
			array(
				'enabled'         => 'yes',
				'test_secret_key' => self::TEST_SECRET,
			)
		);
		update_option( Settings::OPTION, array( 'integration' => 'auto' ) );
		$this->refresh();

		$result = $this->run_check( 'config.integration' );

		$this->assertStatus( Diagnostic_Result::WARNING, $result );
		$this->assertSame( 'plugin_conflict', $result->kb() );
	}

	public function test_missing_secret_key_fails_and_says_where_to_configure(): void {
		$this->use_official( array( 'test_secret_key' => '' ) );

		$result = $this->run_check( 'config.secret_key' );

		$this->assertStatus( Diagnostic_Result::FAIL, $result );
		$this->assertStringContainsString( 'section=paystack', $result->evidence()['configure_at'] );
		$this->assertNotEmpty( $result->next_steps() );
	}

	public function test_configured_secret_key_is_shown_masked_only(): void {
		$this->use_official();

		$result = $this->run_check( 'config.secret_key' );
		$json   = wp_json_encode( $result->to_array() );

		$this->assertStatus( Diagnostic_Result::PASS, $result );
		$this->assertStringNotContainsString( self::TEST_SECRET, $json );
		$this->assertSame( 'sk_test_…4567', $result->evidence()['key_hint'] );
	}

	/**
	 * @dataProvider key_formats
	 */
	public function test_key_format_is_a_local_check( string $key, string $expected ): void {
		$this->use_official( array( 'test_secret_key' => $key ) );

		$result = $this->run_check( 'config.key_format' );

		$this->assertStatus( $expected, $result );
		$this->assertSame( 'local format check (not an authentication test)', $result->evidence()['check_type'] );
	}

	public function key_formats(): array {
		return array(
			'documented test key'              => array( self::TEST_SECRET, Diagnostic_Result::PASS ),
			'public key in secret field'       => array( self::TEST_PUBLIC, Diagnostic_Result::FAIL ),
			'unknown prefix is only a warning' => array( 'rk_test_0123456789abcdef', Diagnostic_Result::WARNING ),
			'embedded whitespace'              => array( 'sk_test_0123 456789', Diagnostic_Result::WARNING ),
		);
	}

	public function test_live_key_in_test_mode_is_an_environment_mismatch(): void {
		$this->use_official( array( 'test_secret_key' => self::LIVE_SECRET ) );

		$result = $this->run_check( 'config.environment_match' );

		$this->assertStatus( Diagnostic_Result::FAIL, $result );
		$this->assertSame( 'wrong_environment', $result->kb() );
	}

	public function test_live_public_key_with_test_mode_is_a_mismatch(): void {
		$this->use_official( array( 'test_public_key' => self::LIVE_PUBLIC ) );

		$this->assertStatus( Diagnostic_Result::FAIL, $this->run_check( 'config.environment_match' ) );
	}

	public function test_matching_environment_passes(): void {
		$this->use_official(
			array(
				'testmode'        => 'no',
				'live_secret_key' => self::LIVE_SECRET,
				'live_public_key' => self::LIVE_PUBLIC,
			)
		);

		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'config.environment_match' ) );
	}

	public function test_popup_checkout_without_public_key_fails(): void {
		$this->use_official(
			array(
				'payment_page'    => 'inline',
				'test_public_key' => '',
			)
		);

		$this->assertStatus( Diagnostic_Result::FAIL, $this->run_check( 'config.public_key' ) );
	}

	public function test_redirect_checkout_does_not_need_a_public_key(): void {
		$this->use_official(
			array(
				'payment_page'    => 'redirect',
				'test_public_key' => '',
			)
		);

		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'config.public_key' ) );
	}

	public function test_secret_key_in_public_field_is_flagged_as_exposure(): void {
		$this->use_official( array( 'test_public_key' => self::TEST_SECRET ) );

		$result = $this->run_check( 'config.public_key' );

		$this->assertStatus( Diagnostic_Result::FAIL, $result );
		$this->assertStringContainsString( 'compromised', implode( ' ', $result->next_steps() ) );
		$this->assertStringNotContainsString( self::TEST_SECRET, wp_json_encode( $result->to_array() ) );
	}

	public function test_http_site_in_live_mode_fails_but_only_warns_in_test_mode(): void {
		update_option( 'home', 'http://example.org' );
		update_option( 'siteurl', 'http://example.org' );

		$this->use_official(
			array(
				'testmode'        => 'no',
				'live_secret_key' => self::LIVE_SECRET,
			)
		);
		$this->assertStatus( Diagnostic_Result::FAIL, $this->run_check( 'config.site_url' ) );

		$this->use_official();
		$this->assertStatus( Diagnostic_Result::WARNING, $this->run_check( 'config.site_url' ) );
	}

	public function test_https_site_passes(): void {
		update_option( 'home', 'https://example.org' );
		update_option( 'siteurl', 'https://example.org' );
		$this->use_official();

		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'config.site_url' ) );
	}

	public function test_environment_checks_pass_on_this_server(): void {
		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'env.php_version' ) );
		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'env.php_extensions' ) );
		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'env.https_transport' ) );
	}

	public function test_manual_configuration_works_without_a_gateway(): void {
		$this->use_manual();

		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'config.secret_key' ) );
		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'config.key_format' ) );
	}

	public function test_selected_but_inactive_integration_fails_clearly(): void {
		update_option( Settings::OPTION, array( 'integration' => 'does-not-exist' ) );
		$this->refresh();

		$this->assertStatus( Diagnostic_Result::FAIL, $this->run_check( 'config.integration' ) );
	}
}
