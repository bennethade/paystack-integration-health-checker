<?php
/**
 * Troubleshooting knowledge base.
 *
 * Each entry lists symptoms, possible causes, how to confirm which cause applies, and
 * fixes. Results link to entries by ID. Entries describe *possible* causes: a result
 * states a confirmed root cause only when its own evidence proves it.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Knowledge base.
 */
final class Troubleshooting {

	/**
	 * All entries, keyed by ID.
	 *
	 * @return array<string,array{title:string,symptoms:string,causes:string[],confirm:string[],fix:string[]}>
	 */
	public static function entries(): array {
		$entries = array(
			'missing_credentials'       => array(
				'title'    => __( 'Missing or invalid credentials', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'Checkout shows a generic payment error; the API check reports 401; no Paystack page opens.', 'paystack-integration-health-checker' ),
				'causes'   => array(
					__( 'No secret key saved for the active environment.', 'paystack-integration-health-checker' ),
					__( 'A key was copied with missing characters or extra spaces.', 'paystack-integration-health-checker' ),
					__( 'The key was rolled (regenerated) in the Paystack dashboard and the old key is still saved.', 'paystack-integration-health-checker' ),
				),
				'confirm'  => array(
					__( 'Run "Secret key configured" and "API authentication".', 'paystack-integration-health-checker' ),
					__( 'Compare the last four characters shown by the checker with the key in Paystack Dashboard > Settings > API Keys & Webhooks.', 'paystack-integration-health-checker' ),
				),
				'fix'      => array( __( 'Paste the current secret key for the active environment into the gateway settings and run the checks again.', 'paystack-integration-health-checker' ) ),
			),
			'key_format'                => array(
				'title'    => __( 'Key has an unexpected format', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'The format check warns that a key does not start with sk_test_/sk_live_ (or pk_test_/pk_live_).', 'paystack-integration-health-checker' ),
				'causes'   => array(
					__( 'A public key was pasted into a secret-key field, or the other way round.', 'paystack-integration-health-checker' ),
					__( 'A key from another provider was pasted.', 'paystack-integration-health-checker' ),
				),
				'confirm'  => array( __( 'The format check is local and cannot prove a key is invalid; the API authentication check is the authoritative test.', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Copy the key again from Paystack Dashboard > Settings > API Keys & Webhooks.', 'paystack-integration-health-checker' ) ),
			),
			'wrong_environment'         => array(
				'title'    => __( 'Test/live environment mismatch', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'Payments appear in the wrong Paystack dashboard mode, webhooks fail signature checks, or verification says the transaction is not found.', 'paystack-integration-health-checker' ),
				'causes'   => array(
					__( 'Test mode is enabled but live keys were saved in the test fields (or the reverse).', 'paystack-integration-health-checker' ),
					__( 'The webhook URL is configured for one environment only in the Paystack dashboard.', 'paystack-integration-health-checker' ),
				),
				'confirm'  => array( __( 'Run "Environment matches credentials" and compare with the transaction\'s "domain" in Payment Diagnostics.', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Use sk_test_/pk_test_ keys with test mode and sk_live_/pk_live_ keys with live mode, and set the webhook URL for each environment.', 'paystack-integration-health-checker' ) ),
			),
			'api_timeout'               => array(
				'title'    => __( 'API connection timeouts', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'Checkout hangs then errors; API checks report a timeout.', 'paystack-integration-health-checker' ),
				'causes'   => array(
					__( 'Outbound HTTPS from the server is blocked or slowed by a firewall or proxy.', 'paystack-integration-health-checker' ),
					__( 'Temporary network congestion or a Paystack incident.', 'paystack-integration-health-checker' ),
				),
				'confirm'  => array( __( 'Re-run "API authentication" a few times and compare elapsed times; ask your host whether outbound connections to api.paystack.co on port 443 are allowed.', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Ask the host to allow outbound HTTPS to api.paystack.co. If the problem is intermittent, check status.paystack.com.', 'paystack-integration-health-checker' ) ),
			),
			'dns_failure'               => array(
				'title'    => __( 'DNS resolution failure', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'API checks report "could not resolve host".', 'paystack-integration-health-checker' ),
				'causes'   => array( __( 'The server\'s DNS resolver is misconfigured or outbound DNS is blocked.', 'paystack-integration-health-checker' ) ),
				'confirm'  => array( __( 'Run "DNS resolution of api.paystack.co".', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Ask the hosting provider to fix DNS resolution on the server.', 'paystack-integration-health-checker' ) ),
			),
			'tls_failure'               => array(
				'title'    => __( 'TLS or certificate errors', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'API checks report SSL/TLS or certificate errors.', 'paystack-integration-health-checker' ),
				'causes'   => array(
					__( 'Outdated CA certificate bundle or OpenSSL on the server.', 'paystack-integration-health-checker' ),
					__( 'A proxy intercepting HTTPS traffic.', 'paystack-integration-health-checker' ),
				),
				'confirm'  => array( __( 'Check "PHP extensions" and the TLS detail in the API check evidence.', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Update the server\'s CA bundle and OpenSSL. Never disable certificate verification; Paystack requires it.', 'paystack-integration-health-checker' ) ),
			),
			'http_401_403'              => array(
				'title'    => __( 'HTTP 401 or 403 from Paystack', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'API authentication fails with 401 Unauthorized or 403 Forbidden.', 'paystack-integration-health-checker' ),
				'causes'   => array(
					__( '401: the secret key is wrong, revoked or belongs to the other environment.', 'paystack-integration-health-checker' ),
					__( 'IP whitelisting is enabled on the Paystack account and this server\'s public IP is not on the list.', 'paystack-integration-health-checker' ),
					__( '403: the key is not permitted to perform the request.', 'paystack-integration-health-checker' ),
				),
				'confirm'  => array( __( 'Check whether IP whitelisting is enabled in the Paystack dashboard and compare with your server\'s outbound IP (ask your host).', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Use the current key for the active environment, or add the server\'s public IPv4 address to the whitelist.', 'paystack-integration-health-checker' ) ),
			),
			'rate_limited'              => array(
				'title'    => __( 'Rate limiting (HTTP 429)', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'Requests fail with 429 "Rate limit exceeded".', 'paystack-integration-health-checker' ),
				'causes'   => array( __( 'Too many requests in the rate-limit window. Paystack documents a default of 600 requests/60 s in live mode and 100 requests/60 s in test mode, per integration.', 'paystack-integration-health-checker' ) ),
				'confirm'  => array( __( 'The x-ratelimit-reset header in the evidence says when the window ends.', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Wait for the reset time. Look for plugins or scripts polling the API in a loop.', 'paystack-integration-health-checker' ) ),
			),
			'paystack_server_error'     => array(
				'title'    => __( 'Paystack server errors (5xx)', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'API checks report HTTP 500-599.', 'paystack-integration-health-checker' ),
				'causes'   => array( __( 'A problem on Paystack\'s side.', 'paystack-integration-health-checker' ) ),
				'confirm'  => array( __( 'Re-run later and check status.paystack.com.', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Nothing to change on the site; report persistent errors to Paystack support.', 'paystack-integration-health-checker' ) ),
			),
			'wrong_reference'           => array(
				'title'    => __( 'Incorrect transaction reference', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'Verification says the transaction was not found.', 'paystack-integration-health-checker' ),
				'causes'   => array(
					__( 'The reference was mistyped or copied from the other environment.', 'paystack-integration-health-checker' ),
					__( 'The transaction was never initialized with that reference.', 'paystack-integration-health-checker' ),
				),
				'confirm'  => array( __( 'Search the reference in the Paystack dashboard in both test and live mode.', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Use the exact reference shown in the Paystack dashboard or the order notes.', 'paystack-integration-health-checker' ) ),
			),
			'amount_currency_mismatch'  => array(
				'title'    => __( 'Amount or currency mismatch', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'A successful Paystack transaction does not match the order total or currency.', 'paystack-integration-health-checker' ),
				'causes'   => array(
					__( 'The order total changed after the transaction was initialized.', 'paystack-integration-health-checker' ),
					__( 'Paystack amounts are in subunits (x100); a conversion bug in the integration.', 'paystack-integration-health-checker' ),
					__( 'The customer bore Paystack fees, so the charged amount is higher than the order total.', 'paystack-integration-health-checker' ),
				),
				'confirm'  => array( __( 'Compare the amounts shown by Payment Diagnostics; check whether requested_amount equals the order total.', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Do not fulfil until the difference is understood; refund or collect the difference as appropriate.', 'paystack-integration-health-checker' ) ),
			),
			'verification_failed'       => array(
				'title'    => __( 'Payment verification failures', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'The customer was charged but the store did not confirm the payment.', 'paystack-integration-health-checker' ),
				'causes'   => array(
					__( 'The callback or webhook could not reach Paystack\'s Verify API (see API checks).', 'paystack-integration-health-checker' ),
					__( 'The integration rejected the transaction because of a mismatch.', 'paystack-integration-health-checker' ),
				),
				'confirm'  => array( __( 'Verify the reference in Payment Diagnostics and compare it with the order.', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Fix the connectivity or configuration problem, then use the gateway\'s own re-verification feature if it has one.', 'paystack-integration-health-checker' ) ),
			),
			'missing_webhooks'          => array(
				'title'    => __( 'Missing webhook events', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'Orders stay pending when customers close the browser before returning to the store.', 'paystack-integration-health-checker' ),
				'causes'   => array(
					__( 'The webhook URL is not set, or set to a different URL, in the Paystack dashboard.', 'paystack-integration-health-checker' ),
					__( 'The site is not publicly reachable (localhost, private network, maintenance mode, basic auth).', 'paystack-integration-health-checker' ),
					__( 'A firewall, security plugin or CDN blocks POST requests from Paystack.', 'paystack-integration-health-checker' ),
				),
				'confirm'  => array( __( 'Compare the expected URL from "Webhook URL" with the dashboard; check delivery attempts in the Paystack dashboard\'s webhook logs.', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Set the exact URL for each environment and allow POST requests to it from Paystack\'s documented IP addresses.', 'paystack-integration-health-checker' ) ),
			),
			'invalid_webhook_signature' => array(
				'title'    => __( 'Invalid webhook signatures', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'The integration rejects Paystack\'s webhooks (for example HTTP 401) or silently ignores them.', 'paystack-integration-health-checker' ),
				'causes'   => array(
					__( 'The store verifies with a different secret key than the one Paystack signs with (test/live mix-up or rotated key).', 'paystack-integration-health-checker' ),
					__( 'A proxy or plugin modifies the request body before the integration reads it; the signature covers the exact raw body.', 'paystack-integration-health-checker' ),
				),
				'confirm'  => array( __( 'Use "Signed test event" to confirm the handler accepts a correctly signed request with the configured key.', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Make sure the secret key for the environment Paystack is sending from is saved, and that nothing rewrites webhook request bodies.', 'paystack-integration-health-checker' ) ),
			),
			'duplicate_webhooks'        => array(
				'title'    => __( 'Duplicate webhook processing', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'Duplicate order notes, double stock reduction or repeated e-mails for one payment.', 'paystack-integration-health-checker' ),
				'causes'   => array(
					__( 'Paystack retries events that do not receive HTTP 200 (every 3 minutes for the first 4 tries, then hourly for 72 hours in live mode).', 'paystack-integration-health-checker' ),
					__( 'The integration does not process events idempotently.', 'paystack-integration-health-checker' ),
				),
				'confirm'  => array( __( 'Check order notes for repeated payment notes and the gateway log for repeated deliveries of one reference.', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Return HTTP 200 quickly and use an integration that ignores repeated events for already-paid orders.', 'paystack-integration-health-checker' ) ),
			),
			'order_not_updating'        => array(
				'title'    => __( 'WooCommerce order status not updating', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'Paystack shows a successful payment but the order is still pending.', 'paystack-integration-health-checker' ),
				'causes'   => array(
					__( 'Neither the customer return nor the webhook reached the store.', 'paystack-integration-health-checker' ),
					__( 'Verification failed (connectivity, key, mismatch).', 'paystack-integration-health-checker' ),
					__( 'A fatal error in the gateway or another plugin during processing.', 'paystack-integration-health-checker' ),
				),
				'confirm'  => array( __( 'Verify the reference in Payment Diagnostics with the order ID, then check "Integration errors in WooCommerce logs".', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Fix the cause found; then use the gateway\'s re-verification feature or update the order manually after confirming the payment in Paystack.', 'paystack-integration-health-checker' ) ),
			),
			'plugin_conflict'           => array(
				'title'    => __( 'Plugin conflicts', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'Two Paystack gateways are active, or fatal errors mention another plugin.', 'paystack-integration-health-checker' ),
				'causes'   => array( __( 'Paystack sends each environment\'s webhooks to one URL only, so with two Paystack gateways only one of them receives webhooks.', 'paystack-integration-health-checker' ) ),
				'confirm'  => array( __( 'Run "Paystack integration detected" to list active Paystack gateways.', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Keep one Paystack gateway enabled, or make sure the webhook URL points to the one that processes orders.', 'paystack-integration-health-checker' ) ),
			),
			'unsupported_wc_feature'    => array(
				'title'    => __( 'Unsupported WooCommerce features', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'The payment method is missing from the Checkout block, or WooCommerce shows an incompatibility notice for HPOS.', 'paystack-integration-health-checker' ),
				'causes'   => array(
					__( 'The gateway does not register a Checkout block integration.', 'paystack-integration-health-checker' ),
					__( 'The gateway has not declared compatibility with High-Performance Order Storage.', 'paystack-integration-health-checker' ),
				),
				'confirm'  => array( __( 'Run "Checkout Blocks support" and "HPOS compatibility".', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Update the gateway; if it still lacks support, use the classic checkout (shortcode) or keep legacy order storage until it is supported.', 'paystack-integration-health-checker' ) ),
			),
			'php_environment'           => array(
				'title'    => __( 'Missing PHP extensions or incompatible PHP version', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'Fatal errors, or HTTPS requests that cannot be made.', 'paystack-integration-health-checker' ),
				'causes'   => array( __( 'PHP is older than required, or curl/openssl/json/hash are missing.', 'paystack-integration-health-checker' ) ),
				'confirm'  => array( __( 'Run the Environment checks.', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Ask the host to upgrade PHP and enable the missing extensions.', 'paystack-integration-health-checker' ) ),
			),
			'site_not_public'           => array(
				'title'    => __( 'Site not publicly reachable', 'paystack-integration-health-checker' ),
				'symptoms' => __( 'Webhooks never arrive on a development or staging site.', 'paystack-integration-health-checker' ),
				'causes'   => array( __( 'The site runs on localhost or a private network that Paystack cannot reach.', 'paystack-integration-health-checker' ) ),
				'confirm'  => array( __( 'Run "Public reachability".', 'paystack-integration-health-checker' ) ),
				'fix'      => array( __( 'Use a public HTTPS URL (for example a tunnelling service) for webhook testing, and set it in the Paystack dashboard.', 'paystack-integration-health-checker' ) ),
			),
		);

		/**
		 * Filters the troubleshooting knowledge base.
		 *
		 * @since 1.0.0
		 * @param array $entries Entries keyed by ID.
		 */
		return (array) apply_filters( 'pihc_troubleshooting_entries', $entries );
	}

	/**
	 * One entry.
	 *
	 * @param string $id Entry ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $id ): ?array {
		$entries = self::entries();
		return $entries[ $id ] ?? null;
	}
}
