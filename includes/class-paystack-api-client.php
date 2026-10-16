<?php
/**
 * Read-only Paystack API client used by diagnostics.
 *
 * Only two documented GET endpoints are ever called (https://paystack.com/docs/api/):
 *   GET /integration/payment_session_timeout  - "Fetch Timeout". Requires the secret key,
 *       changes nothing and returns one number, so it is used to confirm authentication.
 *   GET /transaction/verify/:reference         - "Verify Transaction", only for a reference
 *       the administrator supplies on the Payment Diagnostics screen.
 * The client never initializes or charges transactions and never sends a body.
 *
 * Authentication: "Authorization: Bearer SECRET_KEY". TLS peer verification stays on,
 * as Paystack's docs require.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Paystack API client.
 */
class Paystack_Api_Client {

	public const BASE_URL = 'https://api.paystack.co';

	public const PATH_SESSION_TIMEOUT = '/integration/payment_session_timeout';
	public const PATH_VERIFY          = '/transaction/verify/';

	/**
	 * Request timeout in seconds. Diagnostics should fail fast; a slower answer is itself a finding.
	 */
	public const TIMEOUT = 15;

	/**
	 * Allowed reference characters per Paystack docs ("-", ".", "=" and alphanumerics).
	 */
	public const REFERENCE_PATTERN = '/^[A-Za-z0-9.=\-]{1,100}\z/';

	/**
	 * Secret key.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Redactor.
	 *
	 * @var Redactor
	 */
	private $redactor;

	/**
	 * Constructor.
	 *
	 * @param string   $secret_key Secret key (never logged or returned).
	 * @param Redactor $redactor   Redactor.
	 */
	public function __construct( string $secret_key, Redactor $redactor ) {
		$this->secret_key = $secret_key;
		$this->redactor   = $redactor;
	}

	/**
	 * Calls "Fetch Timeout" to confirm connectivity and authentication.
	 */
	public function fetch_payment_session_timeout(): Api_Response {
		return $this->get( self::PATH_SESSION_TIMEOUT );
	}

	/**
	 * Calls "Verify Transaction".
	 *
	 * @param string $reference Transaction reference (validated against the documented format).
	 */
	public function verify_transaction( string $reference ): Api_Response {
		if ( ! preg_match( self::REFERENCE_PATTERN, $reference ) ) {
			return new Api_Response( Api_Response::BAD_REQUEST, 0, 0, 'Reference contains characters Paystack does not allow.' );
		}
		return $this->get( self::PATH_VERIFY . rawurlencode( $reference ) );
	}

	/**
	 * Performs a GET to a fixed Paystack path and classifies the outcome.
	 *
	 * @param string $path Path under BASE_URL (constants only).
	 */
	private function get( string $path ): Api_Response {
		if ( '' === $this->secret_key ) {
			return new Api_Response( Api_Response::NOT_CONFIGURED );
		}

		$started  = microtime( true );
		$response = wp_remote_get(
			self::BASE_URL . $path,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'sslverify'   => true,
				'user-agent'  => 'PaystackIntegrationHealthChecker/' . ( defined( 'PIHC_VERSION' ) ? PIHC_VERSION : 'dev' ) . '; WordPress/' . get_bloginfo( 'version' ),
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->secret_key,
					'Accept'        => 'application/json',
				),
			)
		);
		$elapsed  = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			return new Api_Response( self::classify_transport_error( $message ), 0, $elapsed, $this->redactor->redact_string( substr( $message, 0, 300 ) ) );
		}

		$status     = (int) wp_remote_retrieve_response_code( $response );
		$rate_limit = array();
		foreach ( array( 'limit', 'remaining', 'reset' ) as $name ) {
			$value = wp_remote_retrieve_header( $response, 'x-ratelimit-' . $name );
			if ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) {
				$rate_limit[ $name ] = $value;
			}
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$message = is_array( $decoded ) && isset( $decoded['message'] ) && is_string( $decoded['message'] )
			? $this->redactor->redact_string( substr( $decoded['message'], 0, 200 ) )
			: '';

		if ( $status < 200 || $status >= 300 ) {
			return new Api_Response( self::classify_http_status( $status ), $status, $elapsed, $message, null, $rate_limit );
		}
		if ( ! is_array( $decoded ) ) {
			return new Api_Response( Api_Response::INVALID_JSON, $status, $elapsed, 'Response body is not valid JSON.', null, $rate_limit );
		}
		if ( true !== ( $decoded['status'] ?? null ) ) {
			return new Api_Response( Api_Response::API_ERROR, $status, $elapsed, $message, null, $rate_limit );
		}
		if ( ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			return new Api_Response( Api_Response::UNEXPECTED, $status, $elapsed, 'Response has no data object.', null, $rate_limit );
		}

		return new Api_Response( Api_Response::OK, $status, $elapsed, $message, $decoded['data'], $rate_limit );
	}

	/**
	 * Classifies a WordPress HTTP transport error message. cURL error numbers are the
	 * most reliable signal; message text is the fallback for other transports.
	 *
	 * @param string $message WP_Error message.
	 */
	public static function classify_transport_error( string $message ): string {
		if ( preg_match( '/cURL error (\d+)/i', $message, $m ) ) {
			$code = (int) $m[1];
			if ( in_array( $code, array( 6 ), true ) ) {
				return Api_Response::DNS;
			}
			if ( 28 === $code ) {
				return Api_Response::TIMEOUT;
			}
			if ( in_array( $code, array( 35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 ), true ) ) {
				return Api_Response::TLS;
			}
			if ( in_array( $code, array( 5, 7 ), true ) ) {
				return Api_Response::CONNECTION;
			}
		}
		if ( preg_match( '/could not resolve|name or service not known|getaddrinfo|no such host/i', $message ) ) {
			return Api_Response::DNS;
		}
		if ( preg_match( '/timed out|timeout/i', $message ) ) {
			return Api_Response::TIMEOUT;
		}
		if ( preg_match( '/ssl|tls|certificate/i', $message ) ) {
			return Api_Response::TLS;
		}
		if ( preg_match( '/connection refused|failed to connect|couldn\'t connect/i', $message ) ) {
			return Api_Response::CONNECTION;
		}
		return Api_Response::TRANSPORT;
	}

	/**
	 * Classifies an HTTP error status.
	 *
	 * @param int $status HTTP status.
	 */
	private static function classify_http_status( int $status ): string {
		if ( 401 === $status ) {
			return Api_Response::UNAUTHORIZED;
		}
		if ( 403 === $status ) {
			return Api_Response::FORBIDDEN;
		}
		if ( 404 === $status ) {
			return Api_Response::NOT_FOUND;
		}
		if ( 429 === $status ) {
			return Api_Response::RATE_LIMITED;
		}
		if ( $status >= 500 ) {
			return Api_Response::SERVER_ERROR;
		}
		return Api_Response::BAD_REQUEST;
	}
}
