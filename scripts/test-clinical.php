<?php
/** Clinical integration test. Run with wp eval-file scripts/test-clinical.php. */

use PharmaSure\Clinical\Installer as ClinicalInstaller;
use PharmaSure\Clinical\Services\PatientService;
use PharmaSure\Clinical\Services\PrescriptionService;
use PharmaSure\Inventory\Installer as InventoryInstaller;

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

ClinicalInstaller::install();
InventoryInstaller::install();
global $wpdb;
$p = $wpdb->prefix . 'ps_';
$ids = array( 'tenants' => array(), 'branches' => array(), 'patients' => array(), 'prescriptions' => array(), 'sales' => array() );
$pass = 0;
$fail = 0;
$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) {
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$condition ? ++$pass : ++$fail;
};

try {
	foreach ( array( 'patients', 'prescriptions', 'prescription_items', 'sales', 'batches', 'stock_balances', 'stock_movements' ) as $table ) {
		$assert( $p . $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . $table ) ), "{$table} table exists" );
	}

	foreach ( array( 'alpha', 'beta' ) as $key ) {
		$token = 'clinical-' . $key . '-' . wp_generate_uuid4();
		$wpdb->insert( $p . 'tenants', array( 'name' => "Clinical {$key}", 'slug' => $token, 'primary_contact_email' => $token . '@example.test', 'status' => 'active' ) );
		$ids['tenants'][] = (int) $wpdb->insert_id;
		$wpdb->insert( $p . 'branches', array( 'tenant_id' => end( $ids['tenants'] ), 'name' => 'Main', 'code' => strtoupper( $key ), 'is_active' => 1 ) );
		$ids['branches'][] = (int) $wpdb->insert_id;
	}
	$wpdb->insert( $p . 'branches', array( 'tenant_id' => $ids['tenants'][0], 'name' => 'Other Alpha Branch', 'code' => 'ALPHA-OTHER', 'is_active' => 1 ) );
	$ids['branch_other'] = (int) $wpdb->insert_id;

	$patients = new PatientService();
	$patient_a = $patients->create_patient( $ids['tenants'][0], $ids['branches'][0], array( 'patient_number' => 'PAT-A', 'first_name' => 'Alice', 'last_name' => 'Alpha', 'phone' => '100' ) );
	$patient_b = $patients->create_patient( $ids['tenants'][1], $ids['branches'][1], array( 'patient_number' => 'PAT-B', 'first_name' => 'Bob', 'last_name' => 'Beta', 'phone' => '200' ) );
	$ids['patients'] = array( (int) ( $patient_a['id'] ?? 0 ), (int) ( $patient_b['id'] ?? 0 ) );
	$assert( min( $ids['patients'] ) > 0, 'patients are created in separate tenant scopes' );
	$assert( 1 === count( $patients->search_patients( $ids['tenants'][0], $ids['branches'][0], 'Alice' ) ), 'tenant can search its patient' );
	$assert( 0 === count( $patients->search_patients( $ids['tenants'][1], $ids['branches'][1], 'Alice' ) ), 'tenant search cannot see another patient' );

	$now = current_time( 'mysql', true );
	$wpdb->insert( $p . 'drugs', array( 'tenant_id' => $ids['tenants'][0], 'sku' => 'CLIN-FEFO', 'name' => 'Test Medicine', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
	$ids['drug'] = (int) $wpdb->insert_id;
	foreach ( array( array( 'OLD', gmdate( 'Y-m-d', strtotime( '-1 day' ) ), 100 ), array( 'EARLY', gmdate( 'Y-m-d', strtotime( '+30 days' ) ), 4 ), array( 'LATE', gmdate( 'Y-m-d', strtotime( '+1 year' ) ), 10 ) ) as $batch ) {
		$wpdb->insert( $p . 'batches', array( 'tenant_id' => $ids['tenants'][0], 'branch_id' => $ids['branches'][0], 'drug_id' => $ids['drug'], 'batch_number' => $batch[0], 'expiry_date' => $batch[1], 'quantity_received' => $batch[2], 'quantity_available' => $batch[2], 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
		$ids['batches'][ $batch[0] ] = (int) $wpdb->insert_id;
	}
	$wpdb->insert( $p . 'batches', array( 'tenant_id' => $ids['tenants'][0], 'branch_id' => $ids['branch_other'], 'drug_id' => $ids['drug'], 'batch_number' => 'OTHER-BRANCH', 'expiry_date' => gmdate( 'Y-m-d', strtotime( '+7 days' ) ), 'quantity_received' => 100, 'quantity_available' => 100, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
	$ids['batches']['OTHER-BRANCH'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'stock_balances', array( 'tenant_id' => $ids['tenants'][0], 'branch_id' => $ids['branches'][0], 'drug_id' => $ids['drug'], 'quantity_available' => 14, 'updated_at' => $now ) );
	$ids['balance'] = (int) $wpdb->insert_id;

	$prescriptions = new PrescriptionService();
	$payload = array(
		'patient_id' => $ids['patients'][0],
		'prescriber' => 'Dr Test',
		'prescription_date' => gmdate( 'Y-m-d' ),
		'items' => array( array( 'drug_id' => $ids['drug'], 'drug_name' => 'Test Medicine', 'dose' => '1 tablet', 'frequency' => 'twice daily', 'quantity' => 10 ) ),
	);
	$cross = $prescriptions->create_prescription( $ids['tenants'][1], $ids['branches'][1], $payload );
	$assert( is_wp_error( $cross ) && 'invalid_scope' === $cross->get_error_code(), 'cross-tenant patient prescription is rejected' );

	$created = $prescriptions->create_prescription( $ids['tenants'][0], $ids['branches'][0], $payload );
	$prescription_id = (int) ( $created['id'] ?? 0 );
	$ids['prescriptions'][] = $prescription_id;
	$assert( $prescription_id > 0, 'draft prescription and items are created atomically' );
	$assert( 'pending_review' === ( $prescriptions->submit_for_review( $ids['tenants'][0], $ids['branches'][0], $prescription_id )['status'] ?? '' ), 'draft submits for review' );
	$assert( 'approved' === ( $prescriptions->approve_prescription( $ids['tenants'][0], $ids['branches'][0], $prescription_id, 1 )['status'] ?? '' ), 'pending prescription is approved' );
	$wrong_scope = $prescriptions->dispense_prescription( $ids['tenants'][1], $ids['branches'][1], $prescription_id, array( 'total_amount_minor' => 500 ) );
	$assert( is_wp_error( $wrong_scope ), 'another tenant cannot dispense the prescription' );
	$dispensed = $prescriptions->dispense_prescription( $ids['tenants'][0], $ids['branches'][0], $prescription_id, array( 'total_amount_minor' => 500 ) );
	$sale_id = (int) ( $dispensed['sale_id'] ?? 0 );
	$ids['sales'][] = $sale_id;
	$assert( $sale_id > 0, 'approved prescription dispenses to a sale' );
	$assert( 'dispensed' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}prescriptions WHERE id = %d", $prescription_id ) ), 'dispensing finalizes prescription state' );
	$assert( 100.0 === (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$p}batches WHERE id=%d", $ids['batches']['OLD'] ) ), 'expired batch is never consumed' );
	$assert( 100.0 === (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$p}batches WHERE id=%d", $ids['batches']['OTHER-BRANCH'] ) ), 'stock from another branch is never consumed' );
	$assert( 0.0 === (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$p}batches WHERE id=%d", $ids['batches']['EARLY'] ) ), 'earliest-expiring eligible batch is consumed first' );
	$assert( 4.0 === (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$p}batches WHERE id=%d", $ids['batches']['LATE'] ) ), 'remaining quantity is allocated from the next batch' );
	$assert( 4.0 === (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$p}stock_balances WHERE id=%d", $ids['balance'] ) ), 'branch stock balance matches batch deductions' );
	$movement_total = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity_delta),0) FROM {$p}stock_movements WHERE reference_type='dispensing' AND reference_id=%d", $sale_id ) );
	$assert( -10.0 === $movement_total, 'immutable movements record the complete dispensed quantity' );
	$again = $prescriptions->dispense_prescription( $ids['tenants'][0], $ids['branches'][0], $prescription_id, array( 'total_amount_minor' => 500 ) );
	$assert( is_wp_error( $again ), 'dispensed prescription cannot be dispensed twice' );

	$payload['items'][0]['quantity'] = 5;
	$short = $prescriptions->create_prescription( $ids['tenants'][0], $ids['branches'][0], $payload );
	$short_id = (int) ( $short['id'] ?? 0 );
	$ids['prescriptions'][] = $short_id;
	$prescriptions->submit_for_review( $ids['tenants'][0], $ids['branches'][0], $short_id );
	$prescriptions->approve_prescription( $ids['tenants'][0], $ids['branches'][0], $short_id, 1 );
	$short_result = $prescriptions->dispense_prescription( $ids['tenants'][0], $ids['branches'][0], $short_id, array( 'total_amount_minor' => 250 ) );
	$assert( is_wp_error( $short_result ) && 'insufficient_stock' === $short_result->get_error_code(), 'insufficient unexpired stock rejects dispensing' );
	$assert( 'approved' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}prescriptions WHERE id=%d", $short_id ) ), 'failed dispensing preserves the approved prescription' );
	$assert( 4.0 === (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$p}stock_balances WHERE id=%d", $ids['balance'] ) ), 'failed dispensing rolls back all stock changes' );
} catch ( Throwable $error ) {
	++$fail;
	echo 'FAIL: unexpected exception: ' . $error->getMessage() . "\n";
} finally {
	if ( $ids['prescriptions'] ) {
		$list = implode( ',', array_map( 'absint', $ids['prescriptions'] ) );
		$wpdb->query( "DELETE FROM {$p}prescription_items WHERE prescription_id IN ({$list})" );
	}
	if ( $ids['tenants'] ) {
		$list = implode( ',', array_map( 'absint', $ids['tenants'] ) );
		foreach ( array( 'audit_events', 'stock_movements', 'stock_balances', 'batches', 'drugs', 'sales', 'prescriptions', 'patients', 'branches' ) as $table ) { $wpdb->query( "DELETE FROM {$p}{$table} WHERE tenant_id IN ({$list})" ); }
		$wpdb->query( "DELETE FROM {$p}tenants WHERE id IN ({$list})" );
	}
}

echo "Clinical tests: {$pass} passed, {$fail} failed.\n";
if ( $fail ) { throw new RuntimeException( 'Clinical integration tests failed.' ); }
