<?php
/**
 * Paystack API connectivity and authentication checks.
 *
 * "API authentication" calls GET /integration/payment_session_timeout (documented,
 * read-only, requires the secret key) once per run. The outcome is classified into
 * transport, HTTP and API-level failures; a PASS requires HTTP 2xx, a valid envelope
 * with status=true AND the documented data field.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Checks;

use PaystackHealthChecker\Api_Response;
use PaystackHealthChecker\Check_Definition;
use PaystackHealthChecker\Diagnostic_Context;
use PaystackHealthChecker\Diagnostic_Registry;
use PaystackHealthChecker\Diagnostic_Result;

defined( 'ABSPATH' ) || exit;

/**
 * Connectivity checks.
 */
final class Connectivity_Checks {

	public const API_HOST      = 'api.paystack.co';
	public const SLOW_MS       = 5000;
	public const DOC_INTEGRATE = 'https://paystack.com/docs/api/integration/';

	/**
	 * Registers the checks.
	 *
	 * @param Diagnostic_Registry $registry Registry.
	 */
	public static function register( Diagnostic_Registry $registry ): void {
		$registry->add(
			new Check_Definition(
				array(
					'id'          => 'api.dns',
					'name'        => __( 'DNS resolution of api.paystack.co', 'paystack-integration-health-checker' ),
					'category'    => Check_Definition::CATEGORY_CONNECTIVITY,
					'description' => __( 'The server can resolve Paystack\'s API hostname. No request is sent to Paystack.', 'paystack-integration-health-checker' ),
					'callback'    => array( __CLASS__, 'dns' ),
				)
			)
		);
		$registry->add(
			new Check_Definition(
				array(
					'id'          => 'api.authentication',
					'name'        => __( 'API connectivity and authentication', 'paystack-integration-health-checker' ),
					'category'    => Check_Definition::CATEGORY_CONNECTIVITY,
					'description' => __( 'Calls Paystack\'s read-only "Fetch Timeout" endpoint with the active secret key to confirm the server can reach Paystack and the key is accepted. No payment is created.', 'paystack-integration-health-checker' ),
					'callback'    => array( __CLASS__, 'authentication' ),
					'requires'    => array( Diagnostic_Context::REQUIRES_SECRET_KEY ),
					'network'     => true,
					'doc_url'     => self::DOC_INTEGRATE,
				)
			)
		);
	}

