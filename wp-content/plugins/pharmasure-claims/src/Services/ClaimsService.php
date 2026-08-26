<?php
namespace PharmaSure\Claims\Services;

use PharmaSure\Claims\Adapters\ManualSubmissionAdapter;

final class ClaimsService {
	private $db; private $p;
	public function __construct( $db = null ) { global $wpdb; $this->db = $db ?: $wpdb; $this->p = $this->db->prefix . 'ps_'; }

	public function create_insurer( $tenant_id, array $data, $actor_id ) {
		$name = sanitize_text_field( $data['name'] ?? '' ); $code = strtoupper( sanitize_key( $data['code'] ?? '' ) );
		$mode = sanitize_key( $data['submission_mode'] ?? 'manual' );
		if ( ! $tenant_id || ! $name || ! $code || ! in_array( $mode, array( 'manual', 'file', 'api' ), true ) ) { return $this->error( 'invalid_insurer', 'Name, code and a valid submission mode are required.' ); }
		if ( 'manual' !== $mode && empty( $data['adapter_key'] ) ) { return $this->error( 'adapter_required', 'File and API submission modes require a configured adapter.' ); }
		$ok = $this->db->insert( $this->p . 'insurers', array( 'tenant_id' => $tenant_id, 'name' => $name, 'code' => $code, 'payer_type' => sanitize_key( $data['payer_type'] ?? 'medical_aid' ), 'submission_mode' => $mode, 'adapter_key' => sanitize_key( $data['adapter_key'] ?? '' ) ?: null, 'contact_email' => sanitize_email( $data['contact_email'] ?? '' ) ?: null, 'contact_phone' => sanitize_text_field( $data['contact_phone'] ?? '' ) ?: null, 'status' => 'active', 'created_at' => $this->now() ) );
		if ( false === $ok ) { return $this->error( 'insurer_not_created', 'The insurer code already exists or the insurer could not be saved.', 409 ); }
		$id = (int) $this->db->insert_id; $this->audit( $tenant_id, $actor_id, 'claims.insurer_created', 'insurer', $id, array( 'code' => $code, 'submission_mode' => $mode ) ); return array( 'id' => $id, 'name' => $name, 'code' => $code, 'submission_mode' => $mode );
	}

	public function create_scheme( $tenant_id, $insurer_id, array $data, $actor_id ) {
		$insurer = $this->row( "SELECT * FROM {$this->p}insurers WHERE id=%d AND tenant_id=%d AND status='active'", $insurer_id, $tenant_id );
		$name = sanitize_text_field( $data['name'] ?? '' ); $code = strtoupper( sanitize_key( $data['code'] ?? '' ) ); $copay = (int) ( $data['copay_bps'] ?? 0 );
		if ( ! $insurer || ! $name || ! $code || $copay < 0 || $copay > 10000 ) { return $this->error( 'invalid_scheme', 'An active insurer, name, code and copay from 0 to 10000 basis points are required.' ); }
		$ok = $this->db->insert( $this->p . 'insurer_schemes', array( 'tenant_id' => $tenant_id, 'insurer_id' => $insurer_id, 'name' => $name, 'code' => $code, 'copay_bps' => $copay, 'annual_limit_minor' => isset( $data['annual_limit_minor'] ) ? max( 0, (int) $data['annual_limit_minor'] ) : null, 'authorization_required' => empty( $data['authorization_required'] ) ? 0 : 1, 'status' => 'active', 'created_at' => $this->now() ) );
		if ( false === $ok ) { return $this->error( 'scheme_not_created', 'The scheme code already exists or could not be saved.', 409 ); }
		$id = (int) $this->db->insert_id; $this->audit( $tenant_id, $actor_id, 'claims.scheme_created', 'insurer_scheme', $id, array( 'insurer_id' => $insurer_id, 'code' => $code ) ); return array( 'id' => $id, 'insurer_id' => (int) $insurer_id, 'name' => $name, 'code' => $code );
	}

