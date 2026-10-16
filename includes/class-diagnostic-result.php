<?php
/**
 * The result of one diagnostic check.
 *
 * Statuses:
 *   PASS    - the check ran and the requirement was satisfied.
 *   WARNING - the check ran and found a potential problem or a condition needing attention.
 *   FAIL    - the check ran and confirmed a problem.
 *   SKIPPED - the check could not be performed (missing prerequisite, unsafe, or internal error).
 *
 * A check reports FAIL only when its evidence confirms the problem. When evidence is
 * suggestive but not conclusive it reports WARNING and says what is still unknown.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable diagnostic result.
 */
final class Diagnostic_Result {

	public const PASS    = 'pass';
	public const WARNING = 'warning';
	public const FAIL    = 'fail';
	public const SKIPPED = 'skipped';

	public const STATUSES = array( self::PASS, self::WARNING, self::FAIL, self::SKIPPED );

	/**
	 * Result fields.
	 *
	 * @var array<string,mixed>
	 */
	private $data;

	/**
	 * Constructor. Prefer the named constructors.
	 *
	 * @param string              $status  One of the status constants.
	 * @param string              $summary One-sentence summary.
	 * @param array<string,mixed> $details Optional: explanation, evidence, next_steps, kb, doc_url,
	 *                                     check_id, check_name, category, timestamp, duration_ms.
	 * @throws \InvalidArgumentException On an unknown status.
	 */
	public function __construct( string $status, string $summary, array $details = array() ) {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown diagnostic status.' );
		}

		$this->data = array(
			'check_id'    => (string) ( $details['check_id'] ?? '' ),
			'check_name'  => (string) ( $details['check_name'] ?? '' ),
			'category'    => (string) ( $details['category'] ?? '' ),
			'status'      => $status,
			'summary'     => $summary,
			'explanation' => (string) ( $details['explanation'] ?? '' ),
			'evidence'    => is_array( $details['evidence'] ?? null ) ? $details['evidence'] : array(),
			'next_steps'  => array_values( array_map( 'strval', (array) ( $details['next_steps'] ?? array() ) ) ),
			'kb'          => (string) ( $details['kb'] ?? '' ),
			'doc_url'     => (string) ( $details['doc_url'] ?? '' ),
			'timestamp'   => (int) ( $details['timestamp'] ?? time() ),
			'duration_ms' => isset( $details['duration_ms'] ) ? (int) $details['duration_ms'] : null,
		);
	}

	/**
	 * PASS result.
	 *
	 * @param string              $summary Summary.
	 * @param array<string,mixed> $details Details.
	 */
	public static function pass( string $summary, array $details = array() ): self {
		return new self( self::PASS, $summary, $details );
	}

	/**
	 * WARNING result.
	 *
	 * @param string              $summary Summary.
	 * @param array<string,mixed> $details Details.
	 */
	public static function warning( string $summary, array $details = array() ): self {
		return new self( self::WARNING, $summary, $details );
	}

	/**
	 * FAIL result.
	 *
	 * @param string              $summary Summary.
	 * @param array<string,mixed> $details Details.
	 */
	public static function fail( string $summary, array $details = array() ): self {
		return new self( self::FAIL, $summary, $details );
	}

	/**
	 * SKIPPED result.
	 *
	 * @param string              $summary Why the check could not run.
	 * @param array<string,mixed> $details Details.
	 */
	public static function skipped( string $summary, array $details = array() ): self {
		return new self( self::SKIPPED, $summary, $details );
	}

	/**
	 * Rebuilds a result from its array form (history storage).
	 *
	 * @param array<string,mixed> $data Array from to_array().
	 */
	public static function from_array( array $data ): self {
		$status = in_array( $data['status'] ?? '', self::STATUSES, true ) ? $data['status'] : self::SKIPPED;
		return new self( $status, (string) ( $data['summary'] ?? '' ), $data );
	}

	/**
	 * Copy with identity and timing metadata filled in by the manager.
	 *
	 * @param string $check_id    Check ID.
	 * @param string $check_name  Check name.
	 * @param string $category    Category.
	 * @param int    $timestamp   Unix time.
	 * @param int    $duration_ms Duration.
	 * @param string $doc_url     Fallback documentation URL.
	 */
	public function with_meta( string $check_id, string $check_name, string $category, int $timestamp, int $duration_ms, string $doc_url = '' ): self {
		$data                = $this->data;
		$data['check_id']    = $check_id;
		$data['check_name']  = $check_name;
		$data['category']    = $category;
		$data['timestamp']   = $timestamp;
		$data['duration_ms'] = $duration_ms;
		if ( '' === $data['doc_url'] ) {
			$data['doc_url'] = $doc_url;
		}
		return new self( $data['status'], $data['summary'], $data );
	}

	/**
	 * Copy with evidence passed through a sanitizer (used before storage and export).
	 *
	 * @param callable $sanitizer fn( array ): array.
	 */
	public function with_sanitized_evidence( callable $sanitizer ): self {
		$data             = $this->data;
		$data['evidence'] = $sanitizer( $data['evidence'] );
		return new self( $data['status'], $data['summary'], $data );
	}

	/**
	 * Check ID.
	 */
	public function check_id(): string {
		return $this->data['check_id'];
	}

	/**
	 * Check name.
	 */
	public function check_name(): string {
		return $this->data['check_name'];
	}

	/**
	 * Category.
	 */
	public function category(): string {
		return $this->data['category'];
	}

	/**
	 * Status constant.
	 */
	public function status(): string {
		return $this->data['status'];
	}

	/**
	 * Whether the result has the given status.
	 *
	 * @param string $status Status constant.
	 */
	public function is( string $status ): bool {
		return $this->data['status'] === $status;
	}

	/**
	 * Summary.
	 */
	public function summary(): string {
		return $this->data['summary'];
	}

	/**
	 * Technical explanation.
	 */
	public function explanation(): string {
		return $this->data['explanation'];
	}

	/**
	 * Sanitized evidence (key => scalar or list).
	 *
	 * @return array<string,mixed>
	 */
	public function evidence(): array {
		return $this->data['evidence'];
	}

	/**
	 * Suggested next steps.
	 *
	 * @return string[]
	 */
	public function next_steps(): array {
		return $this->data['next_steps'];
	}

	/**
	 * Troubleshooting knowledge-base entry ID.
	 */
	public function kb(): string {
		return $this->data['kb'];
	}

	/**
	 * Documentation URL.
	 */
	public function doc_url(): string {
		return $this->data['doc_url'];
	}

	/**
	 * Unix timestamp.
	 */
	public function timestamp(): int {
		return $this->data['timestamp'];
	}

	/**
	 * Duration in milliseconds, if measured.
	 */
	public function duration_ms(): ?int {
		return $this->data['duration_ms'];
	}

	/**
	 * Array form.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}
}
