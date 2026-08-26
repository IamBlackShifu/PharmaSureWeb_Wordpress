<?php
namespace PharmaSure\Clinical\Services;

use PharmaSure\Core\TenantContext;
use PharmaSure\Inventory\Services\StockAllocator;

final class ClinicalWorkspaceService {
	private $db;
	private $p;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
		$this->p = $wpdb->prefix . 'ps_';
	}

	public function workspace( $tenant_id, $branch_id, $search = '' ) {
		$scope = $this->scope( $tenant_id, $branch_id );
		if ( ! $scope ) { return $this->error( 'invalid_clinical_scope', 'An authorized active branch is required.', 403 ); }
		$term = trim( sanitize_text_field( $search ) );
		$where = '';
		$args = array( $tenant_id, $branch_id );
		if ( '' !== $term ) {
			$like = '%' . $this->db->esc_like( $term ) . '%';
			$where = ' AND (patient_number LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR phone LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		$args[] = 60;
		$patients = $this->db->get_results( $this->db->prepare( "SELECT id,patient_number,first_name,last_name,date_of_birth,phone,email,allergies,medical_conditions,current_medications,status,created_at FROM {$this->p}patients WHERE tenant_id=%d AND branch_id=%d AND status='active'{$where} ORDER BY last_name,first_name LIMIT %d", ...$args ), ARRAY_A );
		$prescriptions = $this->db->get_results( $this->db->prepare( "SELECT rx.id,rx.patient_id,rx.prescriber,rx.prescription_date,rx.status,rx.notes,rx.review_notes,rx.rejection_reason,rx.approved_at,rx.dispensed_at,rx.created_at,p.patient_number,p.first_name,p.last_name,COUNT(i.id) item_count,COALESCE(SUM(i.is_controlled),0) controlled_items FROM {$this->p}prescriptions rx JOIN {$this->p}patients p ON p.id=rx.patient_id AND p.tenant_id=rx.tenant_id AND p.branch_id=rx.branch_id LEFT JOIN {$this->p}prescription_items i ON i.tenant_id=rx.tenant_id AND i.branch_id=rx.branch_id AND i.prescription_id=rx.id WHERE rx.tenant_id=%d AND rx.branch_id=%d GROUP BY rx.id,rx.patient_id,rx.prescriber,rx.prescription_date,rx.status,rx.notes,rx.review_notes,rx.rejection_reason,rx.approved_at,rx.dispensed_at,rx.created_at,p.patient_number,p.first_name,p.last_name ORDER BY FIELD(rx.status,'pending_review','approved','draft','rejected','dispensed'),rx.created_at DESC LIMIT %d", $tenant_id, $branch_id, 80 ), ARRAY_A );
		foreach ( $prescriptions as &$prescription ) { $prescription['items'] = $this->prescription_items( $tenant_id, $branch_id, $prescription['id'] ); }
		unset( $prescription );
		$metrics = $this->db->get_row( $this->db->prepare( "SELECT COUNT(*) total,COALESCE(SUM(status='pending_review'),0) pending_review,COALESCE(SUM(status='approved'),0) ready_to_dispense,COALESCE(SUM(status='dispensed' AND dispensed_at>=UTC_DATE()),0) dispensed_today FROM {$this->p}prescriptions WHERE tenant_id=%d AND branch_id=%d", $tenant_id, $branch_id ), ARRAY_A );
		$metrics['active_patients'] = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->p}patients WHERE tenant_id=%d AND branch_id=%d AND status='active'", $tenant_id, $branch_id ) );
		$metrics['controlled_pending'] = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(DISTINCT rx.id) FROM {$this->p}prescriptions rx JOIN {$this->p}prescription_items i ON i.tenant_id=rx.tenant_id AND i.branch_id=rx.branch_id AND i.prescription_id=rx.id AND i.is_controlled=1 WHERE rx.tenant_id=%d AND rx.branch_id=%d AND rx.status IN ('pending_review','approved')", $tenant_id, $branch_id ) );
		$recent = $this->db->get_results( $this->db->prepare( "SELECT s.id,s.receipt_number,s.prescription_id,s.total_amount_minor,s.status,s.created_at,p.patient_number,p.first_name,p.last_name FROM {$this->p}sales s JOIN {$this->p}patients p ON p.id=s.patient_id AND p.tenant_id=s.tenant_id AND p.branch_id=s.branch_id WHERE s.tenant_id=%d AND s.branch_id=%d AND s.prescription_id IS NOT NULL ORDER BY s.created_at DESC,s.id DESC LIMIT %d", $tenant_id, $branch_id, 20 ), ARRAY_A );
		foreach ( $recent as &$sale ) { $sale['claim'] = $this->claim_for_sale( $tenant_id, $branch_id, $sale['id'] ); }
		unset( $sale );
		return array( 'scope' => $scope, 'metrics' => $metrics, 'patients' => $patients ?: array(), 'prescriptions' => $prescriptions ?: array(), 'recent_dispensings' => $recent ?: array(), 'drugs' => $this->search_drugs( $tenant_id, $branch_id, '' ) );
	}

	public function patient_detail( $tenant_id, $branch_id, $patient_id ) {
		$patient = $this->db->get_row( $this->db->prepare( "SELECT id,patient_number,first_name,last_name,date_of_birth,phone,email,address,allergies,medical_conditions,current_medications,status,created_at FROM {$this->p}patients WHERE id=%d AND tenant_id=%d AND branch_id=%d AND status='active'", $patient_id, $tenant_id, $branch_id ), ARRAY_A );
		if ( ! $patient ) { return $this->error( 'patient_not_found', 'Patient was not found in this branch.', 404 ); }
		$patient['prescriptions'] = $this->db->get_results( $this->db->prepare( "SELECT id,prescriber,prescription_date,status,created_at FROM {$this->p}prescriptions WHERE tenant_id=%d AND branch_id=%d AND patient_id=%d ORDER BY created_at DESC LIMIT %d", $tenant_id, $branch_id, $patient_id, 25 ), ARRAY_A );
		$patient['covers'] = $this->patient_covers( $tenant_id, $patient_id );
		return $patient;
	}

	public function search_drugs( $tenant_id, $branch_id, $search ) {
		$term = trim( sanitize_text_field( $search ) );
		$where = '';
		$args = array( $branch_id, $tenant_id );
		if ( '' !== $term ) { $like = '%' . $this->db->esc_like( $term ) . '%'; $where = ' AND (d.sku=%s OR d.barcode=%s OR d.name LIKE %s OR d.generic_name LIKE %s)'; array_push( $args, $term, $term, $like, $like ); }
		$args[] = 40;
		return $this->db->get_results( $this->db->prepare( "SELECT d.id,d.sku,d.barcode,d.name,d.generic_name,d.strength,d.dosage_form,d.selling_price_minor,d.requires_prescription,d.is_controlled,COALESCE(s.quantity_available,0) quantity_available FROM {$this->p}drugs d LEFT JOIN {$this->p}stock_balances s ON s.tenant_id=d.tenant_id AND s.drug_id=d.id AND s.branch_id=%d WHERE d.tenant_id=%d AND d.status='active'{$where} ORDER BY d.requires_prescription DESC,d.name LIMIT %d", ...$args ), ARRAY_A );
	}

	public function create_patient( $tenant_id, $branch_id, array $data, $actor_id ) {
		$first = sanitize_text_field( $data['first_name'] ?? '' ); $last = sanitize_text_field( $data['last_name'] ?? '' );
		if ( ! $this->scope( $tenant_id, $branch_id ) || ! $first || ! $last ) { return $this->error( 'invalid_patient', 'An active branch, first name and last name are required.', 422 ); }
		$dob = sanitize_text_field( $data['date_of_birth'] ?? '' ); if ( $dob && ! $this->date( $dob ) ) { return $this->error( 'invalid_date_of_birth', 'Date of birth must be a valid past date.', 422 ); }
		$number = sanitize_text_field( $data['patient_number'] ?? '' ) ?: 'PAT-' . strtoupper( substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 ) );
		$now = $this->now(); $this->db->query( 'START TRANSACTION' );
		try {
			$this->must_insert( 'patients', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'patient_number' => $number, 'first_name' => $first, 'last_name' => $last, 'date_of_birth' => $dob ?: null, 'phone' => sanitize_text_field( $data['phone'] ?? '' ) ?: null, 'email' => sanitize_email( $data['email'] ?? '' ) ?: null, 'address' => sanitize_textarea_field( $data['address'] ?? '' ) ?: null, 'allergies' => sanitize_textarea_field( $data['allergies'] ?? '' ) ?: null, 'medical_conditions' => sanitize_textarea_field( $data['medical_conditions'] ?? '' ) ?: null, 'current_medications' => sanitize_textarea_field( $data['current_medications'] ?? '' ) ?: null, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
			$id = (int) $this->db->insert_id; $this->db->query( 'COMMIT' ); $this->audit( $tenant_id, $actor_id, 'clinical.patient_created', 'patient', $id, array( 'branch_id' => $branch_id, 'patient_number' => $number ) ); return array( 'id' => $id, 'patient_number' => $number );
		} catch ( \Throwable $error ) { $this->db->query( 'ROLLBACK' ); return $this->error( 'patient_not_created', 'The patient record could not be created.', 409 ); }
	}

	public function create_prescription( $tenant_id, $branch_id, array $data, $actor_id ) {
		$patient_id = absint( $data['patient_id'] ?? 0 ); $prescriber = sanitize_text_field( $data['prescriber'] ?? '' ); $date = sanitize_text_field( $data['prescription_date'] ?? '' ); $items = (array) ( $data['items'] ?? array() );
		if ( ! $patient_id || ! $prescriber || ! $this->date( $date, true ) || ! $items ) { return $this->error( 'invalid_prescription', 'Patient, prescriber, valid prescription date and at least one medicine are required.', 422 ); }
		if ( ! $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}patients WHERE id=%d AND tenant_id=%d AND branch_id=%d AND status='active'", $patient_id, $tenant_id, $branch_id ) ) ) { return $this->error( 'invalid_patient_scope', 'Patient does not belong to this branch.', 403 ); }
		$normalized = array();
		foreach ( $items as $item ) {
			$drug_id = absint( $item['drug_id'] ?? 0 ); $quantity = (float) ( $item['quantity'] ?? 0 ); $dose = sanitize_text_field( $item['dose'] ?? '' ); $frequency = sanitize_text_field( $item['frequency'] ?? '' );
			$drug = $this->db->get_row( $this->db->prepare( "SELECT id,name,strength,selling_price_minor,is_controlled FROM {$this->p}drugs WHERE id=%d AND tenant_id=%d AND status='active'", $drug_id, $tenant_id ), ARRAY_A );
			if ( ! $drug || $quantity <= 0 || ! $dose || ! $frequency ) { return $this->error( 'invalid_prescription_item', 'Every medicine requires an active catalogue match, dose, frequency and positive quantity.', 422 ); }
			$normalized[] = array( 'drug' => $drug, 'quantity' => $quantity, 'dose' => $dose, 'frequency' => $frequency, 'route' => sanitize_text_field( $item['route'] ?? '' ), 'duration' => sanitize_text_field( $item['duration'] ?? '' ), 'repeats' => absint( $item['repeats'] ?? 0 ) );
		}
		$now = $this->now(); $this->db->query( 'START TRANSACTION' );
		try {
			$this->must_insert( 'prescriptions', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'patient_id' => $patient_id, 'prescriber' => $prescriber, 'prescription_date' => $date, 'status' => 'draft', 'notes' => sanitize_textarea_field( $data['notes'] ?? '' ) ?: null, 'created_by' => $actor_id, 'created_at' => $now, 'updated_at' => $now ) ); $id = (int) $this->db->insert_id;
			foreach ( $normalized as $item ) { $drug = $item['drug']; $this->must_insert( 'prescription_items', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'prescription_id' => $id, 'drug_id' => $drug['id'], 'drug_name' => $drug['name'], 'strength' => $drug['strength'], 'dose' => $item['dose'], 'route' => $item['route'] ?: null, 'frequency' => $item['frequency'], 'duration' => $item['duration'] ?: null, 'quantity' => $item['quantity'], 'repeats' => $item['repeats'], 'is_controlled' => (int) $drug['is_controlled'], 'unit_price_minor' => (int) $drug['selling_price_minor'], 'status' => 'active', 'created_at' => $now ) ); }
			$this->db->query( 'COMMIT' ); $this->audit( $tenant_id, $actor_id, 'clinical.prescription_created', 'prescription', $id, array( 'branch_id' => $branch_id, 'patient_id' => $patient_id, 'item_count' => count( $normalized ) ) ); return array( 'id' => $id, 'status' => 'draft' );
		} catch ( \Throwable $error ) { $this->db->query( 'ROLLBACK' ); return $this->error( 'prescription_not_created', 'No prescription or item was saved.', 409 ); }
	}

	public function submit( $tenant_id, $branch_id, $id, $actor_id ) {
		$updated = $this->db->update( $this->p . 'prescriptions', array( 'status' => 'pending_review', 'updated_at' => $this->now() ), array( 'id' => $id, 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'status' => 'draft' ) );
		if ( 1 !== $updated ) { return $this->error( 'invalid_prescription_state', 'Only a branch-owned draft can be submitted.', 409 ); }
		$this->audit( $tenant_id, $actor_id, 'clinical.prescription_submitted', 'prescription', $id, array( 'branch_id' => $branch_id ) ); return array( 'id' => (int) $id, 'status' => 'pending_review' );
	}

	public function review( $tenant_id, $branch_id, $id, array $data, $actor_id ) {
		$outcome = sanitize_key( $data['outcome'] ?? '' ); $notes = sanitize_textarea_field( $data['review_notes'] ?? '' );
		if ( ! in_array( $outcome, array( 'approve', 'reject' ), true ) || ! $notes ) { return $this->error( 'invalid_review', 'A review outcome and clinical rationale are required.', 422 ); }
		$rx = $this->db->get_row( $this->db->prepare( "SELECT id FROM {$this->p}prescriptions WHERE id=%d AND tenant_id=%d AND branch_id=%d AND status='pending_review'", $id, $tenant_id, $branch_id ), ARRAY_A );
		if ( ! $rx ) { return $this->error( 'invalid_prescription_state', 'Only a pending branch prescription can be reviewed.', 409 ); }
		if ( 'reject' === $outcome ) {
			$this->db->update( $this->p . 'prescriptions', array( 'status' => 'rejected', 'review_notes' => $notes, 'rejection_reason' => $notes, 'reviewed_at' => $this->now(), 'reviewed_by_user' => $actor_id, 'updated_at' => $this->now() ), array( 'id' => $id, 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'status' => 'pending_review' ) );
			$this->audit( $tenant_id, $actor_id, 'clinical.prescription_rejected', 'prescription', $id, array( 'branch_id' => $branch_id, 'reason' => $notes ) ); return array( 'id' => (int) $id, 'status' => 'rejected' );
		}
		$allergies = ! empty( $data['allergies_checked'] ); $interactions = ! empty( $data['interactions_checked'] ); $dose = ! empty( $data['dose_checked'] );
		$controlled = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->p}prescription_items WHERE tenant_id=%d AND branch_id=%d AND prescription_id=%d AND is_controlled=1", $tenant_id, $branch_id, $id ) );
		if ( ! $allergies || ! $interactions || ! $dose || ( $controlled && empty( $data['controlled_drug_attested'] ) ) ) { return $this->error( 'clinical_checks_incomplete', 'Allergy, interaction and dose checks—and controlled-drug attestation when applicable—must be completed.', 422 ); }
		$updated = $this->db->update( $this->p . 'prescriptions', array( 'status' => 'approved', 'review_notes' => $notes, 'allergies_checked' => 1, 'interactions_checked' => 1, 'dose_checked' => 1, 'controlled_drug_attested' => $controlled ? 1 : 0, 'approved_at' => $this->now(), 'approved_by_user' => $actor_id, 'reviewed_at' => $this->now(), 'reviewed_by_user' => $actor_id, 'updated_at' => $this->now() ), array( 'id' => $id, 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'status' => 'pending_review' ) );
		if ( 1 !== $updated ) { return $this->error( 'review_conflict', 'Prescription state changed before approval.', 409 ); }
		$this->audit( $tenant_id, $actor_id, 'clinical.prescription_approved', 'prescription', $id, array( 'branch_id' => $branch_id, 'controlled_items' => $controlled, 'review_notes' => $notes ) ); return array( 'id' => (int) $id, 'status' => 'approved' );
	}

	public function dispense( $tenant_id, $branch_id, $id, array $data, $actor_id ) {
		$key = sanitize_text_field( $data['idempotency_key'] ?? '' ); $notes = sanitize_textarea_field( $data['counselling_notes'] ?? '' );
		if ( ! $key || strlen( $key ) > 100 || empty( $data['counselling_provided'] ) || ! $notes ) { return $this->error( 'dispensing_checks_incomplete', 'Idempotency key, counselling confirmation and counselling notes are required.', 422 ); }
		$existing = $this->db->get_row( $this->db->prepare( "SELECT id,receipt_number,total_amount_minor,status FROM {$this->p}sales WHERE tenant_id=%d AND branch_id=%d AND idempotency_key=%s", $tenant_id, $branch_id, $key ), ARRAY_A ); if ( $existing ) { $existing['idempotent_replay'] = true; return $existing; }
		$this->db->query( 'START TRANSACTION' );
		try {
			$rx = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->p}prescriptions WHERE id=%d AND tenant_id=%d AND branch_id=%d AND status='approved' FOR UPDATE", $id, $tenant_id, $branch_id ), ARRAY_A ); if ( ! $rx ) { throw new \DomainException( 'Only an approved branch prescription can be dispensed.' ); }
			$items = $this->prescription_items( $tenant_id, $branch_id, $id, true ); if ( ! $items ) { throw new \DomainException( 'Prescription has no mapped medicine items.' ); }
			$controlled = array_filter( $items, static fn( $item ) => ! empty( $item['is_controlled'] ) ); $witness = absint( $data['controlled_witness_user_id'] ?? 0 );
			if ( $controlled && ( ! $witness || $witness === (int) $actor_id || ! $this->valid_witness( $tenant_id, $branch_id, $witness ) || empty( $rx['controlled_drug_attested'] ) ) ) { throw new \DomainException( 'Controlled dispensing requires a distinct authorized witness and approved controlled-drug review.' ); }
			$subtotal = array_sum( array_map( static fn( $item ) => (int) round( (float) $item['quantity'] * (int) $item['unit_price_minor'] ), $items ) ); $receipt = $this->next_receipt( $tenant_id, $branch_id ); $now = $this->now();
			$this->must_insert( 'sales', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'patient_id' => $rx['patient_id'], 'prescription_id' => $id, 'receipt_number' => $receipt, 'idempotency_key' => $key, 'subtotal_amount_minor' => $subtotal, 'total_amount_minor' => $subtotal, 'status' => 'completed', 'cashier_id' => $actor_id, 'created_at' => $now ) ); $sale_id = (int) $this->db->insert_id; $allocator = new StockAllocator(); $allocations = array();
			foreach ( $items as $item ) {
				$this->must_insert( 'sale_items', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'sale_id' => $sale_id, 'drug_id' => $item['drug_id'], 'description' => $item['drug_name'], 'quantity' => $item['quantity'], 'unit_price_minor' => $item['unit_price_minor'], 'line_total_minor' => (int) round( (float) $item['quantity'] * (int) $item['unit_price_minor'] ) ) );
				$allocation = $allocator->consume_fefo( $tenant_id, $branch_id, $item['drug_id'], $item['quantity'], 'dispensing', $sale_id, TenantContext::instance()->get_correlation_id(), 'Prescription ' . $id ); if ( is_wp_error( $allocation ) ) { $this->db->query( 'ROLLBACK' ); return $allocation; }
				$cost = array_sum( array_column( $allocation, 'cost_amount_minor' ) ); if ( false === $this->db->update( $this->p . 'sale_items', array( 'cost_amount_minor' => $cost ), array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'sale_id' => $sale_id, 'drug_id' => $item['drug_id'] ) ) ) { throw new \RuntimeException( 'Dispensing cost snapshot failed.' ); } $allocations[ $item['id'] ] = $allocation;
				if ( ! empty( $item['is_controlled'] ) ) { $this->must_insert( 'controlled_dispense_register', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'prescription_id' => $id, 'prescription_item_id' => $item['id'], 'drug_id' => $item['drug_id'], 'patient_id' => $rx['patient_id'], 'register_reference' => 'CD-' . strtoupper( substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 14 ) ), 'quantity' => $item['quantity'], 'pharmacist_user_id' => $actor_id, 'witness_user_id' => $witness, 'notes' => $notes, 'status' => 'completed', 'created_at' => $now ) ); }
			}
			$this->must_insert( 'dispensing_checks', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'prescription_id' => $id, 'patient_id' => $rx['patient_id'], 'allergies_checked' => 1, 'interactions_checked' => 1, 'dose_checked' => 1, 'counselling_provided' => 1, 'counselling_notes' => $notes, 'controlled_witness_user_id' => $controlled ? $witness : null, 'status' => 'completed', 'created_by' => $actor_id, 'created_at' => $now ) );
			if ( 1 !== $this->db->update( $this->p . 'prescriptions', array( 'status' => 'dispensed', 'counselling_notes' => $notes, 'dispensed_at' => $now, 'dispensed_by_user' => $actor_id, 'updated_at' => $now ), array( 'id' => $id, 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'status' => 'approved' ) ) ) { throw new \RuntimeException( 'Prescription state changed during dispensing.' ); }
			$this->db->query( 'COMMIT' ); $covers = $this->patient_covers( $tenant_id, $rx['patient_id'] ); $this->audit( $tenant_id, $actor_id, 'clinical.prescription_dispensed', 'prescription', $id, array( 'branch_id' => $branch_id, 'sale_id' => $sale_id, 'receipt_number' => $receipt, 'total_amount_minor' => $subtotal, 'controlled_items' => count( $controlled ), 'counselling_provided' => true ) ); return array( 'id' => $sale_id, 'sale_id' => $sale_id, 'prescription_id' => (int) $id, 'receipt_number' => $receipt, 'total_amount_minor' => $subtotal, 'allocations' => $allocations, 'claim_ready' => ! empty( $covers ), 'covers' => $covers );
		} catch ( \DomainException $error ) { $this->db->query( 'ROLLBACK' ); return $this->error( 'dispensing_rejected', $error->getMessage(), 409 ); } catch ( \Throwable $error ) { $this->db->query( 'ROLLBACK' ); return $this->error( 'dispensing_failed', 'No sale, register entry or stock movement was saved.', 409 ); }
	}

	private function prescription_items( $tenant_id, $branch_id, $id, $lock = false ) { return $this->db->get_results( $this->db->prepare( "SELECT i.id,i.drug_id,i.drug_name,i.strength,i.dose,i.route,i.frequency,i.duration,i.quantity,i.repeats,i.is_controlled,i.unit_price_minor,COALESCE(s.quantity_available,0) quantity_available FROM {$this->p}prescription_items i LEFT JOIN {$this->p}stock_balances s ON s.tenant_id=i.tenant_id AND s.branch_id=i.branch_id AND s.drug_id=i.drug_id WHERE i.tenant_id=%d AND i.branch_id=%d AND i.prescription_id=%d AND i.status='active' ORDER BY i.id" . ( $lock ? ' FOR UPDATE' : '' ), $tenant_id, $branch_id, $id ), ARRAY_A ); }
	private function scope( $tenant_id, $branch_id ) { return $this->db->get_row( $this->db->prepare( "SELECT t.trading_name,t.currency,b.name branch_name FROM {$this->p}tenants t JOIN {$this->p}branches b ON b.tenant_id=t.id AND b.id=%d AND b.is_active=1 WHERE t.id=%d", $branch_id, $tenant_id ), ARRAY_A ); }
	private function patient_covers( $tenant_id, $patient_id ) { if ( $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->p . 'patient_covers' ) ) !== $this->p . 'patient_covers' ) { return array(); } return $this->db->get_results( $this->db->prepare( "SELECT c.id,c.member_number,c.dependent_code,c.valid_from,c.valid_to,c.authorization_number,i.name insurer_name,s.name scheme_name FROM {$this->p}patient_covers c JOIN {$this->p}insurers i ON i.id=c.insurer_id AND i.tenant_id=c.tenant_id JOIN {$this->p}insurer_schemes s ON s.id=c.scheme_id AND s.tenant_id=c.tenant_id WHERE c.tenant_id=%d AND c.patient_id=%d AND c.status='active' AND c.valid_from<=UTC_DATE() AND (c.valid_to IS NULL OR c.valid_to>=UTC_DATE()) ORDER BY i.name,s.name", $tenant_id, $patient_id ), ARRAY_A ); }
	private function claim_for_sale( $tenant_id, $branch_id, $sale_id ) { if ( $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->p . 'claims' ) ) !== $this->p . 'claims' ) { return null; } return $this->db->get_row( $this->db->prepare( "SELECT id,claim_number,status,claimed_amount_minor FROM {$this->p}claims WHERE tenant_id=%d AND branch_id=%d AND sale_id=%d LIMIT 1", $tenant_id, $branch_id, $sale_id ), ARRAY_A ); }
	private function valid_witness( $tenant_id, $branch_id, $user_id ) { $membership = $this->db->get_row( $this->db->prepare( "SELECT id,is_admin FROM {$this->p}tenant_memberships WHERE tenant_id=%d AND user_id=%d AND is_active=1", $tenant_id, $user_id ), ARRAY_A ); if ( ! $membership ) { return false; } return (int) $membership['is_admin'] === 1 || (bool) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}membership_branches WHERE membership_id=%d AND branch_id=%d", $membership['id'], $branch_id ) ); }
	private function next_receipt( $tenant_id, $branch_id ) { $table = $this->p . 'document_sequences'; $row = $this->db->get_row( $this->db->prepare( "SELECT id,next_value FROM {$table} WHERE tenant_id=%d AND branch_id=%d AND document_type='clinical_dispense' FOR UPDATE", $tenant_id, $branch_id ), ARRAY_A ); if ( ! $row ) { $this->must_insert( 'document_sequences', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'document_type' => 'clinical_dispense', 'next_value' => 2 ) ); $number = 1; } else { $number = (int) $row['next_value']; if ( 1 !== $this->db->update( $table, array( 'next_value' => $number + 1 ), array( 'id' => $row['id'], 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'document_type' => 'clinical_dispense' ) ) ) { throw new \RuntimeException( 'Dispensing receipt sequence failed.' ); } return sprintf( 'D%06d', $number ); } return sprintf( 'D%06d', $number ); }
	private function date( $date, $allow_today = false ) { $parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date ); if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) { return false; } return $allow_today ? $date <= gmdate( 'Y-m-d' ) : $date < gmdate( 'Y-m-d' ); }
	private function now() { return current_time( 'mysql', true ); }
	private function must_insert( $table, array $row ) { if ( false === $this->db->insert( $this->p . $table, $row ) ) { throw new \RuntimeException( $this->db->last_error ); } }
	private function audit( $tenant_id, $actor_id, $action, $type, $id, array $details ) { do_action( 'pharmasure_audit_log', array( 'tenant_id' => $tenant_id, 'actor_id' => $actor_id, 'action' => $action, 'object_type' => $type, 'object_id' => $id, 'status' => 'success', 'details' => $details ) ); }
	private function error( $code, $message, $status ) { return new \WP_Error( $code, $message, array( 'status' => $status ) ); }
}
