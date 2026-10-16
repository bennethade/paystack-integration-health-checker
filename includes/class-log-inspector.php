<?php
/**
 * Read-only inspection of WooCommerce logs for integration errors.
 *
 * Supports WooCommerce's two built-in handlers:
 * - File handler: reads the newest log files of the given sources in WC_LOG_DIR
 *   (only the tail of each file, so large logs are cheap to scan).
 * - Database handler: reads {prefix}woocommerce_log with a prepared query.
 *
 * Only counts and a few redacted excerpts leave this class.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce log reader.
 */
class Log_Inspector {

	public const HANDLER_FILE        = 'file';
	public const HANDLER_DATABASE    = 'database';
	public const HANDLER_UNSUPPORTED = 'unsupported';

	/**
	 * Bytes read from the end of each file.
	 */
	private const TAIL_BYTES = 262144;

	/**
	 * Maximum excerpts returned.
	 */
	private const MAX_EXCERPTS = 5;

	/**
	 * Redactor.
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
	 * Which WooCommerce log handler is active.
	 */
	public function handler(): string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return self::HANDLER_UNSUPPORTED;
		}
		// Same precedence as WooCommerce's logging settings: the WC_LOG_HANDLER constant, then the option.
		$handler = defined( 'WC_LOG_HANDLER' ) ? (string) constant( 'WC_LOG_HANDLER' ) : (string) get_option( 'woocommerce_logs_default_handler', '' );
		if ( false !== stripos( $handler, 'LogHandlerDB' ) || false !== stripos( $handler, 'Log_Handler_DB' ) ) {
			return self::HANDLER_DATABASE;
		}
		if ( '' === $handler || false !== stripos( $handler, 'File' ) ) {
			return defined( 'WC_LOG_DIR' ) && is_dir( WC_LOG_DIR ) ? self::HANDLER_FILE : self::HANDLER_UNSUPPORTED;
		}
		return self::HANDLER_UNSUPPORTED;
	}

	/**
	 * Scans recent entries of the given sources.
	 *
	 * @param string[] $sources Log sources (e.g. "pwcg-paystack", "fatal-errors").
	 * @param int      $days    Look-back window.
	 * @param string   $marker  If set, only lines containing this text count (used for fatal-errors).
	 * @param string   $pattern Optional regex lines must match (e.g. webhook-related).
	 * @return array{handler:string,scanned:int,errors:int,warnings:int,excerpts:string[]}
	 */
	public function scan( array $sources, int $days = 7, string $marker = '', string $pattern = '' ): array {
		$handler = $this->handler();
		$lines   = array();

		if ( self::HANDLER_FILE === $handler ) {
			$lines = $this->file_lines( $sources, $days );
		} elseif ( self::HANDLER_DATABASE === $handler ) {
			$lines = $this->database_lines( $sources, $days );
		}

		$report = array(
			'handler'  => $handler,
			'scanned'  => 0,
			'errors'   => 0,
			'warnings' => 0,
			'excerpts' => array(),
		);

		foreach ( $lines as $line ) {
			if ( '' !== $marker && false === stripos( $line, $marker ) ) {
				continue;
			}
			if ( '' !== $pattern && ! preg_match( $pattern, $line ) ) {
				continue;
			}
			++$report['scanned'];
			if ( preg_match( '/\b(EMERGENCY|ALERT|CRITICAL|ERROR)\b/', $line ) ) {
				++$report['errors'];
			} elseif ( preg_match( '/\bWARNING\b/', $line ) ) {
				++$report['warnings'];
			} else {
				continue;
			}
			// Keep the most recent excerpts: lines arrive oldest first.
			$report['excerpts'][] = $this->redactor->redact_string( substr( trim( $line ), 0, 240 ) );
			if ( count( $report['excerpts'] ) > self::MAX_EXCERPTS ) {
				array_shift( $report['excerpts'] );
			}
		}//end foreach

		return $report;
	}

	/**
	 * Lines from recent log files.
	 *
	 * @param string[] $sources Sources.
	 * @param int      $days    Look-back window.
	 * @return string[]
	 */
	private function file_lines( array $sources, int $days ): array {
		$lines  = array();
		$cutoff = time() - $days * DAY_IN_SECONDS;

		foreach ( $sources as $source ) {
			// Sources are plugin-defined constants; never build paths from anything else.
			if ( ! preg_match( '/^[a-z0-9_\-]+$/i', $source ) ) {
				continue;
			}
			$files = glob( trailingslashit( WC_LOG_DIR ) . $source . '-*.log' );
			$files = is_array( $files ) ? $files : array();
			// Oldest file first, so lines are processed chronologically.
			usort(
				$files,
				static function ( $a, $b ) {
					return (int) filemtime( $a ) <=> (int) filemtime( $b );
				}
			);
			foreach ( $files as $file ) {
				if ( filemtime( $file ) < $cutoff || ! is_readable( $file ) ) {
					continue;
				}
				$size   = (int) filesize( $file );
				$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read-only tail of a log file.
				if ( false === $handle ) {
					continue;
				}
				if ( $size > self::TAIL_BYTES ) {
					fseek( $handle, -self::TAIL_BYTES, SEEK_END );
				}
				$chunk = (string) stream_get_contents( $handle );
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				foreach ( preg_split( '/\R/', $chunk ) as $line ) {
					if ( '' !== trim( $line ) ) {
						$lines[] = $line;
					}
				}
			}
		}//end foreach
		return $lines;
	}

	/**
	 * Lines from the database log handler.
	 *
	 * @param string[] $sources Sources.
	 * @param int      $days    Look-back window.
	 * @return string[]
	 */
	private function database_lines( array $sources, int $days ): array {
		global $wpdb;
		if ( array() === $sources ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $sources ), '%s' ) );
		$args         = array_merge( array( $wpdb->prefix . 'woocommerce_log' ), $sources, array( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT level, message FROM %i WHERE source IN ({$placeholders}) AND timestamp >= %s ORDER BY log_id DESC LIMIT 2000", $args ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders holds one %s per source; values are passed in $args.
			ARRAY_A
		);
		$levels       = array(
			800 => 'EMERGENCY',
			700 => 'ALERT',
			600 => 'CRITICAL',
			500 => 'ERROR',
			400 => 'WARNING',
			300 => 'NOTICE',
			200 => 'INFO',
			100 => 'DEBUG',
		);
		$lines        = array();
		// Rows come newest first; process oldest first like file lines.
		foreach ( array_reverse( is_array( $rows ) ? $rows : array() ) as $row ) {
			$lines[] = ( $levels[ (int) $row['level'] ] ?? 'INFO' ) . ' ' . (string) $row['message'];
		}
		return $lines;
	}
}