	public function create_cover( $tenant_id, array $data, $actor_id ) {
		$patient_id = absint( $data['patient_id'] ?? 0 ); $insurer_id = absint( $data['insurer_id'] ?? 0 ); $scheme_id = absint( $data['scheme_id'] ?? 0 );
		$patient = $this->row( "SELECT id FROM {$this->p}patients WHERE id=%d AND tenant_id=%d AND status='active'", $patient_id, $tenant_id );
		$scheme = $this->row( "SELECT s.*,i.status insurer_status FROM {$this->p}insurer_schemes s JOIN {$this->p}insurers i ON i.id=s.insurer_id AND i.tenant_id=s.tenant_id WHERE s.id=%d AND s.insurer_id=%d AND s.tenant_id=%d AND s.status='active'", $scheme_id, $insurer_id, $tenant_id );
		$member = sanitize_text_field( $data['member_number'] ?? '' ); $from = $this->date( $data['valid_from'] ?? '' ); $to = empty( $data['valid_to'] ) ? null : $this->date( $data['valid_to'] );
		if ( ! $patient || ! $scheme || 'active' !== $scheme['insurer_status'] || ! $member || ! $from || ( $to && $to < $from ) ) { return $this->error( 'invalid_cover', 'Patient, active scheme, member number and a valid cover period are required.' ); }
		$ok = $this->db->insert( $this->p . 'patient_covers', array( 'tenant_id' => $tenant_id, 'patient_id' => $patient_id, 'insurer_id' => $insurer_id, 'scheme_id' => $scheme_id, 'member_number' => $member, 'dependent_code' => sanitize_text_field( $data['dependent_code'] ?? '' ) ?: null, 'valid_from' => $from, 'valid_to' => $to, 'authorization_number' => sanitize_text_field( $data['authorization_number'] ?? '' ) ?: null, 'status' => 'active', 'created_at' => $this->now() ) );
		if ( false === $ok ) { return $this->error( 'cover_not_created', 'Patient cover could not be saved.', 409 ); }
		$id = (int) $this->db->insert_id; $this->audit( $tenant_id, $actor_id, 'claims.cover_created', 'patient_cover', $id, array( 'patient_id' => $patient_id, 'insurer_id' => $insurer_id, 'scheme_id' => $scheme_id ) ); return array( 'id' => $id, 'patient_id' => $patient_id, 'member_number' => $member );
	}

