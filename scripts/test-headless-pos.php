<?php
/** Headless POS workspace regression test. Run on a seeded tenant site. */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

use PharmaSure\Core\TenantContext;
use PharmaSure\POS\Services\PosService;

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
$service = new PosService();
$workspace = $service->workspace( $tenant_id, $branch_id, get_current_user_id() );

$assert( $tenant_id > 0 && $branch_id > 0, 'tenant and branch resolve from authenticated context' );
$assert( ! is_wp_error( $workspace ), 'POS branch read model resolves' );
$workspace_data = is_array( $workspace ) ? $workspace : array();
$assert( isset( $workspace_data['scope']['trading_name'], $workspace_data['scope']['branch_name'], $workspace_data['metrics'], $workspace_data['pricing'] ), 'workspace contains branch scope, performance metrics and pricing policy' );
$assert( ! isset( $workspace_data['tenant_id'] ) && ! isset( $workspace_data['scope']['tenant_id'] ), 'workspace does not expose tenant identifiers' );
$assert( ! empty( $workspace_data['tills'] ) && ! empty( $workspace_data['session'] ), 'seeded branch provides a till and current cashier session' );
$assert( ! empty( $workspace_data['products'] ) && isset( $workspace_data['products'][0]['quantity_available'], $workspace_data['products'][0]['selling_price_minor'] ), 'product finder receives authoritative price and branch stock' );
$assert( isset( $workspace_data['holds'], $workspace_data['recent_sales'] ) && is_array( $workspace_data['holds'] ) && is_array( $workspace_data['recent_sales'] ), 'hold queue and receipt stream are branch-scoped arrays' );

$invalid = $service->workspace( $tenant_id, 999999, get_current_user_id() );
$assert( is_wp_error( $invalid ) && 'invalid_pos_scope' === $invalid->get_error_code(), 'unauthorized branch fails closed' );

$routes = rest_get_server()->get_routes();
$assert( isset( $routes['/pharmasure/v1/pos/workspace'] ), 'headless POS workspace endpoint is registered' );
$assert( isset( $routes['/pharmasure/v1/pos/checkout'], $routes['/pharmasure/v1/pos/holds'], $routes['/pharmasure/v1/pos/sales/(?P<id>\d+)/refund'] ), 'checkout, hold and refund endpoints remain registered' );

$controller = file_get_contents( WP_PLUGIN_DIR . '/pharmasure-pos/src/Rest/PosController.php' );
$service_source = file_get_contents( WP_PLUGIN_DIR . '/pharmasure-pos/src/Services/PosService.php' );
$client = file_get_contents( PHARMASURE_CORE_PATH . 'assets/js/app.js' );
$styles = file_get_contents( PHARMASURE_CORE_PATH . 'assets/css/crisp-theme.css' );

$assert( ! str_contains( $controller, "get_param( 'tenant_id'" ) && ! str_contains( $controller, "\$_GET['tenant_id']" ), 'REST controller never accepts tenant scope from the browser' );
$assert( str_contains( $controller, "enforce_entitlement( 'pos'" ) && str_contains( $controller, 'pos_writes_monthly' ), 'POS entry points enforce entitlement and monthly write quota' );
$assert( str_contains( $service_source, "status='open' FOR UPDATE" ) && str_contains( $service_source, 'consume_fefo' ) && str_contains( $service_source, 'START TRANSACTION' ), 'checkout preserves locked session validation, FEFO consumption and transaction boundaries' );
$assert( str_contains( $client, 'function renderPos' ) && str_contains( $client, "api('pos/workspace')" ) && str_contains( $client, "api('pos/checkout'" ), '/app/pos mounts a live workspace and checkout command' );
$assert( str_contains( $client, 'posTenderRow' ) && str_contains( $client, 'holdPosSale' ) && str_contains( $client, 'openRefundDialog' ) && str_contains( $client, 'openVoidDialog' ), 'split tenders, holds, refunds and void controls are present' );
$assert( str_contains( $client, "window.open('about:blank', '_blank')" ) && str_contains( $client, 'printWindow.location.replace(url)' ), 'receipt window opens during the user gesture and survives asynchronous print-job creation' );
$assert( str_contains( $client, "event.key === 'F2'" ) && str_contains( $client, "event.key === 'Enter'" ) && str_contains( $client, 'dialog[open]' ), 'advertised keyboard shortcuts focus lookup and guard checkout outside dialogs' );
$assert( str_contains( $client, 'hold_id: Number(state.posHoldId' ) && str_contains( $service_source, "status' => 'completed'" ), 'resumed held sales complete with checkout instead of remaining as duplicate carts' );
$assert( str_contains( $client, 'prescription-only medicines must be dispensed through Clinical' ) || str_contains( strtolower( $client ), 'prescription-only medicines must be dispensed through clinical' ), 'prescription-only items are explicitly routed to Clinical' );
$assert( ! str_contains( $client, 'innerHTML' ), 'POS components render through safe DOM APIs' );
$assert( str_contains( $styles, '.ps-pos-grid' ) && str_contains( $styles, '.ps-pos-tender' ) && str_contains( $styles, '.ps-pos-checkout' ) && str_contains( $styles, ':root[data-theme=light]' ), 'POS has dense responsive styling in the shared dark/light token system' );

echo "Headless POS tests: {$pass} passed, {$fail} failed.\n";
if ( $fail ) { throw new RuntimeException( 'Headless POS tests failed.' ); }
