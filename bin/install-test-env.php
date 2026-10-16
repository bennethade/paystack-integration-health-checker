<?php
/**
 * Installs a disposable WordPress + WooCommerce (+ official Paystack gateway) environment for the integration test suite.
 *
 * Cross-platform replacement for the usual install-wp-tests.sh (no svn or bash required).
 *
 * Usage:
 *   php bin/install-test-env.php [--wp=7.1.3] [--wc=11.2.1] [--db-name=pwcg_tests]
 *                                [--db-user=root] [--db-pass=] [--db-host=127.0.0.1] [--force]
 *
 * Everything is written to ./.wp-test (git-ignored). The database named by --db-name is
 * used exclusively by the test suite and is wiped by WordPress' test installer on each run,
 * so never point it at a real site's database.
 *
 * @package PaystackHealthChecker
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$options = getopt( '', array( 'wp::', 'wc::', 'paystack::', 'db-name::', 'db-user::', 'db-pass::', 'db-host::', 'force' ) );

$config = array(
	'wp'       => $options['wp'] ?? '7.1.3',
	'wc'       => $options['wc'] ?? '11.2.1',
	'paystack' => $options['paystack'] ?? '5.8.5',
	'db_name'  => $options['db-name'] ?? 'pihc_tests',
	'db_user'  => $options['db-user'] ?? 'root',
	'db_pass'  => $options['db-pass'] ?? '',
	'db_host'  => $options['db-host'] ?? '127.0.0.1',
	'force'    => isset( $options['force'] ),
);

$root       = dirname( __DIR__ );
$base_dir   = $root . '/.wp-test';
$env_dir    = $base_dir . '/wp-' . $config['wp'] . '-wc-' . $config['wc'];
$wp_zip     = 'https://wordpress.org/wordpress-' . $config['wp'] . '.zip';
$wc_zip     = 'https://downloads.wordpress.org/plugin/woocommerce.' . $config['wc'] . '.zip';
$plugin_dir = $env_dir . '/wordpress/wp-content/plugins';

if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $config['db_name'] ) ) {
	fail( 'The database name may only contain letters, numbers and underscores.' );
}

if ( ! is_dir( $env_dir ) && ! mkdir( $env_dir, 0775, true ) ) {
	fail( "Could not create {$env_dir}" );
}

// WordPress core, with WooCommerce and this plugin inside wp-content/plugins exactly as
// on a real site (WooCommerce resolves feature declarations through get_plugins()).
install_archive( $wp_zip, $env_dir, 'WordPress', $config['force'] );
install_archive( $wc_zip, $plugin_dir, 'woocommerce', $config['force'] );
install_archive( 'https://downloads.wordpress.org/plugin/woo-paystack.' . $config['paystack'] . '.zip', $plugin_dir, 'woo-paystack', $config['force'] );
link_plugin( $root, $plugin_dir . '/paystack-integration-health-checker' );

// Optional: the sibling "Gateway for Paystack on WooCommerce" project, if checked out next to this one.
$sibling = dirname( $root ) . '/paystack-woocommerce-gateway';
if ( is_file( $sibling . '/paystack-woocommerce-gateway.php' ) ) {
	link_plugin( $sibling, $plugin_dir . '/paystack-woocommerce-gateway' );
}
create_database( $config );
write_tests_config( $base_dir, $env_dir, $config );

fwrite( STDOUT, "\nTest environment ready:\n  WordPress   {$config['wp']}\n  WooCommerce {$config['wc']}\n  Location    {$env_dir}\n  Database    {$config['db_name']}@{$config['db_host']}\n" );

/**
 * Links the repository into wp-content/plugins (directory junction on Windows, symlink elsewhere).
 *
 * @param string $target Repository root.
 * @param string $link   Link path inside wp-content/plugins.
 */
function link_plugin( string $target, string $link ): void {
	if ( is_link( $link ) || is_dir( $link ) ) {
		return;
	}
	if ( 0 === stripos( PHP_OS, 'WIN' ) ) {
		exec( 'cmd /c mklink /J ' . escapeshellarg( str_replace( '/', '\\', $link ) ) . ' ' . escapeshellarg( str_replace( '/', '\\', $target ) ), $output, $code );
		$ok = 0 === $code;
	} else {
		$ok = symlink( $target, $link );
	}
	if ( ! $ok ) {
		fail( "Could not link the plugin into {$link}" );
	}
	fwrite( STDOUT, "Linked plugin into {$link}\n" );
}

