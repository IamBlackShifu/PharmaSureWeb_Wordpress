<?php
/** Static architecture guardrails for first-party PharmaSure modules. */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$pass = 0;
$fail = 0;
$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) {
	if ( $condition ) { ++$pass; echo "PASS: {$message}\n"; }
	else { ++$fail; echo "FAIL: {$message}\n"; }
};

$plugin_root = WP_PLUGIN_DIR;
$files = array();
foreach ( glob( $plugin_root . '/pharmasure-*', GLOB_ONLYDIR ) ?: array() as $directory ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) { $files[] = $file->getPathname(); }
	}
}

$cpt_violations = array();
$scope_violations = array();
$route_count = 0;
$permission_count = 0;
foreach ( $files as $file ) {
	$source = (string) file_get_contents( $file );
	if ( preg_match( '/\bregister_post_type\s*\(/', $source ) ) { $cpt_violations[] = $file; }
	if ( preg_match( '/get_param\s*\(\s*[\'\"]tenant_id[\'\"]|\$_(?:GET|POST|REQUEST)\s*\[\s*[\'\"]tenant_id[\'\"]/', $source ) ) { $scope_violations[] = $file; }
	$route_count += preg_match_all( '/\bregister_rest_route\s*\(/', $source );
	$permission_count += preg_match_all( '/[\'\"]permission_callback[\'\"]\s*=>/', $source );
}

$assert( count( $files ) > 0, 'first-party PharmaSure PHP files are discovered' );
$assert( empty( $cpt_violations ), 'core pharmacy data does not register custom post types' );
$assert( empty( $scope_violations ), 'tenant scope is not read from client request parameters or superglobals' . ( $scope_violations ? ': ' . implode( ', ', $scope_violations ) : '' ) );
$assert( $route_count > 0, 'first-party REST route registrations are present' );
$assert( $permission_count >= $route_count, 'REST route registrations include permission callbacks' );

$license_source = (string) file_get_contents( $plugin_root . '/pharmasure-core/src/LicenseManager.php' );
$assert( str_contains( $license_source, 'return $this->validate_offline_token( $offline_token );' ), 'explicit offline licence tokens use fail-closed validation' );
$assert( str_contains( $license_source, 'public function enforce_entitlement' ), 'canonical entitlement enforcement is available' );

echo "Architecture guardrails: {$pass} passed, {$fail} failed.\n";
if ( $fail ) { throw new RuntimeException( 'Architecture guardrails failed.' ); }
