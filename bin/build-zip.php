<?php
/**
 * Builds an installable plugin ZIP containing runtime files only.
 *
 * Usage: php bin/build-zip.php
 * Output: dist/paystack-woocommerce-gateway-<version>.zip with a single top-level
 *         paystack-woocommerce-gateway/ folder, as WordPress expects.
 *
 * @package PaystackHealthChecker
 */

$root = dirname( __DIR__ );
$slug = 'paystack-integration-health-checker';

if ( ! preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents( $root . '/' . $slug . '.php' ), $m ) ) {
	fwrite( STDERR, "Could not read the plugin version.\n" );
	exit( 1 );
}
$version = $m[1];

// Runtime allow-list: anything not listed here never ships.
$include = array(
	$slug . '.php',
	'uninstall.php',
	'readme.txt',
	'README.md',
	'LICENSE',
	'includes',
	'admin',
	'languages',
);

$dist = $root . '/dist';
if ( ! is_dir( $dist ) ) {
	mkdir( $dist, 0775, true );
}
$zip_path = $dist . '/' . $slug . '-' . $version . '.zip';
if ( file_exists( $zip_path ) ) {
	unlink( $zip_path );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Could not create {$zip_path}\n" );
	exit( 1 );
}

$count = 0;
foreach ( $include as $entry ) {
	$entry_path = $root . '/' . $entry;
	if ( is_file( $entry_path ) ) {
		$zip->addFile( $entry_path, $slug . '/' . $entry );
		++$count;
		continue;
	}
	if ( ! is_dir( $entry_path ) ) {
		fwrite( STDERR, "Missing required entry: {$entry}\n" );
		exit( 1 );
	}
	$zip->addEmptyDir( $slug . '/' . $entry );
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $entry_path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		$relative = $entry . '/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $entry_path ) + 1 ) );
		$zip->addFile( $file->getPathname(), $slug . '/' . $relative );
		++$count;
	}
}
$zip->close();

// Verify the archive: single top-level folder, main file present, no dev files.
$check = new ZipArchive();
$check->open( $zip_path );
$problems   = array();
$file_count = $check->count();
for ( $i = 0; $i < $file_count; $i++ ) {
	$name = (string) $check->getNameIndex( $i );
	if ( 0 !== strpos( $name, $slug . '/' ) ) {
		$problems[] = "outside top-level folder: {$name}";
	}
	if ( preg_match( '#/(tests|vendor|bin|\.wp-test|node_modules|\.git)/#', '/' . $name ) ) {
		$problems[] = "development file shipped: {$name}";
	}
}
if ( false === $check->locateName( $slug . '/' . $slug . '.php' ) ) {
	$problems[] = 'main plugin file missing';
}
$check->close();

if ( $problems ) {
	fwrite( STDERR, "ZIP verification failed:\n  " . implode( "\n  ", $problems ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, sprintf( "Built %s (%d files, %s KB)\n", $zip_path, $count, number_format( filesize( $zip_path ) / 1024, 1 ) ) );
