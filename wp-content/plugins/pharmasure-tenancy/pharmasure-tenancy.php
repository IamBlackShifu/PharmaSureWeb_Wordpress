<?php
/**
 * PharmaSure Tenancy Plugin
 *
 * @package PharmaSure\Tenancy
 * @author PharmaSure Contributors
 *
 * Plugin Name: PharmaSure Tenancy
 * Plugin URI: https://pharmasure.example.com
 * Description: Manages tenant and branch lifecycle, onboarding, context switching and isolation
 * Version: 1.0.0
 * Requires PHP: 8.2
 * Requires Plugins: pharmasure-core
 * Text Domain: pharmasure-tenancy
 * Domain Path: /languages
 * Network: true
 */

namespace PharmaSure\Tenancy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '1.0.0';
const TABLE_PREFIX = 'ps_';

// Auto-load classes
spl_autoload_register( function ( $class ) {
	if ( strpos( $class, __NAMESPACE__ ) === 0 ) {
		$path = __DIR__ . '/src/' . str_replace( [ __NAMESPACE__ . '\\', '\\' ], [ '', '/' ], $class ) . '.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
} );

// Initialize plugin
add_action( 'plugins_loaded', function () {
	// Initialize only after pharmasure-core is loaded
	if ( ! class_exists( 'PharmaSure\Core\TenantContext' ) ) {
		add_action( 'admin_notices', function () {
			echo wp_kses_post(
				'<div class="notice notice-error"><p>' .
				esc_html__( 'PharmaSure Tenancy requires PharmaSure Core plugin to be activated.', 'pharmasure-tenancy' ) .
				'</p></div>'
			);
		} );
		return;
	}

	Plugin::init();
}, 9 );

class Plugin {
	public static function init() {
		self::register_capabilities();
		self::register_hooks();
		self::register_rest_routes();
	}

	private static function register_capabilities() {
		$cap_group = 'pharmasure_manage_tenant';
		$wp_roles  = wp_roles();

		foreach ( [ 'administrator', 'editor' ] as $role_name ) {
			if ( $wp_roles->is_role( $role_name ) ) {
				$wp_roles->add_cap( $role_name, $cap_group );
			}
		}
	}

	private static function register_hooks() {
		// REST route registration
		add_action( 'rest_api_init', [ Rest\TenantController::class, 'register_routes' ] );
		add_action( 'rest_api_init', [ Rest\BranchController::class, 'register_routes' ] );

		// Admin pages
		add_action( 'admin_menu', [ Admin\TenantAdmin::class, 'register_pages' ] );
		add_action( 'network_admin_menu', [ Admin\TenantAdmin::class, 'register_network_pages' ] );

		// Enqueue admin assets
		add_action( 'admin_enqueue_scripts', [ Admin\TenantAdmin::class, 'enqueue_assets' ] );
	}

	private static function register_rest_routes() {
		// Routes registered in controller classes via rest_api_init hook
	}
}

// Plugin activation hook
register_activation_hook( __FILE__, [ Activation::class, 'activate' ] );

class Activation {
	public static function activate() {
		// Create tables if needed (handled by pharmasure-core migrations)
		// Initialize default settings
		update_option( 'pharmasure_tenancy_activated', current_time( 'mysql' ) );
	}
}
