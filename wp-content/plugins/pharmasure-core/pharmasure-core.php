<?php
/**
 * Plugin Name: PharmaSure Core
 * Plugin URI: https://pharmasure.co.zw/
 * Description: Shared bootstrap, migrations, capabilities, tenant context, licensing and audit foundations for PharmaSure.
 * @package PharmaSure_Core
 * @version 2.0.0
 * Version: 2.2.1
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author: Infinity Lines of Code Pvt Ltd
 * Text Domain: pharmasure-core
 * Network: true
 */

namespace PharmaSure\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'PHARMASURE_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'PHARMASURE_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'PHARMASURE_CORE_VERSION', '2.2.1' );
define( 'PHARMASURE_CORE_DB_VERSION', '1' );
define( 'PHARMASURE_TABLE_PREFIX', 'ps_' );

// Autoloader
require_once PHARMASURE_CORE_PATH . 'src/Autoloader.php';
new Autoloader();

// Activation hooks run before plugins_loaded, so their callback must be loaded now.
require_once PHARMASURE_CORE_PATH . 'src/Plugin.php';

// Plugin activation/deactivation hooks
register_activation_hook( __FILE__, array( '\PharmaSure\Core\Activation', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\PharmaSure\Core\Activation', 'deactivate' ) );

// Initialize the plugin
add_action( 'plugins_loaded', [ '\PharmaSure\Core\Plugin', 'init' ], 10 );
