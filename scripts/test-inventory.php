<?php
/**
 * Inventory integration and tenant-isolation test. Run with: wp eval-file scripts/test-inventory.php
 */

use PharmaSure\Inventory\Installer;
use PharmaSure\Inventory\Services\InventoryService;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

Installer::install();
global $wpdb;
$p = $wpdb->prefix . 'ps_';
$service = new InventoryService();
$ids = array( 'tenants' => array(), 'branches' => array(), 'suppliers' => array(), 'drugs' => array(), 'receipts' => array() );
$pass = 0; $fail = 0;
$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) {
	if ( $condition ) { ++$pass; echo "PASS: $message\n"; } else { ++$fail; echo "FAIL: $message\n"; }
};

try {
	foreach ( array( 'alpha', 'beta' ) as $key ) {
		$token = 'test-' . $key . '-' . wp_generate_uuid4();
		$wpdb->insert( $p . 'tenants', array( 'name' => "Inventory Test $key", 'slug' => $token, 'primary_contact_email' => "$token@example.test", 'status' => 'active' ) );
		$tenant_id = (int) $wpdb->insert_id; $ids['tenants'][] = $tenant_id;
		$wpdb->insert( $p . 'branches', array( 'tenant_id' => $tenant_id, 'name' => 'Main Branch', 'code' => strtoupper( $key ), 'is_active' => 1, 'is_default' => 1 ) );
		$ids['branches'][] = (int) $wpdb->insert_id;
		$supplier = $service->create_supplier( $tenant_id, array( 'name' => "Supplier $key" ) );
		$drug = $service->create_drug( $tenant_id, array( 'sku' => 'SKU-' . strtoupper( $key ), 'name' => "Medicine $key", 'reorder_level' => 5 ) );
		$ids['suppliers'][] = (int) $supplier['id']; $ids['drugs'][] = (int) $drug['id'];
	}

	$assert( $ids['drugs'][0] > 0 && $ids['drugs'][1] > 0, 'same workflow creates independent tenant catalogues' );
	$receipt = $service->receive_stock( $ids['tenants'][0], $ids['branches'][0], array( 'supplier_id' => $ids['suppliers'][0], 'received_date' => gmdate( 'Y-m-d' ), 'purchase_reference' => 'TEST-GRN-001', 'items' => array( array( 'drug_id' => $ids['drugs'][0], 'batch_number' => 'BATCH-A', 'quantity' => 25.5, 'unit_cost_minor' => 125, 'selling_price_minor' => 200, 'expiry_date' => gmdate( 'Y-m-d', strtotime( '+1 year' ) ) ) ) ), 'inventory-test' );
	$assert( ! is_wp_error( $receipt ), 'valid receipt commits atomically' );
	if ( ! is_wp_error( $receipt ) ) { $ids['receipts'][] = (int) $receipt['id']; }
	$balance = (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$p}stock_balances WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d", $ids['tenants'][0], $ids['branches'][0], $ids['drugs'][0] ) );
	$assert( 25.5 === $balance, 'receipt updates exact stock balance' );
	$movement = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}stock_movements WHERE tenant_id=%d AND reference_id=%d", $ids['tenants'][0], $ids['receipts'][0] ?? 0 ) );
	$assert( 1 === $movement, 'receipt produces one immutable ledger movement' );
	$cross = $service->receive_stock( $ids['tenants'][0], $ids['branches'][0], array( 'supplier_id' => $ids['suppliers'][1], 'received_date' => gmdate( 'Y-m-d' ), 'items' => array( array( 'drug_id' => $ids['drugs'][0], 'batch_number' => 'BAD', 'quantity' => 1, 'unit_cost_minor' => 1, 'expiry_date' => gmdate( 'Y-m-d', strtotime( '+1 year' ) ) ) ) ), 'inventory-test-cross' );
	$assert( is_wp_error( $cross ) && 'invalid_scope' === $cross->get_error_code(), 'cross-tenant supplier is rejected' );
	$other_visibility = $service->list_drugs( $ids['tenants'][1], array( 'search' => 'Medicine alpha' ) );
	$assert( 0 === $other_visibility['total'], 'tenant search cannot see another catalogue' );
	$invalid = $service->receive_stock( $ids['tenants'][0], $ids['branches'][0], array( 'supplier_id' => $ids['suppliers'][0], 'received_date' => gmdate( 'Y-m-d' ), 'items' => array( array( 'drug_id' => $ids['drugs'][0], 'batch_number' => 'EXPIRED', 'quantity' => 2, 'unit_cost_minor' => 1, 'expiry_date' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ) ) ) ), 'inventory-test-expired' );
	$assert( is_wp_error( $invalid ) && 'invalid_receipt_item' === $invalid->get_error_code(), 'expired stock receipt is rejected before transaction' );
} finally {
	if ( $ids['tenants'] ) {
		$tenant_list = implode( ',', array_map( 'absint', $ids['tenants'] ) );
		foreach ( array( 'stock_movements', 'stock_balances', 'batches' ) as $table ) { $wpdb->query( "DELETE FROM {$p}{$table} WHERE tenant_id IN ($tenant_list)" ); }
		if ( $ids['receipts'] ) { $receipt_list = implode( ',', array_map( 'absint', $ids['receipts'] ) ); $wpdb->query( "DELETE FROM {$p}stock_receipt_lines WHERE receipt_id IN ($receipt_list)" ); }
		foreach ( array( 'stock_receipts', 'drugs', 'suppliers', 'branches', 'tenants' ) as $table ) { $wpdb->query( "DELETE FROM {$p}{$table} WHERE " . ( in_array( $table, array( 'branches', 'stock_receipts', 'drugs', 'suppliers' ), true ) ? "tenant_id IN ($tenant_list)" : "id IN ($tenant_list)" ) ); }
	}
}

echo "Inventory tests: $pass passed, $fail failed.\n";
if ( $fail ) { throw new RuntimeException( 'Inventory integration tests failed.' ); }
