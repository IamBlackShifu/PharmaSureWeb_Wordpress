<?php
/**
 * Tenant Context - Server-side tenant isolation enforcement
 * 
 * This is the authoritative source for tenant/branch scope on every request.
 * NEVER trust posted tenant_id or branch_id values; always derive from:
 * - Authenticated user membership
 * - WordPress Multisite context
 * - Explicit capability checks
 * 
 * @package PharmaSure_Core
 */

namespace PharmaSure\Core;

class TenantContext {
    private static $instance = null;
    private $tenant_id = null;
    private $branch_id = null;
    private $user_id = null;
    private $memberships = [];
    private $capabilities = [];
    private $timezone = 'UTC';
    private $currency = 'USD';
    private $licence_snapshot = [];
    private $correlation_id = null;

    private function __construct() {
        $this->correlation_id = $this->generate_correlation_id();
        $this->user_id = get_current_user_id();
        
        // For Multisite, resolve tenant from site/blog mapping
        if ( is_multisite() ) {
            $this->tenant_id = $this->resolve_tenant_from_site();
        } else {
            $this->tenant_id = $this->resolve_tenant_from_membership();
        }
        
        $this->load_memberships();
        $saved_branch = (int) get_user_meta( $this->user_id, 'pharmasure_active_branch_id', true );
        if ( $saved_branch && $this->can_access_branch( $saved_branch ) ) {
            $this->branch_id = $saved_branch;
        }
        $this->load_licence_snapshot();
    }

