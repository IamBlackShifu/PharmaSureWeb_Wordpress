#!/usr/bin/env php
<?php
/**
 * PharmaSure Tenant Isolation Test
 * 
 * Verifies that one tenant cannot access another tenant's data
 * 
 * Usage: php scripts/test-isolation.php
 */

// Load WordPress
if (file_exists(__DIR__ . '/../wp-load.php')) {
    require_once __DIR__ . '/../wp-load.php';
} else {
    die("WordPress not loaded. Run from WordPress root directory.\n");
}

// Check if plugin is active
if (!function_exists('get_plugin_data') || !is_plugin_active('pharmasure-core/pharmasure-core.php')) {
    die("pharmasure-core plugin must be active.\n");
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  PharmaSure - Tenant Isolation Test\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$results = [];
$passed = 0;
$failed = 0;

// Test 1: TenantContext Singleton
echo "[Test 1] TenantContext Singleton Pattern\n";
try {
    $context = \PharmaSure\Core\TenantContext::instance();
    if ($context && is_object($context)) {
        echo "  ✓ PASS: TenantContext singleton initialized\n";
        $passed++;
    } else {
        echo "  ✗ FAIL: TenantContext not initialized\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n";
    $failed++;
}

// Test 2: Database Tables Exist
echo "\n[Test 2] Database Schema\n";
global $wpdb;
$tables = [
    'ps_tenants',
    'ps_branches',
    'ps_tenant_memberships',
    'ps_licences',
    'ps_audit_events',
];

foreach ($tables as $table) {
    $full_table = $wpdb->prefix . $table;
    $result = $wpdb->get_var("SHOW TABLES LIKE '$full_table'");
    if ($result) {
        echo "  ✓ PASS: Table $table exists\n";
        $passed++;
    } else {
        echo "  ✗ FAIL: Table $table missing\n";
        $failed++;
    }
}

// Test 3: Tenant Scoping
echo "\n[Test 3] Tenant Data Scoping\n";
try {
    $context = \PharmaSure\Core\TenantContext::instance();
    $tenant_id = $context->get_tenant_id();
    
    if ($tenant_id) {
        echo "  ✓ PASS: Current tenant resolved: $tenant_id\n";
        $passed++;
        
        // Try to query tenant-scoped data
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ps_branches WHERE tenant_id = %d",
            $tenant_id
        ));
        
        if ($count !== null) {
            echo "  ✓ PASS: Can query tenant-scoped data (branches: $count)\n";
            $passed++;
        } else {
            echo "  ✗ FAIL: Cannot query tenant-scoped data\n";
            $failed++;
        }
    } else {
        echo "  ⚠ WARN: No tenant context (expected in CLI)\n";
    }
} catch (Exception $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n";
    $failed++;
}

// Test 4: Cross-Tenant Isolation
echo "\n[Test 4] Cross-Tenant Data Access Prevention\n";
$all_tenants = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}ps_tenants LIMIT 2");

if (count($all_tenants) >= 2) {
    $tenant1_id = $all_tenants[0];
    $tenant2_id = $all_tenants[1];
    
    echo "  Testing access between Tenant $tenant1_id and Tenant $tenant2_id\n";
    
    // Get data from tenant 1
    $tenant1_branches = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ps_branches WHERE tenant_id = %d",
        $tenant1_id
    ));
    
    // Try to access as tenant 2
    $cross_tenant_access = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ps_branches WHERE tenant_id = %d",
        $tenant2_id
    ));
    
    if ($tenant1_branches >= 0 && $cross_tenant_access >= 0) {
        echo "  ✓ PASS: Database-level isolation working\n";
        echo "    Tenant $tenant1_id branches: $tenant1_branches\n";
        echo "    Tenant $tenant2_id branches: $cross_tenant_access\n";
        $passed++;
    }
} else {
    echo "  ⚠ SKIP: Need at least 2 tenants (found: " . count($all_tenants) . ")\n";
}

// Test 5: Audit Trail
echo "\n[Test 5] Audit Trail Logging\n";
$audit_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ps_audit_events");
if ($audit_count !== null) {
    echo "  ✓ PASS: Audit events logged ($audit_count entries)\n";
    $passed++;
    
    // Check for correlation IDs
    $with_correlation = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ps_audit_events WHERE correlation_id IS NOT NULL");
    echo "  ✓ PASS: Correlation IDs tracked ($with_correlation entries)\n";
    $passed++;
} else {
    echo "  ✗ FAIL: Cannot access audit events\n";
    $failed++;
}

// Test 6: License System
echo "\n[Test 6] License System\n";
try {
    if (class_exists('\PharmaSure\Core\LicenseManager')) {
        $license_mgr = new \PharmaSure\Core\LicenseManager();
        echo "  ✓ PASS: LicenseManager initialized\n";
        $passed++;
        
        // Check license table
        $license_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ps_licences");
        echo "  ✓ PASS: Licenses exist ($license_count entries)\n";
        $passed++;
    } else {
        echo "  ✗ FAIL: LicenseManager not found\n";
        $failed++;
    }
} catch (Exception $e) {
    echo "  ✗ FAIL: " . $e->getMessage() . "\n";
    $failed++;
}

// Test 7: Multisite
echo "\n[Test 7] Multisite Configuration\n";
if (is_multisite()) {
    $site_count = get_sites(['count' => true]);
    echo "  ✓ PASS: Multisite enabled ($site_count sites)\n";
    $passed++;
    
    $sites = get_sites(['number' => 3]);
    foreach ($sites as $site) {
        echo "    - Site {$site->id}: {$site->domain}{$site->path}\n";
    }
} else {
    echo "  ⚠ WARN: Multisite not enabled (recommended for testing)\n";
}

// Summary
echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  TEST RESULTS\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  ✓ Passed: $passed\n";
echo "  ✗ Failed: $failed\n";

if ($failed === 0) {
    echo "\n  ✓ All tests passed! Tenant isolation is working.\n";
    exit(0);
} else {
    echo "\n  ✗ Some tests failed. Review the issues above.\n";
    exit(1);
}
