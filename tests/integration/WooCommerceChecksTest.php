<?php
/**
 * WooCommerce compatibility checks against the real official gateway and pwcg plugin.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Tests;

use PaystackHealthChecker\Checks\WooCommerce_Checks;
use PaystackHealthChecker\Diagnostic_Result;

/**
 * @covers \PaystackHealthChecker\Checks\WooCommerce_Checks
 * @covers \PaystackHealthChecker\Integrations\Official_Gateway_Adapter
 * @covers \PaystackHealthChecker\Integrations\Pwcg_Adapter
 */
class WooCommerceChecksTest extends PIHC_Test_Case {

	public function test_woocommerce_active_is_reported(): void {
		$result = $this->run_check( 'wc.active' );

		$this->assertStatus( Diagnostic_Result::PASS, $result );
		$this->assertSame( WC_VERSION, $result->evidence()['version'] );
	}

	public function test_version_newer_than_gateway_tested_version_is_a_warning(): void {
		$this->use_official(); // woo-paystack 5.8.5 declares "WC tested up to: 10.9"; WooCommerce 11.2.1 is loaded.

		$result = $this->run_check( 'wc.version' );

		$this->assertStatus( Diagnostic_Result::WARNING, $result );
		$this->assertSame( '10.9', $result->evidence()['gateway_tested_up_to_wc'] );
		$this->assertSame( '9.6', $result->evidence()['gateway_requires_wc'] );
	}

