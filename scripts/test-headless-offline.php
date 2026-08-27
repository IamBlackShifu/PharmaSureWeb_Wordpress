<?php
/** Headless Offline workspace and tenant-isolation regression. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

use PharmaSure\Core\TenantContext;
use PharmaSure\Offline\Services\DeviceService;
use PharmaSure\Offline\Services\MutationService;

$pass = 0; $fail = 0; $created = array();
$assert = static function ( $ok, $message ) use ( &$pass, &$fail ) { echo ( $ok ? 'PASS: ' : 'FAIL: ' ) . $message . "\n"; $ok ? ++$pass : ++$fail; };
global $wpdb; $p = $wpdb->prefix . 'ps_';
try {
	$owner = get_user_by( 'email', 'owner@greenlife.demo' );
	$assert( (bool) $owner, 'GreenLife owner fixture is available' );
	wp_set_current_user( (int) $owner->ID );
	$property = new ReflectionProperty( TenantContext::class, 'instance' ); $property->setValue( null, null );
	$context = TenantContext::instance(); $tenant = (int) $context->get_tenant_id(); $branch = (int) $context->get_branch_id();
	$assert( $tenant > 0 && $branch > 0, 'tenant and branch resolve from the authenticated session' );

	$devices = new DeviceService();
	$device_result = $devices->register( $tenant, $branch, (int) $owner->ID, array( 'device_name' => 'Headless Offline Test', 'expires_in_days' => 1 ) );
	$assert( ! is_wp_error( $device_result ) && ! empty( $device_result['client_secret'] ), 'licensed tenant can register a branch-scoped offline device' );
	if ( is_wp_error( $device_result ) ) { throw new RuntimeException( $device_result->get_error_code() . ': ' . $device_result->get_error_message() . ' / ' . $wpdb->last_error ); }
	$created['device'] = (int) ( $device_result['id'] ?? 0 );
	$device = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}offline_devices WHERE tenant_id=%d AND branch_id=%d AND id=%d", $tenant, $branch, $created['device'] ), ARRAY_A );
	$mutation_result = ( new MutationService() )->receive( $device, array( 'client_mutation_id' => 'headless-' . wp_generate_uuid4(), 'mutation_type' => 'pos.checkout', 'base_version' => 'fixture-v1', 'payload' => array( 'items' => array( array( 'drug_id' => 1, 'quantity' => 1 ) ), 'idempotency_key' => wp_generate_uuid4() ) ) );
	$created['mutation'] = (int) ( $mutation_result['id'] ?? 0 );
	$assert( $created['mutation'] > 0 && 'requires_online_replay' === ( $mutation_result['status'] ?? '' ), 'offline mutation enters the authoritative replay queue' );

	$other_tenant = $tenant + 999999; $other_payload = wp_json_encode( array( 'items' => array( array( 'drug_id' => 1, 'quantity' => 1 ) ) ) );
	$wpdb->insert( $p . 'offline_devices', array( 'tenant_id' => $other_tenant, 'branch_id' => $branch, 'user_id' => (int) $owner->ID, 'client_id' => 'other_' . wp_generate_password( 20, false, false ), 'device_name' => 'Other Tenant Offline Test', 'encrypted_secret' => 'isolated-fixture', 'secret_fingerprint' => '0000000000000000', 'status' => 'active', 'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 day' ) ), 'created_at' => current_time( 'mysql', true ) ) ); $created['other_device'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'offline_mutations', array( 'tenant_id' => $other_tenant, 'branch_id' => $branch, 'device_id' => $created['other_device'], 'user_id' => (int) $owner->ID, 'client_mutation_id' => 'other-' . wp_generate_uuid4(), 'mutation_type' => 'pos.checkout', 'payload' => $other_payload, 'payload_hash' => hash( 'sha256', $other_payload ), 'status' => 'requires_online_replay', 'received_at' => current_time( 'mysql', true ) ) ); $created['other_mutation'] = (int) $wpdb->insert_id;

	do_action( 'rest_api_init' ); $routes = rest_get_server()->get_routes();
	$assert( isset( $routes['/pharmasure/v1/offline/workspace'], $routes['/pharmasure/v1/offline/mutations/(?P<id>\d+)/discard'], $routes['/pharmasure/v1/offline/security-alerts/(?P<id>\d+)/resolve'] ), 'headless Offline workspace and guarded manager routes are registered' );
	$response = rest_do_request( new WP_REST_Request( 'GET', '/pharmasure/v1/offline/workspace' ) ); $data = $response->get_data();
	$assert( 200 === $response->get_status() && isset( $data['metrics'], $data['mutations'], $data['devices'], $data['security_alerts'], $data['scope'] ), 'licensed owner can load the complete Offline workspace' );
	$assert( ! isset( $data['tenant_id'], $data['branch_id'] ) && ! str_contains( wp_json_encode( $data['devices'] ), 'encrypted_secret' ) && ! str_contains( wp_json_encode( $data['devices'] ), $device_result['client_secret'] ), 'workspace exposes no tenant identifiers or recoverable device secrets' );
	$visible_mutations = array_map( 'intval', array_column( $data['mutations'], 'id' ) );
	$assert( in_array( $created['mutation'], $visible_mutations, true ) && ! in_array( $created['other_mutation'], $visible_mutations, true ), 'workspace queue includes this branch and excludes another tenant' );

	$detail = rest_do_request( new WP_REST_Request( 'GET', '/pharmasure/v1/offline/mutations/' . $created['mutation'] ) ); $detail_data = $detail->get_data();
	$assert( 200 === $detail->get_status() && isset( $detail_data['data']['payload'] ) && ! isset( $detail_data['data']['tenant_id'], $detail_data['data']['payload_hash'] ), 'mutation inspector returns decoded payload without tenant or integrity internals' );
	$blocked = rest_do_request( new WP_REST_Request( 'GET', '/pharmasure/v1/offline/mutations/' . $created['other_mutation'] ) );
	$assert( 404 === $blocked->get_status(), 'another tenant mutation is indistinguishable from a missing record' );

	$client = file_get_contents( WP_PLUGIN_DIR . '/pharmasure-core/assets/js/app.js' ); $css = file_get_contents( WP_PLUGIN_DIR . '/pharmasure-core/assets/css/crisp-theme.css' );
	$assert( str_contains( $client, 'renderOffline' ) && str_contains( $client, 'openOfflineReasonDialog' ) && str_contains( $client, 'showOfflineCredential' ), 'headless Offline UI includes queue actions and one-time device credentials' );
	$assert( str_contains( $css, '.ps-offline-grid' ) && str_contains( $css, '.ps-offline-status.is-conflict' ), 'Offline workspace uses the clinical design system and explicit conflict states' );
} catch ( Throwable $error ) {
	++$fail; echo 'FAIL: unexpected exception: ' . $error->getMessage() . "\n";
} finally {
	foreach ( array( 'device', 'other_device' ) as $key ) { if ( ! empty( $created[ $key ] ) ) { $wpdb->delete( $p . 'offline_nonces', array( 'device_id' => $created[ $key ] ) ); } }
	foreach ( array( 'mutation', 'other_mutation' ) as $key ) { if ( ! empty( $created[ $key ] ) ) { $wpdb->delete( $p . 'offline_mutations', array( 'id' => $created[ $key ] ) ); } }
	foreach ( array( 'device', 'other_device' ) as $key ) { if ( ! empty( $created[ $key ] ) ) { $wpdb->delete( $p . 'offline_devices', array( 'id' => $created[ $key ] ) ); } }
}
echo "Headless Offline tests: {$pass} passed, {$fail} failed.\n"; if ( $fail ) { throw new RuntimeException( 'Headless Offline tests failed.' ); }
