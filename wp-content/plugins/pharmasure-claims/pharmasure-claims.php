<?php
/**
 * Plugin Name: PharmaSure Claims
 * Description: Tenant-scoped insurers, patient cover, claim lifecycle and remittance reconciliation.
 * Version: 1.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Requires Plugins: pharmasure-core, pharmasure-clinical, pharmasure-pos
 * Network: true
 */
namespace PharmaSure\Claims;

if ( ! defined( 'ABSPATH' ) ) { exit; }
const VERSION = '1.1.0';
const DB_VERSION = '1';

spl_autoload_register( static function ( $class ) {
	$prefix = __NAMESPACE__ . '\\';
	if ( 0 !== strpos( $class, $prefix ) ) { return; }
	$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( is_readable( $file ) ) { require_once $file; }
} );

require_once __DIR__ . '/src/Installer.php';
register_activation_hook( __FILE__, array( Installer::class, 'install' ) );

add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'PharmaSure\Core\TenantContext' ) ) { return; }
	if ( DB_VERSION !== get_site_option( 'pharmasure_claims_db_version' ) ) { Installer::install(); }
	add_action( 'rest_api_init', array( Rest\ClaimsController::class, 'register_routes' ) );
}, 40 );
