<?php
/**
 * Autoloader mapping namespaced classes to WordPress-style file names.
 *
 *   PaystackHealthChecker\Diagnostic_Result           -> includes/class-diagnostic-result.php
 *   PaystackHealthChecker\Checks\Webhook_Checks        -> includes/checks/class-webhook-checks.php
 *   PaystackHealthChecker\Integrations\Integration_Adapter (interface)
 *                                                      -> includes/integrations/interface-integration-adapter.php
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Loads plugin classes on demand.
 */
final class Autoloader {

	/**
	 * Registers the autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads a class, interface or abstract class.
	 *
	 * @param string $class_name Fully-qualified name.
	 */
	public static function load( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$name  = strtolower( str_replace( '_', '-', array_pop( $parts ) ) );
		$dir   = dirname( __DIR__ ) . '/includes/' . ( $parts ? strtolower( implode( '/', $parts ) ) . '/' : '' );

		foreach ( array( 'class-', 'interface-', 'abstract-' ) as $kind ) {
			if ( is_readable( $dir . $kind . $name . '.php' ) ) {
				require_once $dir . $kind . $name . '.php';
				return;
			}
		}
	}
}
