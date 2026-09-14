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

if ( '/' !== $request_path && is_file( $candidate ) ) {
	return false;
}

require $document_root . DIRECTORY_SEPARATOR . 'index.php';

