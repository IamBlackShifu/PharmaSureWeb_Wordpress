<?php
/**
 * Plugin Name: PharmaSure Print
 * Description: Tenant-safe print jobs and browser print views for pharmacy documents.
 * Version: 1.0.0
 * Requires PHP: 8.2
 * Requires Plugins: pharmasure-core
 * Network: true
 */

namespace PharmaSure\PrintModule;

if ( ! defined( 'ABSPATH' ) ) { exit; }

const VERSION = '1.0.0';
const DB_VERSION = '1';

spl_autoload_register( static function ( $class ) {
	$prefix = __NAMESPACE__ . '\\';
	if ( 0 !== strpos( $class, $prefix ) ) { return; }
	$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( is_readable( $file ) ) { require_once $file; }
} );

register_activation_hook( __FILE__, array( Installer::class, 'install' ) );

add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'PharmaSure\\Core\\TenantContext' ) ) { return; }
	if ( DB_VERSION !== get_site_option( 'pharmasure_print_db_version' ) ) { Installer::install(); }
	add_action( 'rest_api_init', array( Rest\PrintController::class, 'register_routes' ) );
	add_action( 'admin_post_pharmasure_print_document', array( BrowserPrintController::class, 'render' ) );
	add_action( 'admin_menu', array( Admin\PrintAdmin::class, 'register_page' ), 30 );
}, 25 );

final class BrowserPrintController {
	public static function render() {
		if ( ! current_user_can( 'pharmasure_print_documents' ) ) { wp_die( 'Printing permission is required.', 403 ); }
		check_admin_referer( 'pharmasure_print_document' );
		$job_id = absint( $_GET['job_id'] ?? 0 );
		$context = \PharmaSure\Core\TenantContext::instance();
		$service = new Services\PrintService();
		$job = $service->get_job( (int) $context->get_tenant_id(), $job_id );
		if ( ! $job || ! $context->can_access_branch( (int) $job['branch_id'] ) ) { wp_die( 'Print job not found.', 404 ); }
		$result = $service->render_job( (int) $context->get_tenant_id(), $job_id );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 400 ); }
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped document HTML.
		exit;
	}
}
