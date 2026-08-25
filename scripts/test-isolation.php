#!/usr/bin/env php
<?php
/**
 * PharmaSure tenant-context isolation integration test.
 *
 * Run from the WordPress root: php scripts/test-isolation.php
 */

if ( defined( 'ABSPATH' ) ) {
	// WordPress is already loaded by wp eval-file.
} elseif ( file_exists( __DIR__ . '/../wp-load.php' ) ) {
	require_once __DIR__ . '/../wp-load.php';
} else {
	fwrite( STDERR, "WordPress not loaded. Run from WordPress root directory.\n" );
	exit( 1 );
}

if ( ! is_plugin_active( 'pharmasure-core/pharmasure-core.php' ) ) {
	fwrite( STDERR, "pharmasure-core plugin must be active.\n" );
	exit( 1 );
}

global $wpdb;
$previous_user_id = get_current_user_id();
wp_set_current_user( 1 );
$prefix = $wpdb->prefix . 'ps_';
$pass = 0;
$fail = 0;
$tenant_ids = array();
$branch_ids = array();

$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) {
	if ( $condition ) {
		++$pass;
		echo "PASS: {$message}\n";
	} else {
		++$fail;
		echo "FAIL: {$message}\n";
	}
};

echo "PharmaSure tenant isolation integration tests\n";

try {
	$context = \PharmaSure\Core\TenantContext::instance();
	$user_property = new ReflectionProperty( $context, 'user_id' );
	$original_context_user = $user_property->getValue( $context );
	$user_property->setValue( $context, 1 );
	$assert( $context === \PharmaSure\Core\TenantContext::instance(), 'TenantContext is a singleton' );

	foreach ( array( 'tenants', 'branches', 'tenant_memberships', 'licences', 'audit_events' ) as $table ) {
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prefix . $table ) );
		$assert( $prefix . $table === $exists, "{$table} table exists" );
	}

	foreach ( array( 'alpha', 'beta' ) as $key ) {
		$fixture_token = 'isolation-' . $key . '-' . wp_generate_uuid4();
		$wpdb->insert(
			$prefix . 'tenants',
			array(
				'name' => "Isolation Test {$key}",
				'slug' => $fixture_token,
				'primary_contact_email' => $fixture_token . '@example.test',
				'status' => 'active',
			)
		);
		$tenant_ids[] = (int) $wpdb->insert_id;
		$wpdb->insert(
			$prefix . 'branches',
			array(
				'tenant_id' => end( $tenant_ids ),
				'name' => 'Main Branch',
				'code' => strtoupper( $key ),
				'is_active' => 1,
			)
		);
		$branch_ids[] = (int) $wpdb->insert_id;
	}

	$assert( min( $tenant_ids ) > 0 && min( $branch_ids ) > 0, 'two isolated tenant fixtures are created' );

	$tenant_property = new ReflectionProperty( $context, 'tenant_id' );
	$tenant_property->setValue( $context, $tenant_ids[0] );
	$assert( $context->set_branch( $branch_ids[0] ), 'tenant context allows its own branch' );
	$assert( ! $context->set_branch( $branch_ids[1] ), 'tenant context rejects another tenant branch' );
	$assert( $branch_ids[0] === $context->get_branch_id(), 'rejected branch cannot replace the active branch context' );

	$manager = new \PharmaSure\Core\LicenseManager( $tenant_ids[0] );
	$assert( $manager instanceof \PharmaSure\Core\LicenseManager, 'LicenseManager accepts the scoped tenant ID' );
	$assert( is_multisite() && get_sites( array( 'count' => true ) ) >= 1, 'WordPress Multisite is active' );
} catch ( Throwable $error ) {
	++$fail;
	echo 'FAIL: unexpected exception: ' . $error->getMessage() . "\n";
} finally {
	if ( isset( $context, $tenant_property ) ) {
		$tenant_property->setValue( $context, 0 );
	}
	if ( isset( $context, $user_property ) ) {
		$user_property->setValue( $context, $original_context_user );
	}
	foreach ( $branch_ids as $id ) {
		$wpdb->delete( $prefix . 'branches', array( 'id' => $id ), array( '%d' ) );
	}
	foreach ( $tenant_ids as $id ) {
		$wpdb->delete( $prefix . 'tenants', array( 'id' => $id ), array( '%d' ) );
	}
	delete_user_meta( 1, 'pharmasure_active_branch_id' );
	wp_set_current_user( $previous_user_id );
}

echo "Tenant isolation tests: {$pass} passed, {$fail} failed.\n";
exit( $fail > 0 ? 1 : 0 );
