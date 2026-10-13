<?php
/**
 * Router for PHP's built-in server on Wasmer Edge.
 *
 * Serve real files directly and forward application routes such as /app and
 * WordPress permalinks through the WordPress front controller.
 */

$request_path = rawurldecode( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) );
$document_root = rtrim( (string) ( $_SERVER['DOCUMENT_ROOT'] ?? __DIR__ ), '/\\' );
$candidate = $document_root . DIRECTORY_SEPARATOR . ltrim( $request_path, '/\\' );

// Subdirectory multisite URLs share the physical login script at the root.
// Keep REQUEST_URI intact so WordPress can still identify the pharmacy site.
if ( preg_match( '#^/[^/]+/wp-login\.php$#', $request_path ) ) {
	$_SERVER['SCRIPT_NAME'] = '/wp-login.php';
	$_SERVER['PHP_SELF'] = '/wp-login.php';
	$_SERVER['SCRIPT_FILENAME'] = $document_root . '/wp-login.php';
	require $document_root . '/wp-login.php';
	return true;
}

if ( '/' !== $request_path && is_file( $candidate ) ) {
	return false;
}

require $document_root . DIRECTORY_SEPARATOR . 'index.php';

