<?php
/** Print integration test. Run with wp eval-file scripts/test-print.php. */

use PharmaSure\PrintModule\Installer;
use PharmaSure\PrintModule\Services\PrintService;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }
Installer::install();
global $wpdb;
$p = $wpdb->prefix . 'ps_';
$ids = array();
$pass = 0; $fail = 0;
$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) { echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n"; $condition ? ++$pass : ++$fail; };

try {
	foreach ( array( 'printer_profiles', 'print_templates', 'print_jobs' ) as $table ) {
		$assert( $p . $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . $table ) ), "{$table} table exists" );
	}
	foreach ( array( 'alpha', 'beta' ) as $key ) {
		$token = 'print-' . $key . '-' . wp_generate_uuid4();
		$wpdb->insert( $p . 'tenants', array( 'name' => "Print {$key}", 'slug' => $token, 'primary_contact_email' => $token . '@example.test', 'status' => 'active' ) );
		$ids["tenant_{$key}"] = (int) $wpdb->insert_id;
		$wpdb->insert( $p . 'branches', array( 'tenant_id' => $ids["tenant_{$key}"], 'name' => 'Main', 'code' => strtoupper( $key ), 'receipt_header_text' => 'Licensed pharmacy', 'receipt_footer_text' => 'Thank you', 'is_active' => 1 ) );
		$ids["branch_{$key}"] = (int) $wpdb->insert_id;
	}
	$wpdb->insert( $p . 'patients', array( 'tenant_id' => $ids['tenant_alpha'], 'branch_id' => $ids['branch_alpha'], 'patient_number' => 'PRINT-PAT', 'first_name' => 'Alice', 'last_name' => 'Print', 'status' => 'active', 'created_at' => current_time( 'mysql', true ) ) );
	$ids['patient'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'prescriptions', array( 'tenant_id' => $ids['tenant_alpha'], 'branch_id' => $ids['branch_alpha'], 'patient_id' => $ids['patient'], 'prescriber' => 'Dr Printer', 'prescription_date' => gmdate( 'Y-m-d' ), 'status' => 'dispensed', 'created_at' => current_time( 'mysql', true ) ) );
	$ids['prescription'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'prescription_items', array( 'prescription_id' => $ids['prescription'], 'drug_name' => 'Amoxicillin', 'strength' => '500mg', 'dose' => '1 capsule', 'route' => 'oral', 'frequency' => 'three times daily', 'duration' => '5 days', 'quantity' => 15, 'repeats' => 0 ) );
	$ids['prescription_item'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'sales', array( 'tenant_id' => $ids['tenant_alpha'], 'branch_id' => $ids['branch_alpha'], 'patient_id' => $ids['patient'], 'prescription_id' => $ids['prescription'], 'total_amount_minor' => 1250, 'status' => 'completed', 'created_at' => current_time( 'mysql', true ) ) );
	$ids['sale'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'sale_items', array( 'tenant_id' => $ids['tenant_alpha'], 'branch_id' => $ids['branch_alpha'], 'sale_id' => $ids['sale'], 'drug_id' => 0, 'description' => 'Amoxicillin 500mg', 'quantity' => 2, 'unit_price_minor' => 625, 'line_total_minor' => 1250 ) );
	$ids['sale_item'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'sale_payments', array( 'tenant_id' => $ids['tenant_alpha'], 'branch_id' => $ids['branch_alpha'], 'sale_id' => $ids['sale'], 'method' => 'cash', 'amount_minor' => 1250, 'currency' => 'USD', 'status' => 'captured', 'created_at' => current_time( 'mysql', true ) ) );
	$ids['sale_payment'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'suppliers', array( 'tenant_id' => $ids['tenant_alpha'], 'name' => 'Print Supplier', 'status' => 'active', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ) );
	$ids['supplier'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'drugs', array( 'tenant_id' => $ids['tenant_alpha'], 'sku' => 'PRINT-SKU', 'name' => 'Amoxicillin', 'status' => 'active', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ) );
	$ids['drug'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'stock_receipts', array( 'tenant_id' => $ids['tenant_alpha'], 'branch_id' => $ids['branch_alpha'], 'supplier_id' => $ids['supplier'], 'purchase_reference' => 'PO-PRINT', 'received_date' => gmdate( 'Y-m-d' ), 'status' => 'completed', 'created_at' => current_time( 'mysql', true ), 'created_by' => 1 ) );
	$ids['stock_receipt'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'stock_receipt_lines', array( 'tenant_id' => $ids['tenant_alpha'], 'receipt_id' => $ids['stock_receipt'], 'drug_id' => $ids['drug'], 'batch_number' => 'PRINT-BATCH', 'quantity' => 20, 'unit_cost_minor' => 50, 'selling_price_minor' => 100, 'expiry_date' => gmdate( 'Y-m-d', strtotime( '+1 year' ) ), 'status' => 'completed', 'created_at' => current_time( 'mysql', true ) ) );
	$ids['stock_line'] = (int) $wpdb->insert_id;

	$service = new PrintService();
	$documents = array( 'medication_label' => $ids['prescription'], 'dispensing_summary' => $ids['prescription'], 'stock_receipt' => $ids['stock_receipt'], 'sale_receipt' => $ids['sale'] );
	foreach ( $documents as $type => $entity_id ) {
		$job = $service->create_job( $ids['tenant_alpha'], $type, $entity_id, 1, 'print-test' );
		$assert( ! is_wp_error( $job ) && empty( $job['is_reprint'] ), "{$type} job is queued" );
		$ids['jobs'][] = (int) $job['id'];
		$html = $service->render_job( $ids['tenant_alpha'], $job['id'] );
		$assert( is_string( $html ) && str_contains( $html, '<!doctype html>' ), "{$type} renders printable HTML" );
		$assert( 'completed' === $service->get_job( $ids['tenant_alpha'], $job['id'] )['status'], "{$type} job completes" );
		if ( 'stock_receipt' === $type ) { $assert( str_contains( $html, 'Amoxicillin' ) && str_contains( $html, 'PRINT-BATCH' ), 'stock receipt renders its tenant-scoped medicine and batch line' ); }
		if ( 'sale_receipt' === $type ) {
			$assert( str_contains( $html, 'document-sale_receipt' ) && str_contains( $html, 'size:80mm auto' ), 'sale receipt uses a dedicated 80 mm thermal document' );
			$assert( str_contains( $html, 'Amoxicillin 500mg' ) && str_contains( $html, 'USD 12.50' ), 'sale receipt renders item and authoritative currency totals' );
			$assert( str_contains( $html, 'Payment' ) && str_contains( $html, 'Cash' ), 'sale receipt renders tender evidence' );
		}
	}
	$headless_url = \PharmaSure\PrintModule\BrowserPrintController::print_url( $ids['jobs'][ array_key_last( $ids['jobs'] ) ] );
	$assert( str_contains( $headless_url, '/app/print/' ) && ! str_contains( $headless_url, 'wp-admin' ), 'print jobs use the protected headless document route' );
	$cross = $service->create_job( $ids['tenant_beta'], 'medication_label', $ids['prescription'], 1 );
	$assert( is_wp_error( $cross ), 'tenant cannot print another tenant document' );
	$reprint = $service->create_job( $ids['tenant_alpha'], 'medication_label', $ids['prescription'], 1, 'reprint-test' );
	$ids['jobs'][] = (int) $reprint['id'];
	$assert( ! empty( $reprint['is_reprint'] ), 'repeat document is marked as a reprint' );
	$reprint_html = $service->render_job( $ids['tenant_alpha'], $reprint['id'] );
	$assert( str_contains( $reprint_html, 'REPRINT' ), 'reprint output is visibly marked' );
	$audit = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}audit_events WHERE tenant_id=%d AND event_type IN ('document_printed','document_reprinted')", $ids['tenant_alpha'] ) );
	$assert( 5 === $audit, 'print and reprint actions are audited' );
} catch ( Throwable $error ) { ++$fail; echo 'FAIL: unexpected exception: ' . $error->getMessage() . "\n"; }
finally {
	if ( ! empty( $ids['jobs'] ) ) { $wpdb->query( "DELETE FROM {$p}print_jobs WHERE id IN (" . implode( ',', array_map( 'absint', $ids['jobs'] ) ) . ')' ); }
	if ( ! empty( $ids['tenant_alpha'] ) ) { $wpdb->query( $wpdb->prepare( "DELETE FROM {$p}audit_events WHERE tenant_id=%d AND event_type IN ('document_printed','document_reprinted')", $ids['tenant_alpha'] ) ); }
	foreach ( array( 'stock_line' => 'stock_receipt_lines', 'stock_receipt' => 'stock_receipts', 'drug' => 'drugs', 'supplier' => 'suppliers', 'sale_payment' => 'sale_payments', 'sale_item' => 'sale_items', 'sale' => 'sales', 'prescription_item' => 'prescription_items', 'prescription' => 'prescriptions', 'patient' => 'patients', 'branch_alpha' => 'branches', 'branch_beta' => 'branches', 'tenant_alpha' => 'tenants', 'tenant_beta' => 'tenants' ) as $key => $table ) {
		if ( ! empty( $ids[ $key ] ) ) { $wpdb->delete( $p . $table, array( 'id' => $ids[ $key ] ), array( '%d' ) ); }
	}
}
echo "Print tests: {$pass} passed, {$fail} failed.\n";
if ( $fail ) { throw new RuntimeException( 'Print integration tests failed.' ); }
