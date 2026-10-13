<?php
/** Run with php scripts/test-login-routing.php; no WordPress database needed. */
function network_site_url( $path, $scheme = '' ) { return 'https://example.test/' . ltrim( $path, '/' ); }
function network_home_url( $path ) { return 'https://example.test/' . ltrim( $path, '/' ); }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function add_query_arg( $key, $value, $url ) {
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
}
require dirname( __DIR__ ) . '/wp-content/plugins/pharmasure-core/src/Admin/WhiteLabel.php';
use PharmaSure\Core\Admin\WhiteLabel;
$checks = 0;
function check( $condition, $message ) {
	global $checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$checks;
}
$target = 'https://example.test/app/';
$login = WhiteLabel::canonical_login_url( 'https://example.test/pharmacy/wp-login.php', $target, true );
check( 'https://example.test/wp-login.php' === strtok( $login, '?' ), 'Login must use the physical network endpoint.' );
parse_str( parse_url( $login, PHP_URL_QUERY ), $query );
check( $target === $query['redirect_to'], 'Workspace redirect must survive encoding.' );
check( '1' === $query['reauth'], 'WordPress forced reauthentication must be preserved.' );
$logout = WhiteLabel::canonical_logout_url( 'https://example.test/pharmacy/wp-login.php?action=logout&amp;_wpnonce=fixture123&amp;redirect_to=https%3A%2F%2Fexample.test%2F' );
check( 'https://example.test/wp-login.php' === strtok( $logout, '?' ), 'Logout must use the physical network endpoint.' );
parse_str( parse_url( $logout, PHP_URL_QUERY ), $query );
check( 'fixture123' === $query['_wpnonce'] && 'logout' === $query['action'], 'Logout nonce and action must be preserved.' );
$after = WhiteLabel::logout_redirect( 'https://example.test/wp-admin/' );
check( 'https://example.test/wp-login.php' === strtok( $after, '?' ), 'Logout must land on the sign-in form.' );
parse_str( parse_url( $after, PHP_URL_QUERY ), $query );
check( 'true' === $query['logged_out'] && $target === $query['redirect_to'], 'Logout must show confirmation and preserve the next workspace destination.' );
echo "Passed {$checks} login routing checks.\n";
