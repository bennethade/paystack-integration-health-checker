<?php
/**
 * Registry of available checks. The dashboard and runner read from it, so new checks
 * appear in the UI, the full run, history and exports without other code changes.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Ordered collection of check definitions.
 */
final class Diagnostic_Registry {

	/**
	 * Checks keyed by ID, in registration order.
	 *
	 * @var array<string,Check_Definition>
	 */
	private $checks = array();

	/**
	 * Adds a check.
	 *
	 * @param Check_Definition $check Check.
	 * @throws \InvalidArgumentException When the ID is already registered.
	 */
	public function add( Check_Definition $check ): void {
		if ( isset( $this->checks[ $check->id() ] ) ) {
			throw new \InvalidArgumentException( 'Duplicate check ID: ' . esc_html( $check->id() ) );
		}
		$this->checks[ $check->id() ] = $check;
	}

	/**
	 * A check by ID.
	 *
	 * @param string $id Check ID.
	 */
	public function get( string $id ): ?Check_Definition {
		return $this->checks[ $id ] ?? null;
	}

	/**
	 * Whether a check exists.
	 *
	 * @param string $id Check ID.
	 */
	public function has( string $id ): bool {
		return isset( $this->checks[ $id ] );
	}

	/**
	 * All checks.
	 *
	 * @return Check_Definition[]
	 */
	public function all(): array {
		return array_values( $this->checks );
	}

	/**
	 * Checks in a category.
	 *
	 * @param string $category Category constant.
	 * @return Check_Definition[]
	 */
	public function in_category( string $category ): array {
		return array_values(
			array_filter(
				$this->checks,
				static function ( Check_Definition $check ) use ( $category ) {
					return $check->category() === $category;
				}
			)
		);
	}

	/**
	 * Checks included in the full run.
	 *
	 * @return Check_Definition[]
	 */
	public function full_run(): array {
		return array_values(
			array_filter(
				$this->checks,
				static function ( Check_Definition $check ) {
					return $check->in_full_run();
				}
			)
		);
	}
}