    /**
     * Get singleton instance
     * 
     * @return TenantContext
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Resolve tenant from WordPress Multisite context
     * 
     * @return int|null
     */
    private function resolve_tenant_from_site() {
        $blog_id = get_current_blog_id();
        
        // Query tenant mapping (schema added in migration)
        global $wpdb;
        $table = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'tenant_site_mapping';
        
        $tenant_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT tenant_id FROM $table WHERE site_id = %d",
            $blog_id
        ) );
        
        return $tenant_id ? (int) $tenant_id : null;
    }

    /**
     * Resolve tenant from user membership (fallback for non-Multisite)
     * 
     * @return int|null
     */
    private function resolve_tenant_from_membership() {
        global $wpdb;
        $table = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'tenant_memberships';
        
        $tenant_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT tenant_id FROM $table WHERE user_id = %d AND is_active = 1 LIMIT 1",
            $this->user_id
        ) );
        
        return $tenant_id ? (int) $tenant_id : null;
    }

    /**
     * Load user memberships and validate authorization
     * 
     * @return void
     */
    private function load_memberships() {
        if ( ! $this->tenant_id || ! $this->user_id ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'tenant_memberships';
        
        $this->memberships = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, tenant_id, role, is_admin, is_active FROM $table WHERE user_id = %d AND is_active = 1",
            $this->user_id
        ) );

        // Validate current tenant is in memberships
        $is_authorized = array_filter(
            $this->memberships,
            fn( $m ) => (int) $m->tenant_id === $this->tenant_id
        );

        if ( empty( $is_authorized ) && ! $this->is_super_admin() ) {
            wp_die( 'Unauthorized tenant access', 403 );
        }
    }

    /**
     * Load licence snapshot for feature/quota checks
     * 
     * @return void
     */
    private function load_licence_snapshot() {
        if ( ! $this->tenant_id ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'licences';
        
        $licence = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE tenant_id = %d AND status IN ('active', 'trial', 'grace') ORDER BY updated_at DESC LIMIT 1",
            $this->tenant_id
        ), ARRAY_A );

        if ( $licence ) {
            $this->licence_snapshot = $licence;
            
            // Load entitlements
            $ent_table = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'licence_entitlements';
            $entitlements = $wpdb->get_col( $wpdb->prepare(
                "SELECT entitlement_key FROM $ent_table WHERE licence_id = %d",
                $licence['id']
            ) );
            $this->licence_snapshot['entitlements'] = $entitlements ?? [];
        }
    }

    /**
     * Set branch context for the current request
     * 
     * @param int $branch_id
     * @return bool
     */
    public function set_branch( $branch_id ) {
        if ( ! $this->can_access_branch( $branch_id ) ) {
            return false;
        }
        
        $this->branch_id = (int) $branch_id;

        update_user_meta( $this->user_id, 'pharmasure_active_branch_id', $this->branch_id );
        
        // Update session
        if ( isset( $_SESSION ) ) {
            $_SESSION['pharmasure_branch_id'] = $branch_id;
        }
        
        return true;
    }

    /**
     * Validate branch is owned by current tenant
     * 
     * @param int $branch_id
     * @return bool
     */
    public function can_access_branch( $branch_id ) {
        global $wpdb;
        $branches    = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'branches';
        $memberships = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'tenant_memberships';
        $assignments = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'membership_branches';

        $branch_id = (int) $branch_id;
        if ( ! $branch_id || ! $this->tenant_id || ! $this->user_id ) {
            return false;
        }
        
        $branch_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $branches WHERE id = %d AND tenant_id = %d AND is_active = 1",
            $branch_id,
            $this->tenant_id
        ) );

        if ( ! $branch_exists ) {
            return false;
        }

        if ( $this->is_super_admin() ) {
            return true;
        }

        $membership = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, is_admin FROM $memberships WHERE user_id = %d AND tenant_id = %d AND is_active = 1 LIMIT 1",
            $this->user_id,
            $this->tenant_id
        ) );

        if ( ! $membership ) {
            return false;
        }

        // Tenant administrators intentionally have access to every active
        // branch; operational users require an explicit branch assignment.
        if ( (int) $membership->is_admin === 1 ) {
            return true;
        }

        $assigned = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $assignments WHERE membership_id = %d AND branch_id = %d",
            $membership->id,
            $branch_id
        ) );
        
        return ! empty( $assigned );
    }

    /** Determine whether the current user may access a tenant. */
    public function can_access_tenant( $tenant_id ) {
        global $wpdb;

        $tenant_id = (int) $tenant_id;
        if ( ! $tenant_id || ! $this->user_id ) {
            return false;
        }

        if ( $this->is_super_admin() ) {
            return true;
        }

        $table = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'tenant_memberships';
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE user_id = %d AND tenant_id = %d AND is_active = 1 LIMIT 1",
            $this->user_id,
            $tenant_id
        ) );
    }

    /**
     * Check if feature is entitled
     * 
     * @param string $entitlement_key
     * @return bool
     */
    public function has_entitlement( $entitlement_key ) {
        return in_array( $entitlement_key, $this->licence_snapshot['entitlements'] ?? [], true );
    }

    /**
     * Check if quota is available
     * 
     * @param string $quota_key
     * @return int|false Current value or false if unlimited
     */
    public function check_quota( $quota_key ) {
        return $this->licence_snapshot['quotas'][ $quota_key ] ?? false;
    }

    /**
     * Increment usage meter
     * 
     * @param string $meter_key
     * @param int $amount
     * @return void
     */
    public function increment_usage( $meter_key, $amount = 1 ) {
        if ( ! $this->tenant_id ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'usage_meters';
        
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $table (tenant_id, meter_key, amount, recorded_at) 
             VALUES (%d, %s, %d, NOW())
             ON DUPLICATE KEY UPDATE amount = amount + %d",
            $this->tenant_id,
            $meter_key,
            $amount,
            $amount
        ) );
    }

    /**
     * Is user a platform super-admin?
     * 
     * @return bool
     */
    private function is_super_admin() {
        if ( is_multisite() ) {
            return is_super_admin();
        }
        return current_user_can( 'manage_options' );
    }

    /**
     * Get tenant ID
     */
    public function get_tenant_id() {
        return $this->tenant_id;
    }

    /**
     * Get branch ID
     */
    public function get_branch_id() {
        return $this->branch_id;
    }

    /**
     * Get user ID
     */
    public function get_user_id() {
        return $this->user_id;
    }

    /**
     * Get correlation ID for audit/logging
     */
    public function get_correlation_id() {
        return $this->correlation_id;
    }

    /**
     * Generate unique correlation ID
     */
    private function generate_correlation_id() {
        return sprintf(
            '%s-%s',
            date( 'Ymd-Hi' ),
            bin2hex( random_bytes( 8 ) )
        );
    }
}
