<?php
/** Headless Inventory workspace regression test. Run on a seeded tenant site. */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

use PharmaSure\Core\TenantContext;
use PharmaSure\Inventory\Services\InventoryService;

$pass = 0;
$fail = 0;
$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) {
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$condition ? ++$pass : ++$fail;
};

$owner = get_user_by( 'login', 'owner' );
$assert( $owner instanceof WP_User, 'seeded tenant owner is available' );
if ( $owner ) { wp_set_current_user( $owner->ID ); }
$context_property = new ReflectionProperty( TenantContext::class, 'instance' );
$context_property->setValue( null, null );
$context = TenantContext::instance();
$tenant_id = (int) $context->get_tenant_id();
$branch_id = (int) $context->get_branch_id();
$service = new InventoryService();

$assert( $tenant_id > 0 && $branch_id > 0, 'tenant and branch scope resolve from authentication' );
$views = array( 'catalogue', 'batches', 'receipts', 'movements', 'low-stock', 'expiry', 'suppliers' );
foreach ( $views as $view ) {
	$result = $service->workspace( $tenant_id, $branch_id, $view );
	$assert( ! is_wp_error( $result ) && $view === $result['view'], "$view read model resolves" );
	$assert( isset( $result['scope']['trading_name'], $result['scope']['branch_name'], $result['summary'], $result['rows'], $result['facts'] ), "$view contains scope, summary, rows and inspector facts" );
	$assert( ! isset( $result['tenant_id'] ) && ! isset( $result['scope']['tenant_id'] ), "$view does not expose tenant identifiers" );
}

$invalid = $service->workspace( $tenant_id, 999999, 'catalogue' );
$assert( is_wp_error( $invalid ) && 'invalid_scope' === $invalid->get_error_code(), 'unauthorized branch scope fails closed' );
$fallback = $service->workspace( $tenant_id, $branch_id, 'not-a-view' );
$assert( ! is_wp_error( $fallback ) && 'catalogue' === $fallback['view'], 'service defaults unknown views to catalogue' );
$search = $service->workspace( $tenant_id, $branch_id, 'catalogue', "%' OR 1=1 --" );
$assert( ! is_wp_error( $search ) && 0 === count( $search['rows'] ), 'catalogue search remains prepared and does not alter tenant filters' );

$routes = rest_get_server()->get_routes();
$assert( isset( $routes['/pharmasure/v1/inventory/workspace'] ), 'headless inventory REST endpoint is registered' );
$controller = file_get_contents( WP_PLUGIN_DIR . '/pharmasure-inventory/src/Rest/InventoryController.php' );
$client = file_get_contents( PHARMASURE_CORE_PATH . 'assets/js/app.js' );
$styles = file_get_contents( PHARMASURE_CORE_PATH . 'assets/css/crisp-theme.css' );
$assert( ! str_contains( $controller, "\$_GET['tenant_id']" ) && ! str_contains( $controller, "get_param( 'tenant_id'" ), 'REST controller never accepts client tenant scope' );
$assert( str_contains( $client, 'renderInventory' ) && str_contains( $client, 'inventory/workspace?' ) && str_contains( $client, 'ps-inventory-tabs' ), '/app/inventory mounts the seven-view client workspace' );
$assert( ! str_contains( $client, 'innerHTML' ), 'inventory rows render through safe DOM APIs' );
$assert( str_contains( $client, 'inventoryCommandBar' ) && str_contains( $client, "['receipt', 'Receive stock'" ) && str_contains( $client, "lineEditor('receipt'" ), 'headless inventory exposes multi-line receiving and command workflows' );
$assert( str_contains( $client, "openInventoryWorkflow('batch'" ) && str_contains( $client, 'collectWorkflowLines' ) && str_contains( $client, 'role: \'status\'' ), 'batch, transfer and asynchronous completion controls are keyboard-native' );
$assert( str_contains( $styles, '.ps-workflow-dialog' ) && str_contains( $styles, '.ps-inventory-command-bar' ) && str_contains( $styles, '@media(max-width:520px)' ), 'inventory command UI has dense theme-aware responsive styling' );
$assert( str_contains( $controller, "enforce_entitlement( 'inventory'" ) && str_contains( $controller, 'inventory_writes_monthly' ), 'inventory entry points enforce entitlement and plan write quotas' );

echo "Headless inventory tests: {$pass} passed, {$fail} failed.\n";
if ( $fail ) { throw new RuntimeException( 'Headless inventory tests failed.' ); }
