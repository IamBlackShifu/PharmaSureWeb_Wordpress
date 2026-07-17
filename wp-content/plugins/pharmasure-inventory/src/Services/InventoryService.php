<?php
namespace PharmaSure\Inventory\Services;

final class InventoryService {
	private $db;
	private $p;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
		$this->p  = $wpdb->prefix . 'ps_';
	}

	public function list_drugs( $tenant_id, array $args = array() ) {
		$term = trim( (string) ( $args['search'] ?? '' ) );
		$page = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$like = '%' . $this->db->esc_like( $term ) . '%';
		$where = $term ? 'tenant_id=%d AND status=%s AND (sku LIKE %s OR barcode LIKE %s OR name LIKE %s OR generic_name LIKE %s)' : 'tenant_id=%d AND status=%s';
		$params = $term ? array( $tenant_id, 'active', $like, $like, $like, $like ) : array( $tenant_id, 'active' );
		$total = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->p}drugs WHERE $where", $params ) );
		$params[] = $per_page;
		$params[] = ( $page - 1 ) * $per_page;
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->p}drugs WHERE $where ORDER BY name LIMIT %d OFFSET %d", $params ), ARRAY_A );
		return array( 'data' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int) ceil( $total / $per_page ) );
	}

	public function create_drug( $tenant_id, array $data ) {
		$sku = sanitize_text_field( $data['sku'] ?? '' );
		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( '' === $sku || '' === $name ) {
			return new \WP_Error( 'invalid_drug', 'SKU and name are required.', array( 'status' => 422 ) );
		}
		$row = array(
			'tenant_id' => $tenant_id, 'sku' => $sku, 'barcode' => sanitize_text_field( $data['barcode'] ?? '' ),
			'name' => $name, 'generic_name' => sanitize_text_field( $data['generic_name'] ?? '' ),
			'strength' => sanitize_text_field( $data['strength'] ?? '' ), 'dosage_form' => sanitize_text_field( $data['dosage_form'] ?? '' ),
			'pack_size' => sanitize_text_field( $data['pack_size'] ?? '' ), 'unit_of_measure' => sanitize_key( $data['unit_of_measure'] ?? 'unit' ),
			'category' => sanitize_text_field( $data['category'] ?? '' ), 'manufacturer' => sanitize_text_field( $data['manufacturer'] ?? '' ),
			'requires_prescription' => empty( $data['requires_prescription'] ) ? 0 : 1, 'is_controlled' => empty( $data['is_controlled'] ) ? 0 : 1,
			'cost_price_minor' => max( 0, (int) ( $data['cost_price_minor'] ?? 0 ) ), 'selling_price_minor' => max( 0, (int) ( $data['selling_price_minor'] ?? 0 ) ),
			'reorder_level' => max( 0, (float) ( $data['reorder_level'] ?? 0 ) ), 'status' => 'active',
			'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ), 'created_by' => get_current_user_id(),
		);
		if ( false === $this->db->insert( $this->p . 'drugs', $row ) ) {
			$code = str_contains( $this->db->last_error, 'Duplicate' ) ? 'duplicate_sku' : 'database_error';
			return new \WP_Error( $code, 'The drug could not be created.', array( 'status' => 409 ) );
		}
		return $this->get_tenant_row( 'drugs', $tenant_id, $this->db->insert_id );
	}

	public function list_suppliers( $tenant_id ) {
		return $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->p}suppliers WHERE tenant_id=%d AND status='active' ORDER BY name", $tenant_id ), ARRAY_A );
	}

	public function create_supplier( $tenant_id, array $data ) {
		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( '' === $name ) {
			return new \WP_Error( 'invalid_supplier', 'Supplier name is required.', array( 'status' => 422 ) );
		}
		$now = current_time( 'mysql', true );
		$ok = $this->db->insert( $this->p . 'suppliers', array( 'tenant_id' => $tenant_id, 'name' => $name, 'contact_name' => sanitize_text_field( $data['contact_name'] ?? '' ), 'phone' => sanitize_text_field( $data['phone'] ?? '' ), 'email' => sanitize_email( $data['email'] ?? '' ), 'tax_number' => sanitize_text_field( $data['tax_number'] ?? '' ), 'payment_terms' => sanitize_text_field( $data['payment_terms'] ?? '' ), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now, 'created_by' => get_current_user_id() ) );
		if ( false === $ok ) {
			return new \WP_Error( 'supplier_not_created', 'The supplier could not be created.', array( 'status' => 409 ) );
		}
		return $this->get_tenant_row( 'suppliers', $tenant_id, $this->db->insert_id );
	}

	public function stock_summary( $tenant_id, $branch_id ) {
		return $this->db->get_results( $this->db->prepare( "SELECT d.id,d.sku,d.name,d.generic_name,d.reorder_level,COALESCE(b.quantity_available,0) quantity_available,MIN(CASE WHEN ba.quantity_available>0 THEN ba.expiry_date END) next_expiry FROM {$this->p}drugs d LEFT JOIN {$this->p}stock_balances b ON b.tenant_id=d.tenant_id AND b.drug_id=d.id AND b.branch_id=%d LEFT JOIN {$this->p}batches ba ON ba.tenant_id=d.tenant_id AND ba.drug_id=d.id AND ba.branch_id=%d WHERE d.tenant_id=%d AND d.status='active' GROUP BY d.id,b.quantity_available ORDER BY d.name", $branch_id, $branch_id, $tenant_id ), ARRAY_A );
	}

	public function receive_stock( $tenant_id, $branch_id, array $data, $correlation_id ) {
		$items = $data['items'] ?? array();
		$supplier_id = (int) ( $data['supplier_id'] ?? 0 );
		$received_date = sanitize_text_field( $data['received_date'] ?? gmdate( 'Y-m-d' ) );
		if ( ! $branch_id || ! $supplier_id || ! is_array( $items ) || empty( $items ) || ! $this->valid_date( $received_date ) ) {
			return new \WP_Error( 'invalid_receipt', 'Branch, supplier, received date and at least one item are required.', array( 'status' => 422 ) );
		}
		if ( ! $this->owns( 'branches', $tenant_id, $branch_id ) || ! $this->owns( 'suppliers', $tenant_id, $supplier_id ) ) {
			return new \WP_Error( 'invalid_scope', 'Branch or supplier does not belong to the current tenant.', array( 'status' => 403 ) );
		}
		foreach ( $items as $item ) {
			if ( ! $this->validate_receipt_item( $tenant_id, $item, $received_date ) ) {
				return new \WP_Error( 'invalid_receipt_item', 'Each item needs an owned drug, batch number, positive quantity, non-negative cost and valid future expiry.', array( 'status' => 422 ) );
			}
		}

		$this->db->query( 'START TRANSACTION' );
		try {
			$now = current_time( 'mysql', true );
			$this->must_insert( 'stock_receipts', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'supplier_id' => $supplier_id, 'purchase_reference' => sanitize_text_field( $data['purchase_reference'] ?? '' ), 'received_date' => $received_date, 'notes' => sanitize_textarea_field( $data['notes'] ?? '' ), 'status' => 'completed', 'created_at' => $now, 'created_by' => get_current_user_id() ) );
			$receipt_id = (int) $this->db->insert_id;
			foreach ( $items as $item ) {
				$drug_id = (int) $item['drug_id']; $qty = (float) $item['quantity']; $batch_number = sanitize_text_field( $item['batch_number'] );
				$cost = (int) $item['unit_cost_minor']; $price = max( 0, (int) ( $item['selling_price_minor'] ?? 0 ) ); $expiry = sanitize_text_field( $item['expiry_date'] );
				$manufacture = empty( $item['manufacture_date'] ) ? null : sanitize_text_field( $item['manufacture_date'] );
				$this->must_insert( 'stock_receipt_lines', array( 'receipt_id' => $receipt_id, 'drug_id' => $drug_id, 'batch_number' => $batch_number, 'quantity' => $qty, 'unit_cost_minor' => $cost, 'selling_price_minor' => $price, 'manufacture_date' => $manufacture, 'expiry_date' => $expiry ) );
				$this->db->query( $this->db->prepare( "INSERT INTO {$this->p}batches (tenant_id,branch_id,drug_id,supplier_id,batch_number,manufacture_date,expiry_date,quantity_received,quantity_available,unit_cost_minor,selling_price_minor,status,created_at,updated_at) VALUES (%d,%d,%d,%d,%s,%s,%s,%f,%f,%d,%d,'active',%s,%s) ON DUPLICATE KEY UPDATE quantity_received=quantity_received+VALUES(quantity_received),quantity_available=quantity_available+VALUES(quantity_available),unit_cost_minor=VALUES(unit_cost_minor),selling_price_minor=VALUES(selling_price_minor),expiry_date=VALUES(expiry_date),updated_at=VALUES(updated_at)", $tenant_id, $branch_id, $drug_id, $supplier_id, $batch_number, $manufacture, $expiry, $qty, $qty, $cost, $price, $now, $now ) );
				if ( $this->db->last_error ) { throw new \RuntimeException( $this->db->last_error ); }
				$batch_id = (int) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}batches WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d AND batch_number=%s", $tenant_id, $branch_id, $drug_id, $batch_number ) );
				$this->db->query( $this->db->prepare( "INSERT INTO {$this->p}stock_balances (tenant_id,branch_id,drug_id,quantity_available,updated_at) VALUES (%d,%d,%d,%f,%s) ON DUPLICATE KEY UPDATE quantity_available=quantity_available+VALUES(quantity_available),updated_at=VALUES(updated_at)", $tenant_id, $branch_id, $drug_id, $qty, $now ) );
				if ( $this->db->last_error ) { throw new \RuntimeException( $this->db->last_error ); }
				$this->must_insert( 'stock_movements', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'drug_id' => $drug_id, 'batch_id' => $batch_id, 'movement_type' => 'receipt', 'quantity_delta' => $qty, 'reference_type' => 'stock_receipt', 'reference_id' => $receipt_id, 'reason' => 'Stock received', 'correlation_id' => $correlation_id, 'created_at' => $now, 'created_by' => get_current_user_id() ) );
			}
			$this->db->query( 'COMMIT' );
			do_action( 'pharmasure_audit_log', array( 'tenant_id' => $tenant_id, 'action' => 'inventory.stock_received', 'object_type' => 'stock_receipt', 'object_id' => $receipt_id, 'status' => 'success', 'details' => array( 'branch_id' => $branch_id, 'items' => count( $items ) ) ) );
			return array( 'id' => $receipt_id, 'items_received' => count( $items ) );
		} catch ( \Throwable $e ) {
			$this->db->query( 'ROLLBACK' );
			return new \WP_Error( 'receipt_failed', 'No stock was changed because the receipt could not be completed.', array( 'status' => 500 ) );
		}
	}

	private function validate_receipt_item( $tenant_id, array $item, $received_date ) {
		$expiry = sanitize_text_field( $item['expiry_date'] ?? '' );
		return $this->owns( 'drugs', $tenant_id, (int) ( $item['drug_id'] ?? 0 ) ) && '' !== sanitize_text_field( $item['batch_number'] ?? '' ) && (float) ( $item['quantity'] ?? 0 ) > 0 && (int) ( $item['unit_cost_minor'] ?? -1 ) >= 0 && $this->valid_date( $expiry ) && $expiry > $received_date;
	}

	private function owns( $table, $tenant_id, $id ) { return (bool) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}{$table} WHERE id=%d AND tenant_id=%d", $id, $tenant_id ) ); }
	private function get_tenant_row( $table, $tenant_id, $id ) { return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->p}{$table} WHERE id=%d AND tenant_id=%d", $id, $tenant_id ), ARRAY_A ); }
	private function valid_date( $date ) { $d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date ); return $d && $d->format( 'Y-m-d' ) === $date; }
	private function must_insert( $table, array $row ) { if ( false === $this->db->insert( $this->p . $table, $row ) ) { throw new \RuntimeException( $this->db->last_error ); } }
}