	/**
	 * DNS resolution.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function dns( Diagnostic_Context $context ): Diagnostic_Result {
		unset( $context );
		$started   = microtime( true );
		$addresses = gethostbynamel( self::API_HOST );
		$elapsed   = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( ! is_array( $addresses ) || array() === $addresses ) {
			return Diagnostic_Result::fail(
				__( 'The server could not resolve api.paystack.co.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'Without DNS resolution no API call can reach Paystack. PHP\'s resolver was used; cURL normally uses the same system resolver.', 'paystack-integration-health-checker' ),
					'evidence'    => array(
						'host'       => self::API_HOST,
						'elapsed_ms' => $elapsed,
					),
					'next_steps'  => array( __( 'Ask your host to check the server\'s DNS configuration and outbound DNS access.', 'paystack-integration-health-checker' ) ),
					'kb'          => 'dns_failure',
				)
			);
		}

		return Diagnostic_Result::pass(
			__( 'api.paystack.co resolves.', 'paystack-integration-health-checker' ),
			array(
				'evidence' => array(
					'host'       => self::API_HOST,
					'addresses'  => count( $addresses ),
					'elapsed_ms' => $elapsed,
				),
			)
		);
	}

	/**
	 * API authentication.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function authentication( Diagnostic_Context $context ): Diagnostic_Result {
		$response = $context->authentication_probe();
		$mode     = $context->adapter()->mode();
		$evidence = array_merge(
			array(
				'endpoint'    => 'GET /integration/payment_session_timeout',
				'environment' => $mode,
			),
			$response->evidence()
		);

		if ( $response->is_ok() ) {
			$data = (array) $response->data();
			if ( ! array_key_exists( 'payment_session_timeout', $data ) || ! is_numeric( $data['payment_session_timeout'] ) ) {
				return Diagnostic_Result::warning(
					__( 'Paystack answered, but the response did not have the documented shape.', 'paystack-integration-health-checker' ),
					array(
						'explanation' => __( 'HTTP and the envelope looked successful, but data.payment_session_timeout was missing. Authentication is likely fine; the API response format may have changed.', 'paystack-integration-health-checker' ),
						'evidence'    => $evidence,
					)
				);
			}
			if ( $response->elapsed_ms() > self::SLOW_MS ) {
				return Diagnostic_Result::warning(
					/* translators: %d: milliseconds */
					sprintf( __( 'Authentication succeeded but Paystack took %d ms to answer.', 'paystack-integration-health-checker' ), $response->elapsed_ms() ),
					array(
						'explanation' => __( 'Slow responses risk checkout timeouts. One slow response can be a transient network issue; re-run to compare.', 'paystack-integration-health-checker' ),
						'evidence'    => $evidence,
						'kb'          => 'api_timeout',
					)
				);
			}
			return Diagnostic_Result::pass(
				/* translators: %s: test or live */
				sprintf( __( 'Paystack accepted the %s secret key.', 'paystack-integration-health-checker' ), $mode ),
				array( 'evidence' => $evidence )
			);
		}//end if

		return self::result_for_failure( $response, $evidence );
	}

	/**
	 * Maps a failed API response to a result. Shared with Payment Diagnostics.
	 *
	 * @param Api_Response        $response Response.
	 * @param array<string,mixed> $evidence Evidence.
	 */
	public static function result_for_failure( Api_Response $response, array $evidence ): Diagnostic_Result {
		switch ( $response->category() ) {
			case Api_Response::UNAUTHORIZED:
				return Diagnostic_Result::fail(
					__( 'Paystack rejected the secret key (HTTP 401 Unauthorized).', 'paystack-integration-health-checker' ),
					array(
						'explanation' => __( 'The request reached Paystack and was refused. Possible causes: the key is wrong or was regenerated, it belongs to the other environment, or IP whitelisting is enabled and this server\'s IP is not listed. The response does not say which.', 'paystack-integration-health-checker' ),
						'evidence'    => $evidence,
						'next_steps'  => array(
							__( 'Compare the key hint in "Secret key configured" with the Paystack dashboard for the selected environment.', 'paystack-integration-health-checker' ),
							__( 'If IP whitelisting is enabled on your Paystack account, add this server\'s public IPv4 address.', 'paystack-integration-health-checker' ),
						),
						'kb'          => 'http_401_403',
					)
				);
			case Api_Response::FORBIDDEN:
				return Diagnostic_Result::fail(
					__( 'Paystack refused the request (HTTP 403 Forbidden).', 'paystack-integration-health-checker' ),
					array(
						'explanation' => __( 'The key was recognised but is not allowed to make this request, or the request was blocked (for example by IP restrictions).', 'paystack-integration-health-checker' ),
						'evidence'    => $evidence,
						'next_steps'  => array( __( 'Check IP whitelisting and the key\'s permissions in the Paystack dashboard.', 'paystack-integration-health-checker' ) ),
						'kb'          => 'http_401_403',
					)
				);
			case Api_Response::RATE_LIMITED:
				return Diagnostic_Result::warning(
					__( 'Paystack rate-limited the request (HTTP 429); authentication could not be confirmed.', 'paystack-integration-health-checker' ),
					array(
						'explanation' => __( 'Paystack limits requests per integration and environment. The x-ratelimit-reset value says when to retry.', 'paystack-integration-health-checker' ),
						'evidence'    => $evidence,
						'next_steps'  => array( __( 'Wait for the reset time and run the check again. Look for code that calls the API in a loop.', 'paystack-integration-health-checker' ) ),
						'kb'          => 'rate_limited',
					)
				);
			case Api_Response::SERVER_ERROR:
				return Diagnostic_Result::warning(
					__( 'Paystack returned a server error; authentication could not be confirmed.', 'paystack-integration-health-checker' ),
					array(
						'explanation' => __( 'HTTP 5xx responses indicate a problem on Paystack\'s side, not with this site.', 'paystack-integration-health-checker' ),
						'evidence'    => $evidence,
						'next_steps'  => array( __( 'Run the check again later and check status.paystack.com.', 'paystack-integration-health-checker' ) ),
						'kb'          => 'paystack_server_error',
					)
				);
			case Api_Response::TIMEOUT:
				return Diagnostic_Result::fail(
					__( 'The request to Paystack timed out.', 'paystack-integration-health-checker' ),
					array(
						'explanation' => __( 'No response arrived within the timeout. This can be a blocked outbound connection or a temporary network problem; re-run to see whether it repeats.', 'paystack-integration-health-checker' ),
						'evidence'    => $evidence,
						'next_steps'  => array( __( 'Re-run the check. If it keeps timing out, ask your host whether outbound HTTPS to api.paystack.co is allowed.', 'paystack-integration-health-checker' ) ),
						'kb'          => 'api_timeout',
					)
				);
			case Api_Response::DNS:
				return Diagnostic_Result::fail(
					__( 'The server could not resolve Paystack\'s API hostname.', 'paystack-integration-health-checker' ),
					array(
						'evidence'   => $evidence,
						'next_steps' => array( __( 'Ask your host to fix DNS resolution on the server.', 'paystack-integration-health-checker' ) ),
						'kb'         => 'dns_failure',
					)
				);
			case Api_Response::TLS:
				return Diagnostic_Result::fail(
					__( 'A TLS/certificate error prevented a secure connection to Paystack.', 'paystack-integration-health-checker' ),
					array(
						'explanation' => __( 'Certificate verification must stay enabled; the fix is on the server (CA bundle, OpenSSL) or an intercepting proxy.', 'paystack-integration-health-checker' ),
						'evidence'    => $evidence,
						'next_steps'  => array( __( 'Ask your host to update the CA certificate bundle and OpenSSL.', 'paystack-integration-health-checker' ) ),
						'kb'          => 'tls_failure',
					)
				);
			case Api_Response::CONNECTION:
			case Api_Response::TRANSPORT:
				return Diagnostic_Result::fail(
					__( 'The server could not connect to Paystack.', 'paystack-integration-health-checker' ),
					array(
						'evidence'   => $evidence,
						'next_steps' => array( __( 'Ask your host whether outbound HTTPS connections to api.paystack.co are blocked by a firewall.', 'paystack-integration-health-checker' ) ),
						'kb'         => 'api_timeout',
					)
				);
			case Api_Response::NOT_CONFIGURED:
				return Diagnostic_Result::skipped( __( 'No secret key is configured.', 'paystack-integration-health-checker' ), array( 'evidence' => $evidence ) );
			default:
				return Diagnostic_Result::warning(
					__( 'Paystack\'s response could not be interpreted; authentication is unconfirmed.', 'paystack-integration-health-checker' ),
					array(
						'explanation' => __( 'The response was not the documented JSON envelope (for example an HTML error page from a proxy), or reported status=false. The cause is unknown from this evidence.', 'paystack-integration-health-checker' ),
						'evidence'    => $evidence,
						'next_steps'  => array( __( 'Re-run the check. If it persists, check for proxies between the server and the internet.', 'paystack-integration-health-checker' ) ),
					)
				);
		}//end switch
	}
}
