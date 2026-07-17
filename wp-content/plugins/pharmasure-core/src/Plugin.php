<?php
/**
 * Plugin initialization and setup
 * 
 * @package PharmaSure_Core
 */

namespace PharmaSure\Core;

class Plugin {
    public static function init() {
        add_action( 'init', [ static::class, 'register_capabilities' ], 10 );
        add_action( 'init', [ static::class, 'run_migrations' ], 5 );
        add_action( 'rest_api_init', [ static::class, 'register_rest_routes' ], 10 );
        
        // Initialize tenant context singleton
        TenantContext::instance();
    }

    /**
     * Register WordPress capabilities
     * 
     * @return void
     */
    public static function register_capabilities() {
        // Get admin role
        $admin = get_role( 'administrator' );
        
        if ( ! $admin ) {
            return;
        }

        // Core PharmaSure capabilities
        $capabilities = [
            // Tenant management
            'pharmasure_manage_tenant' => 'Manage tenant settings',
            'pharmasure_manage_branches' => 'Manage branches',
            'pharmasure_manage_users' => 'Manage users and roles',
            
            // Inventory
            'pharmasure_view_inventory' => 'View inventory',
            'pharmasure_manage_inventory' => 'Manage inventory',
            'pharmasure_manage_stock' => 'Manage stock',
            
            // Clinical
            'pharmasure_view_patients' => 'View patients',
            'pharmasure_manage_patients' => 'Manage patients',
            'pharmasure_view_prescriptions' => 'View prescriptions',
            'pharmasure_review_prescriptions' => 'Review and approve prescriptions',
            'pharmasure_dispense_medications' => 'Dispense medications',
            
            // POS
            'pharmasure_access_pos' => 'Access point of sale',
            'pharmasure_manage_tills' => 'Manage till sessions',
            
            // Claims
            'pharmasure_manage_claims' => 'Manage insurance claims',
            
            // Reports
            'pharmasure_view_reports' => 'View reports',
            'pharmasure_export_data' => 'Export data',
            
            // Audit
            'pharmasure_view_audit_log' => 'View audit logs',
        ];

        foreach ( $capabilities as $cap => $label ) {
            $admin->add_cap( $cap );
        }
    }

    /**
     * Run database migrations
     * 
     * @return void
     */
    public static function run_migrations() {
        $migrations = new DatabaseMigrations();
        $results = $migrations->migrate();

        foreach ( $results as $result ) {
            if ( is_wp_error( $result ) ) {
                error_log( 'PharmaSure Migration Failed: ' . $result->get_error_message() );
            }
        }
    }

    /**
     * Register REST API routes
     * 
     * @return void
     */
    public static function register_rest_routes() {
        // Routes will be registered by individual modules
        // This is a placeholder for core routes
    }
}

class Activation {
    public static function activate() {
        // Run migrations on plugin activation
        update_option( 'pharmasure_core_version', PHARMASURE_CORE_VERSION );
        ( new DatabaseMigrations() )->migrate();
    }

    public static function deactivate() {
        // Clean up if needed
    }
}
