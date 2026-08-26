<?php
/** Inventory command-layer integration test. Run on the seeded GreenLife site. */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

use PharmaSure\Core\LicenseManager;
use PharmaSure\Core\TenantContext;
use PharmaSure\Inventory\Services\InventoryService;

$pass = 0;
$fail = 0;
$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) {
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$condition ? ++$pass : ++$fail;
};

global $wpdb;
$owner = get_user_by( 'login', 'owner' );
if ( $owner ) { wp_set_current_user( $owner->ID ); }
$context_property = new ReflectionProperty( TenantContext::class, 'instance' );
$context_property->setValue( null, null );
$context = TenantContext::instance();
$tenant_id = (int) $context->get_tenant_id();
$branch_id = (int) $context->get_branch_id();
$prefix = $wpdb->prefix . 'ps_';
$service = new InventoryService();
$token = strtolower( wp_generate_password( 8, false, false ) );
$ids = array();

try {
	$branches = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$prefix}branches WHERE tenant_id=%d AND is_active=1 ORDER BY is_default DESC,id", $tenant_id ) );
	$destination_id = isset( $branches[1] ) ? (int) $branches[1] : 0;
	$assert( $tenant_id > 0 && $branch_id > 0 && $destination_id > 0, 'two-branch tenant scope is available' );
	$assert( ! is_wp_error( ( new LicenseManager( $tenant_id ) )->enforce_entitlement( 'inventory' ) ), 'inventory entitlement permits the licensed command layer' );
	$licence = ( new LicenseManager( $tenant_id ) )->validate_license();
	$assert( 10000 === (int) ( $licence['quotas']['inventory_writes_monthly'] ?? 0 ), 'enterprise demo plan carries an explicit monthly inventory write quota' );

	$supplier = $service->create_supplier( $tenant_id, array( 'name' => 'Command Test Supplier ' . $token, 'payment_terms' => '14 days' ) );
	$ids['supplier'] = (int) ( $supplier['id'] ?? 0 );
	$assert( $ids['supplier'] > 0, 'tenant supplier is created and audited' );
	$supplier = $service->update_supplier( $tenant_id, $ids['supplier'], array( 'contact_name' => 'Stock Controller', 'email' => 'stock-' . $token . '@example.test' ) );
	$assert( 'Stock Controller' === ( $supplier['contact_name'] ?? '' ), 'supplier maintenance stays tenant scoped' );

	$drug = $service->create_drug( $tenant_id, array( 'sku' => 'CMD-' . strtoupper( $token ), 'name' => 'Command Test Medicine', 'unit_of_measure' => 'unit', 'cost_price_minor' => 100, 'selling_price_minor' => 175, 'reorder_level' => 4 ) );
	$ids['drug'] = (int) ( $drug['id'] ?? 0 );
	$assert( $ids['drug'] > 0, 'catalogue medicine is created' );
	$drug = $service->update_drug( $tenant_id, $ids['drug'], array( 'generic_name' => 'Command Generic', 'reorder_level' => 6 ) );
	$assert( 'Command Generic' === ( $drug['generic_name'] ?? '' ) && 6.0 === (float) ( $drug['reorder_level'] ?? 0 ), 'catalogue medicine is updated without changing tenant identity' );

	$receipt = $service->receive_stock( $tenant_id, $branch_id, array(
		'supplier_id' => $ids['supplier'], 'received_date' => gmdate( 'Y-m-d' ), 'purchase_reference' => 'CMD-' . strtoupper( $token),
		'items' => array( array( 'drug_id' => $ids['drug'], 'batch_number' => 'B-' . strtoupper( $token ), 'quantity' => 10, 'unit_cost_minor' => 100, 'selling_price_minor' => 175, 'expiry_date' => gmdate( 'Y-m-d', strtotime( '+1 year' ) ) ) ),
	), 'command-test-receipt-' . $token );
	$ids['receipt'] = (int) ( $receipt['id'] ?? 0 );
	$ids['batch'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$prefix}batches WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d", $tenant_id, $branch_id, $ids['drug'] ) );
	$line_tenant = (int) $wpdb->get_var( $wpdb->prepare( "SELECT tenant_id FROM {$prefix}stock_receipt_lines WHERE tenant_id=%d AND receipt_id=%d", $tenant_id, $ids['receipt'] ) );
	$assert( $ids['receipt'] > 0 && $ids['batch'] > 0 && $tenant_id === $line_tenant, 'multi-table receipt writes direct tenant scope onto every line' );

	$adjustment = $service->adjust_stock( $tenant_id, $branch_id, array( 'reason_code' => 'stocktake_correction', 'notes' => 'Counted two additional units', 'items' => array( array( 'batch_id' => $ids['batch'], 'quantity_delta' => 2 ) ) ), 'command-test-adjust-' . $token );
	$ids['adjustment'] = (int) ( $adjustment['id'] ?? 0 );
	$quantity = (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$prefix}stock_balances WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d", $tenant_id, $branch_id, $ids['drug'] ) );
	$assert( $ids['adjustment'] > 0 && 12.0 === $quantity, 'adjustment atomically updates batch, balance, lines and movement ledger' );
	$cross_scope = $service->adjust_stock( $tenant_id + 1, $branch_id, array( 'reason_code' => 'data_correction', 'items' => array( array( 'batch_id' => $ids['batch'], 'quantity_delta' => 1 ) ) ), 'cross-tenant-denied' );
	$assert( is_wp_error( $cross_scope ), 'cross-tenant adjustment fails closed' );

	$quarantine = $service->change_batch_status( $tenant_id, $branch_id, $ids['batch'], array( 'status' => 'quarantined', 'reason' => 'Quality assurance hold' ), 'command-test-quarantine-' . $token );
	$quantity = (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$prefix}stock_balances WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d", $tenant_id, $branch_id, $ids['drug'] ) );
	$assert( ! is_wp_error( $quarantine ) && 0.0 === $quantity, 'quarantine removes the batch from branch availability without destroying quantity' );
	$release = $service->change_batch_status( $tenant_id, $branch_id, $ids['batch'], array( 'status' => 'active', 'reason' => 'Quality assurance released' ), 'command-test-release-' . $token );
	$quantity = (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$prefix}stock_balances WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d", $tenant_id, $branch_id, $ids['drug'] ) );
	$assert( ! is_wp_error( $release ) && 12.0 === $quantity, 'release restores the preserved batch to branch availability' );

	$transfer = $service->transfer_stock( $tenant_id, $branch_id, array( 'to_branch_id' => $destination_id, 'notes' => 'Command test transfer', 'items' => array( array( 'drug_id' => $ids['drug'], 'quantity' => 3 ) ) ), 'command-test-transfer-' . $token );
	$ids['transfer'] = (int) ( $transfer['id'] ?? 0 );
	$source_quantity = (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$prefix}stock_balances WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d", $tenant_id, $branch_id, $ids['drug'] ) );
	$destination_quantity = (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$prefix}stock_balances WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d", $tenant_id, $destination_id, $ids['drug'] ) );
	$assert( $ids['transfer'] > 0 && 9.0 === $source_quantity && 3.0 === $destination_quantity, 'FEFO inter-branch transfer creates balanced outbound and inbound stock' );
	$invalid_transfer = $service->transfer_stock( $tenant_id, $branch_id, array( 'to_branch_id' => 999999, 'items' => array( array( 'drug_id' => $ids['drug'], 'quantity' => 1 ) ) ), 'invalid-destination' );
	$assert( is_wp_error( $invalid_transfer ), 'unauthorized destination branch is rejected' );

	$archive_with_stock = $service->archive_drug( $tenant_id, $ids['drug'] );
	$assert( is_wp_error( $archive_with_stock ) && 'drug_has_stock' === $archive_with_stock->get_error_code(), 'medicine archival is blocked while any tenant branch has stock' );
	$archived_supplier = $service->archive_supplier( $tenant_id, $ids['supplier'] );
	$assert( ! is_wp_error( $archived_supplier ) && 'archived' === $archived_supplier['status'], 'supplier is soft-archived without deleting receipt history' );

	$routes = rest_get_server()->get_routes();
	foreach ( array( '/pharmasure/v1/inventory/options', '/pharmasure/v1/inventory/adjustments', '/pharmasure/v1/inventory/transfers', '/pharmasure/v1/inventory/batches/(?P<id>\d+)/status' ) as $route ) {
		$assert( isset( $routes[ $route ] ), $route . ' route is registered' );
	}
} finally {
	if ( ! empty( $ids['drug'] ) ) {
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}stock_movements WHERE tenant_id=%d AND drug_id=%d", $tenant_id, $ids['drug'] ) );
			foreach ( array( 'stock_transfer_lines', 'stock_adjustment_lines' ) as $table ) { $wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}{$table} WHERE tenant_id=%d AND drug_id=%d", $tenant_id, $ids['drug'] ) ); }
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}batch_dispositions WHERE tenant_id=%d AND batch_id IN (SELECT id FROM {$prefix}batches WHERE tenant_id=%d AND drug_id=%d)", $tenant_id, $tenant_id, $ids['drug'] ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}stock_transfers WHERE tenant_id=%d AND id=%d", $tenant_id, (int) ( $ids['transfer'] ?? 0 ) ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}stock_adjustments WHERE tenant_id=%d AND id=%d", $tenant_id, (int) ( $ids['adjustment'] ?? 0 ) ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}stock_balances WHERE tenant_id=%d AND drug_id=%d", $tenant_id, $ids['drug'] ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}batches WHERE tenant_id=%d AND drug_id=%d", $tenant_id, $ids['drug'] ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}stock_receipt_lines WHERE tenant_id=%d AND receipt_id=%d", $tenant_id, (int) ( $ids['receipt'] ?? 0 ) ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}stock_receipts WHERE tenant_id=%d AND id=%d", $tenant_id, (int) ( $ids['receipt'] ?? 0 ) ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}drugs WHERE tenant_id=%d AND id=%d", $tenant_id, $ids['drug'] ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}suppliers WHERE tenant_id=%d AND id=%d", $tenant_id, (int) ( $ids['supplier'] ?? 0 ) ) );
			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $e ) { $wpdb->query( 'ROLLBACK' ); }
	}
}

echo "Inventory command tests: {$pass} passed, {$fail} failed.\n";
if ( $fail ) { throw new RuntimeException( 'Inventory command tests failed.' ); }
