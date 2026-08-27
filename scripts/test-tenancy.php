<?php
/**
 * Full tenant provisioning and isolation integration test. Run with:
 * wp eval-file scripts/test-tenancy.php --url=http://localhost:8080 --allow-root
 */

use PharmaSure\Tenancy\Services\TenantService;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

global $wpdb;
$base = $wpdb->base_prefix . 'ps_';
$pass = 0; $fail = 0; $tenant_id = 0; $site_id = 0; $owner_id = 0;
$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) {
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$condition ? ++$pass : ++$fail;
};

wp_set_current_user( 1 );
$service = new TenantService();
$token = strtolower( wp_generate_password( 8, false, false ) );
$slug = 'tenant-readiness-' . $token;
$owner_email = 'owner-' . $token . '@readiness.test';

try {
	$missing = $service->create_tenant( array() );
	$assert( is_wp_error( $missing ) && 'missing_fields' === $missing->get_error_code(), 'incomplete tenant onboarding fails closed' );

	$result = $service->create_tenant(
		array(
			'legal_name' => 'PharmaSure Readiness Pharmacy (Private) Limited',
			'trading_name' => 'Readiness Pharmacy',
			'slug' => $slug,
			'owner_name' => 'Readiness Owner',
			'owner_email' => $owner_email,
			'branch_name' => 'Central Branch',
			'branch_code' => 'CENTRAL',
			'country' => 'ZW',
			'currency' => 'USD',
			'timezone' => 'Africa/Harare',
			'address' => '1 Evidence Avenue',
			'city' => 'Harare',
			'phone' => '+263000000000',
		)
	);
	$assert( ! is_wp_error( $result ), 'super administrator can provision a complete isolated pharmacy' );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }

	$tenant_id = (int) $result['id']; $site_id = (int) $result['site_id']; $owner_id = (int) $result['owner_id'];
	$assert( $tenant_id > 0 && $site_id > 1 && $owner_id > 0, 'provisioning returns tenant, site and owner identities' );
	$assert( 'trial' === $result['status'] && 'Enterprise trial' === $result['plan'], 'new tenant receives the defined enterprise trial' );
	$assert( wp_check_password( $result['temporary_password'], get_userdata( $owner_id )->user_pass, $owner_id ), 'one-time owner credential is valid' );

	$tenant = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$base}tenants WHERE id=%d", $tenant_id ), ARRAY_A );
	$branch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$base}branches WHERE tenant_id=%d", $tenant_id ), ARRAY_A );
	$membership = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$base}tenant_memberships WHERE tenant_id=%d AND user_id=%d", $tenant_id, $owner_id ), ARRAY_A );
	$mapping = (int) $wpdb->get_var( $wpdb->prepare( "SELECT site_id FROM {$base}tenant_site_mapping WHERE tenant_id=%d", $tenant_id ) );
	$licence = $wpdb->get_row( $wpdb->prepare( "SELECT id,status,expires_at FROM {$base}licences WHERE tenant_id=%d ORDER BY id DESC LIMIT 1", $tenant_id ), ARRAY_A );
	$assert( $tenant && 'Readiness Pharmacy' === $tenant['trading_name'] && 'trial' === $tenant['status'], 'network tenant directory stores the pharmacy identity and trial posture' );
	$assert( $branch && 'CENTRAL' === $branch['code'] && 1 === (int) $branch['is_default'], 'default operational branch is created' );
	$assert( $membership && 'owner' === $membership['role'] && 1 === (int) $membership['is_admin'], 'owner receives tenant-bound all-branch authority' );
	$assert( $mapping === $site_id && get_site( $site_id ), 'tenant-to-site mapping resolves to a live multisite site' );
	$assert( $licence && 'trial' === $licence['status'], 'tenant licence record is active as a trial' );
	$entitlements = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$base}licence_entitlements WHERE licence_id=%d AND is_active=1", (int) $licence['id'] ) );
	$quotas = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$base}licence_quotas WHERE licence_id=%d", (int) $licence['id'] ) );
	$assert( $entitlements >= 8 && $quotas >= 9, 'enterprise trial includes operational entitlements and quotas' );

	$sites = get_blogs_of_user( $owner_id, true );
	$assert( 1 === count( $sites ) && isset( $sites[ $site_id ] ), 'owner belongs only to the newly provisioned pharmacy site' );
	$assert( (int) get_user_meta( $owner_id, 'primary_blog', true ) === $site_id, 'owner primary site is the isolated tenant site' );

	switch_to_blog( $site_id );
	try {
		$site_prefix = $wpdb->prefix . 'ps_';
		$site_tenant = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$site_prefix}tenants WHERE id=%d", $tenant_id ) );
		$site_owner = $wpdb->get_row( $wpdb->prepare( "SELECT role,is_admin FROM {$site_prefix}tenant_memberships WHERE tenant_id=%d AND user_id=%d", $tenant_id, $owner_id ), ARRAY_A );
		$required_tables = array( 'drugs', 'sales', 'prescriptions', 'claims', 'report_schedules', 'offline_devices' );
		$missing_tables = array_filter( $required_tables, static fn( $table ) => $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', $site_prefix . $table ) ) !== $site_prefix . $table );
		$assert( $site_tenant === $tenant_id && $site_owner && 'owner' === $site_owner['role'], 'tenant identity and owner membership are mirrored into the site schema' );
		$assert( ! $missing_tables, 'inventory, POS, clinical, claims, reporting and offline schemas are installed' );
		$user = new WP_User( $owner_id );
		$assert( in_array( 'pharmasure_owner', $user->roles, true ) && user_can( $owner_id, 'pharmasure_view_inventory' ), 'owner has the application role and workspace capability' );
	} finally { restore_current_blog(); }

	$duplicate_email = $service->create_tenant( array( 'legal_name' => 'Blocked', 'trading_name' => 'Blocked', 'slug' => $slug . '-other', 'owner_name' => 'Blocked', 'owner_email' => $owner_email, 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'country' => 'ZW', 'currency' => 'USD', 'timezone' => 'Africa/Harare' ) );
	$assert( is_wp_error( $duplicate_email ) && 'owner_identity_exists' === $duplicate_email->get_error_code(), 'an owner identity cannot be reused across pharmacy tenants' );
	$duplicate_slug = $service->create_tenant( array( 'legal_name' => 'Blocked', 'trading_name' => 'Blocked', 'slug' => $slug, 'owner_name' => 'Other', 'owner_email' => 'other-' . $owner_email, 'branch_name' => 'Main', 'branch_code' => 'MAIN', 'country' => 'ZW', 'currency' => 'USD', 'timezone' => 'Africa/Harare' ) );
	$assert( is_wp_error( $duplicate_slug ) && 'slug_exists' === $duplicate_slug->get_error_code(), 'duplicate tenant URL slugs are rejected before mutation' );
} catch ( Throwable $error ) {
	++$fail; echo 'FAIL: unexpected exception: ' . $error->getMessage() . "\n";
} finally {
	if ( $site_id && get_site( $site_id ) ) { require_once ABSPATH . 'wp-admin/includes/ms.php'; wpmu_delete_blog( $site_id, true ); }
	if ( $tenant_id ) {
		$licence_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$base}licences WHERE tenant_id=%d", $tenant_id ) );
		foreach ( $licence_ids as $licence_id ) { $wpdb->delete( $base . 'licence_entitlements', array( 'licence_id' => (int) $licence_id ) ); $wpdb->delete( $base . 'licence_quotas', array( 'licence_id' => (int) $licence_id ) ); }
		foreach ( array( 'licences', 'tenant_site_mapping', 'membership_branches', 'tenant_memberships', 'branches', 'audit_events' ) as $table ) {
			if ( 'membership_branches' === $table ) { continue; }
			$wpdb->delete( $base . $table, array( 'tenant_id' => $tenant_id ) );
		}
		$wpdb->delete( $base . 'tenants', array( 'id' => $tenant_id ) );
	}
	if ( $owner_id && get_userdata( $owner_id ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $owner_id ); }
}

echo "Tenant provisioning tests: {$pass} passed, {$fail} failed.\n";
if ( $fail ) { throw new RuntimeException( 'Tenant provisioning tests failed.' ); }
