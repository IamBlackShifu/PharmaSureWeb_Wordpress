<?php
/**
 * Plugin Name: PharmaSure Inventory
 * Plugin URI: https://pharmasure.co.zw/
 * Description: Tenant-safe drug catalogue, suppliers, batches, stock balances, receipts and immutable stock movements.
 * Version: 1.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Requires Plugins: pharmasure-core
 * Author: Infinity Lines of Code Pvt Ltd
 * Text Domain: pharmasure-inventory
 * Network: true
 *
 * @package PharmaSure\Inventory
 */

namespace PharmaSure\Inventory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '1.1.0';
const DB_VERSION = '1';

spl_autoload_register(
	static function ( $class ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once __DIR__ . '/src/Installer.php';

register_activation_hook( __FILE__, array( Installer::class, 'install' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'PharmaSure\Core\TenantContext' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'PharmaSure Inventory requires PharmaSure Core.', 'pharmasure-inventory' ) . '</p></div>';
				}
			);
			return;
		}

		if ( DB_VERSION !== get_site_option( 'pharmasure_inventory_db_version' ) ) {
			Installer::install();
		}

		add_action( 'rest_api_init', array( Rest\InventoryController::class, 'register_routes' ) );
		add_action( 'admin_menu', array( Admin\InventoryAdmin::class, 'register_pages' ) );
		add_action( 'admin_enqueue_scripts', array( Admin\InventoryAdmin::class, 'enqueue_assets' ) );
	},
	20
);
