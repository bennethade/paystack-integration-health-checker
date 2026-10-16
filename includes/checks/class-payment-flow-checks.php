<?php
/**
 * Payment flow checks based on evidence in existing orders.
 *
 * Orders are read with wc_get_orders() (HPOS- and posts-compatible) and only counted;
 * no customer data is read into results. Nothing is written.
 *
 * Stage map (what can be verified without a test transaction):
 *   1 Checkout initiation       - configuration checks (gateway enabled/available)
 *   2 Transaction initialization- API authentication (cannot initialize without a payment)
 *   3 Redirect/handoff          - not observable without a test payment
 *   4 Verification              - API authentication; Payment Diagnostics with a reference
 *   5 Webhook reception         - webhook checks (loopback) + Paystack dashboard logs
 *   6 Order state update        - these checks (paid orders, stuck pending orders)
 *   7 Duplicate handling        - only observable in the integration's logs/notes
 *   8 Error logging/recovery    - log checks
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Checks;

use PaystackHealthChecker\Check_Definition;
use PaystackHealthChecker\Diagnostic_Context;
use PaystackHealthChecker\Diagnostic_Registry;
use PaystackHealthChecker\Diagnostic_Result;

defined( 'ABSPATH' ) || exit;

/**
 * Payment flow checks.
 */
final class Payment_Flow_Checks {

	public const WINDOW_DAYS     = 30;
	public const STUCK_AFTER_MIN = 60;
	public const MAX_ORDERS      = 500;

	/**
	 * Registers the checks.
	 *
	 * @param Diagnostic_Registry $registry Registry.
	 */
	public static function register( Diagnostic_Registry $registry ): void {
		$requires = array( Diagnostic_Context::REQUIRES_INTEGRATION, Diagnostic_Context::REQUIRES_GATEWAY );
		$checks   = array(
			array( 'flow.recent_orders', __( 'Recent Paystack order outcomes', 'paystack-integration-health-checker' ), __( 'Counts this gateway\'s orders from the last 30 days by status to show whether payments are completing.', 'paystack-integration-health-checker' ), 'recent_orders' ),
			array( 'flow.stuck_orders', __( 'Orders stuck in pending payment', 'paystack-integration-health-checker' ), __( 'Pending orders older than 60 minutes may be unpaid, abandoned, or paid without the store being notified.', 'paystack-integration-health-checker' ), 'stuck_orders' ),
			array( 'flow.paid_without_transaction', __( 'Paid orders have a transaction ID', 'paystack-integration-health-checker' ), __( 'Paid orders normally record Paystack\'s transaction ID; missing IDs suggest manual status changes or a verification step that did not run.', 'paystack-integration-health-checker' ), 'paid_without_transaction' ),
		);

		foreach ( $checks as $check ) {
			$registry->add(
				new Check_Definition(
					array(
						'id'          => $check[0],
						'name'        => $check[1],
						'category'    => Check_Definition::CATEGORY_PAYMENTS,
						'description' => $check[2],
						'callback'    => array( __CLASS__, $check[3] ),
						'requires'    => $requires,
						'doc_url'     => 'https://paystack.com/docs/payments/verify-payments/',
					)
				)
			);
		}
	}

