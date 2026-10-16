<?php
/**
 * Payment Diagnostics: verify a transaction reference and compare it with an order.
 *
 * Workflow for administrators:
 *   1. Make a test-mode payment on the store.
 *   2. Copy the reference from the Paystack dashboard or the order notes.
 *   3. Enter the reference (and optionally the order ID) here.
 * The checker calls GET /transaction/verify/:reference with the integration's secret key
 * and compares the server-side answer with the order.
 *
 * Guarantees:
 * - Read-only: the order is loaded with wc_get_order() and never saved. No status, note
 *   or meta is written, and no WooCommerce payment hook is fired.
 * - Only non-sensitive transaction fields are kept (status, amount, currency, domain,
 *   channel, gateway response, dates, ID). Customer, card and authorization data from
 *   the response are discarded before anything is displayed or stored.
 * - The result is based on Paystack's API answer, never on a browser redirect.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

use PaystackHealthChecker\Checks\Connectivity_Checks;
use PaystackHealthChecker\Integrations\Integration_Adapter;

defined( 'ABSPATH' ) || exit;

/**
 * Reference verification tool.
 */
class Reference_Verifier {

	public const CHECK_ID = 'flow.reference_verification';

	/**
	 * Fields copied from Paystack's verify response.
	 */
	private const SAFE_FIELDS = array( 'id', 'status', 'reference', 'amount', 'requested_amount', 'currency', 'domain', 'channel', 'gateway_response', 'paid_at', 'created_at' );

	/**
	 * Context.
	 *
	 * @var Diagnostic_Context
	 */
	private $context;

	/**
	 * Constructor.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public function __construct( Diagnostic_Context $context ) {
		$this->context = $context;
	}

	/**
	 * Whether a reference has the format Paystack allows.
	 *
	 * @param string $reference Reference.
	 */
	public static function is_valid_reference( string $reference ): bool {
		return 1 === preg_match( Paystack_Api_Client::REFERENCE_PATTERN, $reference );
	}

	/**
	 * Verifies a reference and optionally compares it with an order.
	 *
	 * @param string $reference Reference (validated).
	 * @param int    $order_id  Order ID, 0 for none.
	 */
	public function verify( string $reference, int $order_id = 0 ): Diagnostic_Result {
		$started = microtime( true );
		$result  = $this->evaluate( $reference, $order_id );
		return $result->with_meta(
			self::CHECK_ID,
			__( 'Transaction reference verification', 'paystack-integration-health-checker' ),
			Check_Definition::CATEGORY_PAYMENTS,
			time(),
			(int) round( ( microtime( true ) - $started ) * 1000 ),
			'https://paystack.com/docs/api/transaction/#verify'
		);
	}

