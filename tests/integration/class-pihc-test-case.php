<?php
/**
 * Base class for integration tests.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Tests;

use PaystackHealthChecker\Diagnostic_Result;
use PaystackHealthChecker\Plugin;
use PaystackHealthChecker\Settings;

/**
 * Shared fixtures: integration configuration, HTTP mocks, runners.
 */
abstract class PIHC_Test_Case extends \WP_UnitTestCase {

	public const TEST_SECRET = 'sk_test_0123456789abcdef0123456789abcdef01234567';
	public const LIVE_SECRET = 'sk_live_fedcba9876543210fedcba9876543210fedcba98';
	public const TEST_PUBLIC = 'pk_test_aaaabbbbccccddddeeeeffff0000111122223333';
	public const LIVE_PUBLIC = 'pk_live_9999888877776666555544443333222211110000';

	/**
	 * Recorded outbound requests.
	 *
	 * @var array<int,array{url:string,args:array}>
	 */
	protected $requests = array();

	/**
	 * Responder for api.paystack.co.
	 *
	 * @var callable|null
	 */
	private $api_responder;

	/**
	 * Responder for loopback (own-site) requests.
	 *
	 * @var callable|null
	 */
	private $loopback_responder;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->requests           = array();
		$this->api_responder      = null;
		$this->loopback_responder = null;

