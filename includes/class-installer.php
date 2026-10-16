<?php
/**
 * Schema, activation, upgrade and deactivation.
 *
 * Tables:
 *   {prefix}pihc_runs     one row per diagnostic run (trigger, overall status, counts,
 *                         non-sensitive environment snapshot as JSON)
 *   {prefix}pihc_results  one row per check result (status, summary, redacted details JSON)
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
final class Installer {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'pihc_db_version';
	public const PRUNE_HOOK        = 'pihc_prune_history';

	/**
	 * Activation.
	 */
	public static function activate(): void {
		self::create_tables();
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Deactivation: stop the pruning job; keep data.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
	}

	/**
	 * Creates or upgrades tables when the schema version changed.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_tables();
		}
	}

	/**
	 * Creates or updates the tables with dbDelta.
	 */
	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$runs    = $wpdb->prefix . Diagnostic_History::RUNS_TABLE;
		$results = $wpdb->prefix . Diagnostic_History::RESULTS_TABLE;

		dbDelta(
			"CREATE TABLE {$runs} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
created_at datetime NOT NULL,
trigger_type varchar(20) NOT NULL,
user_id bigint(20) unsigned NOT NULL DEFAULT 0,
overall varchar(20) NOT NULL,
score tinyint(3) unsigned DEFAULT NULL,
passed smallint(5) unsigned NOT NULL DEFAULT 0,
warnings smallint(5) unsigned NOT NULL DEFAULT 0,
failed smallint(5) unsigned NOT NULL DEFAULT 0,
skipped smallint(5) unsigned NOT NULL DEFAULT 0,
duration_ms int(10) unsigned NOT NULL DEFAULT 0,
environment longtext NOT NULL,
PRIMARY KEY  (id),
KEY created_at (created_at),
KEY trigger_type (trigger_type)
) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$results} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
run_id bigint(20) unsigned NOT NULL,
check_id varchar(100) NOT NULL,
check_name varchar(190) NOT NULL,
category varchar(40) NOT NULL,
status varchar(10) NOT NULL,
summary text NOT NULL,
details longtext NOT NULL,
duration_ms int(10) unsigned DEFAULT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY run_id (run_id),
KEY check_run (check_id, run_id)
) {$collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}
}
