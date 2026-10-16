<?php
/**
 * Authorization, nonces, throttling and outbound-request guards.
 *
 * Threat model highlights:
 * - Only users with the checker capability (manage_woocommerce when WooCommerce is
 *   active, otherwise manage_options; filterable) can run diagnostics, view results,
 *   export reports or delete history. Every state-changing request needs a nonce.
 * - No public REST routes: everything goes through admin-ajax / admin-post with the
 *   checks above.
 * - Outbound requests go only to the fixed Paystack API base URL or to this site's own
 *   URLs (loopback webhook checks). The checker never requests a URL supplied by a user,
 *   so it cannot be used for server-side request forgery.
 * - Expensive or network-bound actions are throttled per user.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Security helpers.
 */
class Security {

	public const NONCE_RUN       = 'pihc_run_diagnostics';
	public const NONCE_RUN_CHECK = 'pihc_run_check';
	public const NONCE_REFERENCE = 'pihc_verify_reference';
	public const NONCE_EXPORT    = 'pihc_export_report';
	public const NONCE_DELETE    = 'pihc_delete_history';

	/**
	 * Throttle windows: action => [ max requests, window seconds ].
	 */
	public const THROTTLES = array(
		'full_run'  => array( 3, 60 ),
		'run_check' => array( 15, 60 ),
		'reference' => array( 10, 60 ),
	);

	/**
	 * Capability required to use the checker.
	 */
	public static function capability(): string {
		$default = class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
		/**
		 * Filters the capability required to use the health checker.
		 *
		 * @since 1.0.0
		 * @param string $capability Capability.
		 */
		return (string) apply_filters( 'pihc_capability', $default );
	}

	/**
	 * Whether the current user may use the checker.
	 */
	public static function current_user_can_use(): bool {
		return current_user_can( self::capability() );
	}

	/**
	 * Records an attempt and reports whether the user is over the limit.
	 *
	 * Uses a per-user transient holding recent timestamps. Not atomic under heavy
	 * concurrency, which is acceptable: it is a guard against accidental repetition and
	 * API quota exhaustion, not an authorization control.
	 *
	 * @param string   $action  Key of self::THROTTLES.
	 * @param int      $user_id User ID.
	 * @param int|null $now     Current time (injectable for tests).
	 * @return int Seconds to wait; 0 when the action is allowed (and recorded).
	 */
	public static function throttle( string $action, int $user_id, ?int $now = null ): int {
		list( $limit, $window ) = self::THROTTLES[ $action ] ?? array( 10, 60 );

		$now    = $now ?? time();
		$key    = 'pihc_rl_' . md5( $action . '|' . $user_id );
		$stamps = get_transient( $key );
		$stamps = array_values(
			array_filter(
				is_array( $stamps ) ? $stamps : array(),
				static function ( $stamp ) use ( $now, $window ) {
					return is_int( $stamp ) && $stamp > $now - $window;
				}
			)
		);

		if ( count( $stamps ) >= $limit ) {
			return max( 1, (int) min( $stamps ) + $window - $now );
		}

		$stamps[] = $now;
		set_transient( $key, $stamps, $window );
		return 0;
	}

	/**
	 * Whether a URL points at this WordPress site (same scheme family, host and port,
	 * no credentials). Used to restrict loopback requests to URLs the site generated.
	 *
	 * @param string $url Candidate URL.
	 */
	public static function is_own_site_url( string $url ): bool {
		$target = wp_parse_url( $url );
		$home   = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $target ) || ! is_array( $home ) ) {
			return false;
		}
		if ( isset( $target['user'] ) || isset( $target['pass'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( (string) ( $target['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( strtolower( (string) ( $target['host'] ?? '' ) ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
			return false;
		}
		return (int) ( $target['port'] ?? 0 ) === (int) ( $home['port'] ?? 0 );
	}

	/**
	 * Whether a host resolves only to private, loopback, link-local or reserved addresses
	 * (so Paystack could not reach it). Returns null when the host cannot be resolved.
	 *
	 * @param string $host Hostname or IP.
	 */
	public static function host_is_private( string $host ): ?bool {
		$host = trim( strtolower( $host ), '[]' );
		if ( 'localhost' === $host || '.localhost' === substr( $host, -10 ) || '.local' === substr( $host, -6 ) || '.test' === substr( $host, -5 ) ) {
			return true;
		}
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : gethostbynamel( $host );
		if ( ! is_array( $ips ) || array() === $ips ) {
			return null;
		}
		foreach ( $ips as $ip ) {
			if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}
		return true;
	}
}