/**
 * Downloads and extracts a zip whose single top-level folder is $inner_folder.
 *
 * @param string $url          Archive URL.
 * @param string $target       Destination directory.
 * @param string $inner_folder Folder name inside the archive.
 * @param bool   $force        Re-download even when the target exists.
 */
function install_archive( string $url, string $target, string $inner_folder, bool $force ): void {
	if ( is_dir( $target . '/' . $inner_folder ) && ! $force ) {
		fwrite( STDOUT, "Using existing {$target}\n" );
		return;
	}

	fwrite( STDOUT, "Downloading {$url}\n" );
	$data = file_get_contents( $url );
	if ( false === $data || strlen( $data ) < 1024 ) {
		fail( "Download failed: {$url}" );
	}

	$zip_path = $target . '.zip';
	file_put_contents( $zip_path, $data );

	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path ) ) {
		fail( "Could not open {$zip_path}" );
	}
	if ( ! is_dir( $target ) ) {
		mkdir( $target, 0775, true );
	}
	$zip->extractTo( $target );
	$zip->close();
	unlink( $zip_path );

	if ( ! is_dir( $target . '/' . $inner_folder ) ) {
		fail( "Archive {$url} did not contain the expected {$inner_folder}/ folder." );
	}
}

/**
 * Creates the test database if it does not exist.
 *
 * @param array $config Installer configuration.
 */
function create_database( array $config ): void {
	mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
	try {
		$mysqli = new mysqli( $config['db_host'], $config['db_user'], $config['db_pass'] );
		$mysqli->query( 'CREATE DATABASE IF NOT EXISTS `' . $config['db_name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
		$mysqli->close();
	} catch ( mysqli_sql_exception $e ) {
		fail( 'Could not create the test database: ' . $e->getMessage() );
	}
	fwrite( STDOUT, "Database {$config['db_name']} is ready\n" );
}

/**
 * Writes the wp-tests-config.php consumed by wp-phpunit, both inside the environment
 * directory and as the default .wp-test/wp-tests-config.php (the most recent install).
 *
 * @param string $base_dir .wp-test directory.
 * @param string $env_dir  Environment directory.
 * @param array  $config   Installer configuration.
 */
function write_tests_config( string $base_dir, string $env_dir, array $config ): void {
	$abspath = str_replace( '\\', '/', $env_dir . '/wordpress/' );

	$contents = "<?php\n// Generated by bin/install-test-env.php. Local-only; git-ignored.\n"
		. 'define( \'ABSPATH\', ' . var_export( $abspath, true ) . " );\n"
		. "define( 'WP_DEFAULT_THEME', 'default' );\n"
		. "define( 'WP_DEBUG', true );\n"
		. 'define( \'DB_NAME\', ' . var_export( $config['db_name'], true ) . " );\n"
		. 'define( \'DB_USER\', ' . var_export( $config['db_user'], true ) . " );\n"
		. 'define( \'DB_PASSWORD\', ' . var_export( $config['db_pass'], true ) . " );\n"
		. 'define( \'DB_HOST\', ' . var_export( $config['db_host'], true ) . " );\n"
		. "define( 'DB_CHARSET', 'utf8mb4' );\n"
		. "define( 'DB_COLLATE', '' );\n"
		. "\$table_prefix = 'wptests_';\n"
		. "define( 'WP_TESTS_DOMAIN', 'example.org' );\n"
		. "define( 'WP_TESTS_EMAIL', 'admin@example.org' );\n"
		. "define( 'WP_TESTS_TITLE', 'Test Blog' );\n"
		. "define( 'WP_PHP_BINARY', 'php' );\n"
		. "define( 'WPLANG', '' );\n";

	file_put_contents( $env_dir . '/wp-tests-config.php', $contents );
	file_put_contents( $base_dir . '/wp-tests-config.php', $contents );
	fwrite( STDOUT, "Wrote {$env_dir}/wp-tests-config.php (and the default {$base_dir}/wp-tests-config.php)\n" );
}

/**
 * Prints an error and exits.
 *
 * @param string $message Error message.
 */
function fail( string $message ): void {
	fwrite( STDERR, "ERROR: {$message}\n" );
	exit( 1 );
}