	public function prepare_claim( $tenant_id, $branch_id, array $data, $actor_id ) {
		$sale_id = absint( $data['sale_id'] ?? 0 ); $cover_id = absint( $data['patient_cover_id'] ?? 0 ); $key = sanitize_text_field( $data['idempotency_key'] ?? '' );
		if ( ! $key ) { return $this->error( 'idempotency_required', 'An idempotency key is required.' ); }
		$existing = $this->row( "SELECT * FROM {$this->p}claims WHERE tenant_id=%d AND idempotency_key=%s", $tenant_id, $key ); if ( $existing ) { $existing['idempotent_replay'] = true; return $existing; }
		$sale = $this->row( "SELECT * FROM {$this->p}sales WHERE id=%d AND tenant_id=%d AND branch_id=%d AND status IN ('completed','partially_refunded')", $sale_id, $tenant_id, $branch_id );
		$cover = $this->row( "SELECT c.*,s.authorization_required,i.submission_mode,i.adapter_key FROM {$this->p}patient_covers c JOIN {$this->p}insurer_schemes s ON s.id=c.scheme_id AND s.tenant_id=c.tenant_id JOIN {$this->p}insurers i ON i.id=c.insurer_id AND i.tenant_id=c.tenant_id WHERE c.id=%d AND c.tenant_id=%d AND c.status='active' AND s.status='active' AND i.status='active'", $cover_id, $tenant_id );
		$service_date = $this->date( $data['service_date'] ?? gmdate( 'Y-m-d' ) );
		if ( ! $sale || ! $cover || ! $sale['patient_id'] || (int) $sale['patient_id'] !== (int) $cover['patient_id'] || ! $service_date || $service_date < $cover['valid_from'] || ( $cover['valid_to'] && $service_date > $cover['valid_to'] ) ) { return $this->error( 'claim_scope_invalid', 'Sale, patient cover and service date must belong to the active tenant, branch and covered patient.', 409 ); }
		$items = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->p}sale_items WHERE tenant_id=%d AND branch_id=%d AND sale_id=%d ORDER BY id", $tenant_id, $branch_id, $sale_id ), ARRAY_A );
		if ( ! $items ) { return $this->error( 'claim_items_required', 'The sale has no itemized lines to claim.' ); }
		$claimed = array_sum( array_map( static fn( $item ) => (int) $item['line_total_minor'], $items ) ); $auth = sanitize_text_field( $data['authorization_number'] ?? $cover['authorization_number'] ?? '' );
		$this->db->query( 'START TRANSACTION' );
		try {
			$number = $this->next_claim_number( $tenant_id, $branch_id );
			$ok = $this->db->insert( $this->p . 'claims', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'patient_id' => $sale['patient_id'], 'patient_cover_id' => $cover_id, 'insurer_id' => $cover['insurer_id'], 'scheme_id' => $cover['scheme_id'], 'sale_id' => $sale_id, 'prescription_id' => $sale['prescription_id'] ?: null, 'claim_number' => $number, 'service_date' => $service_date, 'status' => 'draft', 'claimed_amount_minor' => $claimed, 'authorization_number' => $auth ?: null, 'submission_mode' => $cover['submission_mode'], 'idempotency_key' => $key, 'created_by' => $actor_id, 'created_at' => $this->now() ) );
			if ( false === $ok ) { throw new \DomainException( 'A claim already exists for this sale.' ); }
			$id = (int) $this->db->insert_id;
			foreach ( $items as $item ) { if ( false === $this->db->insert( $this->p . 'claim_items', array( 'tenant_id' => $tenant_id, 'claim_id' => $id, 'sale_item_id' => $item['id'], 'drug_id' => $item['drug_id'], 'description' => $item['description'], 'quantity' => $item['quantity'], 'unit_price_minor' => $item['unit_price_minor'], 'claimed_amount_minor' => $item['line_total_minor'] ) ) ) { throw new \RuntimeException( 'Claim line could not be saved.' ); } }
			$this->event( $tenant_id, $id, 'prepared', null, 'draft', null, array( 'sale_id' => $sale_id ), $actor_id ); $this->db->query( 'COMMIT' );
			$this->audit( $tenant_id, $actor_id, 'claims.claim_prepared', 'claim', $id, array( 'branch_id' => $branch_id, 'claim_number' => $number, 'claimed_amount_minor' => $claimed ) ); return array( 'id' => $id, 'claim_number' => $number, 'status' => 'draft', 'claimed_amount_minor' => $claimed );
		} catch ( \Throwable $e ) { $this->db->query( 'ROLLBACK' ); return $this->error( 'claim_not_prepared', $e->getMessage(), 409 ); }
	}

	public function validate_claim( $tenant_id, $branch_id, $claim_id, $actor_id ) {
		$claim = $this->claim( $tenant_id, $branch_id, $claim_id ); if ( ! $claim || ! in_array( $claim['status'], array( 'draft', 'queried', 'rejected' ), true ) ) { return $this->error( 'claim_not_validatable', 'A draft, queried or rejected branch claim is required.', 409 ); }
		$cover = $this->row( "SELECT c.*,s.authorization_required FROM {$this->p}patient_covers c JOIN {$this->p}insurer_schemes s ON s.id=c.scheme_id AND s.tenant_id=c.tenant_id WHERE c.id=%d AND c.tenant_id=%d", $claim['patient_cover_id'], $tenant_id );
		$count = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->p}claim_items WHERE tenant_id=%d AND claim_id=%d AND claimed_amount_minor>0", $tenant_id, $claim_id ) );
		if ( ! $cover || $claim['service_date'] < $cover['valid_from'] || ( $cover['valid_to'] && $claim['service_date'] > $cover['valid_to'] ) || ! $count ) { return $this->error( 'claim_validation_failed', 'Cover must be valid on the service date and the claim must contain positive lines.' ); }
		if ( $cover['authorization_required'] && ! $claim['authorization_number'] ) { return $this->error( 'authorization_required', 'This scheme requires an authorization number.' ); }
		return $this->transition( $tenant_id, $branch_id, $claim_id, 'validated', 'validated', '', array(), $actor_id );
	}

	public function prepare_submission( $tenant_id, $branch_id, $claim_id, $actor_id ) {
		$claim = $this->claim( $tenant_id, $branch_id, $claim_id ); if ( ! $claim || 'validated' !== $claim['status'] ) { return $this->error( 'claim_not_ready', 'Only a validated claim can be prepared for submission.', 409 ); }
		if ( 'manual' !== $claim['submission_mode'] ) { return $this->error( 'adapter_unavailable', 'The configured insurer adapter is not installed; no electronic submission occurred.', 501 ); }
		$items = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->p}claim_items WHERE tenant_id=%d AND claim_id=%d", $tenant_id, $claim_id ), ARRAY_A );
		$result = ( new ManualSubmissionAdapter() )->prepare( $claim, $items ); return $this->transition( $tenant_id, $branch_id, $claim_id, 'ready_for_submission', 'submission_prepared', 'Manual handoff required.', $result, $actor_id );
	}

	public function mark_submitted( $tenant_id, $branch_id, $claim_id, $payer_reference, $actor_id ) {
		$claim = $this->claim( $tenant_id, $branch_id, $claim_id ); $payer_reference = sanitize_text_field( $payer_reference );
		if ( ! $claim || 'ready_for_submission' !== $claim['status'] || ! $payer_reference ) { return $this->error( 'submission_evidence_required', 'A ready claim and payer submission reference are required.', 409 ); }
		$this->db->update( $this->p . 'claims', array( 'payer_reference' => $payer_reference, 'submitted_at' => $this->now() ), array( 'id' => $claim_id, 'tenant_id' => $tenant_id ) );
		return $this->transition( $tenant_id, $branch_id, $claim_id, 'submitted', 'submitted', '', array( 'payer_reference' => $payer_reference ), $actor_id );
	}

	public function adjudicate( $tenant_id, $branch_id, $claim_id, array $data, $actor_id ) {
		$status = sanitize_key( $data['status'] ?? '' ); $allowed = array( 'acknowledged', 'queried', 'approved', 'partially_approved', 'rejected', 'cancelled' );
		$claim = $this->claim( $tenant_id, $branch_id, $claim_id ); if ( ! $claim || ! in_array( $status, $allowed, true ) ) { return $this->error( 'invalid_claim_decision', 'A valid branch claim decision is required.' ); }
		$valid_from = array( 'submitted', 'acknowledged', 'queried' ); if ( ! in_array( $claim['status'], $valid_from, true ) ) { return $this->error( 'invalid_claim_transition', 'The claim is not awaiting an insurer decision.', 409 ); }
		$reason = sanitize_text_field( $data['reason'] ?? '' ); if ( in_array( $status, array( 'queried', 'rejected', 'cancelled' ), true ) && ! $reason ) { return $this->error( 'decision_reason_required', 'A reason is required for this claim decision.' ); }
		$approved = in_array( $status, array( 'approved', 'partially_approved' ), true ) ? max( 0, (int) ( $data['approved_amount_minor'] ?? 0 ) ) : 0;
		if ( $approved > (int) $claim['claimed_amount_minor'] || ( 'approved' === $status && $approved !== (int) $claim['claimed_amount_minor'] ) || ( 'partially_approved' === $status && ( ! $approved || $approved >= (int) $claim['claimed_amount_minor'] ) ) ) { return $this->error( 'invalid_approved_amount', 'Approved amount must match the claim for approval or be a positive lower amount for partial approval.' ); }
		$update = array( 'approved_amount_minor' => $approved, 'rejected_amount_minor' => max( 0, (int) $claim['claimed_amount_minor'] - $approved ), 'rejection_code' => sanitize_text_field( $data['code'] ?? '' ) ?: null, 'rejection_reason' => $reason ?: null );
		$this->db->update( $this->p . 'claims', $update, array( 'id' => $claim_id, 'tenant_id' => $tenant_id ) ); return $this->transition( $tenant_id, $branch_id, $claim_id, $status, 'adjudicated', $reason, $update, $actor_id );
	}

	public function correct_claim( $tenant_id, $branch_id, $claim_id, array $data, $actor_id ) {
		$claim = $this->claim( $tenant_id, $branch_id, $claim_id ); $reason = sanitize_text_field( $data['reason'] ?? '' );
		if ( ! $claim || ! in_array( $claim['status'], array( 'queried', 'rejected' ), true ) || ! $reason ) { return $this->error( 'claim_not_correctable', 'A queried or rejected claim and a documented correction are required.', 409 ); }
		$authorization = sanitize_text_field( $data['authorization_number'] ?? $claim['authorization_number'] ?? '' );
		$this->db->update( $this->p . 'claims', array( 'authorization_number' => $authorization ?: null, 'rejection_code' => null, 'rejection_reason' => null, 'approved_amount_minor' => 0, 'rejected_amount_minor' => 0, 'updated_at' => $this->now() ), array( 'id' => $claim_id, 'tenant_id' => $tenant_id, 'branch_id' => $branch_id ) );
		return $this->transition( $tenant_id, $branch_id, $claim_id, 'draft', 'corrected', $reason, array( 'authorization_updated' => (bool) $authorization ), $actor_id, $claim['status'] );
	}

	public function reconcile( $tenant_id, array $data, $actor_id ) {
		$insurer_id = absint( $data['insurer_id'] ?? 0 ); $branch_id = absint( $data['_authorized_branch_id'] ?? 0 ); $reference = sanitize_text_field( $data['reference'] ?? '' ); $key = sanitize_text_field( $data['idempotency_key'] ?? '' ); $date = $this->date( $data['received_date'] ?? '' ); $lines = (array) ( $data['items'] ?? array() );
		if ( ! $insurer_id || ! $reference || ! $key || ! $date ) { return $this->error( 'invalid_remittance', 'Insurer, reference, idempotency key and received date are required.' ); }
		$existing = $this->row( "SELECT * FROM {$this->p}remittances WHERE tenant_id=%d AND idempotency_key=%s", $tenant_id, $key ); if ( $existing ) { if ( (int) $existing['insurer_id'] !== $insurer_id || $existing['reference'] !== $reference ) { return $this->error( 'remittance_idempotency_conflict', 'The idempotency key belongs to another remittance.', 409 ); } $existing['idempotent_replay'] = true; return $existing; }
		if ( ! $lines ) { return $this->error( 'invalid_remittance', 'At least one remittance item is required.' ); }
		$this->db->query( 'START TRANSACTION' );
		try {
			$total = 0; $claims = array();
			foreach ( $lines as $line ) { $claim_id = absint( $line['claim_id'] ?? 0 ); $claim = $branch_id ? $this->row( "SELECT * FROM {$this->p}claims WHERE id=%d AND tenant_id=%d AND branch_id=%d AND insurer_id=%d FOR UPDATE", $claim_id, $tenant_id, $branch_id, $insurer_id ) : $this->row( "SELECT * FROM {$this->p}claims WHERE id=%d AND tenant_id=%d AND insurer_id=%d FOR UPDATE", $claim_id, $tenant_id, $insurer_id ); $paid = max( 0, (int) ( $line['paid_amount_minor'] ?? 0 ) ); if ( ! $claim || ! in_array( $claim['status'], array( 'approved', 'partially_approved', 'partially_paid' ), true ) || $paid <= 0 || (int) $claim['paid_amount_minor'] + $paid > (int) $claim['approved_amount_minor'] ) { throw new \DomainException( 'Each remittance line must reference an approved claim in the authorized branch and cannot overpay it.' ); } $total += $paid; $claims[] = array( $claim, $line, $paid ); }
			if ( false === $this->db->insert( $this->p . 'remittances', array( 'tenant_id' => $tenant_id, 'insurer_id' => $insurer_id, 'reference' => $reference, 'received_date' => $date, 'total_paid_minor' => $total, 'status' => 'reconciled', 'idempotency_key' => $key, 'created_by' => $actor_id, 'created_at' => $this->now() ) ) ) { throw new \DomainException( 'Duplicate or invalid remittance.' ); }
			$id = (int) $this->db->insert_id;
			foreach ( $claims as [ $claim, $line, $paid ] ) { $claim_id = (int) $claim['id']; $new_paid = (int) $claim['paid_amount_minor'] + $paid; $status = $new_paid === (int) $claim['approved_amount_minor'] ? 'paid' : 'partially_paid'; $this->db->insert( $this->p . 'remittance_items', array( 'tenant_id' => $tenant_id, 'remittance_id' => $id, 'claim_id' => $claim_id, 'payer_claim_reference' => $claim['payer_reference'], 'paid_amount_minor' => $paid, 'adjustment_amount_minor' => (int) ( $line['adjustment_amount_minor'] ?? 0 ), 'rejection_code' => sanitize_text_field( $line['rejection_code'] ?? '' ) ?: null, 'rejection_reason' => sanitize_text_field( $line['rejection_reason'] ?? '' ) ?: null ) ); $this->db->update( $this->p . 'claims', array( 'paid_amount_minor' => $new_paid, 'status' => $status, 'updated_at' => $this->now() ), array( 'id' => $claim_id, 'tenant_id' => $tenant_id ) ); $this->event( $tenant_id, $claim_id, 'remittance_reconciled', $claim['status'], $status, '', array( 'remittance_id' => $id, 'paid_amount_minor' => $paid ), $actor_id ); }
			$this->db->query( 'COMMIT' ); $this->audit( $tenant_id, $actor_id, 'claims.remittance_reconciled', 'remittance', $id, array( 'insurer_id' => $insurer_id, 'reference' => $reference, 'total_paid_minor' => $total ) ); return array( 'id' => $id, 'reference' => $reference, 'total_paid_minor' => $total, 'status' => 'reconciled' );
		} catch ( \Throwable $e ) { $this->db->query( 'ROLLBACK' ); return $this->error( 'remittance_rejected', $e->getMessage(), 409 ); }
	}

	public function write_off( $tenant_id, $branch_id, $claim_id, $amount, $reason, $actor_id ) {
		$claim = $this->claim( $tenant_id, $branch_id, $claim_id ); $amount = (int) $amount; $reason = sanitize_text_field( $reason );
		$remaining = $claim ? (int) $claim['claimed_amount_minor'] - (int) $claim['paid_amount_minor'] - (int) $claim['writeoff_amount_minor'] : 0;
		if ( ! $claim || ! in_array( $claim['status'], array( 'rejected', 'partially_approved', 'partially_paid' ), true ) || $amount <= 0 || $amount > $remaining || ! $reason ) { return $this->error( 'invalid_writeoff', 'A reason and an amount within the unpaid rejected/partial claim balance are required.', 409 ); }
		$new = (int) $claim['writeoff_amount_minor'] + $amount; $this->db->update( $this->p . 'claims', array( 'writeoff_amount_minor' => $new, 'status' => 'written_off', 'updated_at' => $this->now() ), array( 'id' => $claim_id, 'tenant_id' => $tenant_id ) ); return $this->transition( $tenant_id, $branch_id, $claim_id, 'written_off', 'written_off', $reason, array( 'amount_minor' => $amount ), $actor_id, $claim['status'] );
	}

	public function get_claim( $tenant_id, $branch_id, $claim_id ) { $claim = $this->claim( $tenant_id, $branch_id, $claim_id ); if ( ! $claim ) { return $this->error( 'claim_not_found', 'Claim not found.', 404 ); } $claim['items'] = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->p}claim_items WHERE tenant_id=%d AND claim_id=%d ORDER BY id", $tenant_id, $claim_id ), ARRAY_A ); $claim['events'] = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->p}claim_events WHERE tenant_id=%d AND claim_id=%d ORDER BY id", $tenant_id, $claim_id ), ARRAY_A ); return $claim; }

	private function transition( $tenant_id, $branch_id, $claim_id, $to, $event, $reason, $payload, $actor_id, $from = null ) { $claim = $this->claim( $tenant_id, $branch_id, $claim_id ); if ( ! $claim ) { return $this->error( 'claim_not_found', 'Claim not found.', 404 ); } $from = $from ?: $claim['status']; if ( $from !== $to ) { $this->db->update( $this->p . 'claims', array( 'status' => $to, 'updated_at' => $this->now() ), array( 'id' => $claim_id, 'tenant_id' => $tenant_id ) ); } $this->event( $tenant_id, $claim_id, $event, $from, $to, $reason, $payload, $actor_id ); $this->audit( $tenant_id, $actor_id, 'claims.' . $event, 'claim', $claim_id, array_merge( array( 'branch_id' => $branch_id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason ), $payload ) ); return array( 'id' => (int) $claim_id, 'status' => $to ); }
	private function event( $tenant_id, $claim_id, $event, $from, $to, $reason, $payload, $actor_id ) { $this->db->insert( $this->p . 'claim_events', array( 'tenant_id' => $tenant_id, 'claim_id' => $claim_id, 'event_type' => $event, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason ?: null, 'payload' => wp_json_encode( $payload ), 'actor_id' => $actor_id, 'created_at' => $this->now() ) ); }
	private function claim( $tenant_id, $branch_id, $id ) { return $this->row( "SELECT * FROM {$this->p}claims WHERE id=%d AND tenant_id=%d AND branch_id=%d", $id, $tenant_id, $branch_id ); }
	private function next_claim_number( $tenant_id, $branch_id ) { $table = $this->p . 'document_sequences'; $row = $this->row( "SELECT id,next_value FROM {$table} WHERE tenant_id=%d AND branch_id=%d AND document_type='claim' FOR UPDATE", $tenant_id, $branch_id ); if ( ! $row ) { if ( false === $this->db->insert( $table, array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'document_type' => 'claim', 'next_value' => 2 ) ) ) { throw new \RuntimeException( 'Claim sequence could not be created.' ); } $value = 1; } else { $value = (int) $row['next_value']; if ( 1 !== $this->db->update( $table, array( 'next_value' => $value + 1 ), array( 'id' => (int) $row['id'] ) ) ) { throw new \RuntimeException( 'Claim sequence could not be updated.' ); } } return sprintf( 'C%08d', $value ); }
	private function row( $sql, ...$args ) { return $this->db->get_row( $this->db->prepare( $sql, ...$args ), ARRAY_A ); }
	private function date( $value ) { $d = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $value ); return $d && $d->format( 'Y-m-d' ) === $value ? $value : null; }
	private function now() { return current_time( 'mysql', true ); }
	private function error( $code, $message, $status = 422 ) { return new \WP_Error( $code, $message, array( 'status' => $status ) ); }
	private function audit( $tenant_id, $actor_id, $action, $type, $id, $details ) { do_action( 'pharmasure_audit_log', array( 'tenant_id' => $tenant_id, 'actor_id' => $actor_id, 'action' => $action, 'object_type' => $type, 'object_id' => $id, 'details' => $details ) ); }
}