	public function test_version_within_range_passes(): void {
		$this->use_pwcg(); // Declares WC 9.0 - 11.2.

		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'wc.version' ) );
	}

	public function test_enabled_and_available_gateway_passes(): void {
		$this->use_pwcg();

		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'wc.gateway_status' ) );
	}

	public function test_disabled_gateway_is_a_warning(): void {
		$this->use_pwcg( array( 'enabled' => 'no' ) );

		$this->assertStatus( Diagnostic_Result::WARNING, $this->run_check( 'wc.gateway_status' ) );
	}

	public function test_enabled_but_hidden_gateway_fails(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->use_pwcg();

		$result = $this->run_check( 'wc.gateway_status' );

		$this->assertStatus( Diagnostic_Result::FAIL, $result );
		$this->assertSame( 'no', $result->evidence()['available'] );
	}

	public function test_unsupported_currency_fails(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$this->use_official();

		$this->assertStatus( Diagnostic_Result::FAIL, $this->run_check( 'wc.currency' ) );
	}

	public function test_currency_accepted_by_gateway_but_not_in_api_docs_is_a_warning(): void {
		update_option( 'woocommerce_currency', 'EGP' );
		$this->use_official();

		$result = $this->run_check( 'wc.currency' );

		$this->assertStatus( Diagnostic_Result::WARNING, $result );
		$this->assertStringContainsString( 'EGP', $result->evidence()['gateway_accepts'] );
	}

	public function test_documented_currency_passes(): void {
		$this->use_official();

		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'wc.currency' ) );
	}

	public function test_hpos_declarations_are_read_from_woocommerce(): void {
		$this->use_official();
		$official = $this->run_check( 'wc.hpos' );
		$this->use_pwcg();
		$pwcg = $this->run_check( 'wc.hpos' );

		$this->assertStatus( Diagnostic_Result::PASS, $official );
		$this->assertSame( 'compatible', $official->evidence()['gateway_declaration'] );
		$this->assertStatus( Diagnostic_Result::PASS, $pwcg );
	}

	public function test_undeclared_hpos_with_hpos_enabled_fails(): void {
		$this->assertSame( 'undeclared', WooCommerce_Checks::declared_compatibility( 'custom_order_tables', 'not-a-plugin/plugin.php' ) );
	}

	public function test_blocks_registered_but_undeclared_is_a_warning_when_the_checkout_block_is_used(): void {
		$this->use_checkout_page( '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->' );
		$this->use_official(); // Registers block integrations but does not declare cart_checkout_blocks (5.8.5).

		$result = $this->run_check( 'wc.blocks' );

		$this->assertStatus( Diagnostic_Result::WARNING, $result );
		$this->assertSame( 'registered', $result->evidence()['block_integration'] );
		$this->assertSame( 'undeclared', $result->evidence()['cart_checkout_blocks_flag'] );
	}

	public function test_blocks_registered_and_declared_passes(): void {
		$this->use_checkout_page( '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->' );
		$this->use_pwcg();

		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'wc.blocks' ) );
	}

	public function test_classic_checkout_does_not_require_blocks_support(): void {
		$this->use_checkout_page( '[woocommerce_checkout]' );
		$this->use_official();

		$result = $this->run_check( 'wc.blocks' );

		$this->assertStatus( Diagnostic_Result::WARNING, $result, 'Registered-but-undeclared is still reported.' );
		$this->assertSame( 'no (classic/shortcode)', $result->evidence()['checkout_uses_block'] );
	}

	public function test_missing_checkout_page_fails(): void {
		update_option( 'woocommerce_checkout_page_id', 0 );

		$this->assertStatus( Diagnostic_Result::FAIL, $this->run_check( 'wc.checkout_pages' ) );
	}

	public function test_complete_checkout_pages_pass(): void {
		$this->use_checkout_page( '[woocommerce_checkout]' );
		$cart = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[woocommerce_cart]',
			)
		);
		update_option( 'woocommerce_cart_page_id', $cart );

		$this->assertStatus( Diagnostic_Result::PASS, $this->run_check( 'wc.checkout_pages' ) );
	}

	public function test_checker_declares_its_own_compatibility_separately(): void {
		$result = $this->run_check( 'wc.checker_compat' );

		$this->assertStatus( Diagnostic_Result::PASS, $result );
		$this->assertSame( 'compatible', $result->evidence()['custom_order_tables'] );
	}

	public function test_gateway_errors_in_logs_are_reported(): void {
		$this->use_pwcg();
		$file = trailingslashit( WC_LOG_DIR ) . 'pwcg-paystack-' . gmdate( 'Y-m-d' ) . '-' . wp_generate_password( 8, false ) . '.log';
		file_put_contents( $file, gmdate( 'c' ) . " ERROR Transaction initialization failed. {\"category\":\"timeout\"}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		try {
			$result = $this->run_check( 'wc.logs' );
		} finally {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$this->assertStatus( Diagnostic_Result::WARNING, $result );
		$this->assertSame( 1, $result->evidence()['gateway_errors'] );
	}

	public function test_official_gateway_logs_note_that_it_does_not_log(): void {
		$this->use_official();

		$result = $this->run_check( 'wc.logs' );

		$this->assertStatus( Diagnostic_Result::PASS, $result );
		$this->assertStringContainsString( 'does not write to the WooCommerce log', $result->explanation() );
	}

	public function test_checks_never_change_store_or_gateway_settings(): void {
		$this->use_official();
		$before = array(
			get_option( 'woocommerce_currency' ),
			get_option( 'woocommerce_paystack_settings' ),
			get_option( 'woocommerce_checkout_page_id' ),
			get_option( 'woocommerce_custom_orders_table_enabled' ),
		);
		$this->mock_api( self::timeout_ok() );
		$this->mock_loopback( self::response( 200, '' ) );

		\PaystackHealthChecker\Plugin::instance()->manager()->run_all();

		$this->assertSame(
			$before,
			array(
				get_option( 'woocommerce_currency' ),
				get_option( 'woocommerce_paystack_settings' ),
				get_option( 'woocommerce_checkout_page_id' ),
				get_option( 'woocommerce_custom_orders_table_enabled' ),
			)
		);
	}

	/**
	 * Creates and assigns a checkout page.
	 *
	 * @param string $content Page content.
	 */
	private function use_checkout_page( string $content ): void {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		update_option( 'woocommerce_checkout_page_id', $page );
	}
}
