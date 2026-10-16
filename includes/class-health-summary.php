<?php
/**
 * Overall health calculation.
 *
 * Rules (documented in README "Understanding diagnostic statuses"):
 * - Only evaluated checks (PASS, WARNING, FAIL) count. SKIPPED never raises or lowers
 *   the score; instead "coverage" shows how many checks could actually be evaluated.
 * - Score = round( 100 * ( passed + 0.5 * warnings ) / evaluated ). Null when nothing
 *   was evaluated.
 * - Overall status:
 *     critical         at least one FAIL
 *     needs_attention  no FAIL, at least one WARNING
 *     healthy          only PASS among evaluated checks
 *     unknown          nothing evaluated (everything skipped)
 *   The overall status is driven by the worst finding, not by the score, so a single
 *   confirmed failure can never be averaged away by many passing checks.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregates results into counts, score and overall status.
 */
final class Health_Summary {

	public const CRITICAL        = 'critical';
	public const NEEDS_ATTENTION = 'needs_attention';
	public const HEALTHY         = 'healthy';
	public const UNKNOWN         = 'unknown';

	/**
	 * Counts per status.
	 *
	 * @var array<string,int>
	 */
	private $counts;

	/**
	 * Constructor.
	 *
	 * @param array<string,int> $counts Counts keyed by status.
	 */
	public function __construct( array $counts ) {
		$this->counts = array();
		foreach ( Diagnostic_Result::STATUSES as $status ) {
			$this->counts[ $status ] = max( 0, (int) ( $counts[ $status ] ?? 0 ) );
		}
	}

	/**
	 * Builds a summary from results.
	 *
	 * @param Diagnostic_Result[] $results Results.
	 */
	public static function from_results( array $results ): self {
		$counts = array_fill_keys( Diagnostic_Result::STATUSES, 0 );
		foreach ( $results as $result ) {
			++$counts[ $result->status() ];
		}
		return new self( $counts );
	}

	/**
	 * Count for a status.
	 *
	 * @param string $status Status constant.
	 */
	public function count( string $status ): int {
		return $this->counts[ $status ] ?? 0;
	}

	/**
	 * All counts.
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		return $this->counts;
	}

	/**
	 * Total number of checks.
	 */
	public function total(): int {
		return array_sum( $this->counts );
	}

	/**
	 * Checks that actually produced a verdict.
	 */
	public function evaluated(): int {
		return $this->total() - $this->counts[ Diagnostic_Result::SKIPPED ];
	}

	/**
	 * Health score 0-100, or null when nothing was evaluated.
	 */
	public function score(): ?int {
		$evaluated = $this->evaluated();
		if ( 0 === $evaluated ) {
			return null;
		}
		$points = $this->counts[ Diagnostic_Result::PASS ] + 0.5 * $this->counts[ Diagnostic_Result::WARNING ];
		return (int) round( 100 * $points / $evaluated );
	}

	/**
	 * Percentage of checks that could be evaluated.
	 */
	public function coverage(): int {
		$total = $this->total();
		return 0 === $total ? 0 : (int) round( 100 * $this->evaluated() / $total );
	}

	/**
	 * Overall status constant.
	 */
	public function overall(): string {
		if ( $this->counts[ Diagnostic_Result::FAIL ] > 0 ) {
			return self::CRITICAL;
		}
		if ( $this->counts[ Diagnostic_Result::WARNING ] > 0 ) {
			return self::NEEDS_ATTENTION;
		}
		if ( $this->counts[ Diagnostic_Result::PASS ] > 0 ) {
			return self::HEALTHY;
		}
		return self::UNKNOWN;
	}

	/**
	 * Human-readable label for an overall status.
	 *
	 * @param string $overall Overall status constant.
	 */
	public static function label( string $overall ): string {
		switch ( $overall ) {
			case self::CRITICAL:
				return __( 'Problems found', 'paystack-integration-health-checker' );
			case self::NEEDS_ATTENTION:
				return __( 'Needs attention', 'paystack-integration-health-checker' );
			case self::HEALTHY:
				return __( 'Healthy', 'paystack-integration-health-checker' );
			default:
				return __( 'Unknown (not enough evidence)', 'paystack-integration-health-checker' );
		}
	}
}
