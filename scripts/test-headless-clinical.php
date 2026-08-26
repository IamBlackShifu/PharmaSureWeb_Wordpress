<?php
/** Headless Clinical safety and workflow regression test. */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

use PharmaSure\Clinical\Services\ClinicalWorkspaceService;

\PharmaSure\Inventory\Installer::install();
\PharmaSure\POS\Installer::install();
\PharmaSure\Clinical\Installer::install();
if ( class_exists( '\PharmaSure\Claims\Installer' ) ) { \PharmaSure\Claims\Installer::install(); }

global $wpdb;
$p = $wpdb->prefix . 'ps_'; $pass = 0; $fail = 0; $ids = array();
$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) { echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n"; $condition ? ++$pass : ++$fail; };

try {
	foreach ( array( 'patients', 'prescriptions', 'prescription_items', 'dispensing_checks', 'controlled_dispense_register' ) as $table ) { $assert( $p . $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . $table ) ), "{$table} table exists" ); }
	$token = 'headless-clinical-' . wp_generate_uuid4(); $now = current_time( 'mysql', true );
	$wpdb->insert( $p . 'tenants', array( 'name' => 'Headless Clinical Test', 'trading_name' => 'Headless Clinical Test', 'slug' => $token, 'primary_contact_email' => $token . '@example.test', 'currency' => 'USD', 'status' => 'active' ) ); $ids['tenant'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'branches', array( 'tenant_id' => $ids['tenant'], 'name' => 'Clinical Main', 'code' => 'CLIN', 'is_active' => 1 ) ); $ids['branch'] = (int) $wpdb->insert_id;
	$service = new ClinicalWorkspaceService();
	$patient = $service->create_patient( $ids['tenant'], $ids['branch'], array( 'patient_number' => 'PAT-SAFE-1', 'first_name' => 'Rudo', 'last_name' => 'Ncube', 'date_of_birth' => '1990-06-15', 'allergies' => 'Sulfonamides', 'medical_conditions' => 'Asthma', 'current_medications' => 'Salbutamol inhaler' ), 1 ); $ids['patient'] = (int) ( $patient['id'] ?? 0 );
	$assert( $ids['patient'] > 0, 'patient safety profile is created with a generated audit mutation' );
	$detail = $service->patient_detail( $ids['tenant'], $ids['branch'], $ids['patient'] );
	$assert( 'Sulfonamides' === ( $detail['allergies'] ?? '' ) && 'Asthma' === ( $detail['medical_conditions'] ?? '' ), 'allergies and medical conditions remain visible in patient context' );

	$wpdb->insert( $p . 'drugs', array( 'tenant_id' => $ids['tenant'], 'sku' => 'CL-SAFE', 'name' => 'Safety Test Medicine', 'strength' => '10mg', 'selling_price_minor' => 125, 'requires_prescription' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) ); $ids['drug'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'batches', array( 'tenant_id' => $ids['tenant'], 'branch_id' => $ids['branch'], 'drug_id' => $ids['drug'], 'batch_number' => 'SAFE-01', 'expiry_date' => gmdate( 'Y-m-d', strtotime( '+1 year' ) ), 'quantity_received' => 5, 'quantity_available' => 5, 'unit_cost_minor' => 60, 'selling_price_minor' => 125, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) ); $ids['batch'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'stock_balances', array( 'tenant_id' => $ids['tenant'], 'branch_id' => $ids['branch'], 'drug_id' => $ids['drug'], 'quantity_available' => 5, 'updated_at' => $now ) );
	$rx = $service->create_prescription( $ids['tenant'], $ids['branch'], array( 'patient_id' => $ids['patient'], 'prescriber' => 'Dr Safety', 'prescription_date' => gmdate( 'Y-m-d' ), 'items' => array( array( 'drug_id' => $ids['drug'], 'drug_name' => 'Browser spoof', 'dose' => '1 tablet', 'route' => 'oral', 'frequency' => 'once daily', 'duration' => '2 days', 'quantity' => 2 ) ) ), 1 ); $ids['rx'] = (int) ( $rx['id'] ?? 0 );
	$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}prescription_items WHERE tenant_id=%d AND branch_id=%d AND prescription_id=%d", $ids['tenant'], $ids['branch'], $ids['rx'] ), ARRAY_A );
	$assert( $ids['rx'] > 0 && 'Safety Test Medicine' === $item['drug_name'] && 125 === (int) $item['unit_price_minor'], 'prescription stores authoritative tenant medicine identity and price' );
	$assert( 'pending_review' === ( $service->submit( $ids['tenant'], $ids['branch'], $ids['rx'], 1 )['status'] ?? '' ), 'draft enters pharmacist review queue' );
	$incomplete = $service->review( $ids['tenant'], $ids['branch'], $ids['rx'], array( 'outcome' => 'approve', 'review_notes' => 'Incomplete check', 'allergies_checked' => true ), 1 );
	$assert( is_wp_error( $incomplete ) && 'clinical_checks_incomplete' === $incomplete->get_error_code(), 'approval fails closed until allergy, interaction and dose checks are complete' );
	$approved = $service->review( $ids['tenant'], $ids['branch'], $ids['rx'], array( 'outcome' => 'approve', 'review_notes' => 'Profile, interaction and dose verified.', 'allergies_checked' => true, 'interactions_checked' => true, 'dose_checked' => true ), 1 );
	$assert( 'approved' === ( $approved['status'] ?? '' ), 'completed pharmacist review approves dispensing' );
	$missing_counselling = $service->dispense( $ids['tenant'], $ids['branch'], $ids['rx'], array( 'idempotency_key' => wp_generate_uuid4() ), 1 );
	$assert( is_wp_error( $missing_counselling ) && 'dispensing_checks_incomplete' === $missing_counselling->get_error_code(), 'dispensing fails closed without counselling evidence' );
	$key = wp_generate_uuid4(); $dispensed = $service->dispense( $ids['tenant'], $ids['branch'], $ids['rx'], array( 'idempotency_key' => $key, 'total_amount_minor' => 1, 'counselling_provided' => true, 'counselling_notes' => 'Explained dose, adherence, storage and return precautions.' ), 1 ); $ids['sale'] = (int) ( $dispensed['sale_id'] ?? 0 );
	$assert( $ids['sale'] > 0 && 250 === (int) ( $dispensed['total_amount_minor'] ?? 0 ), 'dispensing ignores client total and calculates authoritative catalogue value' );
	$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}sale_items WHERE tenant_id=%d AND branch_id=%d AND sale_id=%d AND cost_amount_minor=120", $ids['tenant'], $ids['branch'], $ids['sale'] ) ), 'claim-ready sale item stores immutable quantity, price and exact historical cost' );
	$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}dispensing_checks WHERE tenant_id=%d AND branch_id=%d AND prescription_id=%d AND counselling_provided=1", $ids['tenant'], $ids['branch'], $ids['rx'] ) ), 'counselling and dispensing checks are persisted' );
	$assert( 3.0 === (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$p}stock_balances WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d", $ids['tenant'], $ids['branch'], $ids['drug'] ) ), 'FEFO dispensing updates only authorized branch stock' );
	$replay = $service->dispense( $ids['tenant'], $ids['branch'], $ids['rx'], array( 'idempotency_key' => $key, 'counselling_provided' => true, 'counselling_notes' => 'Retry' ), 1 );
	$assert( ! empty( $replay['idempotent_replay'] ) && $ids['sale'] === (int) $replay['id'], 'dispensing retry returns the original sale without consuming stock twice' );

	$wpdb->insert( $p . 'drugs', array( 'tenant_id' => $ids['tenant'], 'sku' => 'CL-CONTROL', 'name' => 'Controlled Test Medicine', 'strength' => '5mg', 'selling_price_minor' => 500, 'requires_prescription' => 1, 'is_controlled' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) ); $ids['controlled_drug'] = (int) $wpdb->insert_id;
	$controlled = $service->create_prescription( $ids['tenant'], $ids['branch'], array( 'patient_id' => $ids['patient'], 'prescriber' => 'Dr Controlled', 'prescription_date' => gmdate( 'Y-m-d' ), 'items' => array( array( 'drug_id' => $ids['controlled_drug'], 'dose' => '1 tablet', 'frequency' => 'nightly', 'quantity' => 1 ) ) ), 1 ); $ids['controlled_rx'] = (int) ( $controlled['id'] ?? 0 ); $service->submit( $ids['tenant'], $ids['branch'], $ids['controlled_rx'], 1 );
	$without_attestation = $service->review( $ids['tenant'], $ids['branch'], $ids['controlled_rx'], array( 'outcome' => 'approve', 'review_notes' => 'Checks recorded', 'allergies_checked' => true, 'interactions_checked' => true, 'dose_checked' => true ), 1 );
	$assert( is_wp_error( $without_attestation ) && 'clinical_checks_incomplete' === $without_attestation->get_error_code(), 'controlled prescription cannot be approved without legal attestation' );
	$service->review( $ids['tenant'], $ids['branch'], $ids['controlled_rx'], array( 'outcome' => 'approve', 'review_notes' => 'Controlled requirements verified', 'allergies_checked' => true, 'interactions_checked' => true, 'dose_checked' => true, 'controlled_drug_attested' => true ), 1 );
	$no_witness = $service->dispense( $ids['tenant'], $ids['branch'], $ids['controlled_rx'], array( 'idempotency_key' => wp_generate_uuid4(), 'counselling_provided' => true, 'counselling_notes' => 'Controlled medicine counselling' ), 1 );
	$assert( is_wp_error( $no_witness ) && 'dispensing_rejected' === $no_witness->get_error_code(), 'controlled dispensing requires a distinct authorized witness' );

	$workspace = $service->workspace( $ids['tenant'], $ids['branch'] );
	$assert( ! is_wp_error( $workspace ) && isset( $workspace['scope'], $workspace['metrics'], $workspace['patients'], $workspace['prescriptions'], $workspace['recent_dispensings'] ), 'Clinical workspace returns complete branch-scoped read model' );
	$assert( ! isset( $workspace['tenant_id'] ) && ! isset( $workspace['scope']['tenant_id'] ), 'Clinical workspace does not expose tenant identifiers' );
	$invalid = $service->workspace( $ids['tenant'], 999999 ); $assert( is_wp_error( $invalid ) && 'invalid_clinical_scope' === $invalid->get_error_code(), 'unauthorized branch workspace fails closed' );

	$routes = rest_get_server()->get_routes(); $assert( isset( $routes['/pharmasure/v1/clinical/workspace'], $routes['/pharmasure/v1/clinical/prescriptions/(?P<id>\d+)/review'], $routes['/pharmasure/v1/clinical/prescriptions/(?P<id>\d+)/dispense'] ), 'Clinical workspace, review and dispense REST routes are registered' );
	$client = file_get_contents( PHARMASURE_CORE_PATH . 'assets/js/app.js' ); $styles = file_get_contents( PHARMASURE_CORE_PATH . 'assets/css/crisp-theme.css' ); $controller = file_get_contents( WP_PLUGIN_DIR . '/pharmasure-clinical/src/Rest/ClinicalWorkspaceController.php' );
	$assert( str_contains( $controller, "enforce_entitlement" ) || str_contains( file_get_contents( WP_PLUGIN_DIR . '/pharmasure-clinical/src/Rest/ClinicalAccess.php' ), "enforce_entitlement( 'clinical'" ), 'Clinical entry points enforce license entitlement' );
	$assert( str_contains( $client, 'function renderClinical' ) && str_contains( $client, 'openClinicalReviewDialog' ) && str_contains( $client, 'openClinicalDispenseDialog' ) && ! str_contains( $client, 'innerHTML' ), 'headless client provides safe patient, review and dispensing workflows' );
	$assert( str_contains( $styles, '.ps-clinical-grid' ) && str_contains( $styles, '.ps-clinical-alert' ) && str_contains( $styles, ':root[data-theme=light]' ), 'Clinical workspace uses responsive dark/light safety styling' );
} catch ( Throwable $error ) { ++$fail; echo 'FAIL: unexpected exception: ' . $error->getMessage() . "\n"; }
finally {
	if ( ! empty( $ids['tenant'] ) ) {
		$tenant = $ids['tenant'];
		foreach ( array( 'controlled_dispense_register', 'dispensing_checks', 'claim_events', 'claim_items', 'claims', 'patient_covers', 'insurer_schemes', 'insurers', 'audit_events', 'sale_payments', 'sale_items', 'sales', 'stock_movements', 'stock_balances', 'batches', 'prescription_items', 'prescriptions', 'patients', 'drugs', 'document_sequences', 'branches' ) as $table ) { if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . $table ) ) === $p . $table ) { $wpdb->query( $wpdb->prepare( "DELETE FROM {$p}{$table} WHERE tenant_id=%d", $tenant ) ); } }
		$wpdb->delete( $p . 'tenants', array( 'id' => $tenant ), array( '%d' ) );
	}
}

echo "Headless Clinical tests: {$pass} passed, {$fail} failed.\n";
if ( $fail ) { throw new RuntimeException( 'Headless Clinical tests failed.' ); }