		delete_option( Settings::OPTION );
		delete_option( 'woocommerce_paystack_settings' );
		delete_option( 'woocommerce_pwcg_paystack_settings' );
		if ( PIHC_TESTS_WOOCOMMERCE ) {
			update_option( 'woocommerce_currency', 'NGN' );
		}

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
		$this->refresh();
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		Plugin::instance()->reset();
		parent::tear_down();
	}

	/**
	 * Rebuilds cached services and WooCommerce gateway instances after settings change.
	 */
	protected function refresh(): void {
		Plugin::instance()->reset();
		if ( PIHC_TESTS_WOOCOMMERCE && did_action( 'woocommerce_init' ) ) {
			WC()->payment_gateways()->init();
		}
	}

	/**
	 * Configures the official woo-paystack gateway and selects it.
	 *
	 * @param array<string,string> $overrides Settings.
	 */
	protected function use_official( array $overrides = array() ): void {
		update_option(
			'woocommerce_paystack_settings',
			array_merge(
				array(
					'enabled'         => 'yes',
					'testmode'        => 'yes',
					'payment_page'    => 'redirect',
					'test_secret_key' => self::TEST_SECRET,
					'test_public_key' => self::TEST_PUBLIC,
					'live_secret_key' => '',
					'live_public_key' => '',
				),
				$overrides
			)
		);
		update_option( Settings::OPTION, array( 'integration' => 'woo-paystack' ) );
		$this->refresh();
	}

	/**
	 * Configures the pwcg gateway and selects it.
	 *
	 * @param array<string,string> $overrides Settings.
	 */
	protected function use_pwcg( array $overrides = array() ): void {
		update_option(
			'woocommerce_pwcg_paystack_settings',
			array_merge(
				array(
					'enabled'         => 'yes',
					'testmode'        => 'yes',
					'test_secret_key' => self::TEST_SECRET,
					'live_secret_key' => '',
					'title'           => 'Pay with Paystack',
				),
				$overrides
			)
		);
		update_option( Settings::OPTION, array( 'integration' => 'pwcg' ) );
		$this->refresh();
	}

	/**
	 * Configures manual keys and selects the manual adapter.
	 *
	 * @param array<string,mixed> $overrides Settings.
	 */
	protected function use_manual( array $overrides = array() ): void {
		update_option(
			Settings::OPTION,
			array_merge(
				array(
					'integration'            => 'manual',
					'manual_mode'            => 'test',
					'manual_test_secret_key' => self::TEST_SECRET,
				),
				$overrides
			)
		);
		$this->refresh();
	}

	/**
	 * Runs one check through the manager (prerequisites, timing, redaction included).
	 *
	 * @param string $check_id Check ID.
	 */
	protected function run_check( string $check_id ): Diagnostic_Result {
		$plugin = Plugin::instance();
		$check  = $plugin->registry()->get( $check_id );
		$this->assertNotNull( $check, 'Unknown check ' . $check_id );
		return $plugin->manager()->run_check( $check, $plugin->context() );
	}

	/**
	 * Asserts a result status with a helpful message.
	 *
	 * @param string            $expected Status.
	 * @param Diagnostic_Result $result   Result.
	 */
	protected function assertStatus( string $expected, Diagnostic_Result $result ): void {
		$this->assertSame( $expected, $result->status(), $result->check_id() . ': ' . $result->summary() . ' ' . wp_json_encode( $result->evidence() ) );
	}

	/**
	 * Records requests and answers mocked ones (pre_http_request filter).
	 *
	 * @param mixed  $preempt Preempt.
	 * @param array  $args    Args.
	 * @param string $url     URL.
	 * @return mixed
	 */
	public function intercept( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( 0 === strpos( $url, 'https://api.paystack.co/' ) && null !== $this->api_responder ) {
			return call_user_func( $this->api_responder, $url, $args );
		}
		if ( \PaystackHealthChecker\Security::is_own_site_url( $url ) && null !== $this->loopback_responder ) {
			return call_user_func( $this->loopback_responder, $url, $args );
		}
		return $preempt;
	}

	/**
	 * Mocks Paystack API responses.
	 *
	 * @param callable|array|\WP_Error $responder Response or fn( $url, $args ).
	 */
	protected function mock_api( $responder ): void {
		$this->api_responder = is_callable( $responder ) ? $responder : static function () use ( $responder ) {
			return $responder;
		};
	}

	/**
	 * Mocks loopback responses.
	 *
	 * @param callable|array|\WP_Error $responder Response or fn( $url, $args ).
	 */
	protected function mock_loopback( $responder ): void {
		$this->loopback_responder = is_callable( $responder ) ? $responder : static function () use ( $responder ) {
			return $responder;
		};
	}

	/**
	 * HTTP API response array.
	 *
	 * @param int                  $code    Status.
	 * @param array|string         $body    Body.
	 * @param array<string,string> $headers Headers.
	 */
	protected static function response( int $code, $body, array $headers = array() ): array {
		return array(
			'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
			'body'     => is_array( $body ) ? wp_json_encode( $body ) : $body,
			'response' => array(
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Documented "Fetch Timeout" success response.
	 */
	protected static function timeout_ok(): array {
		return self::response(
			200,
			array(
				'status'  => true,
				'message' => 'Payment session timeout retrieved',
				'data'    => array( 'payment_session_timeout' => 30 ),
			)
		);
	}

	/**
	 * Requests made to a host.
	 *
	 * @param string $prefix URL prefix.
	 */
	protected function requests_to( string $prefix ): array {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $request ) use ( $prefix ) {
					return 0 === strpos( $request['url'], $prefix );
				}
			)
		);
	}

	/**
	 * Creates an order paid with a gateway.
	 *
	 * @param array<string,mixed> $args total, currency, status, gateway, txn, age_minutes, meta.
	 */
	protected function create_order( array $args = array() ): \WC_Order {
		$args  = array_merge(
			array(
				'total'       => '15000.00',
				'currency'    => 'NGN',
				'status'      => 'pending',
				'gateway'     => 'paystack',
				'txn'         => '',
				'age_minutes' => 0,
				'meta'        => array(),
			),
			$args
		);
		$order = wc_create_order();
		$order->set_currency( $args['currency'] );
		$order->set_payment_method( $args['gateway'] );
		$order->set_billing_email( 'customer@example.com' );
		$order->set_total( $args['total'] );
		$order->set_status( $args['status'] );
		$order->set_created_via( 'checkout' );
		if ( '' !== $args['txn'] ) {
			$order->set_transaction_id( $args['txn'] );
		}
		if ( $args['age_minutes'] > 0 ) {
			$order->set_date_created( time() - $args['age_minutes'] * MINUTE_IN_SECONDS );
		}
		foreach ( $args['meta'] as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
		return wc_get_order( $order->get_id() );
	}
}
