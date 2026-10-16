<?php
/**
 * Runs `php -l` on every runtime and test PHP file. Exits non-zero on any error.
 *
 * Usage: php bin/lint-syntax.php
 *
 * @package PaystackHealthChecker
 */

$root  = dirname( __DIR__ );
$dirs  = array( $root . '/includes', $root . '/admin', $root . '/tests', $root . '/bin' );
$files = array( $root . '/paystack-integration-health-checker.php', $root . '/uninstall.php' );

foreach ( $dirs as $dir ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
}

$failures = 0;
foreach ( $files as $file ) {
	$output = array();
	exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $output, $code );
	if ( 0 !== $code ) {
		++$failures;
		fwrite( STDERR, implode( PHP_EOL, $output ) . PHP_EOL );
	}
}

fwrite( STDOUT, sprintf( "Checked %d files, %d with syntax errors.\n", count( $files ), $failures ) );
exit( $failures > 0 ? 1 : 0 );
