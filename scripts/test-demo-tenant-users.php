<?php
/** Verify that demo tenant owners are isolated to their assigned sites. */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

$cases = array(
	'owner@greenlife.demo' => array( 'allowed' => '/greenlife-pharmacy/', 'denied' => '/sunrise-pharmacy/', 'tenant' => 101 ),
	'owner@sunrise.demo' => array( 'allowed' => '/sunrise-pharmacy/', 'denied' => '/greenlife-pharmacy/', 'tenant' => 102 ),
);
$pass = 0;
$fail = 0;
$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) {
	if ( $condition ) { ++$pass; echo "PASS: {$message}\n"; }
	else { ++$fail; echo "FAIL: {$message}\n"; }
};

global $wpdb;
foreach ( $cases as $email => $case ) {
	$user = get_user_by( 'email', $email );
	$assert( $user instanceof WP_User, "{$email} exists" );
	if ( ! $user ) { continue; }
	$paths = array_map( static fn( $blog ) => $blog->path, get_blogs_of_user( $user->ID, true ) );
	$assert( in_array( $case['allowed'], $paths, true ), "{$email} belongs to its tenant site" );
	$assert( ! in_array( $case['denied'], $paths, true ), "{$email} cannot access the other tenant site" );
	$assert( 1 === count( $paths ), "{$email} has exactly one site assignment" );
	$site = get_sites( array( 'path' => $case['allowed'], 'number' => 1 ) );
	$site = $site ? reset( $site ) : null;
	$assert( $site && (int) get_user_meta( $user->ID, 'primary_blog', true ) === (int) $site->blog_id, "{$email} has the correct primary site" );
	if ( $site ) {
		switch_to_blog( $site->blog_id );
		$membership = $wpdb->get_row( $wpdb->prepare( "SELECT tenant_id,role,is_admin,is_active FROM {$wpdb->prefix}ps_tenant_memberships WHERE user_id=%d", $user->ID ), ARRAY_A );
		$assert( $membership && (int) $membership['tenant_id'] === $case['tenant'] && 'owner' === $membership['role'] && 1 === (int) $membership['is_admin'] && 1 === (int) $membership['is_active'], "{$email} has one active owner membership" );
		restore_current_blog();
	}
}

echo "Demo tenant user isolation: {$pass} passed, {$fail} failed.\n";
if ( $fail ) { throw new RuntimeException( 'Demo tenant user isolation failed.' ); }
