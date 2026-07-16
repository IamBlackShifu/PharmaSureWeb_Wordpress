<?php
/**
 * PharmaSure Core Plugin Bootstrap
 * 
 * Shared functionality, database versioning, capabilities, 
 * tenant context, REST conventions, jobs, events, and audit foundation.
 * 
 * @package PharmaSure_Core
 * @version 1.0.0
 */

namespace PharmaSure\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'PHARMASURE_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'PHARMASURE_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'PHARMASURE_CORE_VERSION', '1.0.0' );
define( 'PHARMASURE_CORE_DB_VERSION', '1' );
define( 'PHARMASURE_TABLE_PREFIX', 'ps_' );

// Autoloader
require_once PHARMASURE_CORE_PATH . 'src/Autoloader.php';
new Autoloader();

// Plugin activation/deactivation hooks
register_activation_hook( __FILE__, [ '\PharmaSure\Core\Activation', 'activate' ] );
register_deactivation_hook( __FILE__, [ '\PharmaSure\Core\Activation', 'deactivate' ] );

// Initialize the plugin
add_action( 'plugins_loaded', [ '\PharmaSure\Core\Plugin', 'init' ], 10 );
