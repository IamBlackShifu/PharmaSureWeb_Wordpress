<?php
/**
 * Tenancy onboarding integration test. Run with:
 * wp eval-file scripts/test-tenancy.php --allow-root
 */

use PharmaSure\Tenancy\Services\TenantService;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

global $wpdb;
$prefix = $wpdb->prefix . 'ps_';
$pass = 0;
$fail = 0;
$tenant_id = 0;
$branch_id = 0;
$owner_id = 0;

$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) {
	if ( $condition ) {
		++$pass;
		echo "PASS: {$message}\n";
	} else {
		++$fail;
		echo "FAIL: {$message}\n";
	}
};

wp_set_current_user( 1 );
$service = new TenantService();
$token = wp_generate_uuid4();
$owner_email = 'tenant-owner-' . $token . '@example.test';

try {
	$missing = $service->create_tenant( array() );
	$assert( is_wp_error( $missing ) && 'missing_fields' === $missing->get_error_code(), 'missing onboarding fields are rejected' );

	$result = $service->create_tenant(
		array(
			'legal_name' => 'PharmaSure Integration Pharmacy',
			'trading_name' => 'Integration Pharmacy',
			'slug' => 'tenant-test-' . $token,
			'owner_email' => $owner_email,
			'country' => 'ZW',
			'currency' => 'USD',
			'timezone' => 'Africa/Harare',
			'address' => '1 Test Avenue',
			'phone' => '+263000000000',
		)
	);
	$assert( ! is_wp_error( $result ), 'tenant onboarding completes' );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}

	$tenant_id = (int) $result['id'];
	$owner_id = is_wp_error( $result['owner_id'] ) ? 0 : (int) $result['owner_id'];
	$tenant = $service->get_tenant( $tenant_id );
	$assert( 'PharmaSure Integration Pharmacy' === $tenant['name'], 'tenant uses the migrated name column' );
	$assert( $owner_email === $tenant['primary_contact_email'], 'primary contact email is stored' );

	$branch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$prefix}branches WHERE tenant_id = %d", $tenant_id ), ARRAY_A );
	$branch_id = (int) ( $branch['id'] ?? 0 );
	$assert( $branch_id > 0 && 'MAIN' === $branch['code'], 'default main branch is created' );

	$membership = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$prefix}tenant_memberships WHERE tenant_id = %d AND user_id = %d", $tenant_id, $owner_id ), ARRAY_A );
	$assert( $owner_id > 0 && 1 === (int) ( $membership['is_active'] ?? 0 ), 'owner user and active membership are created' );

	$list = $service->list_tenants( array( 'search' => 'Integration Pharmacy' ) );
	$assert( 1 === (int) $list['total'], 'tenant listing searches migrated name fields' );

	$updated = $service->update_tenant( $tenant_id, array( 'legal_name' => 'Updated Integration Pharmacy', 'phone' => '+263111111111' ) );
	$assert( 'Updated Integration Pharmacy' === $updated['name'] && '+263111111111' === $updated['primary_contact_phone'], 'tenant update maps API fields to schema columns' );
} catch ( Throwable $error ) {
	++$fail;
	echo 'FAIL: unexpected exception: ' . $error->getMessage() . "\n";
} finally {
	if ( $tenant_id ) {
		$wpdb->delete( $prefix . 'tenant_memberships', array( 'tenant_id' => $tenant_id ), array( '%d' ) );
		$wpdb->delete( $prefix . 'branches', array( 'tenant_id' => $tenant_id ), array( '%d' ) );
		$wpdb->delete( $prefix . 'audit_events', array( 'tenant_id' => $tenant_id ), array( '%d' ) );
		$wpdb->delete( $prefix . 'tenants', array( 'id' => $tenant_id ), array( '%d' ) );
	}
	if ( $owner_id ) {
		wp_delete_user( $owner_id );
	}
}

echo "Tenancy tests: {$pass} passed, {$fail} failed.\n";
if ( $fail ) {
	throw new RuntimeException( 'Tenancy integration tests failed.' );
}
