#!/usr/bin/env php
<?php
/**
 * PharmaSure licensing integration test.
 *
 * Run from the WordPress root: php scripts/test-license.php
 */

if ( file_exists( __DIR__ . '/../wp-load.php' ) ) {
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
$prefix = $wpdb->prefix . 'ps_';
$pass = 0;
$fail = 0;
$ids = array();
$previous_private_key = get_option( 'pharmasure_license_signing_key_private', null );

$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) {
	if ( $condition ) {
		++$pass;
		echo "PASS: {$message}\n";
	} else {
		++$fail;
		echo "FAIL: {$message}\n";
	}
};

echo "PharmaSure licensing integration tests\n";

try {
	foreach ( array( 'licences', 'licence_entitlements', 'licence_quotas', 'licence_signing_keys', 'token_revocation_list' ) as $table ) {
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prefix . $table ) );
		$assert( $prefix . $table === $exists, "{$table} table exists" );
	}

	$key_resource = openssl_pkey_new(
		array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		)
	);
	$assert( false !== $key_resource, 'RSA signing key can be generated' );
	if ( false === $key_resource ) {
		throw new RuntimeException( 'Unable to generate RSA test key' );
	}

	openssl_pkey_export( $key_resource, $private_key );
	$key_details = openssl_pkey_get_details( $key_resource );
	$public_key = $key_details['key'] ?? '';
	$assert( str_contains( $public_key, 'BEGIN PUBLIC KEY' ), 'generated public key is valid PEM' );

	$token = wp_generate_uuid4();
	$wpdb->insert(
		$prefix . 'tenants',
		array(
			'name' => 'Licence Test Tenant',
			'slug' => 'licence-test-' . $token,
			'primary_contact_email' => 'licence-' . $token . '@example.test',
			'status' => 'active',
		)
	);
	$ids['tenant'] = (int) $wpdb->insert_id;

	$wpdb->insert( $prefix . 'products', array( 'sku' => 'TEST-' . $token, 'name' => 'Test Product' ) );
	$ids['product'] = (int) $wpdb->insert_id;
	$wpdb->insert( $prefix . 'plans', array( 'product_id' => $ids['product'], 'name' => 'Test Plan', 'plan_tier' => 'test' ) );
	$ids['plan'] = (int) $wpdb->insert_id;
	$wpdb->insert(
		$prefix . 'licences',
		array(
			'tenant_id' => $ids['tenant'],
			'plan_id' => $ids['plan'],
			'status' => 'active',
			'licence_key' => 'LIC-' . $token,
			'activated_at' => current_time( 'mysql', true ),
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( 30 * DAY_IN_SECONDS ) ),
		)
	);
	$ids['licence'] = (int) $wpdb->insert_id;
	$wpdb->insert( $prefix . 'licence_entitlements', array( 'licence_id' => $ids['licence'], 'entitlement_key' => 'inventory', 'is_active' => 1 ) );
	$ids['entitlement'] = (int) $wpdb->insert_id;
	$wpdb->insert( $prefix . 'licence_quotas', array( 'licence_id' => $ids['licence'], 'quota_key' => 'branches', 'limit_value' => 3 ) );
	$ids['quota'] = (int) $wpdb->insert_id;
	$wpdb->insert( $prefix . 'licence_signing_keys', array( 'key_id' => 'pharmasure-v1', 'public_key' => $public_key, 'is_active' => 1 ) );
	$ids['signing_key'] = (int) $wpdb->insert_id;
	update_option( 'pharmasure_license_signing_key_private', $private_key, false );

	$assert( $ids['tenant'] > 0 && $ids['licence'] > 0 && $ids['signing_key'] > 0, 'licensing fixtures are created' );

	$manager = new \PharmaSure\Core\LicenseManager( $ids['tenant'] );
	$licence = $manager->validate_license();
	$assert( ! is_wp_error( $licence ), 'database licence validation succeeds' );
	$assert( in_array( 'inventory', $licence['entitlements'] ?? array(), true ), 'entitlement is loaded from the migrated schema' );
	$assert( 3 === ( $licence['quotas']['branches'] ?? null ), 'quota is loaded from the migrated schema' );

	$jwt = $manager->issue_offline_token( 'integration-device', 1 );
	$assert( is_string( $jwt ) && 3 === count( explode( '.', $jwt ) ), 'RS256 offline token is issued' );
	$offline = is_string( $jwt ) ? $manager->validate_license( $jwt ) : null;
	$assert( is_array( $offline ) && 'offline_token' === ( $offline['source'] ?? '' ), 'offline token signature and claims validate' );
	$tampered_parts = is_string( $jwt ) ? explode( '.', $jwt ) : array();
	if ( 3 === count( $tampered_parts ) && '' !== $tampered_parts[2] ) {
		$tampered_parts[2][0] = 'A' === $tampered_parts[2][0] ? 'B' : 'A';
	}
	$tampered = implode( '.', $tampered_parts );
	$tampered_result = $manager->validate_license( $tampered );
	$assert( is_wp_error( $tampered_result ) && 'invalid_signature' === $tampered_result->get_error_code(), 'an explicitly supplied token with a bad signature fails closed' );
	$entitled = $manager->enforce_entitlement( 'inventory' );
	$assert( is_array( $entitled ), 'entitlement enforcement permits a licensed feature' );
	$denied = $manager->enforce_entitlement( 'not-in-plan' );
	$assert( is_wp_error( $denied ) && 'entitlement_required' === $denied->get_error_code(), 'entitlement enforcement fails closed for an unavailable feature' );

	$other_tenant = new \PharmaSure\Core\LicenseManager( $ids['tenant'] + 1000000 );
	$cross_tenant = is_string( $jwt ) ? $other_tenant->validate_license( $jwt ) : null;
	$assert( is_wp_error( $cross_tenant ), 'offline token cannot authorize another tenant' );

	$service = new \PharmaSure\Licensing\Services\LicenseService();
	$assert( $service->has_entitlement( $ids['tenant'], 'inventory' ), 'licensing plugin reads entitlements using the shared schema' );
	$quota = $service->check_quota( $ids['tenant'], 'branches' );
	$assert( 3 === $quota['limit'] && 0 === $quota['used'] && $quota['available'], 'licensing plugin reads quota and usage using the shared schema' );
} catch ( Throwable $error ) {
	++$fail;
	echo 'FAIL: unexpected exception: ' . $error->getMessage() . "\n";
} finally {
	if ( isset( $ids['tenant'] ) ) {
		$wpdb->delete( $prefix . 'audit_events', array( 'tenant_id' => $ids['tenant'] ), array( '%d' ) );
	}
	foreach ( array( 'signing_key' => 'licence_signing_keys', 'quota' => 'licence_quotas', 'entitlement' => 'licence_entitlements', 'licence' => 'licences', 'plan' => 'plans', 'product' => 'products', 'tenant' => 'tenants' ) as $id_key => $table ) {
		if ( ! empty( $ids[ $id_key ] ) ) {
			$wpdb->delete( $prefix . $table, array( 'id' => $ids[ $id_key ] ), array( '%d' ) );
		}
	}

	if ( null === $previous_private_key ) {
		delete_option( 'pharmasure_license_signing_key_private' );
	} else {
		update_option( 'pharmasure_license_signing_key_private', $previous_private_key, false );
	}
}

echo "Licensing tests: {$pass} passed, {$fail} failed.\n";
exit( $fail > 0 ? 1 : 0 );
