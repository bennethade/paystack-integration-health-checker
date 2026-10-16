<?php
/**
 * Classified outcome of one Paystack API request.
 *
 * Distinguishes three layers so findings can be precise:
 *   transport  - no HTTP response at all (DNS, TLS, connection, timeout)
 *   HTTP       - a response with a non-2xx status (401, 403, 404, 429, 5xx, ...)
 *   API        - HTTP 2xx but the Paystack envelope says status=false, or is malformed
 *
 * Only redacted, non-sensitive information is kept.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * API response value object.
 */
final class Api_Response {

	public const OK             = 'ok';
	public const NOT_CONFIGURED = 'not_configured';
	public const DNS            = 'dns_failure';
	public const TLS            = 'tls_failure';
	public const CONNECTION     = 'connection_failure';
	public const TIMEOUT        = 'timeout';
	public const TRANSPORT      = 'transport_failure';
	public const UNAUTHORIZED   = 'http_401_unauthorized';
	public const FORBIDDEN      = 'http_403_forbidden';
	public const NOT_FOUND      = 'http_404_not_found';
	public const BAD_REQUEST    = 'http_4xx_client_error';
	public const RATE_LIMITED   = 'http_429_rate_limited';
	public const SERVER_ERROR   = 'http_5xx_server_error';
	public const INVALID_JSON   = 'invalid_json';
	public const API_ERROR      = 'api_status_false';
	public const UNEXPECTED     = 'unexpected_response';

	/**
	 * Classification constant.
	 *
	 * @var string
	 */
	private $category;

	/**
	 * HTTP status (0 when no response).
	 *
	 * @var int
	 */
	private $http_status;

	/**
	 * Elapsed time in milliseconds.
	 *
	 * @var int
	 */
	private $elapsed_ms;

	/**
	 * Redacted detail (transport error or Paystack message).
	 *
	 * @var string
	 */
	private $detail;

	/**
	 * Response "data" object when the envelope was valid.
	 *
	 * @var array<string,mixed>|null
	 */
	private $data;

	/**
	 * Rate-limit headers (limit, remaining, reset) when present.
	 *
	 * @var array<string,string>
	 */
	private $rate_limit;

	/**
	 * Constructor.
	 *
	 * @param string                   $category    Classification.
	 * @param int                      $http_status HTTP status.
	 * @param int                      $elapsed_ms  Elapsed milliseconds.
	 * @param string                   $detail      Redacted detail.
	 * @param array<string,mixed>|null $data        Envelope data.
	 * @param array<string,string>     $rate_limit  Rate-limit headers.
	 */
	public function __construct( string $category, int $http_status = 0, int $elapsed_ms = 0, string $detail = '', ?array $data = null, array $rate_limit = array() ) {
		$this->category    = $category;
		$this->http_status = $http_status;
		$this->elapsed_ms  = $elapsed_ms;
		$this->detail      = $detail;
		$this->data        = $data;
		$this->rate_limit  = $rate_limit;
	}

	/**
	 * Classification.
	 */
	public function category(): string {
		return $this->category;
	}

	/**
	 * Whether the request succeeded end to end.
	 */
	public function is_ok(): bool {
		return self::OK === $this->category;
	}

	/**
	 * Whether no HTTP response was received.
	 */
	public function is_transport_failure(): bool {
		return in_array( $this->category, array( self::DNS, self::TLS, self::CONNECTION, self::TIMEOUT, self::TRANSPORT ), true );
	}

	/**
	 * HTTP status.
	 */
	public function http_status(): int {
		return $this->http_status;
	}

	/**
	 * Elapsed milliseconds.
	 */
	public function elapsed_ms(): int {
		return $this->elapsed_ms;
	}

	/**
	 * Redacted detail.
	 */
	public function detail(): string {
		return $this->detail;
	}

	/**
	 * Envelope data.
	 *
	 * @return array<string,mixed>|null
	 */
	public function data(): ?array {
		return $this->data;
	}

	/**
	 * Rate-limit headers.
	 *
	 * @return array<string,string>
	 */
	public function rate_limit(): array {
		return $this->rate_limit;
	}

	/**
	 * Evidence safe to display and store.
	 *
	 * @return array<string,mixed>
	 */
	public function evidence(): array {
		$evidence = array(
			'category'    => $this->category,
			'http_status' => $this->http_status > 0 ? $this->http_status : 'no response',
			'elapsed_ms'  => $this->elapsed_ms,
		);
		if ( '' !== $this->detail ) {
			$evidence['detail'] = $this->detail;
		}
		foreach ( $this->rate_limit as $name => $value ) {
			$evidence[ 'x-ratelimit-' . $name ] = $value;
		}
		return $evidence;
	}
}
