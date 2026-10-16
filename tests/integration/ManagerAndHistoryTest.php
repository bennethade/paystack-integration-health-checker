<?php
/**
 * Runner behaviour, status calculation, history storage, retention and extension point.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Tests;

use PaystackHealthChecker\Check_Definition;
use PaystackHealthChecker\Diagnostic_Context;
use PaystackHealthChecker\Diagnostic_Registry;
use PaystackHealthChecker\Diagnostic_Result;
use PaystackHealthChecker\Health_Summary;
use PaystackHealthChecker\Plugin;

/**
 * @covers \PaystackHealthChecker\Diagnostic_Manager
 * @covers \PaystackHealthChecker\Diagnostic_History
 * @covers \PaystackHealthChecker\Diagnostic_Registry
 */
class ManagerAndHistoryTest extends PIHC_Test_Case {

	public function set_up() {
		parent::set_up();
		$this->use_official();
		$this->mock_api( self::timeout_ok() );
		$this->mock_loopback( self::response( 200, '' ) );
	}

	private function check( string $id, callable $callback, array $requires = array() ): Check_Definition {
		return new Check_Definition(
			array(
				'id'       => $id,
				'name'     => 'Test ' . $id,
				'category' => Check_Definition::CATEGORY_CONFIGURATION,
				'callback' => $callback,
				'requires' => $requires,
			)
		);
	}

	public function test_full_run_is_stored_with_counts_and_environment(): void {
		$run = Plugin::instance()->manager()->run_all();

		$stored = Plugin::instance()->history()->get_run( $run['run_id'] );
		$this->assertNotNull( $stored );
		$this->assertSame( 'full', $stored['run']['trigger'] );
		$this->assertCount( count( Plugin::instance()->registry()->full_run() ), $stored['results'] );
		$this->assertSame( $run['summary']->counts(), $stored['run']['counts'] );
		$this->assertSame( 'test', $stored['run']['environment']['environment'] );
		$this->assertTrue( $stored['run']['environment']['key_configured'] );
		$this->assertSame( WC_VERSION, $stored['run']['environment']['woocommerce_version'] );
	}

	public function test_every_result_has_identity_status_and_timing(): void {
		$run = Plugin::instance()->manager()->run_all();

		foreach ( $run['results'] as $result ) {
			$this->assertNotSame( '', $result->check_id() );
			$this->assertNotSame( '', $result->check_name() );
			$this->assertContains( $result->status(), Diagnostic_Result::STATUSES );
			$this->assertNotSame( '', $result->summary() );
			$this->assertIsInt( $result->duration_ms() );
			$this->assertGreaterThan( 0, $result->timestamp() );
		}
	}

	public function test_exception_inside_a_check_becomes_skipped_not_pass(): void {
		$plugin = Plugin::instance();
		$check  = $this->check(
			'test.explodes',
			static function () {
				throw new \RuntimeException( 'boom with ' . PIHC_Test_Case::TEST_SECRET );
			}
		);

		$result = $plugin->manager()->run_check( $check, $plugin->context() );

		$this->assertStatus( Diagnostic_Result::SKIPPED, $result );
		$this->assertStringNotContainsString( self::TEST_SECRET, wp_json_encode( $result->to_array() ), 'Error text is redacted.' );
	}

	public function test_invalid_return_value_becomes_skipped(): void {
		$plugin = Plugin::instance();
		$check  = $this->check(
			'test.returns_true',
			static function () {
				return true;
			}
		);

		$this->assertStatus( Diagnostic_Result::SKIPPED, $plugin->manager()->run_check( $check, $plugin->context() ) );
	}

	public function test_unmet_prerequisite_skips_without_running_the_callback(): void {
		$plugin = Plugin::instance();
		$ran    = false;
		$check  = $this->check(
			'test.needs_webhook',
			static function () use ( &$ran ) {
				$ran = true;
				return Diagnostic_Result::pass( 'ran' );
			},
			array( Diagnostic_Context::REQUIRES_WEBHOOK_URL )
		);
		$this->use_manual();

		$result = $plugin->manager()->run_check( $check, Plugin::instance()->context() );

		$this->assertStatus( Diagnostic_Result::SKIPPED, $result );
		$this->assertFalse( $ran );
	}

