<?php
/**
 * Bootstrap for WordPress-free unit tests of pure classes.
 *
 * @package PaystackHealthChecker
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stand-in.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain.
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escaping stand-in.
	 *
	 * @param string $text Text.
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

require_once dirname( __DIR__, 2 ) . '/includes/class-autoloader.php';
\PaystackHealthChecker\Autoloader::register();
