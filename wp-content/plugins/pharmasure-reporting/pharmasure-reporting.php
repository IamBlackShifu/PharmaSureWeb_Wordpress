<?php
/**
 * Plugin Name: PharmaSure Reporting
 * Description: Tenant-safe operational, clinical, financial, security and scheduled reporting with CSV, XLSX and PDF exports.
 * Version: 1.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Requires Plugins: pharmasure-core
 * Network: true
 */
namespace PharmaSure\Reporting;

if ( ! defined( 'ABSPATH' ) ) { exit; }
const VERSION = '1.1.0';
const DB_VERSION = '1';

spl_autoload_register( static function ( $class ) {
	$prefix = __NAMESPACE__ . '\\'; if ( 0 !== strpos( $class, $prefix ) ) { return; }
	$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( is_readable( $file ) ) { require_once $file; }
} );

require_once __DIR__ . '/src/Installer.php';
register_activation_hook( __FILE__, array( Installer::class, 'install' ) );
add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'PharmaSure\Core\TenantContext' ) ) { return; }
	if ( DB_VERSION !== get_site_option( 'pharmasure_reporting_db_version' ) ) { Installer::install(); }
	add_action( 'rest_api_init', array( Rest\ReportingController::class, 'register_routes' ) );
	add_action( 'admin_menu', array( Admin\ReportingAdmin::class, 'register_page' ), 35 );
	add_action( 'admin_post_pharmasure_export_report', array( Admin\ReportingAdmin::class, 'export' ) );
	add_action( 'pharmasure_run_scheduled_reports', array( Services\ScheduleService::class, 'run_due' ) );
}, 50 );
add_action( 'init', static function () { if ( ! wp_next_scheduled( 'pharmasure_run_scheduled_reports' ) ) { wp_schedule_event( time() + 300, 'hourly', 'pharmasure_run_scheduled_reports' ); } } );
