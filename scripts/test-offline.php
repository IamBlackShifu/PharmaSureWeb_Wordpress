<?php
/** Signed offline intake test. Run with wp eval-file scripts/test-offline.php. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
\PharmaSure\Offline\Installer::install();
global $wpdb; $p = $wpdb->prefix . 'ps_'; $ids = array(); $pass = 0; $fail = 0;
$assert = static function ( $ok, $message ) use ( &$pass, &$fail ) { echo ( $ok ? 'PASS: ' : 'FAIL: ' ) . $message . "\n"; $ok ? ++$pass : ++$fail; };
try {
	foreach ( array( 'offline_devices', 'offline_nonces', 'offline_mutations' ) as $table ) { $assert( $p . $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . $table ) ), "$table table exists" ); }
	$token = 'off-' . wp_generate_uuid4();
	$wpdb->insert( $p . 'tenants', array( 'name' => 'Offline Test', 'slug' => $token, 'primary_contact_email' => "$token@example.test", 'status' => 'active' ) ); $ids['tenant'] = (int) $wpdb->insert_id;
	$plan = (int) $wpdb->get_var( "SELECT id FROM {$p}plans WHERE is_active=1 ORDER BY id LIMIT 1" );
	$wpdb->insert( $p . 'licences', array( 'tenant_id' => $ids['tenant'], 'plan_id' => $plan, 'status' => 'active', 'licence_key' => 'OFF-' . wp_generate_password( 12, false, false ), 'activated_at' => current_time( 'mysql', true ), 'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 day' ) ) ) ); $ids['licence'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'licence_entitlements', array( 'licence_id' => $ids['licence'], 'entitlement_key' => 'offline', 'is_active' => 1 ) );
	$wpdb->insert( $p . 'licence_quotas', array( 'licence_id' => $ids['licence'], 'quota_key' => 'offline_devices', 'limit_value' => 2 ) );
	$wpdb->insert( $p . 'licence_quotas', array( 'licence_id' => $ids['licence'], 'quota_key' => 'offline_mutations_monthly', 'limit_value' => 10 ) );
	$wpdb->insert( $p . 'tenant_memberships', array( 'user_id' => 1, 'tenant_id' => $ids['tenant'], 'role' => 'admin', 'is_admin' => 1, 'is_active' => 1 ) ); $ids['membership'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'branches', array( 'tenant_id' => $ids['tenant'], 'name' => 'Main', 'code' => 'MAIN', 'is_active' => 1 ) ); $ids['branch'] = (int) $wpdb->insert_id;

	$devices = new \PharmaSure\Offline\Services\DeviceService();
	$created = $devices->register( $ids['tenant'], $ids['branch'], 1, array( 'device_name' => 'Till Tablet', 'expires_in_days' => 7 ) ); $ids['device'] = (int) ( $created['id'] ?? 0 );
	$assert( $ids['device'] > 0 && ! empty( $created['client_secret'] ), 'device returns a one-time client secret' );
	$stored = $wpdb->get_var( $wpdb->prepare( "SELECT encrypted_secret FROM {$p}offline_devices WHERE tenant_id=%d AND id=%d", $ids['tenant'], $ids['device'] ) );
	$assert( ! str_contains( $stored, $created['client_secret'] ), 'device secret is encrypted at rest' );

	$payload = array( 'client_mutation_id' => 'm-1', 'mutation_type' => 'pos.checkout', 'base_version' => 'stock-v1', 'payload' => array( 'items' => array( array( 'drug_id' => 1, 'quantity' => 1 ) ) ) );
	$body = wp_json_encode( $payload ); $timestamp = (string) time(); $nonce = 'nonce_' . bin2hex( random_bytes( 12 ) );
	$signature = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . hash( 'sha256', $body ), $created['client_secret'] );
	$device = $devices->authenticate( $created['client_id'], $timestamp, $nonce, $body, $signature );
	$assert( ! is_wp_error( $device ) && (int) $device['branch_id'] === $ids['branch'], 'valid signed device envelope authenticates in its branch' );
	$assert( is_wp_error( $devices->authenticate( $created['client_id'], $timestamp, $nonce, $body, $signature ) ), 'nonce replay is rejected' );

	$mutations = new \PharmaSure\Offline\Services\MutationService(); $mutation = $mutations->receive( $device, $payload ); $ids['mutation'] = (int) ( $mutation['id'] ?? 0 );
	$assert( 'requires_online_replay' === ( $mutation['status'] ?? '' ), 'offline sale is not falsely marked applied' );
	$replay = $mutations->receive( $device, $payload ); $assert( ! empty( $replay['idempotent_replay'] ) && (int) $replay['id'] === $ids['mutation'], 'identical offline mutation is deduplicated' );
	$changed = $payload; $changed['payload']['items'][0]['quantity'] = 2; $assert( is_wp_error( $mutations->receive( $device, $changed ) ), 'mutation ID reuse with changed content is a conflict' );
	$assert( 'revoked' === ( $devices->revoke( $ids['tenant'], $ids['device'], 1 )['status'] ?? '' ), 'device can be revoked' );
	$nonce2 = 'nonce_' . bin2hex( random_bytes( 12 ) ); $signature2 = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $nonce2 . '.' . hash( 'sha256', $body ), $created['client_secret'] );
	$assert( is_wp_error( $devices->authenticate( $created['client_id'], $timestamp, $nonce2, $body, $signature2 ) ), 'revoked device cannot authenticate' );
} catch ( Throwable $error ) { ++$fail; echo 'FAIL: unexpected exception: ' . $error->getMessage() . "\n"; }
finally {
	if ( ! empty( $ids['device'] ) ) { $wpdb->delete( $p . 'offline_nonces', array( 'device_id' => $ids['device'] ) ); }
	if ( ! empty( $ids['tenant'] ) ) { foreach ( array( 'audit_events', 'security_events', 'offline_mutations', 'offline_devices', 'tenant_memberships', 'branches' ) as $table ) { $wpdb->delete( $p . $table, array( 'tenant_id' => $ids['tenant'] ) ); } }
	if ( ! empty( $ids['licence'] ) ) { $wpdb->delete( $p . 'licence_quotas', array( 'licence_id' => $ids['licence'] ) ); $wpdb->delete( $p . 'licence_entitlements', array( 'licence_id' => $ids['licence'] ) ); $wpdb->delete( $p . 'licences', array( 'id' => $ids['licence'], 'tenant_id' => $ids['tenant'] ) ); }
	if ( ! empty( $ids['tenant'] ) ) { $wpdb->delete( $p . 'tenants', array( 'id' => $ids['tenant'] ) ); }
}
echo "Offline tests: $pass passed, $fail failed.\n"; if ( $fail ) { throw new RuntimeException( 'Offline tests failed.' ); }
