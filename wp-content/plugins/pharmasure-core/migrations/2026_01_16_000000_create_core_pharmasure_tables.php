<?php
/**
 * Database Migration: Create Core PharmaSure Tables
 * 
 * Sets up the foundational multi-tenant schema with:
 * - Tenant and branch management
 * - User memberships and roles
 * - License and subscription tables
 * - Audit and security tables
 * - Outbox for transactional events
 * 
 * @package PharmaSure\Migrations
 */

namespace PharmaSure\Migrations;

class CreateCorePharmaSureTables {
    public function up() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $prefix = PHARMASURE_TABLE_PREFIX;

        // === TENANT & MULTISITE MAPPING ===
        
        // Tenant site mapping for WordPress Multisite
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}tenant_site_mapping (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_tenant_site (tenant_id, site_id),
            INDEX idx_tenant (tenant_id),
            INDEX idx_site (site_id)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // === TENANT & BRANCH ===

        // Tenants (organizations)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}tenants (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(100) NOT NULL UNIQUE,
            trading_name VARCHAR(255),
            logo_url VARCHAR(2048),
            website_url VARCHAR(2048),
            primary_contact_name VARCHAR(255),
            primary_contact_email VARCHAR(255) NOT NULL,
            primary_contact_phone VARCHAR(20),
            address_line_1 VARCHAR(255),
            address_line_2 VARCHAR(255),
            city VARCHAR(100),
            state_province VARCHAR(100),
            postal_code VARCHAR(20),
            country VARCHAR(2),
            timezone VARCHAR(50) DEFAULT 'UTC',
            currency VARCHAR(3) DEFAULT 'USD',
            tax_registration_number VARCHAR(50),
            status ENUM('active', 'trial', 'suspended', 'archived') DEFAULT 'trial',
            onboarded_at DATETIME,
            suspended_at DATETIME,
            archived_at DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by BIGINT UNSIGNED,
            UNIQUE KEY unique_slug (slug),
            INDEX idx_status (status),
            INDEX idx_country (country),
            INDEX idx_created (created_at)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // Branches (physical pharmacy locations)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}branches (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            code VARCHAR(50) NOT NULL,
            address_line_1 VARCHAR(255),
            address_line_2 VARCHAR(255),
            city VARCHAR(100),
            state_province VARCHAR(100),
            postal_code VARCHAR(20),
            country VARCHAR(2),
            phone VARCHAR(20),
            email VARCHAR(255),
            manager_name VARCHAR(255),
            timezone VARCHAR(50),
            receipt_header_text LONGTEXT,
            receipt_footer_text LONGTEXT,
            tax_registration_number VARCHAR(50),
            default_currency VARCHAR(3),
            stock_location_code VARCHAR(50),
            is_active TINYINT(1) DEFAULT 1,
            is_default TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by BIGINT UNSIGNED,
            UNIQUE KEY unique_tenant_code (tenant_id, code),
            INDEX idx_tenant (tenant_id),
            INDEX idx_active (is_active)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // === USERS & MEMBERSHIP ===

        // Tenant memberships (user-to-tenant mapping)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}tenant_memberships (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            tenant_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(50) NOT NULL,
            is_admin TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            invited_at DATETIME,
            activated_at DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_tenant (user_id, tenant_id),
            INDEX idx_user (user_id),
            INDEX idx_tenant (tenant_id),
            INDEX idx_role (role)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // Membership branches (which branches can a user access?)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}membership_branches (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            membership_id BIGINT UNSIGNED NOT NULL,
            branch_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_membership_branch (membership_id, branch_id),
            INDEX idx_membership (membership_id),
            INDEX idx_branch (branch_id)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // User sessions (track active sessions)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}user_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            tenant_id BIGINT UNSIGNED NOT NULL,
            session_token VARCHAR(255) NOT NULL UNIQUE,
            ip_address VARCHAR(45),
            user_agent VARCHAR(500),
            device_name VARCHAR(255),
            expires_at DATETIME NOT NULL,
            last_activity DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_tenant (user_id, tenant_id),
            INDEX idx_expires (expires_at)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // === LICENSING & COMMERCIAL ===

        // Products
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}products (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sku (sku),
            INDEX idx_active (is_active)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // Plans
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}plans (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            plan_tier VARCHAR(50) NOT NULL,
            description LONGTEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_product (product_id),
            INDEX idx_tier (plan_tier)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // Prices
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}prices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            plan_id BIGINT UNSIGNED NOT NULL,
            currency VARCHAR(3) NOT NULL,
            amount_minor BIGINT NOT NULL,
            billing_interval ENUM('month', 'year', 'one_time') DEFAULT 'month',
            effective_from DATE,
            effective_until DATE,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_plan (plan_id),
            INDEX idx_currency (currency),
            INDEX idx_effective (effective_from, effective_until)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // Entitlements
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}entitlements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entitlement_key VARCHAR(100) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT,
            is_quota TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_key (entitlement_key)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // Plan entitlements
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}plan_entitlements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            plan_id BIGINT UNSIGNED NOT NULL,
            entitlement_id BIGINT UNSIGNED NOT NULL,
            quota_limit INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_plan_entitlement (plan_id, entitlement_id),
            INDEX idx_plan (plan_id),
            INDEX idx_entitlement (entitlement_id)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // Licenses
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}licences (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id BIGINT UNSIGNED NOT NULL,
            plan_id BIGINT UNSIGNED NOT NULL,
            status ENUM('trial', 'active', 'past_due', 'grace', 'suspended', 'expired', 'cancelled', 'revoked') DEFAULT 'active',
            licence_key VARCHAR(255) NOT NULL UNIQUE,
            activated_at DATETIME,
            expires_at DATETIME,
            grace_period_days INT DEFAULT 14,
            revocation_reason VARCHAR(500),
            revoked_at DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_tenant_active (tenant_id, status),
            INDEX idx_tenant (tenant_id),
            INDEX idx_status (status),
            INDEX idx_expires (expires_at)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // License entitlements (granted features)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}licence_entitlements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            licence_id BIGINT UNSIGNED NOT NULL,
            entitlement_key VARCHAR(100) NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_licence (licence_id),
            INDEX idx_entitlement (entitlement_key)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // License quotas
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}licence_quotas (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            licence_id BIGINT UNSIGNED NOT NULL,
            quota_key VARCHAR(100) NOT NULL,
            limit_value INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_licence (licence_id),
            INDEX idx_quota (quota_key)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // === AUDIT & SECURITY ===

        // Audit events (immutable append-only log)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}audit_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            correlation_id VARCHAR(100),
            tenant_id BIGINT UNSIGNED,
            actor_id BIGINT UNSIGNED,
            event_type VARCHAR(100) NOT NULL,
            entity_type VARCHAR(100),
            entity_id BIGINT UNSIGNED,
            action VARCHAR(50),
            status ENUM('success', 'failure') DEFAULT 'success',
            details LONGTEXT,
            before_state LONGTEXT,
            after_state LONGTEXT,
            ip_address VARCHAR(45),
            user_agent VARCHAR(500),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tenant (tenant_id),
            INDEX idx_actor (actor_id),
            INDEX idx_event_type (event_type),
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_created (created_at),
            INDEX idx_correlation (correlation_id)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // Security events
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}security_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id BIGINT UNSIGNED,
            user_id BIGINT UNSIGNED,
            event_type VARCHAR(100) NOT NULL,
            severity ENUM('info', 'warning', 'critical') DEFAULT 'info',
            description VARCHAR(500),
            ip_address VARCHAR(45),
            user_agent VARCHAR(500),
            is_resolved TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tenant (tenant_id),
            INDEX idx_user (user_id),
            INDEX idx_event_type (event_type),
            INDEX idx_severity (severity),
            INDEX idx_created (created_at)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // === OPERATIONS ===

        // Settings (tenant/branch-specific)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id BIGINT UNSIGNED,
            branch_id BIGINT UNSIGNED,
            setting_key VARCHAR(255) NOT NULL,
            setting_value LONGTEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_setting (tenant_id, branch_id, setting_key),
            INDEX idx_tenant (tenant_id),
            INDEX idx_branch (branch_id)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // Outbox (transactional event publishing)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}outbox_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id BIGINT UNSIGNED NOT NULL,
            aggregate_type VARCHAR(100) NOT NULL,
            aggregate_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(100) NOT NULL,
            event_version INT DEFAULT 1,
            event_data LONGTEXT NOT NULL,
            published_at DATETIME,
            is_published TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tenant (tenant_id),
            INDEX idx_published (is_published),
            INDEX idx_aggregate (aggregate_type, aggregate_id),
            INDEX idx_created (created_at)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // Usage meters (license quota tracking)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}usage_meters (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id BIGINT UNSIGNED NOT NULL,
            meter_key VARCHAR(100) NOT NULL,
            amount BIGINT DEFAULT 0,
            period_start DATE,
            period_end DATE,
            recorded_at DATETIME NOT NULL,
            UNIQUE KEY unique_meter (tenant_id, meter_key),
            INDEX idx_tenant (tenant_id),
            INDEX idx_meter (meter_key),
            INDEX idx_period (period_start, period_end)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // License signing keys (for JWT token validation)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}licence_signing_keys (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            key_id VARCHAR(100) NOT NULL UNIQUE,
            public_key LONGTEXT NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            rotated_at DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_active (is_active)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // Token revocation list (for offline license tokens)
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}token_revocation_list (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            token_hash VARCHAR(64) NOT NULL UNIQUE,
            revocation_reason VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_hash (token_hash)
        ) $charset_collate;";
        $this->execute_sql( $sql );

        // === DATABASE VERSIONING ===

        // Migrations tracking
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}{$prefix}migrations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            plugin_slug VARCHAR(100) NOT NULL,
            status ENUM('pending', 'completed', 'failed', 'rolled_back') DEFAULT 'pending',
            run_at DATETIME,
            rolled_back_at DATETIME,
            error_message LONGTEXT,
            execution_time_ms INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_plugin_status (plugin_slug, status)
        ) $charset_collate;";
        $this->execute_sql( $sql );
    }

    /**
     * Execute SQL safely
     * 
     * @param string $sql
     * @return void
     */
    private function execute_sql( $sql ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
