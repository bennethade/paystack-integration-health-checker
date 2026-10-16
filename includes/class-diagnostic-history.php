<?php
/**
 * Stores and retrieves diagnostic runs.
 *
 * Results are redacted before they reach this class (Diagnostic_Manager) and only the
 * fields needed for troubleshooting are stored. Retention is bounded by both a maximum
 * number of runs and a maximum age (settings), enforced after every run and daily.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostic history repository.
 */
class Diagnostic_History {

	public const RUNS_TABLE    = 'pihc_runs';
	public const RESULTS_TABLE = 'pihc_results';

	/**
	 * Runs table name.
	 */
	private function runs_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::RUNS_TABLE;
	}

	/**
	 * Results table name.
	 */
	private function results_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::RESULTS_TABLE;
	}

	/**
	 * Saves a run with its results.
	 *
	 * @param string              $trigger     Trigger type.
	 * @param Diagnostic_Result[] $results     Results (already redacted).
	 * @param Health_Summary      $summary     Summary.
	 * @param array<string,mixed> $environment Environment snapshot.
	 * @param int                 $user_id     User who ran it.
	 * @return int Run ID (0 on failure).
	 */
	public function save_run( string $trigger, array $results, Health_Summary $summary, array $environment, int $user_id ): int {
		global $wpdb;

		$now      = current_time( 'mysql', true );
		$duration = 0;
		foreach ( $results as $result ) {
			$duration += (int) $result->duration_ms();
		}

		$row     = array(
			'created_at'   => $now,
			'trigger_type' => substr( $trigger, 0, 20 ),
			'user_id'      => $user_id,
			'overall'      => $summary->overall(),
			'passed'       => $summary->count( Diagnostic_Result::PASS ),
			'warnings'     => $summary->count( Diagnostic_Result::WARNING ),
			'failed'       => $summary->count( Diagnostic_Result::FAIL ),
			'skipped'      => $summary->count( Diagnostic_Result::SKIPPED ),
			'duration_ms'  => $duration,
			'environment'  => (string) wp_json_encode( $environment ),
		);
		$formats = array( '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s' );
		if ( null !== $summary->score() ) {
			$row['score'] = $summary->score();
			$formats[]    = '%d';
		}

		if ( false === $wpdb->insert( $this->runs_table(), $row, $formats ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
			return 0;
		}
		$run_id = (int) $wpdb->insert_id;

		foreach ( $results as $result ) {
			$data = $result->to_array();
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
				$this->results_table(),
				array(
					'run_id'      => $run_id,
					'check_id'    => substr( $result->check_id(), 0, 100 ),
					'check_name'  => substr( $result->check_name(), 0, 190 ),
					'category'    => substr( $result->category(), 0, 40 ),
					'status'      => $result->status(),
					'summary'     => $result->summary(),
					'details'     => (string) wp_json_encode(
						array(
							'explanation' => $data['explanation'],
							'evidence'    => $data['evidence'],
							'next_steps'  => $data['next_steps'],
							'kb'          => $data['kb'],
							'doc_url'     => $data['doc_url'],
							'timestamp'   => $data['timestamp'],
						)
					),
					'duration_ms' => $result->duration_ms() ?? 0,
					'created_at'  => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
		}//end foreach

		return $run_id;
	}

	/**
	 * Recent runs, newest first.
	 *
	 * @param int $limit  Max rows.
	 * @param int $offset Offset.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_runs( int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table; history must be fresh.
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $this->runs_table(), max( 1, $limit ), max( 0, $offset ) ),
			ARRAY_A
		);
		return array_map( array( $this, 'hydrate_run' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Number of stored runs.
	 */
	public function count_runs(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->runs_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * One run with its results.
	 *
	 * @param int $run_id Run ID.
	 * @return array{run:array<string,mixed>,results:Diagnostic_Result[]}|null
	 */
	public function get_run( int $run_id ): ?array {
		global $wpdb;
		$run = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->runs_table(), $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! is_array( $run ) ) {
			return null;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d ORDER BY id ASC', $this->results_table(), $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return array(
			'run'     => $this->hydrate_run( $run ),
			'results' => array_map( array( $this, 'hydrate_result' ), is_array( $rows ) ? $rows : array() ),
		);
	}

	/**
	 * Most recent run of a trigger type.
	 *
	 * @param string $trigger Trigger type.
	 * @return array{run:array<string,mixed>,results:Diagnostic_Result[]}|null
	 */
	public function latest_run( string $trigger = Diagnostic_Manager::TRIGGER_FULL ): ?array {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE trigger_type = %s ORDER BY id DESC LIMIT 1', $this->runs_table(), $trigger ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $id > 0 ? $this->get_run( $id ) : null;
	}

	/**
	 * Most recent stored result for each check (any trigger), keyed by check ID.
	 *
	 * @return array<string,Diagnostic_Result>
	 */
	public function latest_results_by_check(): array {
		global $wpdb;
		$rows    = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT r.* FROM %i r INNER JOIN ( SELECT check_id, MAX(id) AS max_id FROM %i GROUP BY check_id ) latest ON r.id = latest.max_id',
				$this->results_table(),
				$this->results_table()
			),
			ARRAY_A
		);
		$results = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$results[ $row['check_id'] ] = $this->hydrate_result( $row );
		}
		return $results;
	}

	/**
	 * Recent statuses of a check across runs, newest first (for run-to-run comparison).
	 *
	 * @param string $check_id Check ID.
	 * @param int    $limit    Max entries.
	 * @return array<int,array{run_id:int,status:string,created_at:string}>
	 */
	public function trend( string $check_id, int $limit = 5 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT run_id, status, created_at FROM %i WHERE check_id = %s ORDER BY id DESC LIMIT %d', $this->results_table(), $check_id, max( 1, $limit ) ),
			ARRAY_A
		);
		return array_map(
			static function ( $row ) {
				return array(
					'run_id'     => (int) $row['run_id'],
					'status'     => (string) $row['status'],
					'created_at' => (string) $row['created_at'],
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Deletes one run and its results.
	 *
	 * @param int $run_id Run ID.
	 */
	public function delete_run( int $run_id ): bool {
		global $wpdb;
		$wpdb->delete( $this->results_table(), array( 'run_id' => $run_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( $this->runs_table(), array( 'id' => $run_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes all history.
	 */
	public function delete_all(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->results_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->runs_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Applies retention: keeps at most $max_runs runs and nothing older than $max_days.
	 *
	 * @param int $max_runs Maximum runs kept.
	 * @param int $max_days Maximum age in days.
	 * @return int Runs deleted.
	 */
	public function prune( int $max_runs, int $max_days ): int {
		global $wpdb;

		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $max_days * DAY_IN_SECONDS );
		$by_age  = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE created_at < %s', $this->runs_table(), $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$by_size = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 18446744073709551615 OFFSET %d', $this->runs_table(), max( 1, $max_runs ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$ids = array_unique( array_map( 'intval', array_merge( (array) $by_age, (array) $by_size ) ) );
		foreach ( $ids as $id ) {
			$this->delete_run( $id );
		}
		return count( $ids );
	}

	/**
	 * Converts a runs row to typed values.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function hydrate_run( array $row ): array {
		$environment = json_decode( (string) $row['environment'], true );
		return array(
			'id'          => (int) $row['id'],
			'created_at'  => (string) $row['created_at'],
			'trigger'     => (string) $row['trigger_type'],
			'user_id'     => (int) $row['user_id'],
			'overall'     => (string) $row['overall'],
			'score'       => null === $row['score'] ? null : (int) $row['score'],
			'counts'      => array(
				Diagnostic_Result::PASS    => (int) $row['passed'],
				Diagnostic_Result::WARNING => (int) $row['warnings'],
				Diagnostic_Result::FAIL    => (int) $row['failed'],
				Diagnostic_Result::SKIPPED => (int) $row['skipped'],
			),
			'duration_ms' => (int) $row['duration_ms'],
			'environment' => is_array( $environment ) ? $environment : array(),
		);
	}

	/**
	 * Converts a results row to a Diagnostic_Result.
	 *
	 * @param array<string,mixed> $row Row.
	 */
	private function hydrate_result( array $row ): Diagnostic_Result {
		$details = json_decode( (string) $row['details'], true );
		$details = is_array( $details ) ? $details : array();
		return Diagnostic_Result::from_array(
			array_merge(
				$details,
				array(
					'check_id'    => (string) $row['check_id'],
					'check_name'  => (string) $row['check_name'],
					'category'    => (string) $row['category'],
					'status'      => (string) $row['status'],
					'summary'     => (string) $row['summary'],
					'duration_ms' => null === $row['duration_ms'] ? null : (int) $row['duration_ms'],
				)
			)
		);
	}
}
