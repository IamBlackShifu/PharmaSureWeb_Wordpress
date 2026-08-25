<?php
/** Branch membership authorization integration test. Run with wp eval-file. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

global $wpdb;
$p = $wpdb->prefix . 'ps_';
$ids = array();
$pass = 0;
$fail = 0;
$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) {
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$condition ? ++$pass : ++$fail;
};
$reset_context = static function () {
	$property = new ReflectionProperty( \PharmaSure\Core\TenantContext::class, 'instance' );
	$property->setAccessible( true );
	$property->setValue( null, null );
};

try {
	$user_id = wp_create_user( 'branch-test-' . wp_generate_uuid4(), wp_generate_password( 24 ), 'branch-test-' . wp_generate_uuid4() . '@example.test' );
	if ( is_wp_error( $user_id ) ) { throw new RuntimeException( $user_id->get_error_message() ); }
	$ids['user'] = (int) $user_id;

	foreach ( array( 'alpha', 'beta' ) as $key ) {
		$token = 'branch-access-' . $key . '-' . wp_generate_uuid4();
		$wpdb->insert( $p . 'tenants', array( 'name' => "Branch access {$key}", 'slug' => $token, 'primary_contact_email' => $token . '@example.test', 'status' => 'active' ) );
		$ids[ "tenant_{$key}" ] = (int) $wpdb->insert_id;
		$wpdb->insert( $p . 'branches', array( 'tenant_id' => $ids[ "tenant_{$key}" ], 'name' => 'Main', 'code' => strtoupper( $key ), 'is_active' => 1 ) );
		$ids[ "branch_{$key}" ] = (int) $wpdb->insert_id;
	}
	$wpdb->insert( $p . 'branches', array( 'tenant_id' => $ids['tenant_alpha'], 'name' => 'Unassigned', 'code' => 'UNASSIGNED', 'is_active' => 1 ) );
	$ids['branch_unassigned'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'branches', array( 'tenant_id' => $ids['tenant_alpha'], 'name' => 'Inactive', 'code' => 'INACTIVE', 'is_active' => 0 ) );
	$ids['branch_inactive'] = (int) $wpdb->insert_id;

	$wpdb->insert( $p . 'tenant_memberships', array( 'user_id' => $ids['user'], 'tenant_id' => $ids['tenant_alpha'], 'role' => 'cashier', 'is_admin' => 0, 'is_active' => 1 ) );
	$ids['membership'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'membership_branches', array( 'membership_id' => $ids['membership'], 'branch_id' => $ids['branch_alpha'] ) );
	$ids['assignment'] = (int) $wpdb->insert_id;

	// Build the singleton under the WP-CLI administrator, then explicitly set
	// its request identity to the fixture user. This avoids depending on the
	// current Multisite blog-to-tenant mapping in an isolated fixture test.
	wp_set_current_user( 1 );
	$reset_context();
	$context = \PharmaSure\Core\TenantContext::instance();
	wp_set_current_user( $ids['user'] );
	$user_property = new ReflectionProperty( $context, 'user_id' );
	$user_property->setAccessible( true );
	$user_property->setValue( $context, $ids['user'] );
	$tenant_property = new ReflectionProperty( $context, 'tenant_id' );
	$tenant_property->setAccessible( true );
	$tenant_property->setValue( $context, $ids['tenant_alpha'] );
	$assert( $context->can_access_tenant( $ids['tenant_alpha'] ), 'active membership grants tenant access' );
	$assert( ! $context->can_access_tenant( $ids['tenant_beta'] ), 'another tenant is denied' );
	$assert( $context->can_access_branch( $ids['branch_alpha'] ), 'explicit branch assignment grants access' );
	$assert( ! $context->can_access_branch( $ids['branch_unassigned'] ), 'unassigned branch in the same tenant is denied' );
	$assert( ! $context->can_access_branch( $ids['branch_beta'] ), 'branch in another tenant is denied' );
	$assert( ! $context->can_access_branch( $ids['branch_inactive'] ), 'inactive branch is denied' );
	$assert( ! $context->set_branch( $ids['branch_unassigned'] ), 'unauthorized branch cannot become active context' );
	$assert( $context->set_branch( $ids['branch_alpha'] ), 'authorized branch becomes active context' );
} catch ( Throwable $error ) {
	++$fail;
	echo 'FAIL: unexpected exception: ' . $error->getMessage() . "\n";
} finally {
	wp_set_current_user( 0 );
	$reset_context();
	foreach ( array( 'assignment' => 'membership_branches', 'membership' => 'tenant_memberships', 'branch_alpha' => 'branches', 'branch_beta' => 'branches', 'branch_unassigned' => 'branches', 'branch_inactive' => 'branches', 'tenant_alpha' => 'tenants', 'tenant_beta' => 'tenants' ) as $key => $table ) {
		if ( ! empty( $ids[ $key ] ) ) { $wpdb->delete( $p . $table, array( 'id' => $ids[ $key ] ), array( '%d' ) ); }
	}
	if ( ! empty( $ids['user'] ) ) {
		if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
		wp_delete_user( $ids['user'] );
	}
}

echo "Branch authorization tests: {$pass} passed, {$fail} failed.\n";
if ( $fail ) { throw new RuntimeException( 'Branch authorization tests failed.' ); }
