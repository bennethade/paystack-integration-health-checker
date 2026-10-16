<?php
/**
 * Server environment checks (PHP version, extensions, HTTPS transport).
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker\Checks;

use PaystackHealthChecker\Check_Definition;
use PaystackHealthChecker\Diagnostic_Context;
use PaystackHealthChecker\Diagnostic_Registry;
use PaystackHealthChecker\Diagnostic_Result;

defined( 'ABSPATH' ) || exit;

/**
 * Environment checks.
 */
final class Environment_Checks {

	public const MIN_PHP = '7.4';

	/**
	 * Registers the checks.
	 *
	 * @param Diagnostic_Registry $registry Registry.
	 */
	public static function register( Diagnostic_Registry $registry ): void {
		$registry->add(
			new Check_Definition(
				array(
					'id'          => 'env.php_version',
					'name'        => __( 'PHP version', 'paystack-integration-health-checker' ),
					'category'    => Check_Definition::CATEGORY_ENVIRONMENT,
					'description' => __( 'PHP meets the minimum version of this plugin and of the Paystack gateway being diagnosed.', 'paystack-integration-health-checker' ),
					'callback'    => array( __CLASS__, 'php_version' ),
				)
			)
		);
		$registry->add(
			new Check_Definition(
				array(
					'id'          => 'env.php_extensions',
					'name'        => __( 'PHP extensions', 'paystack-integration-health-checker' ),
					'category'    => Check_Definition::CATEGORY_ENVIRONMENT,
					'description' => __( 'Extensions needed for HTTPS API calls and webhook signature checks (openssl, json, hash with SHA-512, curl).', 'paystack-integration-health-checker' ),
					'callback'    => array( __CLASS__, 'php_extensions' ),
				)
			)
		);
		$registry->add(
			new Check_Definition(
				array(
					'id'          => 'env.https_transport',
					'name'        => __( 'HTTPS request support', 'paystack-integration-health-checker' ),
					'category'    => Check_Definition::CATEGORY_ENVIRONMENT,
					'description' => __( 'The WordPress HTTP API has a transport that supports SSL, which every Paystack API call requires.', 'paystack-integration-health-checker' ),
					'callback'    => array( __CLASS__, 'https_transport' ),
				)
			)
		);
	}

	/**
	 * PHP version check.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function php_version( Diagnostic_Context $context ): Diagnostic_Result {
		$required = self::MIN_PHP;
		$adapter  = $context->adapter();
		if ( null !== $adapter && method_exists( $adapter, 'plugin_headers' ) ) {
			$headers = $adapter->plugin_headers();
			if ( ! empty( $headers['requires_php'] ) && version_compare( $headers['requires_php'], $required, '>' ) ) {
				$required = $headers['requires_php'];
			}
		}

		$evidence = array(
			'php_version' => PHP_VERSION,
			'required'    => $required,
		);

		if ( version_compare( PHP_VERSION, $required, '<' ) ) {
			return Diagnostic_Result::fail(
				/* translators: 1: running PHP version, 2: required version */
				sprintf( __( 'PHP %1$s is older than the required %2$s.', 'paystack-integration-health-checker' ), PHP_VERSION, $required ),
				array(
					'evidence'   => $evidence,
					'next_steps' => array( __( 'Ask your host to upgrade PHP.', 'paystack-integration-health-checker' ) ),
					'kb'         => 'php_environment',
				)
			);
		}

		return Diagnostic_Result::pass(
			/* translators: %s: PHP version */
			sprintf( __( 'PHP %s meets the requirements.', 'paystack-integration-health-checker' ), PHP_VERSION ),
			array( 'evidence' => $evidence )
		);
	}

	/**
	 * PHP extension check.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function php_extensions( Diagnostic_Context $context ): Diagnostic_Result {
		unset( $context );

		$missing_required = array();
		foreach ( array( 'openssl', 'json', 'hash' ) as $extension ) {
			if ( ! extension_loaded( $extension ) ) {
				$missing_required[] = $extension;
			}
		}
		$sha512 = function_exists( 'hash_hmac_algos' ) && in_array( 'sha512', hash_hmac_algos(), true );
		if ( ! $sha512 ) {
			$missing_required[] = 'hash_hmac sha512';
		}
		$curl = extension_loaded( 'curl' );

		$evidence = array(
			'openssl'     => extension_loaded( 'openssl' ) ? ( defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : 'loaded' ) : 'missing',
			'json'        => extension_loaded( 'json' ) ? 'loaded' : 'missing',
			'hmac_sha512' => $sha512 ? 'available' : 'missing',
			'curl'        => $curl ? 'loaded' : 'missing',
		);

		if ( $missing_required ) {
			return Diagnostic_Result::fail(
				/* translators: %s: list of extensions */
				sprintf( __( 'Required PHP features are missing: %s.', 'paystack-integration-health-checker' ), implode( ', ', $missing_required ) ),
				array(
					'explanation' => __( 'HTTPS API calls need openssl; webhook signatures need HMAC-SHA512 from the hash extension.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array( __( 'Ask your host to enable the missing PHP extensions.', 'paystack-integration-health-checker' ) ),
					'kb'          => 'php_environment',
				)
			);
		}

		if ( ! $curl ) {
			return Diagnostic_Result::warning(
				__( 'The curl extension is missing; WordPress will fall back to PHP streams for HTTPS.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'Requests can still work, but timeouts and TLS errors are reported less precisely, and some hosts configure streams without proper CA certificates.', 'paystack-integration-health-checker' ),
					'evidence'    => $evidence,
					'next_steps'  => array( __( 'Ask your host to enable the PHP curl extension.', 'paystack-integration-health-checker' ) ),
					'kb'          => 'php_environment',
				)
			);
		}

		return Diagnostic_Result::pass( __( 'All required PHP extensions are available.', 'paystack-integration-health-checker' ), array( 'evidence' => $evidence ) );
	}

	/**
	 * HTTPS transport check.
	 *
	 * @param Diagnostic_Context $context Context.
	 */
	public static function https_transport( Diagnostic_Context $context ): Diagnostic_Result {
		unset( $context );
		if ( ! wp_http_supports( array( 'ssl' ) ) ) {
			return Diagnostic_Result::fail(
				__( 'WordPress has no HTTP transport that supports HTTPS.', 'paystack-integration-health-checker' ),
				array(
					'explanation' => __( 'Paystack\'s API is only available over HTTPS, so no API call can succeed.', 'paystack-integration-health-checker' ),
					'next_steps'  => array( __( 'Enable the curl and openssl PHP extensions.', 'paystack-integration-health-checker' ) ),
					'kb'          => 'tls_failure',
				)
			);
		}
		return Diagnostic_Result::pass( __( 'WordPress can make HTTPS requests.', 'paystack-integration-health-checker' ) );
	}
}
