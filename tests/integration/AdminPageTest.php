<?php
/**
 * Admin page rendering: every tab renders, keys are never printed, statuses are textual.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Tests;

use PaystackHealthChecker\Admin\Admin_Page;
use PaystackHealthChecker\Plugin;
use PaystackHealthChecker\Settings;

/**
 * @covers \PaystackHealthChecker\Admin\Admin_Page
 * @covers \PaystackHealthChecker\Admin\Result_Renderer
 */
class AdminPageTest extends PIHC_Test_Case {

	public function set_up() {
		parent::set_up();
		$this->use_official( array( 'test_public_key' => self::TEST_PUBLIC ) );
		update_option(
			Settings::OPTION,
			array(
				'integration'            => 'woo-paystack',
				'manual_test_secret_key' => self::LIVE_SECRET,
			)
		);
		$this->refresh();
		$this->mock_api( self::timeout_ok() );
		$this->mock_loopback( self::response( 200, '' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function render_tab( string $tab, array $extra = array() ): string {
		$_GET  = array_merge( array( 'tab' => $tab ), $extra );
		$level = ob_get_level();
		ob_start();
		try {
			( new Admin_Page( Plugin::instance() ) )->render();
		} finally {
			$html = '';
			while ( ob_get_level() > $level ) {
				$html = (string) ob_get_clean() . $html;
			}
		}
		return $html;
	}

	/**
	 * @dataProvider tabs
	 */
	public function test_every_tab_renders_without_exposing_keys( string $tab ): void {
		Plugin::instance()->manager()->run_all();

		$html = $this->render_tab( $tab );

		$this->assertStringContainsString( 'Paystack Integration Health Checker', $html );
		$this->assertStringContainsString( 'nav-tab-active', $html );
		foreach ( array( self::TEST_SECRET, self::LIVE_SECRET, self::TEST_PUBLIC ) as $key ) {
			$this->assertStringNotContainsString( $key, $html, 'Tab ' . $tab . ' printed a key.' );
		}
	}

	public function tabs(): array {
		return array_map(
			static function ( $tab ) {
				return array( $tab );
			},
			array_keys( Admin_Page::tabs() )
		);
	}

	public function test_overview_shows_counts_environment_and_actions(): void {
		Plugin::instance()->manager()->run_all();

		$html = $this->render_tab( 'overview' );

		$this->assertStringContainsString( 'Run Diagnostics', $html );
		$this->assertStringContainsString( 'Download Diagnostic Report', $html );
		$this->assertStringContainsString( 'Paystack WooCommerce Payment Gateway (official plugin)', $html );
		$this->assertMatchesRegularExpression( '/pihc-tile__label">(PASS|WARNING|FAIL|SKIPPED)</', $html, 'Statuses are text, not colour only.' );
		$this->assertStringContainsString( '_pihc_nonce', $html );
	}

	public function test_configuration_tab_shows_masked_manual_key_only(): void {
		$html = $this->render_tab( 'configuration' );

		$this->assertStringContainsString( 'sk_live_…ba98', $html );
		$this->assertStringContainsString( 'value=""', $html, 'Key inputs are never pre-filled.' );
	}

	public function test_history_detail_shows_trend_and_exports(): void {
		$run = Plugin::instance()->manager()->run_all();

		$html = $this->render_tab( 'history', array( 'run' => (string) $run['run_id'] ) );

		$this->assertStringContainsString( 'Comparison with previous runs', $html );
		$this->assertStringContainsString( 'action=pihc_export', $html );
	}

	public function test_untrusted_tab_parameter_falls_back_to_overview(): void {
		$html = $this->render_tab( '../../etc/passwd' );

		$this->assertStringContainsString( 'Run Diagnostics', $html );
	}

	public function test_page_refuses_users_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( \WPDieException::class );

		$this->render_tab( 'overview' );
	}

	public function test_menu_is_registered_under_woocommerce(): void {
		global $submenu;
		( new Admin_Page( Plugin::instance() ) )->register_menu();

		$slugs = wp_list_pluck( $submenu['woocommerce'] ?? array(), 2 );
		$this->assertContains( Settings::PAGE_SLUG, $slugs );
	}
}
