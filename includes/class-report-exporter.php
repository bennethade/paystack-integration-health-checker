<?php
/**
 * Builds sanitized diagnostic reports (JSON or plain text).
 *
 * Reports are built from stored, already-redacted history, then redacted again as a
 * whole with every key value configured on the site (defence in depth). They contain
 * versions, the environment snapshot, every result (skipped checks marked as such) and
 * evidence; never keys, authorization headers, customer data or full transaction data.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Report exporter.
 */
class Report_Exporter {

	public const FORMAT_JSON = 'json';
	public const FORMAT_TEXT = 'text';

	/**
	 * Redactor seeded with the site's keys.
	 *
	 * @var Redactor
	 */
	private $redactor;

	/**
	 * Constructor.
	 *
	 * @param Redactor $redactor Redactor.
	 */
	public function __construct( Redactor $redactor ) {
		$this->redactor = $redactor;
	}

	/**
	 * Report data for a stored run.
	 *
	 * @param array{run:array<string,mixed>,results:Diagnostic_Result[]} $stored Stored run.
	 * @return array<string,mixed>
	 */
	public function build( array $stored ): array {
		$run     = $stored['run'];
		$summary = new Health_Summary( $run['counts'] );
		$results = array();
		$skipped = array();

		foreach ( $stored['results'] as $result ) {
			$data      = $result->to_array();
			$results[] = array(
				'check_id'        => $data['check_id'],
				'name'            => $data['check_name'],
				'category'        => $data['category'],
				'status'          => strtoupper( $data['status'] ),
				'summary'         => $data['summary'],
				'explanation'     => $data['explanation'],
				'evidence'        => $data['evidence'],
				'next_steps'      => $data['next_steps'],
				'troubleshooting' => $data['kb'],
				'documentation'   => $data['doc_url'],
				'checked_at'      => gmdate( 'c', $data['timestamp'] ),
				'duration_ms'     => $data['duration_ms'],
			);
			if ( $result->is( Diagnostic_Result::SKIPPED ) ) {
				$skipped[] = array(
					'check_id' => $data['check_id'],
					'reason'   => $data['summary'],
				);
			}
		}//end foreach

		$report = array(
			'report'         => array(
				'generator'    => 'Paystack Integration Health Checker',
				'version'      => defined( 'PIHC_VERSION' ) ? PIHC_VERSION : 'dev',
				'generated_at' => gmdate( 'c' ),
				'notice'       => 'Secrets, authorization headers and customer data are excluded. SKIPPED checks were not performed and do not count toward the score.',
			),
			'environment'    => $run['environment'],
			'run'            => array(
				'id'            => $run['id'],
				'created_at'    => $run['created_at'] . ' UTC',
				'trigger'       => $run['trigger'],
				'overall'       => $run['overall'],
				'overall_label' => Health_Summary::label( $run['overall'] ),
				'score'         => $run['score'],
				'coverage'      => $summary->coverage() . '%',
				'counts'        => array_change_key_case( $run['counts'], CASE_UPPER ),
			),
			'skipped_checks' => $skipped,
			'results'        => $results,
		);

		return $this->redactor->redact_array( $report );
	}

	/**
	 * Serializes a report.
	 *
	 * @param array<string,mixed> $report Report from build().
	 * @param string              $format FORMAT_* constant.
	 */
	public function render( array $report, string $format ): string {
		if ( self::FORMAT_TEXT === $format ) {
			return $this->render_text( $report );
		}
		return (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Download file name.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $format Format.
	 */
	public static function filename( int $run_id, string $format ): string {
		return sprintf( 'paystack-health-report-run-%d-%s.%s', $run_id, gmdate( 'Ymd-His' ), self::FORMAT_TEXT === $format ? 'txt' : 'json' );
	}

	/**
	 * Plain-text rendering.
	 *
	 * @param array<string,mixed> $report Report.
	 */
	private function render_text( array $report ): string {
		$lines   = array();
		$lines[] = 'PAYSTACK INTEGRATION HEALTH REPORT';
		$lines[] = str_repeat( '=', 60 );
		$lines[] = 'Generated: ' . $report['report']['generated_at'] . ' by ' . $report['report']['generator'] . ' ' . $report['report']['version'];
		$lines[] = $report['report']['notice'];
		$lines[] = '';
		$lines[] = 'RUN';
		foreach ( $report['run'] as $key => $value ) {
			$lines[] = sprintf( '  %-14s %s', $key . ':', is_array( $value ) ? self::flatten( $value ) : self::scalar( $value ) );
		}
		$lines[] = '';
		$lines[] = 'ENVIRONMENT';
		foreach ( (array) $report['environment'] as $key => $value ) {
			$lines[] = sprintf( '  %-20s %s', $key . ':', self::scalar( $value ) );
		}
		$lines[] = '';
		$lines[] = 'RESULTS';
		foreach ( $report['results'] as $result ) {
			$lines[] = str_repeat( '-', 60 );
			$lines[] = sprintf( '[%s] %s (%s)', $result['status'], $result['name'], $result['check_id'] );
			$lines[] = '  ' . $result['summary'];
			if ( '' !== $result['explanation'] ) {
				$lines[] = '  Why: ' . $result['explanation'];
			}
			foreach ( (array) $result['evidence'] as $key => $value ) {
				$lines[] = sprintf( '  - %s: %s', $key, is_array( $value ) ? self::flatten( $value ) : self::scalar( $value ) );
			}
			foreach ( (array) $result['next_steps'] as $step ) {
				$lines[] = '  > ' . $step;
			}
		}
		if ( $report['skipped_checks'] ) {
			$lines[] = '';
			$lines[] = 'NOT PERFORMED (SKIPPED)';
			foreach ( $report['skipped_checks'] as $skipped ) {
				$lines[] = '  ' . $skipped['check_id'] . ': ' . $skipped['reason'];
			}
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Formats a nested value on one line.
	 *
	 * @param array<mixed> $value Value.
	 */
	private static function flatten( array $value ): string {
		$parts = array();
		foreach ( $value as $key => $item ) {
			$item    = is_array( $item ) ? '{' . self::flatten( $item ) . '}' : self::scalar( $item );
			$parts[] = is_int( $key ) ? $item : $key . '=' . $item;
		}
		return implode( ', ', $parts );
	}

	/**
	 * Formats a scalar.
	 *
	 * @param mixed $value Value.
	 */
	private static function scalar( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( null === $value ) {
			return 'n/a';
		}
		return (string) $value;
	}
}
