<?php
/**
 * Behaviour without WooCommerce. Run with:
 *   PIHC_TESTS_WOOCOMMERCE=0 vendor/bin/phpunit --group no-woocommerce
 * (only the health checker is loaded in that mode).
 *
 * @package PaystackHealthChecker
 * @group no-woocommerce
 */

namespace PaystackHealthChecker\Tests;

use PaystackHealthChecker\Admin\Admin_Page;
use PaystackHealthChecker\Diagnostic_Result;
use PaystackHealthChecker\Plugin;
use PaystackHealthChecker\Security;
use PaystackHealthChecker\Settings;

/**
 * @group no-woocommerce
 * @coversNothing
 */
class NoWooCommerceTest extends PIHC_Test_Case {

	public function set_up() {
		parent::set_up();
		if ( PIHC_TESTS_WOOCOMMERCE ) {
			$this->markTestSkipped( 'Run with PIHC_TESTS_WOOCOMMERCE=0.' );
		}
	}

	public function test_woocommerce_is_really_absent(): void {
		$this->assertFalse( class_exists( 'WooCommerce' ) );
	}

	public function test_no_integration_and_no_keys_is_a_clear_failure(): void {
		$result = $this->run_check( 'config.integration' );

		$this->assertStatus( Diagnostic_Result::FAIL, $result );
		$this->assertStringContainsString( 'No Paystack integration', $result->summary() );
	}

	public function test_full_run_completes_and_woocommerce_checks_are_skipped(): void {
		$run = Plugin::instance()->manager()->run_all();

		foreach ( $run['results'] as $result ) {
			if ( 'wc.active' === $result->check_id() ) {
				// WooCommerce is installed in the test environment but not active.
				$this->assertStatus( Diagnostic_Result::WARNING, $result );
			} elseif ( 0 === strpos( $result->check_id(), 'wc.' ) || 0 === strpos( $result->check_id(), 'flow.' ) ) {
				$this->assertStatus( Diagnostic_Result::SKIPPED, $result );
			}
		}
		$this->assertGreaterThan( 0, $run['summary']->count( Diagnostic_Result::PASS ), 'Environment checks still run.' );
	}

	public function test_manual_keys_enable_api_diagnostics_without_woocommerce(): void {
		$this->use_manual();
		$this->mock_api( self::timeout_ok() );

		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'config.secret_key' ) );
		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'api.authentication' ) );
		$this->assertStatus( Diagnostic_Result::SKIPPED, $this->run_check( 'webhook.endpoint_reachable' ), 'Custom integration webhook URL is unknown.' );
	}

	public function test_capability_and_admin_location_without_woocommerce(): void {
		$this->assertSame( 'manage_options', Security::capability(), 'Without WooCommerce, manage_options is required.' );
		$this->assertStringContainsString( 'tools.php?page=' . Settings::PAGE_SLUG, Settings::admin_url() );
	}

	public function test_menu_is_under_tools(): void {
		global $submenu;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new Admin_Page( Plugin::instance() ) )->register_menu();

		$this->assertContains( Settings::PAGE_SLUG, wp_list_pluck( $submenu['tools.php'] ?? array(), 2 ) );
	}

	public function test_reference_verification_works_without_orders(): void {
		$this->use_manual();
		$this->mock_api(
			self::response(
				200,
				array(
					'status' => true,
					'data'   => array(
						'status'    => 'success',
						'reference' => 'REF-NOWC',
						'amount'    => 5000,
						'currency'  => 'NGN',
						'domain'    => 'test',
					),
				)
			)
		);

		$result = Plugin::instance()->reference_verifier()->verify( 'REF-NOWC', 0 );

		$this->assertStatus( Diagnostic_Result::PASS, $result );
	}
}
