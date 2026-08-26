<?php
/**
 * Plugin Name: PharmaSure Print
 * Description: Tenant-safe print jobs and browser print views for pharmacy documents.
 * Version: 1.1.0
 * Requires PHP: 8.2
 * Requires Plugins: pharmasure-core
 * Network: true
 */

namespace PharmaSure\PrintModule;

if ( ! defined( 'ABSPATH' ) ) { exit; }

const VERSION = '1.1.0';
const DB_VERSION = '1';
const ROUTE_VERSION = '1';

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
	add_action( 'init', array( BrowserPrintController::class, 'register_route' ), 99 );
	add_filter( 'query_vars', array( BrowserPrintController::class, 'query_vars' ) );
	add_action( 'template_redirect', array( BrowserPrintController::class, 'render_headless' ), -100 );
	add_action( 'rest_api_init', array( Rest\PrintController::class, 'register_routes' ) );
	add_action( 'admin_post_pharmasure_print_document', array( BrowserPrintController::class, 'render' ) );
	add_action( 'admin_menu', array( Admin\PrintAdmin::class, 'register_page' ), 30 );
}, 25 );

final class BrowserPrintController {
	public static function register_route() {
		add_rewrite_rule( '^app/print/([0-9]+)/?$', 'index.php?pharmasure_print_job=$matches[1]', 'top' );
		if ( ROUTE_VERSION !== get_site_option( 'pharmasure_print_route_version' ) ) {
			flush_rewrite_rules( false );
			update_site_option( 'pharmasure_print_route_version', ROUTE_VERSION );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = 'pharmasure_print_job';
		return $vars;
	}

	public static function print_url( $job_id ) {
		$job_id = absint( $job_id );
		return add_query_arg( '_wpnonce', wp_create_nonce( 'pharmasure_print_document_' . $job_id ), home_url( '/app/print/' . $job_id . '/' ) );
	}

	public static function render_headless() {
		$job_id = absint( get_query_var( 'pharmasure_print_job' ) );
		if ( ! $job_id ) { return; }
		if ( ! is_user_logged_in() ) { auth_redirect(); }
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'pharmasure_print_document_' . $job_id ) ) { wp_die( 'The print link has expired. Request the document again.', 403 ); }
		self::render_job( $job_id );
	}

	public static function render() {
		if ( ! current_user_can( 'pharmasure_print_documents' ) ) { wp_die( 'Printing permission is required.', 403 ); }
		check_admin_referer( 'pharmasure_print_document' );
		$job_id = absint( $_GET['job_id'] ?? 0 );
		self::render_job( $job_id );
	}

	private static function render_job( $job_id ) {
		if ( ! current_user_can( 'pharmasure_print_documents' ) ) { wp_die( 'Printing permission is required.', 403 ); }
		$context = \PharmaSure\Core\TenantContext::instance();
		$service = new Services\PrintService();
		$job = $service->get_job( (int) $context->get_tenant_id(), $job_id );
		if ( ! $job || ! $context->can_access_branch( (int) $job['branch_id'] ) ) { wp_die( 'Print job not found.', 404 ); }
		$result = $service->render_job( (int) $context->get_tenant_id(), $job_id );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), 400 ); }
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped document HTML.
		exit;
	}
}
