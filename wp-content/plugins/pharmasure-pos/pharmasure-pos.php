<?php
/**
 * Plugin Name: PharmaSure POS
 * Description: Branch-scoped tills, itemized sales, split tenders and atomic FEFO checkout.
 * Version: 1.6.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Requires Plugins: pharmasure-core, pharmasure-inventory
 * Network: true
 */
namespace PharmaSure\POS;

if ( ! defined( 'ABSPATH' ) ) { exit; }
const VERSION = '1.6.0';
const DB_VERSION = '7';

spl_autoload_register( static function ( $class ) {
	$prefix = __NAMESPACE__ . '\\';
	if ( 0 !== strpos( $class, $prefix ) ) { return; }
	$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( is_readable( $file ) ) { require_once $file; }
} );

require_once __DIR__ . '/src/Installer.php';
register_activation_hook( __FILE__, array( Installer::class, 'install' ) );

add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'PharmaSure\Core\TenantContext' ) || ! class_exists( 'PharmaSure\Inventory\Services\StockAllocator' ) ) { return; }
	if ( DB_VERSION !== get_site_option( 'pharmasure_pos_db_version' ) ) { Installer::install(); }
	add_action( 'rest_api_init', array( Rest\PosController::class, 'register_routes' ) );
	add_action( 'admin_menu', array( Admin\PosAdmin::class, 'register_page' ), 30 );
	add_action( 'admin_enqueue_scripts', array( Admin\PosAdmin::class, 'enqueue_assets' ) );
}, 30 );
