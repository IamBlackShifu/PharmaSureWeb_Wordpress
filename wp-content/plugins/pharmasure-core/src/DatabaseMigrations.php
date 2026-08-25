<?php
/**
 * Database Migration System
 * 
 * Handles plugin schema versioning, migrations, and rollback capability.
 * Every schema change is versioned and reversible.
 * 
 * @package PharmaSure_Core
 */

namespace PharmaSure\Core;

class DatabaseMigrations {
    private $plugin_slug = 'pharmasure-core';
    private $migrations_dir = '';

    public function __construct() {
        $this->migrations_dir = PHARMASURE_CORE_PATH . 'migrations';
    }

    /**
     * Put each dbDelta field or index definition on its own line.
     *
     * dbDelta treats physical lines as schema definitions. Several modules
     * intentionally keep CREATE statements compact, so normalize only commas
     * followed by a new column or index definition; commas inside composite
     * indexes and DECIMAL declarations remain untouched.
     */
    public static function normalize_dbdelta_sql( $sql ) {
        if ( is_array( $sql ) ) {
            return array_map( [ static::class, 'normalize_dbdelta_sql' ], $sql );
        }
        return preg_replace(
			'/,\s*(?=(?:(?:PRIMARY|UNIQUE)\s+KEY\b|KEY\s+[A-Za-z_][A-Za-z0-9_]*\s*\(|[A-Za-z_][A-Za-z0-9_]*\s+(?:BIGINT|INT|TINYINT|VARCHAR|CHAR|DECIMAL|LONGTEXT|TEXT|DATETIME|TIMESTAMP|DATE)\b))/i',
            ",\n",
            (string) $sql
        );
    }

    /**
     * Run pending migrations
     * 
     * @return array Migration results
     */
    public function migrate() {
        $pending = $this->get_pending_migrations();
        $results = [];

        foreach ( $pending as $migration_file ) {
            $result = $this->run_migration( $migration_file );
            $results[] = $result;
            
            if ( is_wp_error( $result ) ) {
                $this->log_migration_error( $migration_file, $result );
                break; // Stop on first error
            }
        }

        return $results;
    }

    /**
     * Get pending migrations
     * 
     * @return array
     */
    private function get_pending_migrations() {
        if ( ! is_dir( $this->migrations_dir ) ) {
            return [];
        }

        $run_migrations = $this->get_completed_migrations();
        $all_migrations = array_diff(
            scandir( $this->migrations_dir ),
            [ '.', '..' ]
        );

        $pending = [];
        foreach ( $all_migrations as $file ) {
            $migration_name = basename( $file, '.php' );
            if ( ! in_array( $migration_name, $run_migrations, true ) && substr( $file, -4 ) === '.php' ) {
                $pending[] = $file;
            }
        }

        sort( $pending ); // Run in chronological order
        return $pending;
    }

    /**
     * Get list of completed migrations
     * 
     * @return array
     */
    private function get_completed_migrations() {
        global $wpdb;
        $table = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'migrations';
        
        // Create migrations table if it doesn't exist
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
            $this->create_migrations_table();
        }

        $migrations = $wpdb->get_col(
            "SELECT migration_name FROM $table WHERE status = 'completed' ORDER BY run_at ASC"
        );

        return $migrations ?? [];
    }

    /**
     * Create the migrations tracking table
     * 
     * @return void
     */
    private function create_migrations_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'migrations';

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            plugin_slug VARCHAR(100) NOT NULL,
            status ENUM('pending', 'completed', 'failed', 'rolled_back') NOT NULL DEFAULT 'pending',
            run_at DATETIME NULL,
            rolled_back_at DATETIME NULL,
            error_message LONGTEXT NULL,
            execution_time_ms INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_plugin_status (plugin_slug, status)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Run a single migration file
     * 
     * @param string $migration_file
     * @return array|WP_Error
     */
    private function run_migration( $migration_file ) {
        $migration_name = basename( $migration_file, '.php' );
        $start_time = microtime( true );

        try {
            // Include migration file
            $migration_path = $this->migrations_dir . '/' . $migration_file;
            
            if ( ! file_exists( $migration_path ) ) {
                return new \WP_Error( 'file_not_found', "Migration file not found: $migration_file" );
            }

            // Timestamp prefixes order migrations but are not part of PHP class names.
            $class_suffix = preg_replace( '/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $migration_name );
            $class_name   = 'PharmaSure\\Migrations\\' . $this->camel_case( $class_suffix );
            
            require_once $migration_path;

            if ( ! class_exists( $class_name ) ) {
                return new \WP_Error( 'class_not_found', "Migration class not found: $class_name" );
            }

            $migration = new $class_name();
            
            if ( ! method_exists( $migration, 'up' ) ) {
                return new \WP_Error( 'method_not_found', "Migration::up() method not found in $class_name" );
            }

            // Execute migration
            $migration->up();

            $execution_time = (int) ( ( microtime( true ) - $start_time ) * 1000 );
            
            // Record successful migration
            $this->record_migration_completion( $migration_name, $execution_time );

            return [
                'migration' => $migration_name,
                'status' => 'completed',
                'execution_time_ms' => $execution_time,
            ];
        } catch ( \Throwable $e ) {
            $execution_time = (int) ( ( microtime( true ) - $start_time ) * 1000 );
            $this->record_migration_failure( $migration_name, $e->getMessage(), $execution_time );
            
            return new \WP_Error( 'migration_failed', $e->getMessage() );
        }
    }

    /**
     * Record migration completion
     * 
     * @param string $migration_name
     * @param int $execution_time_ms
     * @return void
     */
    private function record_migration_completion( $migration_name, $execution_time_ms ) {
        global $wpdb;
        $table = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'migrations';

        $wpdb->insert(
            $table,
            [
                'migration_name' => $migration_name,
                'plugin_slug' => $this->plugin_slug,
                'status' => 'completed',
                'run_at' => current_time( 'mysql', true ),
                'execution_time_ms' => $execution_time_ms,
            ],
            [ '%s', '%s', '%s', '%s', '%d' ]
        );
    }

    /**
     * Record migration failure
     * 
     * @param string $migration_name
     * @param string $error_message
     * @param int $execution_time_ms
     * @return void
     */
    private function record_migration_failure( $migration_name, $error_message, $execution_time_ms ) {
        global $wpdb;
        $table = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'migrations';

        $wpdb->insert(
            $table,
            [
                'migration_name' => $migration_name,
                'plugin_slug' => $this->plugin_slug,
                'status' => 'failed',
                'error_message' => $error_message,
                'execution_time_ms' => $execution_time_ms,
            ],
            [ '%s', '%s', '%s', '%s', '%d' ]
        );
    }

    /**
     * Convert snake_case to CamelCase
     * 
     * @param string $string
     * @return string
     */
    private function camel_case( $string ) {
        return str_replace( ' ', '', ucwords( str_replace( '_', ' ', $string ) ) );
    }

    /**
     * Log migration error
     * 
     * @param string $migration_file
     * @param WP_Error $error
     * @return void
     */
    private function log_migration_error( $migration_file, $error ) {
        error_log( "PharmaSure Migration Error [$migration_file]: " . $error->get_error_message() );
    }
}
