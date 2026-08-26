<?php
namespace PharmaSure\POS\Services;

use PharmaSure\Inventory\Services\StockAllocator;

final class PosService {
	private $db;
	private $p;
	private const PAYMENT_METHODS = array( 'cash', 'card', 'mobile_money', 'bank_transfer', 'medical_aid' );

	public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix . 'ps_'; }

	public function create_till( $tenant_id, $branch_id, array $data ) {
		$name = sanitize_text_field( $data['name'] ?? '' );
		$code = strtoupper( sanitize_key( $data['code'] ?? '' ) );
		if ( ! $name || ! $code || ! $this->owns_active_branch( $tenant_id, $branch_id ) ) { return new \WP_Error( 'invalid_till', 'An active branch, till name and code are required.', array( 'status' => 422 ) ); }
		$ok = $this->db->insert( $this->p . 'tills', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'name' => $name, 'code' => $code, 'status' => 'active', 'created_at' => current_time( 'mysql', true ), 'created_by' => get_current_user_id() ) );
		if ( false === $ok ) { return new \WP_Error( 'till_not_created', 'Till code must be unique in this branch.', array( 'status' => 409 ) ); }
		$id = (int) $this->db->insert_id;
		$this->audit( $tenant_id, get_current_user_id(), 'pos.till_created', 'till', $id, array( 'branch_id' => $branch_id, 'code' => $code ) );
		return array( 'id' => $id );
	}

	public function open_session( $tenant_id, $branch_id, $till_id, $cashier_id, $opening_float_minor ) {
		if ( $opening_float_minor < 0 ) { return new \WP_Error( 'invalid_till', 'An active branch till and non-negative opening float are required.', array( 'status' => 422 ) ); }
		$this->db->query( 'START TRANSACTION' );
		$till = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}tills WHERE id=%d AND tenant_id=%d AND branch_id=%d AND status='active' FOR UPDATE", $till_id, $tenant_id, $branch_id ) );
		if ( ! $till ) { $this->db->query( 'ROLLBACK' ); return new \WP_Error( 'invalid_till', 'An active branch till and non-negative opening float are required.', array( 'status' => 422 ) ); }
		$open = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}till_sessions WHERE tenant_id=%d AND branch_id=%d AND till_id=%d AND status='open' LIMIT 1", $tenant_id, $branch_id, $till_id ) );
		if ( $open ) { $this->db->query( 'ROLLBACK' ); return new \WP_Error( 'till_already_open', 'This till already has an open session.', array( 'status' => 409 ) ); }
		$cashier_open = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}till_sessions WHERE tenant_id=%d AND branch_id=%d AND cashier_id=%d AND status='open' LIMIT 1", $tenant_id, $branch_id, $cashier_id ) );
		if ( $cashier_open ) { $this->db->query( 'ROLLBACK' ); return new \WP_Error( 'cashier_session_open', 'This cashier already has an open session in the branch.', array( 'status' => 409 ) ); }
		$ok = $this->db->insert( $this->p . 'till_sessions', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'till_id' => $till_id, 'cashier_id' => $cashier_id, 'status' => 'open', 'opening_float_minor' => (int) $opening_float_minor, 'opened_at' => current_time( 'mysql', true ) ) );
		if ( false === $ok ) { $this->db->query( 'ROLLBACK' ); return new \WP_Error( 'session_not_opened', 'Till session could not be opened.', array( 'status' => 500 ) ); }
		$id = (int) $this->db->insert_id; $this->db->query( 'COMMIT' );
		$this->audit( $tenant_id, $cashier_id, 'pos.till_opened', 'till_session', $id, array( 'branch_id' => $branch_id, 'till_id' => $till_id, 'opening_float_minor' => (int) $opening_float_minor ) );
		return array( 'id' => $id );
	}

	public function close_session( $tenant_id, $branch_id, $session_id, $cashier_id, $counted_cash_minor ) {
		if ( $counted_cash_minor < 0 ) { return new \WP_Error( 'invalid_cash_count', 'Counted cash cannot be negative.', array( 'status' => 422 ) ); }
		$this->db->query( 'START TRANSACTION' );
		$session = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->p}till_sessions WHERE id=%d AND tenant_id=%d AND branch_id=%d AND cashier_id=%d AND status='open' FOR UPDATE", $session_id, $tenant_id, $branch_id, $cashier_id ), ARRAY_A );
		if ( ! $session ) { $this->db->query( 'ROLLBACK' ); return new \WP_Error( 'invalid_till_session', 'Open till session not found for this cashier.', array( 'status' => 409 ) ); }
		$cash_sales = (int) $this->db->get_var( $this->db->prepare( "SELECT COALESCE(SUM(p.amount_minor),0) FROM {$this->p}sale_payments p JOIN {$this->p}sales s ON s.id=p.sale_id AND s.tenant_id=p.tenant_id AND s.branch_id=p.branch_id WHERE s.tenant_id=%d AND s.branch_id=%d AND s.till_session_id=%d AND p.tenant_id=%d AND p.branch_id=%d AND p.method='cash' AND p.status='captured'", $tenant_id, $branch_id, $session_id, $tenant_id, $branch_id ) );
		$cash_refunds = (int) $this->db->get_var( $this->db->prepare( "SELECT COALESCE(SUM(rp.amount_minor),0) FROM {$this->p}refund_payments rp JOIN {$this->p}refunds r ON r.id=rp.refund_id AND r.tenant_id=rp.tenant_id JOIN {$this->p}sales s ON s.id=r.sale_id AND s.tenant_id=r.tenant_id AND s.branch_id=r.branch_id WHERE s.tenant_id=%d AND s.branch_id=%d AND s.till_session_id=%d AND rp.tenant_id=%d AND rp.method='cash' AND rp.status='completed'", $tenant_id, $branch_id, $session_id, $tenant_id ) );
		$expected = (int) $session['opening_float_minor'] + $cash_sales - $cash_refunds;
		$variance = (int) $counted_cash_minor - $expected;
		$updated = $this->db->update( $this->p . 'till_sessions', array( 'status' => 'closed', 'expected_cash_minor' => $expected, 'counted_cash_minor' => (int) $counted_cash_minor, 'variance_minor' => $variance, 'variance_status' => 0 === $variance ? 'not_required' : 'pending', 'closed_at' => current_time( 'mysql', true ) ), array( 'id' => $session_id, 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'cashier_id' => $cashier_id, 'status' => 'open' ) );
		if ( 1 !== $updated ) { $this->db->query( 'ROLLBACK' ); return new \WP_Error( 'session_not_closed', 'Till session could not be closed.', array( 'status' => 409 ) ); }
		$this->db->query( 'COMMIT' );
		do_action( 'pharmasure_audit_log', array( 'tenant_id' => $tenant_id, 'actor_id' => $cashier_id, 'action' => 'pos.till_closed', 'object_type' => 'till_session', 'object_id' => $session_id, 'details' => array( 'branch_id' => $branch_id, 'expected_cash_minor' => $expected, 'counted_cash_minor' => (int) $counted_cash_minor, 'variance_minor' => (int) $counted_cash_minor - $expected ) ) );
		return array( 'id' => $session_id, 'expected_cash_minor' => $expected, 'counted_cash_minor' => (int) $counted_cash_minor, 'variance_minor' => $variance, 'variance_status' => 0 === $variance ? 'not_required' : 'pending' );
	}

	public function approve_variance( $tenant_id, $branch_id, $session_id, $manager_id, $reason ) {
		$reason = sanitize_text_field( $reason ); if ( ! $reason ) { return new \WP_Error( 'variance_reason_required', 'A manager reason is required.', array( 'status' => 422 ) ); }
		$updated = $this->db->update( $this->p . 'till_sessions', array( 'variance_status' => 'approved', 'variance_reason' => $reason, 'variance_approved_by' => $manager_id, 'variance_approved_at' => current_time( 'mysql', true ) ), array( 'id' => $session_id, 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'status' => 'closed', 'variance_status' => 'pending' ) );
		if ( 1 !== $updated ) { return new \WP_Error( 'variance_not_pending', 'A pending branch till variance was not found.', array( 'status' => 409 ) ); }
		do_action( 'pharmasure_audit_log', array( 'tenant_id' => $tenant_id, 'actor_id' => $manager_id, 'action' => 'pos.variance_approved', 'object_type' => 'till_session', 'object_id' => $session_id, 'details' => array( 'branch_id' => $branch_id, 'reason' => $reason ) ) ); return array( 'id' => (int) $session_id, 'variance_status' => 'approved' );
	}

	public function search_products( $tenant_id, $branch_id, $term, $limit = 20 ) {
		$term = trim( sanitize_text_field( $term ) ); $limit = min( 50, max( 1, (int) $limit ) );
		if ( '' === $term ) { return array(); }
		$like = '%' . $this->db->esc_like( $term ) . '%';
		return $this->db->get_results( $this->db->prepare(
			"SELECT d.id,d.sku,d.barcode,d.name,d.generic_name,d.strength,d.selling_price_minor,d.requires_prescription,COALESCE(s.quantity_available,0) quantity_available
			 FROM {$this->p}drugs d LEFT JOIN {$this->p}stock_balances s ON s.tenant_id=d.tenant_id AND s.drug_id=d.id AND s.branch_id=%d
			 WHERE d.tenant_id=%d AND d.status='active' AND (d.barcode=%s OR d.sku=%s OR d.name LIKE %s OR d.generic_name LIKE %s)
			 ORDER BY (d.barcode=%s) DESC,(d.sku=%s) DESC,d.name LIMIT %d",
			$branch_id, $tenant_id, $term, $term, $like, $like, $term, $term, $limit
		), ARRAY_A );
	}

	/** Build the complete branch and cashier state for the standalone POS shell. */
	public function workspace( $tenant_id, $branch_id, $cashier_id ) {
		if ( ! $this->owns_active_branch( $tenant_id, $branch_id ) ) {
			return new \WP_Error( 'invalid_pos_scope', 'An authorized active branch is required.', array( 'status' => 403 ) );
		}
		$scope = $this->db->get_row( $this->db->prepare( "SELECT t.trading_name,t.currency,b.name branch_name FROM {$this->p}tenants t JOIN {$this->p}branches b ON b.tenant_id=t.id AND b.id=%d AND b.is_active=1 WHERE t.id=%d", $branch_id, $tenant_id ), ARRAY_A );
		$tills = $this->db->get_results( $this->db->prepare( "SELECT t.id,t.name,t.code,t.status,s.id session_id,s.cashier_id,s.opened_at FROM {$this->p}tills t LEFT JOIN {$this->p}till_sessions s ON s.tenant_id=t.tenant_id AND s.branch_id=t.branch_id AND s.till_id=t.id AND s.status='open' WHERE t.tenant_id=%d AND t.branch_id=%d AND t.status='active' ORDER BY t.name", $tenant_id, $branch_id ), ARRAY_A );
		$session = $this->db->get_row( $this->db->prepare( "SELECT s.id,s.till_id,s.opening_float_minor,s.opened_at,t.name till_name,t.code till_code FROM {$this->p}till_sessions s JOIN {$this->p}tills t ON t.id=s.till_id AND t.tenant_id=s.tenant_id AND t.branch_id=s.branch_id WHERE s.tenant_id=%d AND s.branch_id=%d AND s.cashier_id=%d AND s.status='open' ORDER BY s.opened_at DESC LIMIT 1", $tenant_id, $branch_id, $cashier_id ), ARRAY_A );
		$products = $this->db->get_results( $this->db->prepare( "SELECT d.id,d.sku,d.barcode,d.name,d.generic_name,d.strength,d.dosage_form,d.selling_price_minor,d.requires_prescription,COALESCE(s.quantity_available,0) quantity_available FROM {$this->p}drugs d JOIN {$this->p}stock_balances s ON s.tenant_id=d.tenant_id AND s.drug_id=d.id AND s.branch_id=%d WHERE d.tenant_id=%d AND d.status='active' AND s.quantity_available>0 ORDER BY d.requires_prescription,d.name LIMIT %d", $branch_id, $tenant_id, 18 ), ARRAY_A );
		$sales = $this->db->get_results( $this->db->prepare( "SELECT s.id,s.receipt_number,s.subtotal_amount_minor,s.discount_amount_minor,s.tax_amount_minor,s.total_amount_minor,s.status,s.created_at,COUNT(si.id) item_count FROM {$this->p}sales s LEFT JOIN {$this->p}sale_items si ON si.tenant_id=s.tenant_id AND si.branch_id=s.branch_id AND si.sale_id=s.id WHERE s.tenant_id=%d AND s.branch_id=%d GROUP BY s.id,s.receipt_number,s.subtotal_amount_minor,s.discount_amount_minor,s.tax_amount_minor,s.total_amount_minor,s.status,s.created_at ORDER BY s.created_at DESC,s.id DESC LIMIT %d", $tenant_id, $branch_id, 20 ), ARRAY_A );
		foreach ( $sales as &$sale ) {
			$sale['items'] = $this->db->get_results( $this->db->prepare( "SELECT drug_id,description,quantity,unit_price_minor,line_total_minor FROM {$this->p}sale_items WHERE tenant_id=%d AND branch_id=%d AND sale_id=%d ORDER BY id", $tenant_id, $branch_id, $sale['id'] ), ARRAY_A );
			$sale['payments'] = $this->db->get_results( $this->db->prepare( "SELECT method,amount_minor,currency,external_reference,status FROM {$this->p}sale_payments WHERE tenant_id=%d AND branch_id=%d AND sale_id=%d ORDER BY id", $tenant_id, $branch_id, $sale['id'] ), ARRAY_A );
		}
		unset( $sale );
		$metrics = $this->db->get_row( $this->db->prepare( "SELECT COUNT(*) transaction_count,COALESCE(SUM(CASE WHEN status<>'voided' THEN total_amount_minor ELSE 0 END),0) gross_minor,COALESCE(SUM(CASE WHEN status='voided' THEN total_amount_minor ELSE 0 END),0) voided_minor FROM {$this->p}sales WHERE tenant_id=%d AND branch_id=%d AND created_at>=UTC_DATE()", $tenant_id, $branch_id ), ARRAY_A );
		$metrics['refund_minor'] = (int) $this->db->get_var( $this->db->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$this->p}refunds WHERE tenant_id=%d AND branch_id=%d AND status IN ('completed','pending_provider') AND created_at>=UTC_DATE()", $tenant_id, $branch_id ) );
		$metrics['net_minor'] = (int) $metrics['gross_minor'] - (int) $metrics['refund_minor'];
		return array(
			'scope' => $scope,
			'session' => $session ?: null,
			'tills' => $tills ?: array(),
			'products' => $products ?: array(),
			'holds' => $this->list_holds( $tenant_id, $branch_id ),
			'recent_sales' => $sales ?: array(),
			'metrics' => $metrics,
			'pricing' => $this->pricing_policy( $tenant_id, $branch_id ),
		);
	}

	public function configure_pricing( $tenant_id, $branch_id, $tax_rate_bps, $max_discount_bps ) {
		$tax_rate_bps = (int) $tax_rate_bps; $max_discount_bps = (int) $max_discount_bps;
		if ( $tax_rate_bps < 0 || $tax_rate_bps > 10000 || $max_discount_bps < 0 || $max_discount_bps > 10000 ) { return new \WP_Error( 'invalid_pricing_policy', 'Tax and discount basis points must be between 0 and 10000.', array( 'status' => 422 ) ); }
		foreach ( array( 'pos_tax_rate_bps' => $tax_rate_bps, 'pos_max_discount_bps' => $max_discount_bps ) as $key => $value ) {
			$sql = $this->db->prepare( "INSERT INTO {$this->p}settings (tenant_id,branch_id,setting_key,setting_value,created_at,updated_at) VALUES (%d,%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=VALUES(updated_at)", $tenant_id, $branch_id, $key, (string) $value, current_time( 'mysql', true ), current_time( 'mysql', true ) );
			$this->db->query( $sql ); if ( $this->db->last_error ) { return new \WP_Error( 'pricing_not_saved', 'Pricing policy could not be saved.', array( 'status' => 500 ) ); }
		}
		$this->audit( $tenant_id, get_current_user_id(), 'pos.pricing_configured', 'branch', $branch_id, array( 'tax_rate_bps' => $tax_rate_bps, 'max_discount_bps' => $max_discount_bps ) );
		return array( 'tax_rate_bps' => $tax_rate_bps, 'max_discount_bps' => $max_discount_bps );
	}

	public function checkout( $tenant_id, $branch_id, $session_id, $cashier_id, array $data, $correlation_id, $can_discount = false ) {
		$idempotency = sanitize_text_field( $data['idempotency_key'] ?? '' );
		$items = $data['items'] ?? array();
		$payments = $data['payments'] ?? array();
		if ( ! $idempotency || strlen( $idempotency ) > 100 || ! is_array( $items ) || ! $items || ! is_array( $payments ) || ! $payments ) {
			return new \WP_Error( 'invalid_checkout', 'Idempotency key, items and payments are required.', array( 'status' => 422 ) );
		}
		$existing = $this->db->get_row( $this->db->prepare( "SELECT id,branch_id,cashier_id,receipt_number,total_amount_minor FROM {$this->p}sales WHERE tenant_id=%d AND idempotency_key=%s", $tenant_id, $idempotency ), ARRAY_A );
		if ( $existing ) {
			if ( (int) $existing['branch_id'] !== (int) $branch_id || (int) $existing['cashier_id'] !== (int) $cashier_id ) { return new \WP_Error( 'idempotency_conflict', 'Idempotency key is already used by another checkout context.', array( 'status' => 409 ) ); }
			unset( $existing['branch_id'], $existing['cashier_id'] ); $existing['idempotent_replay'] = true; return $existing;
		}

		$normalized = $this->normalize_items( $tenant_id, $items );
		if ( is_wp_error( $normalized ) ) { return $normalized; }
		$subtotal = array_sum( array_column( $normalized, 'line_total_minor' ) );
		$policy = $this->pricing_policy( $tenant_id, $branch_id );
		$discount = max( 0, (int) ( $data['discount_amount_minor'] ?? 0 ) );
		$discount_reason = sanitize_text_field( $data['discount_reason'] ?? '' );
		if ( $discount > 0 && ( ! $can_discount || ! $discount_reason ) ) { return new \WP_Error( 'discount_forbidden', 'Discount permission and a reason are required.', array( 'status' => 403 ) ); }
		$maximum_discount = (int) floor( $subtotal * $policy['max_discount_bps'] / 10000 );
		if ( $discount > $maximum_discount ) { return new \WP_Error( 'discount_exceeded', 'Discount exceeds this branch policy.', array( 'status' => 422, 'maximum_discount_minor' => $maximum_discount ) ); }
		$taxable = $subtotal - $discount;
		$tax = (int) round( $taxable * $policy['tax_rate_bps'] / 10000 );
		$total = $taxable + $tax;
		$currency = strtoupper( (string) $this->db->get_var( $this->db->prepare( "SELECT currency FROM {$this->p}tenants WHERE id=%d", $tenant_id ) ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) { $currency = 'USD'; }
		$valid_payments = $this->normalize_payments( $payments, $currency );
		if ( is_wp_error( $valid_payments ) ) { return $valid_payments; }
		if ( array_sum( array_column( $valid_payments, 'amount_minor' ) ) !== $total ) {
			return new \WP_Error( 'payment_mismatch', 'Payment amounts must exactly equal the sale total.', array( 'status' => 422, 'total_amount_minor' => $total ) );
		}

		$this->db->query( 'START TRANSACTION' );
		try {
			$session = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->p}till_sessions WHERE id=%d AND tenant_id=%d AND branch_id=%d AND cashier_id=%d AND status='open' FOR UPDATE", $session_id, $tenant_id, $branch_id, $cashier_id ), ARRAY_A );
			if ( ! $session ) { throw new \DomainException( 'No open till session belongs to this cashier and branch.' ); }
			$hold_id = absint( $data['hold_id'] ?? 0 );
			if ( $hold_id && ! $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}sale_holds WHERE id=%d AND tenant_id=%d AND branch_id=%d AND till_session_id=%d AND held_by=%d AND status='held' FOR UPDATE", $hold_id, $tenant_id, $branch_id, $session_id, $cashier_id ) ) ) { throw new \DomainException( 'The held sale is no longer active in this till session.' ); }
			$patient_id = empty( $data['patient_id'] ) ? null : (int) $data['patient_id'];
			if ( $patient_id && ! $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}patients WHERE id=%d AND tenant_id=%d AND branch_id=%d AND status='active'", $patient_id, $tenant_id, $branch_id ) ) ) { throw new \DomainException( 'Patient does not belong to this tenant and branch.' ); }
			$receipt = $this->next_receipt( $tenant_id, $branch_id );
			$now = current_time( 'mysql', true );
			$this->must_insert( 'sales', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'till_session_id' => $session_id, 'patient_id' => $patient_id, 'receipt_number' => $receipt, 'idempotency_key' => $idempotency, 'subtotal_amount_minor' => $subtotal, 'discount_amount_minor' => $discount, 'discount_reason' => $discount_reason ?: null, 'tax_amount_minor' => $tax, 'total_amount_minor' => $total, 'status' => 'completed', 'cashier_id' => $cashier_id, 'created_at' => $now ) );
			$sale_id = (int) $this->db->insert_id;
			$allocator = new StockAllocator();
			foreach ( $normalized as $item ) {
				$this->must_insert( 'sale_items', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'sale_id' => $sale_id, 'drug_id' => $item['drug_id'], 'description' => $item['description'], 'quantity' => $item['quantity'], 'unit_price_minor' => $item['unit_price_minor'], 'line_total_minor' => $item['line_total_minor'] ) );
				$allocation = $allocator->consume_fefo( $tenant_id, $branch_id, $item['drug_id'], $item['quantity'], 'sale', $sale_id, $correlation_id, 'POS sale ' . $receipt );
				if ( is_wp_error( $allocation ) ) { $this->db->query( 'ROLLBACK' ); return $allocation; }
				$cost = array_sum( array_map( static fn( $batch ) => (int) ( $batch['cost_amount_minor'] ?? 0 ), $allocation ) );
				if ( false === $this->db->update( $this->p . 'sale_items', array( 'cost_amount_minor' => $cost ), array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'sale_id' => $sale_id, 'drug_id' => $item['drug_id'] ) ) ) { throw new \RuntimeException( 'Historical sale cost could not be captured.' ); }
			}
			foreach ( $valid_payments as $payment ) { $payment['tenant_id'] = $tenant_id; $payment['branch_id'] = $branch_id; $payment['sale_id'] = $sale_id; $payment['created_at'] = $now; $this->must_insert( 'sale_payments', $payment ); }
			if ( $hold_id && 1 !== $this->db->update( $this->p . 'sale_holds', array( 'status' => 'completed' ), array( 'id' => $hold_id, 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'till_session_id' => $session_id, 'held_by' => $cashier_id, 'status' => 'held' ) ) ) { throw new \RuntimeException( 'Held-sale completion could not be recorded.' ); }
			$this->db->query( 'COMMIT' );
			do_action( 'pharmasure_audit_log', array( 'tenant_id' => $tenant_id, 'actor_id' => $cashier_id, 'action' => 'pos.sale_completed', 'object_type' => 'sale', 'object_id' => $sale_id, 'details' => array( 'branch_id' => $branch_id, 'receipt_number' => $receipt, 'subtotal_amount_minor' => $subtotal, 'discount_amount_minor' => $discount, 'discount_reason' => $discount_reason, 'tax_amount_minor' => $tax, 'total_amount_minor' => $total, 'payment_count' => count( $valid_payments ), 'resumed_hold_id' => $hold_id ?: null ) ) );
			return array( 'id' => $sale_id, 'receipt_number' => $receipt, 'subtotal_amount_minor' => $subtotal, 'discount_amount_minor' => $discount, 'tax_amount_minor' => $tax, 'total_amount_minor' => $total, 'idempotent_replay' => false );
		} catch ( \DomainException $error ) {
			$this->db->query( 'ROLLBACK' );
			return new \WP_Error( 'invalid_till_session', $error->getMessage(), array( 'status' => 409 ) );
		} catch ( \Throwable $error ) {
			$this->db->query( 'ROLLBACK' );
			$replay = $this->db->get_row( $this->db->prepare( "SELECT id,branch_id,cashier_id,receipt_number,total_amount_minor FROM {$this->p}sales WHERE tenant_id=%d AND idempotency_key=%s", $tenant_id, $idempotency ), ARRAY_A );
			if ( $replay && (int) $replay['branch_id'] === (int) $branch_id && (int) $replay['cashier_id'] === (int) $cashier_id ) {
				unset( $replay['branch_id'], $replay['cashier_id'] ); $replay['idempotent_replay'] = true; return $replay;
			}
			return new \WP_Error( 'checkout_failed', 'No payment, sale or stock change was saved.', array( 'status' => 409 ) );
		}
	}

	private function normalize_items( $tenant_id, array $items ) {
		$combined = array();
		foreach ( $items as $item ) { $id = (int) ( $item['drug_id'] ?? 0 ); $qty = (float) ( $item['quantity'] ?? 0 ); if ( ! $id || $qty <= 0 ) { return new \WP_Error( 'invalid_sale_item', 'Every item needs a drug and positive quantity.', array( 'status' => 422 ) ); } $combined[ $id ] = ( $combined[ $id ] ?? 0 ) + $qty; }
		$out = array();
		foreach ( $combined as $drug_id => $quantity ) {
			$drug = $this->db->get_row( $this->db->prepare( "SELECT id,name,selling_price_minor,requires_prescription FROM {$this->p}drugs WHERE id=%d AND tenant_id=%d AND status='active'", $drug_id, $tenant_id ), ARRAY_A );
			if ( ! $drug ) { return new \WP_Error( 'invalid_drug_scope', 'A sale item is not active in this tenant.', array( 'status' => 403 ) ); }
			if ( ! empty( $drug['requires_prescription'] ) ) { return new \WP_Error( 'prescription_required', 'Prescription-only items must be processed through clinical dispensing.', array( 'status' => 409, 'drug_id' => $drug_id ) ); }
			$price = (int) $drug['selling_price_minor'];
			$out[] = array( 'drug_id' => (int) $drug_id, 'description' => $drug['name'], 'quantity' => $quantity, 'unit_price_minor' => $price, 'line_total_minor' => (int) round( $quantity * $price ) );
		}
		return $out;
	}

	private function normalize_payments( array $payments, $currency = 'USD' ) {
		$out = array();
		foreach ( $payments as $payment ) {
			$method = sanitize_key( $payment['method'] ?? '' ); $amount = (int) ( $payment['amount_minor'] ?? 0 ); $reference = sanitize_text_field( $payment['external_reference'] ?? '' );
			if ( ! in_array( $method, self::PAYMENT_METHODS, true ) || $amount <= 0 || ( 'cash' !== $method && ! $reference ) ) { return new \WP_Error( 'invalid_payment', 'Each tender needs a supported method, positive amount and external reference for non-cash payments.', array( 'status' => 422 ) ); }
			$out[] = array( 'method' => $method, 'amount_minor' => $amount, 'currency' => $currency, 'external_reference' => $reference ?: null, 'status' => 'cash' === $method ? 'captured' : 'recorded' );
		}
		return $out;
	}

	public function pricing_policy( $tenant_id, $branch_id ) {
		$rows = $this->db->get_results( $this->db->prepare( "SELECT setting_key,setting_value FROM {$this->p}settings WHERE tenant_id=%d AND branch_id=%d AND setting_key IN ('pos_tax_rate_bps','pos_max_discount_bps')", $tenant_id, $branch_id ), ARRAY_A );
		$policy = array( 'tax_rate_bps' => 0, 'max_discount_bps' => 0 );
		foreach ( $rows as $row ) { if ( 'pos_tax_rate_bps' === $row['setting_key'] ) { $policy['tax_rate_bps'] = min( 10000, max( 0, (int) $row['setting_value'] ) ); } elseif ( 'pos_max_discount_bps' === $row['setting_key'] ) { $policy['max_discount_bps'] = min( 10000, max( 0, (int) $row['setting_value'] ) ); } }
		return $policy;
	}

	public function hold_sale( $tenant_id, $branch_id, $session_id, $user_id, array $data ) {
		$session = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}till_sessions WHERE id=%d AND tenant_id=%d AND branch_id=%d AND cashier_id=%d AND status='open'", $session_id, $tenant_id, $branch_id, $user_id ) );
		if ( ! $session ) { return new \WP_Error( 'invalid_till_session', 'An open till session is required.', array( 'status' => 409 ) ); }
		$items = $this->normalize_items( $tenant_id, (array) ( $data['items'] ?? array() ) );
		if ( is_wp_error( $items ) || ! $items ) { return is_wp_error( $items ) ? $items : new \WP_Error( 'empty_hold', 'A held sale needs at least one item.', array( 'status' => 422 ) ); }
		$reference = 'H-' . strtoupper( substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 ) );
		$ok = $this->db->insert( $this->p . 'sale_holds', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'till_session_id' => $session_id, 'reference' => $reference, 'cart_data' => wp_json_encode( array( 'items' => $items, 'discount_amount_minor' => max( 0, (int) ( $data['discount_amount_minor'] ?? 0 ) ), 'discount_reason' => sanitize_text_field( $data['discount_reason'] ?? '' ) ) ), 'notes' => sanitize_text_field( $data['notes'] ?? '' ), 'status' => 'held', 'held_by' => $user_id, 'held_at' => current_time( 'mysql', true ) ) );
		if ( false === $ok ) { return new \WP_Error( 'hold_failed', 'Sale could not be held.', array( 'status' => 500 ) ); }
		$id = (int) $this->db->insert_id; do_action( 'pharmasure_audit_log', array( 'tenant_id' => $tenant_id, 'actor_id' => $user_id, 'action' => 'pos.sale_held', 'object_type' => 'sale_hold', 'object_id' => $id, 'details' => array( 'branch_id' => $branch_id, 'reference' => $reference, 'items' => count( $items ) ) ) ); return array( 'id' => $id, 'reference' => $reference );
	}

	public function list_holds( $tenant_id, $branch_id ) {
		$rows = $this->db->get_results( $this->db->prepare( "SELECT id,reference,cart_data,notes,held_by,held_at FROM {$this->p}sale_holds WHERE tenant_id=%d AND branch_id=%d AND status='held' ORDER BY held_at DESC LIMIT 100", $tenant_id, $branch_id ), ARRAY_A );
		foreach ( $rows as &$row ) { $row['cart'] = json_decode( $row['cart_data'], true ); unset( $row['cart_data'] ); }
		return $rows;
	}

	public function cancel_hold( $tenant_id, $branch_id, $hold_id, $user_id ) {
		$updated = $this->db->update( $this->p . 'sale_holds', array( 'status' => 'cancelled', 'cancelled_at' => current_time( 'mysql', true ) ), array( 'id' => $hold_id, 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'status' => 'held' ) );
		if ( 1 !== $updated ) { return new \WP_Error( 'hold_not_found', 'Active held sale was not found.', array( 'status' => 404 ) ); }
		do_action( 'pharmasure_audit_log', array( 'tenant_id' => $tenant_id, 'actor_id' => $user_id, 'action' => 'pos.hold_cancelled', 'object_type' => 'sale_hold', 'object_id' => $hold_id, 'details' => array( 'branch_id' => $branch_id ) ) );
		return array( 'id' => $hold_id, 'status' => 'cancelled' );
	}

	public function refund_sale( $tenant_id, $branch_id, $sale_id, $user_id, array $data, $correlation_id, $kind = 'refund' ) {
		$reason = sanitize_text_field( $data['reason'] ?? '' ); $disposition = sanitize_key( $data['disposition'] ?? 'quarantine' );
		$idempotency = sanitize_text_field( $data['idempotency_key'] ?? '' );
		if ( ! $reason || ! $idempotency || strlen( $idempotency ) > 100 || ! in_array( $disposition, array( 'restock', 'quarantine' ), true ) ) { return new \WP_Error( 'invalid_refund', 'An idempotency key, reason and valid stock disposition are required.', array( 'status' => 422 ) ); }
		$this->db->query( 'START TRANSACTION' );
		try {
			$sale = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->p}sales WHERE id=%d AND tenant_id=%d AND branch_id=%d FOR UPDATE", $sale_id, $tenant_id, $branch_id ), ARRAY_A );
			if ( ! $sale ) { throw new \DomainException( 'Sale was not found in this branch.' ); }
			$existing_refund = $this->db->get_row( $this->db->prepare( "SELECT id,sale_id,amount_minor,status FROM {$this->p}refunds WHERE tenant_id=%d AND idempotency_key=%s", $tenant_id, $idempotency ), ARRAY_A );
			if ( $existing_refund ) { $this->db->query( 'ROLLBACK' ); if ( (int) $existing_refund['sale_id'] !== (int) $sale_id ) { return new \WP_Error( 'refund_idempotency_conflict', 'Refund idempotency key belongs to another sale.', array( 'status' => 409 ) ); } $existing_refund['idempotent_replay'] = true; return $existing_refund; }
			if ( ! in_array( $sale['status'], array( 'completed', 'partially_refunded' ), true ) ) { throw new \DomainException( 'Sale is no longer refundable.' ); }
			$sale_items = $this->db->get_results( $this->db->prepare( "SELECT drug_id,description,quantity,unit_price_minor FROM {$this->p}sale_items WHERE sale_id=%d AND tenant_id=%d AND branch_id=%d ORDER BY id", $sale_id, $tenant_id, $branch_id ), ARRAY_A );
			$requested = array(); foreach ( (array) ( $data['items'] ?? array() ) as $item ) { $drug_id = (int) ( $item['drug_id'] ?? 0 ); $qty = (float) ( $item['quantity'] ?? 0 ); if ( ! $drug_id || $qty <= 0 ) { throw new \DomainException( 'Every refund item needs a drug and positive quantity.' ); } $requested[ $drug_id ] = ( $requested[ $drug_id ] ?? 0 ) + $qty; }
			if ( ! $requested ) { throw new \DomainException( 'At least one refund item is required.' ); }
			$by_drug = array(); foreach ( $sale_items as $item ) { $by_drug[ (int) $item['drug_id'] ] = $item; }
			$refund_subtotal = 0; foreach ( $requested as $drug_id => $qty ) { if ( empty( $by_drug[ $drug_id ] ) ) { throw new \DomainException( 'Refund item was not part of the original sale.' ); } $already = (float) $this->db->get_var( $this->db->prepare( "SELECT COALESCE(SUM(quantity),0) FROM {$this->p}refund_items WHERE tenant_id=%d AND sale_id=%d AND drug_id=%d", $tenant_id, $sale_id, $drug_id ) ); if ( $qty > (float) $by_drug[ $drug_id ]['quantity'] - $already + 0.000001 ) { throw new \DomainException( 'Refund quantity exceeds the remaining sold quantity.' ); } $refund_subtotal += (int) round( $qty * (int) $by_drug[ $drug_id ]['unit_price_minor'] ); }
			$amount = (int) round( (int) $sale['total_amount_minor'] * $refund_subtotal / max( 1, (int) $sale['subtotal_amount_minor'] ) );
			$prior_amount = (int) $this->db->get_var( $this->db->prepare( "SELECT COALESCE(SUM(amount_minor),0) FROM {$this->p}refunds WHERE tenant_id=%d AND sale_id=%d AND status IN ('completed','pending_provider')", $tenant_id, $sale_id ) );
			$amount = min( $amount, (int) $sale['total_amount_minor'] - $prior_amount );
			$this->must_insert( 'refunds', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'sale_id' => $sale_id, 'kind' => sanitize_key( $kind ), 'idempotency_key' => $idempotency, 'amount_minor' => $amount, 'reason' => $reason, 'status' => 'processing', 'created_by' => $user_id, 'created_at' => current_time( 'mysql', true ) ) ); $refund_id = (int) $this->db->insert_id;
			$allocations = array();
			foreach ( $requested as $drug_id => $qty ) {
				$movements = $this->db->get_results( $this->db->prepare( "SELECT m.batch_id,m.unit_cost_minor,-m.quantity_delta sold_quantity,b.expiry_date,b.status FROM {$this->p}stock_movements m JOIN {$this->p}batches b ON b.id=m.batch_id AND b.tenant_id=m.tenant_id WHERE m.tenant_id=%d AND m.branch_id=%d AND m.reference_type='sale' AND m.reference_id=%d AND m.drug_id=%d AND m.quantity_delta<0 ORDER BY m.id FOR UPDATE", $tenant_id, $branch_id, $sale_id, $drug_id ), ARRAY_A );
				$remaining = $qty;
				foreach ( $movements as $movement ) {
					if ( $remaining <= 0.000001 ) { break; }
					$returned = (float) $this->db->get_var( $this->db->prepare( "SELECT COALESCE(SUM(quantity),0) FROM {$this->p}refund_items WHERE tenant_id=%d AND sale_id=%d AND drug_id=%d AND batch_id=%d", $tenant_id, $sale_id, $drug_id, $movement['batch_id'] ) );
					$take = min( $remaining, (float) $movement['sold_quantity'] - $returned ); if ( $take <= 0 ) { continue; }
					if ( 'restock' === $disposition && ( $movement['expiry_date'] < gmdate( 'Y-m-d' ) || 'active' !== $movement['status'] ) ) { throw new \DomainException( 'Expired or inactive stock must be quarantined, not restocked.' ); }
					$allocations[] = array( 'drug_id' => $drug_id, 'batch_id' => (int) $movement['batch_id'], 'quantity' => $take, 'unit_cost_minor' => isset( $movement['unit_cost_minor'] ) ? (int) $movement['unit_cost_minor'] : null, 'cost_amount_minor' => isset( $movement['unit_cost_minor'] ) ? (int) round( $take * (int) $movement['unit_cost_minor'] ) : null, 'base' => (int) round( $take * (int) $by_drug[ $drug_id ]['unit_price_minor'] ) );
					$remaining -= $take;
				}
				if ( $remaining > 0.000001 ) { throw new \DomainException( 'Original batch allocation is incomplete; refund stopped safely.' ); }
			}
			$money_left = $amount; $base_left = max( 1, array_sum( array_column( $allocations, 'base' ) ) );
			foreach ( $allocations as $index => $allocation ) {
				$item_amount = $index === array_key_last( $allocations ) ? $money_left : (int) round( $money_left * $allocation['base'] / $base_left ); $money_left -= $item_amount; $base_left -= $allocation['base'];
				$this->must_insert( 'refund_items', array( 'tenant_id' => $tenant_id, 'refund_id' => $refund_id, 'sale_id' => $sale_id, 'drug_id' => $allocation['drug_id'], 'batch_id' => $allocation['batch_id'], 'quantity' => $allocation['quantity'], 'disposition' => $disposition, 'amount_minor' => $item_amount, 'cost_amount_minor' => $allocation['cost_amount_minor'] ) );
				$movement = array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'drug_id' => $allocation['drug_id'], 'batch_id' => $allocation['batch_id'], 'movement_type' => 'restock' === $disposition ? 'return' : 'return_quarantine', 'quantity_delta' => 'restock' === $disposition ? $allocation['quantity'] : 0, 'unit_cost_minor' => $allocation['unit_cost_minor'], 'reference_type' => 'refund', 'reference_id' => $refund_id, 'reason' => $reason, 'correlation_id' => $correlation_id, 'created_at' => current_time( 'mysql', true ), 'created_by' => $user_id );
				if ( 'restock' === $disposition && 1 !== $this->db->query( $this->db->prepare( "UPDATE {$this->p}batches SET quantity_available=quantity_available+%f,updated_at=%s WHERE id=%d AND tenant_id=%d AND branch_id=%d", $allocation['quantity'], current_time( 'mysql', true ), $allocation['batch_id'], $tenant_id, $branch_id ) ) ) { throw new \RuntimeException( 'Batch restoration failed.' ); }
				$this->must_insert( 'stock_movements', $movement );
			}
			if ( 'restock' === $disposition ) { foreach ( $requested as $drug_id => $qty ) { if ( 1 !== $this->db->query( $this->db->prepare( "UPDATE {$this->p}stock_balances SET quantity_available=quantity_available+%f,updated_at=%s WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d", $qty, current_time( 'mysql', true ), $tenant_id, $branch_id, $drug_id ) ) ) { throw new \RuntimeException( 'Stock balance restoration failed.' ); } } }
			$pending = false; $remaining_money = $amount; $payments = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->p}sale_payments WHERE sale_id=%d AND tenant_id=%d AND branch_id=%d ORDER BY id", $sale_id, $tenant_id, $branch_id ), ARRAY_A ); foreach ( $payments as $payment ) { if ( $remaining_money <= 0 ) { break; } $reversed = (int) $this->db->get_var( $this->db->prepare( "SELECT COALESCE(SUM(rp.amount_minor),0) FROM {$this->p}refund_payments rp JOIN {$this->p}refunds r ON r.id=rp.refund_id AND r.tenant_id=rp.tenant_id WHERE rp.tenant_id=%d AND rp.sale_payment_id=%d AND r.tenant_id=%d AND r.sale_id=%d AND r.status IN ('completed','pending_provider','processing')", $tenant_id, $payment['id'], $tenant_id, $sale_id ) ); $take = min( $remaining_money, (int) $payment['amount_minor'] - $reversed ); if ( $take <= 0 ) { continue; } $payment_status = 'cash' === $payment['method'] ? 'completed' : 'pending_provider'; $pending = $pending || 'pending_provider' === $payment_status; $this->must_insert( 'refund_payments', array( 'tenant_id' => $tenant_id, 'refund_id' => $refund_id, 'sale_payment_id' => $payment['id'], 'method' => $payment['method'], 'amount_minor' => $take, 'status' => $payment_status, 'external_reference' => $payment['external_reference'] ) ); $remaining_money -= $take; }
			if ( $remaining_money > 0 ) { throw new \RuntimeException( 'Original payments cannot cover the refund.' ); }
			$all_refunded = true; foreach ( $sale_items as $item ) { $refunded = (float) $this->db->get_var( $this->db->prepare( "SELECT COALESCE(SUM(quantity),0) FROM {$this->p}refund_items WHERE tenant_id=%d AND sale_id=%d AND drug_id=%d", $tenant_id, $sale_id, $item['drug_id'] ) ); if ( $refunded + 0.000001 < (float) $item['quantity'] ) { $all_refunded = false; break; } }
			$refund_status = $pending ? 'pending_provider' : 'completed'; $this->db->update( $this->p . 'refunds', array( 'status' => $refund_status ), array( 'id' => $refund_id, 'tenant_id' => $tenant_id, 'branch_id' => $branch_id ) ); $sale_status = 'void' === $kind ? 'voided' : ( $all_refunded ? 'refunded' : 'partially_refunded' ); $this->db->update( $this->p . 'sales', array( 'status' => $sale_status ), array( 'id' => $sale_id, 'tenant_id' => $tenant_id, 'branch_id' => $branch_id ) ); $this->db->query( 'COMMIT' );
			do_action( 'pharmasure_audit_log', array( 'tenant_id' => $tenant_id, 'actor_id' => $user_id, 'action' => 'void' === $kind ? 'pos.sale_voided' : 'pos.sale_refunded', 'object_type' => 'sale', 'object_id' => $sale_id, 'details' => array( 'branch_id' => $branch_id, 'refund_id' => $refund_id, 'amount_minor' => $amount, 'reason' => $reason, 'disposition' => $disposition, 'payment_status' => $refund_status ) ) );
			return array( 'id' => $refund_id, 'sale_id' => $sale_id, 'amount_minor' => $amount, 'status' => $refund_status, 'sale_status' => $sale_status );
		} catch ( \DomainException $error ) { $this->db->query( 'ROLLBACK' ); return new \WP_Error( 'refund_rejected', $error->getMessage(), array( 'status' => 409 ) ); } catch ( \Throwable $error ) { $this->db->query( 'ROLLBACK' ); return new \WP_Error( 'refund_failed', 'No refund, payment reversal or stock change was saved.', array( 'status' => 409 ) ); }
	}

	public function void_sale( $tenant_id, $branch_id, $sale_id, $user_id, $reason, $correlation_id ) {
		$items = $this->db->get_results( $this->db->prepare( "SELECT drug_id,quantity FROM {$this->p}sale_items WHERE sale_id=%d AND tenant_id=%d AND branch_id=%d", $sale_id, $tenant_id, $branch_id ), ARRAY_A );
		return $this->refund_sale( $tenant_id, $branch_id, $sale_id, $user_id, array( 'idempotency_key' => 'void-sale-' . $sale_id, 'reason' => $reason, 'disposition' => 'restock', 'items' => $items ), $correlation_id, 'void' );
	}

	private function next_receipt( $tenant_id, $branch_id ) {
		$table = $this->p . 'document_sequences';
		$row = $this->db->get_row( $this->db->prepare( "SELECT id,next_value FROM {$table} WHERE tenant_id=%d AND branch_id=%d AND document_type='sale_receipt' FOR UPDATE", $tenant_id, $branch_id ), ARRAY_A );
		if ( ! $row ) { $this->must_insert( 'document_sequences', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'document_type' => 'sale_receipt', 'next_value' => 2 ) ); $number = 1; }
		else { $number = (int) $row['next_value']; if ( 1 !== $this->db->update( $table, array( 'next_value' => $number + 1 ), array( 'id' => (int) $row['id'], 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'document_type' => 'sale_receipt' ) ) ) { throw new \RuntimeException( 'Receipt sequence could not be updated.' ); } }
		return sprintf( 'R%06d', $number );
	}

	private function owns_active_branch( $tenant_id, $branch_id ) { return (bool) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}branches WHERE id=%d AND tenant_id=%d AND is_active=1", $branch_id, $tenant_id ) ); }
	private function audit( $tenant_id, $actor_id, $action, $object_type, $object_id, array $details ) { do_action( 'pharmasure_audit_log', array( 'tenant_id' => $tenant_id, 'actor_id' => $actor_id, 'action' => $action, 'object_type' => $object_type, 'object_id' => $object_id, 'status' => 'success', 'details' => $details ) ); }
	private function must_insert( $table, array $row ) { if ( false === $this->db->insert( $this->p . $table, $row ) ) { throw new \RuntimeException( $this->db->last_error ); } }
}