	public function test_evidence_is_redacted_before_storage(): void {
		$plugin = Plugin::instance();
		$check  = $this->check(
			'test.leaky',
			static function () {
				return Diagnostic_Result::warning(
					'leaky',
					array(
						'evidence' => array(
							'raw'           => 'Authorization: Bearer ' . PIHC_Test_Case::TEST_SECRET,
							'secret_key'    => PIHC_Test_Case::LIVE_SECRET,
							'customer_note' => 'email ada@example.com',
						),
					)
				);
			}
		);

		$result = $plugin->manager()->run_check( $check, $plugin->context() );
		$json   = wp_json_encode( $result->to_array() );

		foreach ( array( self::TEST_SECRET, self::LIVE_SECRET, 'ada@example.com' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
	}

	public function test_registered_custom_checks_join_the_full_run(): void {
		add_action(
			'pihc_register_checks',
			static function ( Diagnostic_Registry $registry ) {
				$registry->add(
					new Check_Definition(
						array(
							'id'       => 'custom.example',
							'name'     => 'Custom example',
							'category' => Check_Definition::CATEGORY_CONFIGURATION,
							'callback' => static function () {
								return Diagnostic_Result::pass( 'Custom check ran.' );
							},
						)
					)
				);
			}
		);
		Plugin::instance()->reset();

		$run = Plugin::instance()->manager()->run_all();

		$ids = array_map(
			static function ( $result ) {
				return $result->check_id();
			},
			$run['results']
		);
		$this->assertContains( 'custom.example', $ids );
	}

	public function test_skipped_checks_do_not_change_the_score(): void {
		$base         = new Health_Summary(
			array(
				'pass'    => 3,
				'warning' => 1,
				'fail'    => 0,
				'skipped' => 0,
			)
		);
		$with_skipped = new Health_Summary(
			array(
				'pass'    => 3,
				'warning' => 1,
				'fail'    => 0,
				'skipped' => 10,
			)
		);

		$this->assertSame( $base->score(), $with_skipped->score() );
		$this->assertSame( 100, $base->coverage() );
		$this->assertLessThan( 30, $with_skipped->coverage() );
	}

	public function test_single_check_and_reference_runs_are_stored_separately(): void {
		$manager = Plugin::instance()->manager();
		$manager->run_all();
		$single = $manager->run_single( 'config.secret_key' );

		$this->assertSame( 'single', Plugin::instance()->history()->get_run( $single['run_id'] )['run']['trigger'] );
		$this->assertNotSame( $single['run_id'], Plugin::instance()->history()->latest_run( 'full' )['run']['id'], 'The dashboard summary keeps showing the last full run.' );
		$this->assertNull( $manager->run_single( 'not.a_check' ) );
	}

	public function test_latest_results_and_trend(): void {
		$manager = Plugin::instance()->manager();
		$manager->run_single( 'config.secret_key' );
		$this->use_official( array( 'test_secret_key' => '' ) );
		Plugin::instance()->manager()->run_single( 'config.secret_key' );

		$history = Plugin::instance()->history();
		$latest  = $history->latest_results_by_check();
		$trend   = $history->trend( 'config.secret_key' );

		$this->assertStatus( Diagnostic_Result::FAIL, $latest['config.secret_key'] );
		$this->assertSame( array( 'fail', 'pass' ), array_column( $trend, 'status' ) );
	}

	public function test_retention_by_count_and_age(): void {
		global $wpdb;
		$manager = Plugin::instance()->manager();
		for ( $i = 0; $i < 7; $i++ ) {
			$manager->run_single( 'env.php_version' );
		}
		$history = Plugin::instance()->history();
		$oldest  = $history->list_runs( 1, 6 )[0]['id'];
		$wpdb->update( $wpdb->prefix . 'pihc_runs', array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ) ), array( 'id' => $oldest ) ); // phpcs:ignore WordPress.DB

		$history->prune( 5, 90 );

		$this->assertSame( 5, $history->count_runs() );
		$this->assertNull( $history->get_run( $oldest ) );
		$orphans = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i r LEFT JOIN %i p ON r.run_id = p.id WHERE p.id IS NULL', $wpdb->prefix . 'pihc_results', $wpdb->prefix . 'pihc_runs' ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 0, $orphans, 'Results of pruned runs are deleted too.' );
	}

	public function test_retention_setting_is_applied_after_each_run(): void {
		update_option(
			'pihc_settings',
			array(
				'integration'    => 'woo-paystack',
				'retention_runs' => 5,
			)
		);
		$this->refresh();
		for ( $i = 0; $i < 8; $i++ ) {
			Plugin::instance()->manager()->run_single( 'env.php_version' );
		}

		$this->assertSame( 5, Plugin::instance()->history()->count_runs() );
	}

	public function test_delete_run_and_delete_all(): void {
		$manager = Plugin::instance()->manager();
		$first   = $manager->run_single( 'env.php_version' );
		$manager->run_single( 'env.php_version' );
		$history = Plugin::instance()->history();

		$history->delete_run( $first['run_id'] );
		$this->assertNull( $history->get_run( $first['run_id'] ) );
		$this->assertSame( 1, $history->count_runs() );

		$history->delete_all();
		$this->assertSame( 0, $history->count_runs() );
		$this->assertSame( array(), $history->latest_results_by_check() );
	}
}