	/**
	 * Performs the verification.
	 *
	 * @param string $reference Reference.
	 * @param int    $order_id  Order ID.
	 */
	private function evaluate( string $reference, int $order_id ): Diagnostic_Result {
		if ( ! self::is_valid_reference( $reference ) ) {
			return Diagnostic_Result::skipped( __( 'The reference contains characters Paystack does not allow (only letters, digits, "-", "." and "=").', 'paystack-integration-health-checker' ) );
		}

		$unmet = $this->context->unmet_prerequisite( array( Diagnostic_Context::REQUIRES_SECRET_KEY ) );
		if ( null !== $unmet ) {
			return Diagnostic_Result::skipped( $unmet );
		}

		$adapter  = $this->context->adapter();
		$response = $this->context->api()->verify_transaction( $reference );
		$evidence = array_merge(
			array(
				'endpoint'    => 'GET /transaction/verify/:reference',
				'reference'   => $reference,
				'environment' => $adapter->mode(),
			),
			$response->evidence()
		);

		if ( in_array( $response->category(), array( Api_Response::NOT_FOUND, Api_Response::BAD_REQUEST ), true ) ) {
			return Diagnostic_Result::fail(
				/* translators: %s: test or live */
				sprintf( __( 'Paystack did not find this reference in the %s environment.', 'paystack-integration-health-checker' ), $adapter->mode() ),
				array(
					'explanation' => __( 'The reference may be mistyped, belong to the other environment, or never have been initialized.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array( __( 'Copy the reference exactly from the Paystack dashboard, in the same mode as the store.', 'paystack-integration-health-checker' ) ),
					'kb'          => 'wrong_reference',
				)
			);
		}
		if ( ! $response->is_ok() ) {
			return Connectivity_Checks::result_for_failure( $response, $evidence );
		}

		$transaction = self::safe_transaction( (array) $response->data() );
		$evidence    = array_merge( $evidence, array( 'paystack' => $transaction ) );

		if ( ! isset( $transaction['status'], $transaction['reference'] ) || ! hash_equals( $reference, (string) $transaction['reference'] ) ) {
			return Diagnostic_Result::warning(
				__( 'Paystack\'s answer did not contain the expected reference.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'The response cannot be trusted for this reference; the cause is unknown.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
				)
			);
		}

		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( $order_id > 0 && ! $order instanceof \WC_Order ) {
			return Diagnostic_Result::warning(
				/* translators: %d: order ID */
				sprintf( __( 'The transaction was verified, but order #%d was not found.', 'paystack-integration-health-checker' ), $order_id ),
				array( 'evidence' => $evidence )
			);
		}

		$environment_note = '';
		if ( isset( $transaction['domain'] ) && $transaction['domain'] !== $adapter->mode() ) {
			/* translators: 1: transaction environment, 2: store environment */
			$environment_note = sprintf( __( 'The transaction belongs to the %1$s environment but the store is in %2$s mode.', 'paystack-integration-health-checker' ), $transaction['domain'], $adapter->mode() );
		}

		if ( ! $order instanceof \WC_Order ) {
			return $this->transaction_only_result( $transaction, $evidence, $environment_note );
		}

		return $this->compare_with_order( $transaction, $order, $adapter, $evidence, $environment_note );
	}

	/**
	 * Result when no order was supplied.
	 *
	 * @param array<string,mixed> $transaction      Safe transaction fields.
	 * @param array<string,mixed> $evidence         Evidence.
	 * @param string              $environment_note Environment mismatch note.
	 */
	private function transaction_only_result( array $transaction, array $evidence, string $environment_note ): Diagnostic_Result {
		$status = (string) $transaction['status'];
		$steps  = array( __( 'Enter the order ID to compare the transaction with the WooCommerce order.', 'paystack-integration-health-checker' ) );

		if ( '' !== $environment_note ) {
			return Diagnostic_Result::warning(
				$environment_note,
				array(
					'evidence' => $evidence,
					'kb'       => 'wrong_environment',
				)
			);
		}
		if ( 'success' === $status ) {
			return Diagnostic_Result::pass(
				__( 'Paystack confirms this transaction was successful.', 'paystack-integration-health-checker' ),
				array(
					'evidence'   => $evidence,
					'next_steps' => $steps,
				)
			);
		}
		return Diagnostic_Result::warning(
			/* translators: %s: Paystack status */
			sprintf( __( 'Paystack reports this transaction as "%s".', 'paystack-integration-health-checker' ), $status ),
			array(
				'explanation' => __( 'Only "success" means the customer was charged. abandoned/ongoing/pending/processing/queued are not final; failed and reversed mean no money was kept.', 'paystack-integration-health-checker' ),
				'evidence'    => $evidence,
				'next_steps'  => $steps,
				'kb'          => 'verification_failed',
			)
		);
	}

	/**
	 * Compares the transaction with an order (read-only).
	 *
	 * @param array<string,mixed> $transaction      Safe transaction fields.
	 * @param \WC_Order           $order            Order.
	 * @param Integration_Adapter $adapter          Adapter.
	 * @param array<string,mixed> $evidence         Evidence.
	 * @param string              $environment_note Environment mismatch note.
	 */
	private function compare_with_order( array $transaction, \WC_Order $order, Integration_Adapter $adapter, array $evidence, string $environment_note ): Diagnostic_Result {
		$expected  = self::to_subunits( (string) $order->get_total() );
		$currency  = strtoupper( (string) $order->get_currency() );
		$ref_key   = $adapter->order_reference_meta_key();
		$order_ref = null !== $ref_key ? (string) $order->get_meta( $ref_key ) : '';

		$evidence['order'] = array(
			'order_id'                => $order->get_id(),
			'status'                  => $order->get_status(),
			'is_paid'                 => $order->is_paid() ? 'yes' : 'no',
			'payment_method'          => $order->get_payment_method(),
			'total_subunits'          => $expected,
			'currency'                => $currency,
			'transaction_id_recorded' => '' !== (string) $order->get_transaction_id() ? 'yes' : 'no',
			'reference_on_order'      => '' !== $order_ref ? $order_ref : 'not recorded',
		);

		$findings = array();
		$failures = array();

		if ( '' !== $environment_note ) {
			$failures[] = $environment_note;
		}
		if ( null !== $adapter->gateway_id() && $order->get_payment_method() !== $adapter->gateway_id() ) {
			/* translators: %s: payment method ID */
			$findings[] = sprintf( __( 'The order was placed with payment method "%s", not the gateway being diagnosed.', 'paystack-integration-health-checker' ), $order->get_payment_method() );
		}
		if ( '' !== $order_ref && ! hash_equals( $order_ref, (string) $transaction['reference'] ) ) {
			$findings[] = __( 'The order records a different reference; this transaction may be an earlier or a separate attempt.', 'paystack-integration-health-checker' );
		}

		$status = (string) $transaction['status'];
		if ( 'success' === $status ) {
			$charged = (int) ( $transaction['amount'] ?? 0 );
			if ( strtoupper( (string) ( $transaction['currency'] ?? '' ) ) !== $currency ) {
				/* translators: 1: transaction currency, 2: order currency */
				$failures[] = sprintf( __( 'Currency mismatch: Paystack %1$s, order %2$s.', 'paystack-integration-health-checker' ), (string) ( $transaction['currency'] ?? '?' ), $currency );
			} elseif ( null !== $expected && $charged !== $expected && ! ( isset( $transaction['requested_amount'] ) && (int) $transaction['requested_amount'] === $expected && $charged > $expected ) ) {
				/* translators: 1: charged subunits, 2: expected subunits */
				$failures[] = sprintf( __( 'Amount mismatch: Paystack charged %1$d subunits, the order total is %2$d subunits.', 'paystack-integration-health-checker' ), $charged, $expected );
			}
			if ( ! $order->is_paid() && ! $failures ) {
				return Diagnostic_Result::fail(
					__( 'Paystack confirms the payment, but the order is not marked as paid.', 'paystack-integration-health-checker' ),
					array(
						'explanation' => __( 'The payment succeeded at Paystack but did not reach the order. The webhook or the customer return was not received or not processed. This tool does not change the order.', 'paystack-integration-health-checker' ),
						'evidence'    => $evidence,
						'next_steps'  => array_merge(
							$findings,
							array(
								__( 'Use the gateway\'s own re-verification feature, or mark the order paid manually after confirming in the Paystack dashboard.', 'paystack-integration-health-checker' ),
								__( 'Then run the webhook checks to find out why the notification was not processed.', 'paystack-integration-health-checker' ),
							)
						),
						'kb'          => 'order_not_updating',
					)
				);
			}
		} elseif ( $order->is_paid() ) {
			$findings[] = sprintf(
				/* translators: %s: Paystack status */
				__( 'The order is marked paid, but this transaction is "%s".', 'paystack-integration-health-checker' ),
				$status
			);
		}//end if

		if ( $failures ) {
			return Diagnostic_Result::fail(
				__( 'The transaction does not match the order.', 'paystack-integration-health-checker' ),
				array(
					'evidence'   => $evidence,
					'next_steps' => array_merge( $failures, $findings, array( __( 'Do not fulfil the order until the difference is understood.', 'paystack-integration-health-checker' ) ) ),
					'kb'         => '' !== $environment_note ? 'wrong_environment' : 'amount_currency_mismatch',
				)
			);
		}

		if ( $findings || 'success' !== $status ) {
			return Diagnostic_Result::warning(
				/* translators: %s: Paystack status */
				sprintf( __( 'Paystack status "%s"; the order and transaction need a closer look.', 'paystack-integration-health-checker' ), $status ),
				array(
					'evidence'   => $evidence,
					'next_steps' => $findings ? $findings : array( __( 'Wait for the transaction to finish, or ask the customer to pay again if it was abandoned or failed.', 'paystack-integration-health-checker' ) ),
					'kb'         => 'verification_failed',
				)
			);
		}

		return Diagnostic_Result::pass(
			__( 'Paystack confirms the payment and it matches the paid order (amount, currency, status).', 'paystack-integration-health-checker' ),
			array( 'evidence' => $evidence )
		);
	}

	/**
	 * Keeps only non-sensitive transaction fields.
	 *
	 * @param array<string,mixed> $data Verify response data.
	 * @return array<string,scalar>
	 */
	public static function safe_transaction( array $data ): array {
		$safe = array();
		foreach ( self::SAFE_FIELDS as $field ) {
			if ( isset( $data[ $field ] ) && is_scalar( $data[ $field ] ) ) {
				$safe[ $field ] = 'id' === $field ? (string) $data[ $field ] : $data[ $field ];
			}
		}
		if ( isset( $safe['gateway_response'] ) ) {
			$safe['gateway_response'] = substr( (string) $safe['gateway_response'], 0, 100 );
		}
		return $safe;
	}

	/**
	 * Converts a decimal order total to Paystack subunits (x100) exactly.
	 *
	 * @param string $total Decimal string.
	 * @return int|null Null when the total cannot be represented exactly.
	 */
	public static function to_subunits( string $total ): ?int {
		if ( ! preg_match( '/^(\d{1,12})(?:\.(\d+))?\z/', trim( $total ), $m ) ) {
			return null;
		}
		$fraction = $m[2] ?? '';
		if ( strlen( $fraction ) > 2 && '' !== trim( substr( $fraction, 2 ), '0' ) ) {
			return null;
		}
		return (int) $m[1] * 100 + (int) str_pad( substr( $fraction, 0, 2 ), 2, '0' );
	}
}
