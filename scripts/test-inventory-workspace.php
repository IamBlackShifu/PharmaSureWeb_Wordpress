<?php
/** Inventory workspace render regression test. Run with wp eval-file on a seeded tenant site. */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

use PharmaSure\Core\TenantContext;
use PharmaSure\Inventory\Admin\InventoryAdmin;

$pass = 0;
$fail = 0;
$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) {
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$condition ? ++$pass : ++$fail;
};

$owner = get_user_by( 'login', 'owner' );
$assert( $owner instanceof WP_User, 'seeded inventory owner is available' );
if ( $owner ) {
	wp_set_current_user( $owner->ID );
}

// WP-CLI can initialize the singleton before --user is applied. Reset only in
// this test process so scope is resolved exactly as it is on an HTTP request.
$context_property = new ReflectionProperty( TenantContext::class, 'instance' );
$context_property->setValue( null, null );
$context = TenantContext::instance();
$assert( (int) $context->get_tenant_id() > 0, 'tenant scope resolves from the current site' );
$assert( (int) $context->get_branch_id() > 0, 'branch scope resolves from the authenticated user' );
$assert( current_user_can( 'pharmasure_view_inventory' ), 'inventory capability is enforced' );

$views = array( 'catalogue', 'batches', 'receipts', 'movements', 'low-stock', 'expiry', 'suppliers' );
foreach ( $views as $view ) {
	$_GET['page'] = 'pharmasure-inventory';
	$_GET['view'] = $view;
	ob_start();
	InventoryAdmin::render_workspace();
	$html = ob_get_clean();
	$assert( str_contains( $html, 'ps-inventory-workspace' ), "$view renders inside the clinical inventory shell" );
	$assert( str_contains( $html, 'Context inspector' ), "$view renders its contextual inspector" );
}

$admin_source = file_get_contents( WP_PLUGIN_DIR . '/pharmasure-inventory/src/Admin/InventoryAdmin.php' );
$assert( ! str_contains( $admin_source, "\$_GET['tenant_id']" ), 'workspace never accepts tenant scope from the request' );
$assert( str_contains( $admin_source, 'tabindex="0"' ) && str_contains( $admin_source, 'aria-current="page"' ), 'tables and section navigation expose keyboard context' );

echo "Inventory workspace tests: {$pass} passed, {$fail} failed.\n";
if ( $fail ) { throw new RuntimeException( 'Inventory workspace tests failed.' ); }