	/**
	 * Order outcome counts.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function recent_orders( Diagnostic_Context $context ): Diagnostic_Result {
		$orders = self::recent( (string) $context->adapter()->gateway_id() );
		if ( array() === $orders ) {
			return Diagnostic_Result::skipped(
				__( 'No orders used this gateway in the last 30 days, so there is no payment evidence to evaluate.', 'paystack-integration-health-checker' ),
				array( 'next_steps' => array( __( 'Place a test-mode order and verify it under Payment Diagnostics.', 'paystack-integration-health-checker' ) ) )
			);
		}

		$counts = array();
		foreach ( $orders as $order ) {
			$status            = $order->get_status();
			$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + 1;
		}
		ksort( $counts );

		$total    = count( $orders );
		$paid     = ( $counts['processing'] ?? 0 ) + ( $counts['completed'] ?? 0 );
		$failed   = $counts['failed'] ?? 0;
		$evidence = array(
			'window'          => self::WINDOW_DAYS . ' days',
			'orders_examined' => $total,
			'status_counts'   => $counts,
			'sample_limited'  => $total >= self::MAX_ORDERS ? 'yes (newest ' . self::MAX_ORDERS . ')' : 'no',
		);

		if ( 0 === $paid && $total >= 3 ) {
			return Diagnostic_Result::warning(
				/* translators: %d: count */
				sprintf( __( 'None of the last %d orders using this gateway was paid.', 'paystack-integration-health-checker' ), $total ),
				array(
					'explanation' => __( 'Either customers are abandoning payment, or payments succeed at Paystack but are not confirmed on the store. These cannot be told apart without checking a reference.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array( __( 'Take a reference from the Paystack dashboard for one of these orders and verify it under Payment Diagnostics with the order ID.', 'paystack-integration-health-checker' ) ),
					'kb'          => 'order_not_updating',
				)
			);
		}

		if ( $total >= 5 && $failed / $total > 0.5 ) {
			return Diagnostic_Result::warning(
				__( 'More than half of recent orders using this gateway failed.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'Failed orders usually mean declined payments, which are normal in small numbers. A high rate can also indicate a configuration problem; the reason is in each order\'s notes and the Paystack dashboard.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'kb'          => 'verification_failed',
				)
			);
		}

		return Diagnostic_Result::pass(
			/* translators: 1: paid, 2: total */
			sprintf( __( '%1$d of %2$d recent orders using this gateway were paid.', 'paystack-integration-health-checker' ), $paid, $total ),
			array( 'evidence' => $evidence )
		);
	}

	/**
	 * Stuck pending orders.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function stuck_orders( Diagnostic_Context $context ): Diagnostic_Result {
		$cutoff = time() - self::STUCK_AFTER_MIN * MINUTE_IN_SECONDS;
		$stuck  = array_filter(
			self::recent( (string) $context->adapter()->gateway_id(), array( 'pending' ) ),
			static function ( \WC_Order $order ) use ( $cutoff ) {
				$created = $order->get_date_created();
				return null !== $created && $created->getTimestamp() < $cutoff;
			}
		);

		$evidence = array(
			'window'         => self::WINDOW_DAYS . ' days',
			'pending_over'   => self::STUCK_AFTER_MIN . ' minutes',
			'count'          => count( $stuck ),
			'example_orders' => array_slice(
				array_map(
					static function ( \WC_Order $order ) {
						return '#' . $order->get_order_number();
					},
					array_values( $stuck )
				),
				0,
				5
			),
		);

		if ( $stuck ) {
			return Diagnostic_Result::warning(
				/* translators: %d: count */
				sprintf( __( '%d orders have been pending payment for over an hour.', 'paystack-integration-health-checker' ), count( $stuck ) ),
				array(
					'explanation' => __( 'Most are usually abandoned checkouts. Some may have been paid without the store being notified (missing webhook, failed verification). The order data alone cannot tell which.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array( __( 'Search the Paystack dashboard for these orders. For any successful transaction, verify its reference with the order ID under Payment Diagnostics.', 'paystack-integration-health-checker' ) ),
					'kb'          => 'order_not_updating',
				)
			);
		}

		return Diagnostic_Result::pass( __( 'No orders using this gateway are stuck in pending payment.', 'paystack-integration-health-checker' ), array( 'evidence' => $evidence ) );
	}

	/**
	 * Paid orders without a transaction ID.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function paid_without_transaction( Diagnostic_Context $context ): Diagnostic_Result {
		$paid = self::recent( (string) $context->adapter()->gateway_id(), array( 'processing', 'completed' ) );
		if ( array() === $paid ) {
			return Diagnostic_Result::skipped( __( 'No paid orders used this gateway in the last 30 days.', 'paystack-integration-health-checker' ) );
		}

		$missing  = array_filter(
			$paid,
			static function ( \WC_Order $order ) {
				return '' === (string) $order->get_transaction_id();
			}
		);
		$evidence = array(
			'paid_orders'            => count( $paid ),
			'without_transaction_id' => count( $missing ),
		);

		if ( $missing ) {
			return Diagnostic_Result::warning(
				/* translators: %d: count */
				sprintf( __( '%d paid orders have no transaction ID.', 'paystack-integration-health-checker' ), count( $missing ) ),
				array(
					'explanation' => __( 'Gateways normally store the Paystack transaction ID when they verify a payment. Missing IDs suggest the status was changed manually, or the integration does not store IDs. Not necessarily an error.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array( __( 'Spot-check one of these orders against the Paystack dashboard.', 'paystack-integration-health-checker' ) ),
				)
			);
		}

		return Diagnostic_Result::pass( __( 'All recent paid orders have a transaction ID.', 'paystack-integration-health-checker' ), array( 'evidence' => $evidence ) );
	}

	/**
	 * Recent orders for a gateway.
	 *
	 * @param string   $gateway_id Gateway ID.
	 * @param string[] $statuses   Statuses (empty = any).
	 * @return \WC_Order[]
	 */
	private static function recent( string $gateway_id, array $statuses = array() ): array {
		$args = array(
			'payment_method' => $gateway_id,
			'date_created'   => '>' . ( time() - self::WINDOW_DAYS * DAY_IN_SECONDS ),
			'limit'          => self::MAX_ORDERS,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'type'           => 'shop_order',
		);
		if ( $statuses ) {
			$args['status'] = $statuses;
		}
		$orders = wc_get_orders( $args );
		return array_values(
			array_filter(
				is_array( $orders ) ? $orders : array(),
				static function ( $order ) {
					return $order instanceof \WC_Order;
				}
			)
		);
	}
}
