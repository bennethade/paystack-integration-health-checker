<?php
/**
 * Runs checks and records the results.
 *
 * For every check the manager:
 *  1. evaluates prerequisites - unmet means SKIPPED with the reason;
 *  2. runs the callback, timing it;
 *  3. converts exceptions or invalid return values into SKIPPED ("could not be
 *     completed") - an error inside a check is never reported as PASS;
 *  4. stamps identity/timing metadata and redacts the evidence;
 *  5. stores the run in history.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostic runner.
 */
class Diagnostic_Manager {

	public const TRIGGER_FULL      = 'full';
	public const TRIGGER_SINGLE    = 'single';
	public const TRIGGER_REFERENCE = 'reference';

	/**
	 * Registry.
	 *
	 * @var Diagnostic_Registry
	 */
	private $registry;

	/**
	 * Factory for a fresh context per run: fn(): Diagnostic_Context.
	 *
	 * @var callable
	 */
	private $context_factory;

	/**
	 * History store.
	 *
	 * @var Diagnostic_History
	 */
	private $history;

	/**
	 * Redactor.
	 *
	 * @var Redactor
	 */
	private $redactor;

	/**
	 * Constructor.
	 *
	 * @param Diagnostic_Registry $registry        Registry.
	 * @param callable            $context_factory Context factory.
	 * @param Diagnostic_History  $history         History.
	 * @param Redactor            $redactor        Redactor.
	 */
	public function __construct( Diagnostic_Registry $registry, callable $context_factory, Diagnostic_History $history, Redactor $redactor ) {
		$this->registry        = $registry;
		$this->context_factory = $context_factory;
		$this->history         = $history;
		$this->redactor        = $redactor;
	}

	/**
	 * Runs every check in the full suite and stores the run.
	 *
	 * @return array{run_id:int,results:Diagnostic_Result[],summary:Health_Summary}
	 */
	public function run_all(): array {
		$context = call_user_func( $this->context_factory );
		$results = array();
		foreach ( $this->registry->full_run() as $check ) {
			$results[] = $this->run_check( $check, $context );
		}
		return $this->store( self::TRIGGER_FULL, $results, $context );
	}

	/**
	 * Runs one check (any check, including manual-only ones) and stores it as a run.
	 *
	 * @param string $check_id Check ID.
	 * @return array{run_id:int,results:Diagnostic_Result[],summary:Health_Summary}|null Null for an unknown ID.
	 */
	public function run_single( string $check_id ): ?array {
		$check = $this->registry->get( $check_id );
		if ( null === $check ) {
			return null;
		}
		$context = call_user_func( $this->context_factory );
		return $this->store( self::TRIGGER_SINGLE, array( $this->run_check( $check, $context ) ), $context );
	}

	/**
	 * Stores an externally produced result (e.g. reference verification) as a run.
	 *
	 * @param Diagnostic_Result $result Result.
	 * @return array{run_id:int,results:Diagnostic_Result[],summary:Health_Summary}
	 */
	public function record( Diagnostic_Result $result ): array {
		$result = $result->with_sanitized_evidence( array( $this->redactor, 'redact_array' ) );
		return $this->store( self::TRIGGER_REFERENCE, array( $result ), call_user_func( $this->context_factory ) );
	}

	/**
	 * Runs one check with prerequisite handling, timing and error containment.
	 *
	 * @param Check_Definition   $check   Check.
	 * @param Diagnostic_Context $context Context.
	 */
	public function run_check( Check_Definition $check, Diagnostic_Context $context ): Diagnostic_Result {
		$started = microtime( true );
		$unmet   = $context->unmet_prerequisite( $check->requires() );

		if ( null !== $unmet ) {
			$result = Diagnostic_Result::skipped(
				$unmet,
				array( 'explanation' => __( 'A prerequisite for this check is missing, so it was not run. Skipped checks do not affect the health score.', 'paystack-integration-health-checker' ) )
			);
		} else {
			try {
				$result = $check->run( $context );
				if ( ! $result instanceof Diagnostic_Result ) {
					$result = Diagnostic_Result::skipped( __( 'The check did not return a valid result.', 'paystack-integration-health-checker' ) );
				}
			} catch ( \Throwable $e ) {
				$result = Diagnostic_Result::skipped(
					__( 'The check could not be completed because of an internal error.', 'paystack-integration-health-checker' ),
					array(
						'explanation' => __( 'The error was contained so other checks could run. It is not counted as a pass or a failure.', 'paystack-integration-health-checker' ),
						'evidence'    => array(
							'error' => get_class( $e ) . ': ' . $this->redactor->redact_string( substr( $e->getMessage(), 0, 300 ) ),
						),
					)
				);
			}
		}//end if

		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		return $result
			->with_meta( $check->id(), $check->name(), $check->category(), time(), $duration, $check->doc_url() )
			->with_sanitized_evidence( array( $this->redactor, 'redact_array' ) );
	}

	/**
	 * Persists a run and returns it.
	 *
	 * @param string              $trigger Trigger constant.
	 * @param Diagnostic_Result[] $results Results.
	 * @param Diagnostic_Context  $context Context.
	 * @return array{run_id:int,results:Diagnostic_Result[],summary:Health_Summary}
	 */
	private function store( string $trigger, array $results, Diagnostic_Context $context ): array {
		$summary = Health_Summary::from_results( $results );
		$run_id  = $this->history->save_run( $trigger, $results, $summary, Environment::snapshot( $context ), get_current_user_id() );
		$this->history->prune( $context->settings()->retention_runs(), $context->settings()->retention_days() );

		return array(
			'run_id'  => $run_id,
			'results' => $results,
			'summary' => $summary,
		);
	}
}
