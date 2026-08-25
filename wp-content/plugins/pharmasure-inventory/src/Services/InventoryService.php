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
		$id = (int) $this->db->insert_id;
		$this->audit( $tenant_id, 'inventory.drug_created', 'drug', $id, array( 'sku' => $sku ) );
		return $this->get_tenant_row( 'drugs', $tenant_id, $id );
	}

	public function update_drug( $tenant_id, $drug_id, array $data ) {
		$before = $this->get_tenant_row( 'drugs', $tenant_id, $drug_id );
		if ( ! $before ) {
			return new \WP_Error( 'drug_not_found', 'Medicine was not found in the current tenant.', array( 'status' => 404 ) );
		}
		$sku = sanitize_text_field( $data['sku'] ?? $before['sku'] );
		$name = sanitize_text_field( $data['name'] ?? $before['name'] );
		if ( '' === $sku || '' === $name ) {
			return new \WP_Error( 'invalid_drug', 'SKU and name are required.', array( 'status' => 422 ) );
		}
		$row = array(
			'sku' => $sku, 'barcode' => sanitize_text_field( $data['barcode'] ?? $before['barcode'] ),
			'name' => $name, 'generic_name' => sanitize_text_field( $data['generic_name'] ?? $before['generic_name'] ),
			'strength' => sanitize_text_field( $data['strength'] ?? $before['strength'] ), 'dosage_form' => sanitize_text_field( $data['dosage_form'] ?? $before['dosage_form'] ),
			'pack_size' => sanitize_text_field( $data['pack_size'] ?? $before['pack_size'] ), 'unit_of_measure' => sanitize_key( $data['unit_of_measure'] ?? $before['unit_of_measure'] ),
			'category' => sanitize_text_field( $data['category'] ?? $before['category'] ), 'manufacturer' => sanitize_text_field( $data['manufacturer'] ?? $before['manufacturer'] ),
			'requires_prescription' => isset( $data['requires_prescription'] ) ? ( empty( $data['requires_prescription'] ) ? 0 : 1 ) : (int) $before['requires_prescription'],
			'is_controlled' => isset( $data['is_controlled'] ) ? ( empty( $data['is_controlled'] ) ? 0 : 1 ) : (int) $before['is_controlled'],
			'cost_price_minor' => max( 0, (int) ( $data['cost_price_minor'] ?? $before['cost_price_minor'] ) ),
			'selling_price_minor' => max( 0, (int) ( $data['selling_price_minor'] ?? $before['selling_price_minor'] ) ),
			'reorder_level' => max( 0, (float) ( $data['reorder_level'] ?? $before['reorder_level'] ) ),
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( false === $this->db->update( $this->p . 'drugs', $row, array( 'id' => (int) $drug_id, 'tenant_id' => (int) $tenant_id ) ) ) {
			$code = str_contains( $this->db->last_error, 'Duplicate' ) ? 'duplicate_sku' : 'database_error';
			return new \WP_Error( $code, 'The medicine could not be updated.', array( 'status' => 409 ) );
		}
		$this->audit( $tenant_id, 'inventory.drug_updated', 'drug', $drug_id, array( 'before' => $before, 'after' => $row ) );
		return $this->get_tenant_row( 'drugs', $tenant_id, $drug_id );
	}

	public function archive_drug( $tenant_id, $drug_id ) {
		$drug = $this->get_tenant_row( 'drugs', $tenant_id, $drug_id );
		if ( ! $drug ) {
			return new \WP_Error( 'drug_not_found', 'Medicine was not found in the current tenant.', array( 'status' => 404 ) );
		}
		$available = (float) $this->db->get_var( $this->db->prepare( "SELECT COALESCE(SUM(quantity_available),0) FROM {$this->p}stock_balances WHERE tenant_id=%d AND drug_id=%d", $tenant_id, $drug_id ) );
		if ( $available > 0 ) {
			return new \WP_Error( 'drug_has_stock', 'A medicine with available stock cannot be archived.', array( 'status' => 409 ) );
		}
		$this->db->update( $this->p . 'drugs', array( 'status' => 'archived', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $drug_id, 'tenant_id' => (int) $tenant_id ) );
		$this->audit( $tenant_id, 'inventory.drug_archived', 'drug', $drug_id, array( 'sku' => $drug['sku'] ) );
		return array( 'id' => (int) $drug_id, 'status' => 'archived' );
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
		$id = (int) $this->db->insert_id;
		$this->audit( $tenant_id, 'inventory.supplier_created', 'supplier', $id, array( 'name' => $name ) );
		return $this->get_tenant_row( 'suppliers', $tenant_id, $id );
	}

	public function update_supplier( $tenant_id, $supplier_id, array $data ) {
		$before = $this->get_tenant_row( 'suppliers', $tenant_id, $supplier_id );
		if ( ! $before ) {
			return new \WP_Error( 'supplier_not_found', 'Supplier was not found in the current tenant.', array( 'status' => 404 ) );
		}
		$name = sanitize_text_field( $data['name'] ?? $before['name'] );
		if ( '' === $name ) {
			return new \WP_Error( 'invalid_supplier', 'Supplier name is required.', array( 'status' => 422 ) );
		}
		$row = array(
			'name' => $name, 'contact_name' => sanitize_text_field( $data['contact_name'] ?? $before['contact_name'] ),
			'phone' => sanitize_text_field( $data['phone'] ?? $before['phone'] ), 'email' => sanitize_email( $data['email'] ?? $before['email'] ),
			'tax_number' => sanitize_text_field( $data['tax_number'] ?? $before['tax_number'] ), 'payment_terms' => sanitize_text_field( $data['payment_terms'] ?? $before['payment_terms'] ),
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( false === $this->db->update( $this->p . 'suppliers', $row, array( 'id' => (int) $supplier_id, 'tenant_id' => (int) $tenant_id ) ) ) {
			return new \WP_Error( 'supplier_not_updated', 'The supplier could not be updated.', array( 'status' => 409 ) );
		}
		$this->audit( $tenant_id, 'inventory.supplier_updated', 'supplier', $supplier_id, array( 'before' => $before, 'after' => $row ) );
		return $this->get_tenant_row( 'suppliers', $tenant_id, $supplier_id );
	}

	public function archive_supplier( $tenant_id, $supplier_id ) {
		$supplier = $this->get_tenant_row( 'suppliers', $tenant_id, $supplier_id );
		if ( ! $supplier ) {
			return new \WP_Error( 'supplier_not_found', 'Supplier was not found in the current tenant.', array( 'status' => 404 ) );
		}
		$this->db->update( $this->p . 'suppliers', array( 'status' => 'archived', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $supplier_id, 'tenant_id' => (int) $tenant_id ) );
		$this->audit( $tenant_id, 'inventory.supplier_archived', 'supplier', $supplier_id, array( 'name' => $supplier['name'] ) );
		return array( 'id' => (int) $supplier_id, 'status' => 'archived' );
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
				$this->must_insert( 'stock_receipt_lines', array( 'tenant_id' => $tenant_id, 'receipt_id' => $receipt_id, 'drug_id' => $drug_id, 'batch_number' => $batch_number, 'quantity' => $qty, 'unit_cost_minor' => $cost, 'selling_price_minor' => $price, 'manufacture_date' => $manufacture, 'expiry_date' => $expiry, 'status' => 'completed', 'created_at' => $now ) );
				$this->db->query( $this->db->prepare( "INSERT INTO {$this->p}batches (tenant_id,branch_id,drug_id,supplier_id,batch_number,manufacture_date,expiry_date,quantity_received,quantity_available,unit_cost_minor,selling_price_minor,status,created_at,updated_at) VALUES (%d,%d,%d,%d,%s,%s,%s,%f,%f,%d,%d,'active',%s,%s) ON DUPLICATE KEY UPDATE quantity_received=quantity_received+VALUES(quantity_received),quantity_available=quantity_available+VALUES(quantity_available),unit_cost_minor=VALUES(unit_cost_minor),selling_price_minor=VALUES(selling_price_minor),expiry_date=VALUES(expiry_date),updated_at=VALUES(updated_at)", $tenant_id, $branch_id, $drug_id, $supplier_id, $batch_number, $manufacture, $expiry, $qty, $qty, $cost, $price, $now, $now ) );
				if ( $this->db->last_error ) { throw new \RuntimeException( $this->db->last_error ); }
				$batch_id = (int) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}batches WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d AND batch_number=%s", $tenant_id, $branch_id, $drug_id, $batch_number ) );
				$this->db->query( $this->db->prepare( "INSERT INTO {$this->p}stock_balances (tenant_id,branch_id,drug_id,quantity_available,updated_at) VALUES (%d,%d,%d,%f,%s) ON DUPLICATE KEY UPDATE quantity_available=quantity_available+VALUES(quantity_available),updated_at=VALUES(updated_at)", $tenant_id, $branch_id, $drug_id, $qty, $now ) );
				if ( $this->db->last_error ) { throw new \RuntimeException( $this->db->last_error ); }
				$this->must_insert( 'stock_movements', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'drug_id' => $drug_id, 'batch_id' => $batch_id, 'movement_type' => 'receipt', 'quantity_delta' => $qty, 'unit_cost_minor' => $cost, 'reference_type' => 'stock_receipt', 'reference_id' => $receipt_id, 'reason' => 'Stock received', 'correlation_id' => $correlation_id, 'created_at' => $now, 'created_by' => get_current_user_id() ) );
			}
			$this->db->query( 'COMMIT' );
			do_action( 'pharmasure_audit_log', array( 'tenant_id' => $tenant_id, 'action' => 'inventory.stock_received', 'object_type' => 'stock_receipt', 'object_id' => $receipt_id, 'status' => 'success', 'details' => array( 'branch_id' => $branch_id, 'items' => count( $items ) ) ) );
			return array( 'id' => $receipt_id, 'items_received' => count( $items ) );
		} catch ( \Throwable $e ) {
			$this->db->query( 'ROLLBACK' );
			return new \WP_Error( 'receipt_failed', 'No stock was changed because the receipt could not be completed.', array( 'status' => 500 ) );
		}
	}

	/** Return only tenant-owned reference data used by inventory command forms. */
	public function command_options( $tenant_id, $branch_id ) {
		if ( ! $this->owns_active_branch( $tenant_id, $branch_id ) ) {
			return new \WP_Error( 'invalid_scope', 'An authorized active branch is required.', array( 'status' => 403 ) );
		}
		return array(
			'drugs' => $this->db->get_results( $this->db->prepare( "SELECT id,sku,name,strength,unit_of_measure,cost_price_minor,selling_price_minor,reorder_level FROM {$this->p}drugs WHERE tenant_id=%d AND status='active' ORDER BY name", $tenant_id ), ARRAY_A ),
			'suppliers' => $this->db->get_results( $this->db->prepare( "SELECT id,name,payment_terms FROM {$this->p}suppliers WHERE tenant_id=%d AND status='active' ORDER BY name", $tenant_id ), ARRAY_A ),
			'batches' => $this->db->get_results( $this->db->prepare( "SELECT b.id,b.drug_id,b.batch_number,b.expiry_date,b.quantity_available,b.status,d.sku,d.name FROM {$this->p}batches b JOIN {$this->p}drugs d ON d.id=b.drug_id AND d.tenant_id=b.tenant_id WHERE b.tenant_id=%d AND b.branch_id=%d AND b.quantity_available>0 ORDER BY b.expiry_date,b.id", $tenant_id, $branch_id ), ARRAY_A ),
			'branches' => $this->db->get_results( $this->db->prepare( "SELECT id,name,code FROM {$this->p}branches WHERE tenant_id=%d AND is_active=1 AND id<>%d ORDER BY name", $tenant_id, $branch_id ), ARRAY_A ),
		);
	}

	public function adjust_stock( $tenant_id, $branch_id, array $data, $correlation_id ) {
		$items = $data['items'] ?? array();
		$reason_code = sanitize_key( $data['reason_code'] ?? '' );
		$allowed_reasons = array( 'stocktake_correction', 'damage', 'loss', 'return_to_stock', 'data_correction' );
		if ( ! $this->owns_active_branch( $tenant_id, $branch_id ) || ! is_array( $items ) || empty( $items ) || ! in_array( $reason_code, $allowed_reasons, true ) ) {
			return new \WP_Error( 'invalid_adjustment', 'An active branch, reason and at least one adjustment line are required.', array( 'status' => 422 ) );
		}
		$this->db->query( 'START TRANSACTION' );
		try {
			$now = current_time( 'mysql', true );
			$number = $this->reference_number( 'ADJ' );
			$this->must_insert( 'stock_adjustments', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'adjustment_number' => $number, 'reason_code' => $reason_code, 'notes' => sanitize_text_field( $data['notes'] ?? '' ), 'status' => 'completed', 'created_at' => $now, 'created_by' => get_current_user_id() ) );
			$adjustment_id = (int) $this->db->insert_id;
			$seen = array();
			foreach ( $items as $item ) {
				$batch_id = (int) ( $item['batch_id'] ?? 0 );
				$delta = round( (float) ( $item['quantity_delta'] ?? 0 ), 3 );
				if ( ! $batch_id || 0.0 === $delta || isset( $seen[ $batch_id ] ) ) { throw new \DomainException( 'invalid_line' ); }
				$seen[ $batch_id ] = true;
				$batch = $this->db->get_row( $this->db->prepare( "SELECT id,drug_id,quantity_available,unit_cost_minor,status,expiry_date FROM {$this->p}batches WHERE id=%d AND tenant_id=%d AND branch_id=%d FOR UPDATE", $batch_id, $tenant_id, $branch_id ), ARRAY_A );
				if ( ! $batch || 'active' !== $batch['status'] || $batch['expiry_date'] <= gmdate( 'Y-m-d' ) || (float) $batch['quantity_available'] + $delta < 0 ) { throw new \DomainException( 'invalid_line' ); }
				$drug_id = (int) $batch['drug_id'];
				$this->must_update_quantity( 'batches', $delta, array( 'id' => $batch_id, 'tenant_id' => $tenant_id, 'branch_id' => $branch_id ), $now );
				$this->must_update_quantity( 'stock_balances', $delta, array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'drug_id' => $drug_id ), $now );
				$line_reason = sanitize_text_field( $item['reason'] ?? $data['notes'] ?? $reason_code );
				$this->must_insert( 'stock_adjustment_lines', array( 'tenant_id' => $tenant_id, 'adjustment_id' => $adjustment_id, 'drug_id' => $drug_id, 'batch_id' => $batch_id, 'quantity_delta' => $delta, 'unit_cost_minor' => (int) $batch['unit_cost_minor'], 'reason' => $line_reason, 'status' => 'completed', 'created_at' => $now ) );
				$this->must_insert( 'stock_movements', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'drug_id' => $drug_id, 'batch_id' => $batch_id, 'movement_type' => 'adjustment', 'quantity_delta' => $delta, 'unit_cost_minor' => (int) $batch['unit_cost_minor'], 'reference_type' => 'stock_adjustment', 'reference_id' => $adjustment_id, 'reason' => $line_reason, 'correlation_id' => $correlation_id, 'created_at' => $now, 'created_by' => get_current_user_id() ) );
			}
			$this->db->query( 'COMMIT' );
			$this->audit( $tenant_id, 'inventory.stock_adjusted', 'stock_adjustment', $adjustment_id, array( 'branch_id' => $branch_id, 'reason_code' => $reason_code, 'items' => count( $items ), 'correlation_id' => $correlation_id ) );
			return array( 'id' => $adjustment_id, 'adjustment_number' => $number, 'items_adjusted' => count( $items ) );
		} catch ( \DomainException $e ) {
			$this->db->query( 'ROLLBACK' );
			return new \WP_Error( 'invalid_adjustment_line', 'Every adjustment must target one active, unexpired owned batch without making stock negative.', array( 'status' => 422 ) );
		} catch ( \Throwable $e ) {
			$this->db->query( 'ROLLBACK' );
			return new \WP_Error( 'adjustment_failed', 'No stock was changed because the adjustment could not be completed.', array( 'status' => 500 ) );
		}
	}

	public function change_batch_status( $tenant_id, $branch_id, $batch_id, array $data, $correlation_id ) {
		$new_status = sanitize_key( $data['status'] ?? '' );
		$reason = sanitize_text_field( $data['reason'] ?? '' );
		if ( ! in_array( $new_status, array( 'active', 'quarantined', 'expired', 'withdrawn' ), true ) || '' === $reason ) {
			return new \WP_Error( 'invalid_disposition', 'A supported batch status and reason are required.', array( 'status' => 422 ) );
		}
		$this->db->query( 'START TRANSACTION' );
		try {
			$batch = $this->db->get_row( $this->db->prepare( "SELECT id,drug_id,quantity_available,unit_cost_minor,status,expiry_date FROM {$this->p}batches WHERE id=%d AND tenant_id=%d AND branch_id=%d FOR UPDATE", $batch_id, $tenant_id, $branch_id ), ARRAY_A );
			if ( ! $batch || $batch['status'] === $new_status || ( 'active' === $new_status && $batch['expiry_date'] <= gmdate( 'Y-m-d' ) ) ) { throw new \DomainException( 'invalid_transition' ); }
			$was_available = 'active' === $batch['status'];
			$will_be_available = 'active' === $new_status;
			$available_delta = ( $will_be_available ? 1 : 0 ) * (float) $batch['quantity_available'] - ( $was_available ? 1 : 0 ) * (float) $batch['quantity_available'];
			$now = current_time( 'mysql', true );
			if ( 0.0 !== $available_delta ) {
				$this->must_update_quantity( 'stock_balances', $available_delta, array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'drug_id' => (int) $batch['drug_id'] ), $now );
			}
			$updated = $this->db->update( $this->p . 'batches', array( 'status' => $new_status, 'updated_at' => $now ), array( 'id' => (int) $batch_id, 'tenant_id' => (int) $tenant_id, 'branch_id' => (int) $branch_id ) );
			if ( false === $updated ) { throw new \RuntimeException( $this->db->last_error ); }
			$this->must_insert( 'batch_dispositions', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'batch_id' => $batch_id, 'previous_status' => $batch['status'], 'new_status' => $new_status, 'quantity_affected' => abs( $available_delta ), 'reason' => $reason, 'status' => 'completed', 'created_at' => $now, 'created_by' => get_current_user_id() ) );
			$disposition_id = (int) $this->db->insert_id;
			$this->must_insert( 'stock_movements', array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'drug_id' => (int) $batch['drug_id'], 'batch_id' => $batch_id, 'movement_type' => 'active' === $new_status ? 'release' : $new_status, 'quantity_delta' => $available_delta, 'unit_cost_minor' => (int) $batch['unit_cost_minor'], 'reference_type' => 'batch_disposition', 'reference_id' => $disposition_id, 'reason' => $reason, 'correlation_id' => $correlation_id, 'created_at' => $now, 'created_by' => get_current_user_id() ) );
			$this->db->query( 'COMMIT' );
			$this->audit( $tenant_id, 'inventory.batch_disposition_changed', 'batch', $batch_id, array( 'branch_id' => $branch_id, 'from' => $batch['status'], 'to' => $new_status, 'reason' => $reason, 'correlation_id' => $correlation_id ) );
			return array( 'id' => (int) $batch_id, 'status' => $new_status, 'available_delta' => $available_delta );
		} catch ( \DomainException $e ) {
			$this->db->query( 'ROLLBACK' );
			return new \WP_Error( 'invalid_batch_transition', 'The batch transition is not allowed for this branch or expiry state.', array( 'status' => 422 ) );
		} catch ( \Throwable $e ) {
			$this->db->query( 'ROLLBACK' );
			return new \WP_Error( 'batch_transition_failed', 'The batch status was not changed.', array( 'status' => 500 ) );
		}
	}

	public function transfer_stock( $tenant_id, $from_branch_id, array $data, $correlation_id ) {
		$to_branch_id = (int) ( $data['to_branch_id'] ?? 0 );
		$items = $data['items'] ?? array();
		if ( $from_branch_id === $to_branch_id || ! $this->owns_active_branch( $tenant_id, $from_branch_id ) || ! $this->owns_active_branch( $tenant_id, $to_branch_id ) || ! is_array( $items ) || empty( $items ) ) {
			return new \WP_Error( 'invalid_transfer', 'Choose a different authorized active branch and at least one item.', array( 'status' => 422 ) );
		}
		$this->db->query( 'START TRANSACTION' );
		try {
			$now = current_time( 'mysql', true );
			$number = $this->reference_number( 'TRF' );
			$this->must_insert( 'stock_transfers', array( 'tenant_id' => $tenant_id, 'from_branch_id' => $from_branch_id, 'to_branch_id' => $to_branch_id, 'transfer_number' => $number, 'notes' => sanitize_text_field( $data['notes'] ?? '' ), 'status' => 'completed', 'created_at' => $now, 'created_by' => get_current_user_id() ) );
			$transfer_id = (int) $this->db->insert_id;
			$seen = array();
			$line_count = 0;
			foreach ( $items as $item ) {
				$drug_id = (int) ( $item['drug_id'] ?? 0 );
				$required = round( (float) ( $item['quantity'] ?? 0 ), 3 );
				if ( ! $drug_id || $required <= 0 || isset( $seen[ $drug_id ] ) || ! $this->owns( 'drugs', $tenant_id, $drug_id ) ) { throw new \DomainException( 'invalid_item' ); }
				$seen[ $drug_id ] = true;
				$batches = $this->db->get_results( $this->db->prepare( "SELECT id,supplier_id,batch_number,manufacture_date,expiry_date,quantity_available,unit_cost_minor,selling_price_minor FROM {$this->p}batches WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d AND status='active' AND quantity_available>0 AND expiry_date>UTC_DATE() ORDER BY expiry_date,id FOR UPDATE", $tenant_id, $from_branch_id, $drug_id ), ARRAY_A );
				$remaining = $required;
				foreach ( $batches as $source ) {
					if ( $remaining <= 0 ) { break; }
					$quantity = min( $remaining, (float) $source['quantity_available'] );
					$this->must_update_quantity( 'batches', -$quantity, array( 'id' => (int) $source['id'], 'tenant_id' => $tenant_id, 'branch_id' => $from_branch_id ), $now );
					$destination = $this->db->get_row( $this->db->prepare( "SELECT id,status FROM {$this->p}batches WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d AND batch_number=%s FOR UPDATE", $tenant_id, $to_branch_id, $drug_id, $source['batch_number'] ), ARRAY_A );
					if ( $destination && 'active' !== $destination['status'] ) { throw new \DomainException( 'destination_batch_unavailable' ); }
					if ( $destination ) {
						$this->db->query( $this->db->prepare( "UPDATE {$this->p}batches SET quantity_received=quantity_received+%f,quantity_available=quantity_available+%f,updated_at=%s WHERE id=%d AND tenant_id=%d AND branch_id=%d", $quantity, $quantity, $now, (int) $destination['id'], $tenant_id, $to_branch_id ) );
						$destination_batch_id = (int) $destination['id'];
					} else {
						$this->must_insert( 'batches', array( 'tenant_id' => $tenant_id, 'branch_id' => $to_branch_id, 'drug_id' => $drug_id, 'supplier_id' => (int) $source['supplier_id'] ?: null, 'batch_number' => $source['batch_number'], 'manufacture_date' => $source['manufacture_date'], 'expiry_date' => $source['expiry_date'], 'quantity_received' => $quantity, 'quantity_available' => $quantity, 'unit_cost_minor' => (int) $source['unit_cost_minor'], 'selling_price_minor' => (int) $source['selling_price_minor'], 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) );
						$destination_batch_id = (int) $this->db->insert_id;
					}
					$this->upsert_balance( $tenant_id, $to_branch_id, $drug_id, $quantity, $now );
					$this->must_insert( 'stock_transfer_lines', array( 'tenant_id' => $tenant_id, 'transfer_id' => $transfer_id, 'drug_id' => $drug_id, 'source_batch_id' => (int) $source['id'], 'destination_batch_id' => $destination_batch_id, 'quantity' => $quantity, 'unit_cost_minor' => (int) $source['unit_cost_minor'], 'selling_price_minor' => (int) $source['selling_price_minor'], 'batch_number' => $source['batch_number'], 'expiry_date' => $source['expiry_date'], 'status' => 'completed', 'created_at' => $now ) );
					foreach ( array( array( $from_branch_id, (int) $source['id'], -$quantity, 'transfer_out' ), array( $to_branch_id, $destination_batch_id, $quantity, 'transfer_in' ) ) as $movement ) {
						$this->must_insert( 'stock_movements', array( 'tenant_id' => $tenant_id, 'branch_id' => $movement[0], 'drug_id' => $drug_id, 'batch_id' => $movement[1], 'movement_type' => $movement[3], 'quantity_delta' => $movement[2], 'unit_cost_minor' => (int) $source['unit_cost_minor'], 'reference_type' => 'stock_transfer', 'reference_id' => $transfer_id, 'reason' => 'Inter-branch transfer ' . $number, 'correlation_id' => $correlation_id, 'created_at' => $now, 'created_by' => get_current_user_id() ) );
					}
					$remaining = round( $remaining - $quantity, 3 );
					$line_count++;
				}
				if ( $remaining > 0 ) { throw new \DomainException( 'insufficient_stock' ); }
				$this->must_update_quantity( 'stock_balances', -$required, array( 'tenant_id' => $tenant_id, 'branch_id' => $from_branch_id, 'drug_id' => $drug_id ), $now );
			}
			$this->db->query( 'COMMIT' );
			$this->audit( $tenant_id, 'inventory.stock_transferred', 'stock_transfer', $transfer_id, array( 'from_branch_id' => $from_branch_id, 'to_branch_id' => $to_branch_id, 'items' => count( $items ), 'allocations' => $line_count, 'correlation_id' => $correlation_id ) );
			return array( 'id' => $transfer_id, 'transfer_number' => $number, 'allocations' => $line_count );
		} catch ( \DomainException $e ) {
			$this->db->query( 'ROLLBACK' );
			return new \WP_Error( 'transfer_not_allowed', 'The transfer contains invalid, unavailable or insufficient stock.', array( 'status' => 422 ) );
		} catch ( \Throwable $e ) {
			$this->db->query( 'ROLLBACK' );
			return new \WP_Error( 'transfer_failed', 'No stock was moved because the transfer could not be completed.', array( 'status' => 500 ) );
		}
	}

	/** Build the authenticated branch read model used by the standalone application. */
	public function workspace( $tenant_id, $branch_id, $view = 'catalogue', $search = '' ) {
		$tenant_id = (int) $tenant_id;
		$branch_id = (int) $branch_id;
		$allowed = array( 'catalogue', 'batches', 'receipts', 'movements', 'low-stock', 'expiry', 'suppliers' );
		$view = in_array( $view, $allowed, true ) ? $view : 'catalogue';
		if ( ! $tenant_id || ! $branch_id || ! $this->owns( 'branches', $tenant_id, $branch_id ) ) {
			return new \WP_Error( 'invalid_scope', 'An authorized tenant branch is required.', array( 'status' => 403 ) );
		}

		$scope = $this->db->get_row(
			$this->db->prepare(
				"SELECT t.trading_name,t.currency,b.name branch_name FROM {$this->p}tenants t JOIN {$this->p}branches b ON b.id=%d AND b.tenant_id=t.id AND b.is_active=1 WHERE t.id=%d",
				$branch_id,
				$tenant_id
			),
			ARRAY_A
		);
		$summary = array(
			'products' => (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->p}drugs WHERE tenant_id=%d AND status='active'", $tenant_id ) ),
			'units' => (float) $this->db->get_var( $this->db->prepare( "SELECT COALESCE(SUM(quantity_available),0) FROM {$this->p}stock_balances WHERE tenant_id=%d AND branch_id=%d", $tenant_id, $branch_id ) ),
			'low_stock' => (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM (SELECT d.id FROM {$this->p}drugs d LEFT JOIN {$this->p}stock_balances s ON s.tenant_id=d.tenant_id AND s.drug_id=d.id AND s.branch_id=%d WHERE d.tenant_id=%d AND d.status='active' GROUP BY d.id,d.reorder_level HAVING COALESCE(SUM(s.quantity_available),0)<=d.reorder_level) scoped_low", $branch_id, $tenant_id ) ),
			'expiry' => (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->p}batches WHERE tenant_id=%d AND branch_id=%d AND status='active' AND quantity_available>0 AND expiry_date<=DATE_ADD(UTC_DATE(),INTERVAL 180 DAY)", $tenant_id, $branch_id ) ),
		);

		$rows = array();
		$facts = array();
		switch ( $view ) {
			case 'batches':
				$rows = $this->db->get_results( $this->db->prepare( "SELECT bt.id,bt.batch_number,bt.expiry_date,bt.quantity_received,bt.quantity_available,bt.unit_cost_minor,bt.selling_price_minor,bt.status,d.sku,d.name,d.strength,s.name supplier_name FROM {$this->p}batches bt JOIN {$this->p}drugs d ON d.id=bt.drug_id AND d.tenant_id=bt.tenant_id LEFT JOIN {$this->p}suppliers s ON s.id=bt.supplier_id AND s.tenant_id=bt.tenant_id WHERE bt.tenant_id=%d AND bt.branch_id=%d ORDER BY bt.expiry_date ASC,bt.id DESC LIMIT %d", $tenant_id, $branch_id, 150 ), ARRAY_A );
				$facts = array( 'active_batches' => count( $rows ), 'available_units' => array_sum( array_map( static fn( $row ) => (float) $row['quantity_available'], $rows ) ) );
				break;

			case 'receipts':
				$rows = $this->db->get_results( $this->db->prepare( "SELECT r.id,r.purchase_reference,r.received_date,r.status,r.created_at,s.name supplier_name,b.name branch_name,COUNT(l.id) line_count,COALESCE(SUM(l.quantity),0) units_received,COALESCE(SUM(ROUND(l.quantity*l.unit_cost_minor)),0) receipt_value_minor FROM {$this->p}stock_receipts r JOIN {$this->p}suppliers s ON s.id=r.supplier_id AND s.tenant_id=r.tenant_id JOIN {$this->p}branches b ON b.id=r.branch_id AND b.tenant_id=r.tenant_id LEFT JOIN {$this->p}stock_receipt_lines l ON l.tenant_id=r.tenant_id AND l.receipt_id=r.id WHERE r.tenant_id=%d AND r.branch_id=%d GROUP BY r.id,r.purchase_reference,r.received_date,r.status,r.created_at,s.name,b.name ORDER BY r.received_date DESC,r.id DESC LIMIT %d", $tenant_id, $branch_id, 100 ), ARRAY_A );
				$facts = array( 'receipts' => count( $rows ), 'units_received' => array_sum( array_map( static fn( $row ) => (float) $row['units_received'], $rows ) ) );
				break;

			case 'movements':
				$rows = $this->db->get_results( $this->db->prepare( "SELECT m.id,m.created_at,m.movement_type,m.quantity_delta,m.unit_cost_minor,m.reference_type,m.reference_id,m.reason,m.correlation_id,d.sku,d.name FROM {$this->p}stock_movements m JOIN {$this->p}drugs d ON d.id=m.drug_id AND d.tenant_id=m.tenant_id WHERE m.tenant_id=%d AND m.branch_id=%d ORDER BY m.created_at DESC,m.id DESC LIMIT %d", $tenant_id, $branch_id, 150 ), ARRAY_A );
				$inbound = count( array_filter( $rows, static fn( $row ) => (float) $row['quantity_delta'] > 0 ) );
				$facts = array( 'entries' => count( $rows ), 'inbound' => $inbound, 'outbound' => count( $rows ) - $inbound );
				break;

			case 'low-stock':
				$rows = $this->db->get_results( $this->db->prepare( "SELECT d.id,d.sku,d.name,d.generic_name,d.reorder_level,d.unit_of_measure,COALESCE(SUM(sb.quantity_available),0) quantity_available,GREATEST(d.reorder_level-COALESCE(SUM(sb.quantity_available),0),0) shortfall FROM {$this->p}drugs d LEFT JOIN {$this->p}stock_balances sb ON sb.tenant_id=d.tenant_id AND sb.drug_id=d.id AND sb.branch_id=%d WHERE d.tenant_id=%d AND d.status='active' GROUP BY d.id,d.sku,d.name,d.generic_name,d.reorder_level,d.unit_of_measure HAVING quantity_available<=d.reorder_level ORDER BY shortfall DESC,d.name LIMIT %d", $branch_id, $tenant_id, 150 ), ARRAY_A );
				$facts = array( 'action_items' => count( $rows ), 'out_of_stock' => count( array_filter( $rows, static fn( $row ) => (float) $row['quantity_available'] <= 0 ) ) );
				break;

			case 'expiry':
				$rows = $this->db->get_results( $this->db->prepare( "SELECT bt.id,bt.batch_number,bt.expiry_date,bt.quantity_available,bt.unit_cost_minor,d.sku,d.name,s.name supplier_name,DATEDIFF(bt.expiry_date,UTC_DATE()) days_remaining FROM {$this->p}batches bt JOIN {$this->p}drugs d ON d.id=bt.drug_id AND d.tenant_id=bt.tenant_id LEFT JOIN {$this->p}suppliers s ON s.id=bt.supplier_id AND s.tenant_id=bt.tenant_id WHERE bt.tenant_id=%d AND bt.branch_id=%d AND bt.status='active' AND bt.quantity_available>0 ORDER BY bt.expiry_date ASC,bt.id ASC LIMIT %d", $tenant_id, $branch_id, 150 ), ARRAY_A );
				$facts = array( 'batches' => count( $rows ), 'within_180_days' => count( array_filter( $rows, static fn( $row ) => (int) $row['days_remaining'] <= 180 ) ), 'stock_value_minor' => array_sum( array_map( static fn( $row ) => (float) $row['quantity_available'] * (int) $row['unit_cost_minor'], $rows ) ) );
				break;

			case 'suppliers':
				$rows = $this->db->get_results( $this->db->prepare( "SELECT s.id,s.name,s.contact_name,s.phone,s.email,s.tax_number,s.payment_terms,s.status,COUNT(r.id) receipt_count,MAX(r.received_date) last_receipt FROM {$this->p}suppliers s LEFT JOIN {$this->p}stock_receipts r ON r.supplier_id=s.id AND r.tenant_id=s.tenant_id AND r.branch_id=%d WHERE s.tenant_id=%d GROUP BY s.id,s.name,s.contact_name,s.phone,s.email,s.tax_number,s.payment_terms,s.status ORDER BY s.status DESC,s.name LIMIT %d", $branch_id, $tenant_id, 100 ), ARRAY_A );
				$facts = array( 'suppliers' => count( $rows ), 'active' => count( array_filter( $rows, static fn( $row ) => 'active' === $row['status'] ) ) );
				break;

			case 'catalogue':
			default:
				$search = trim( sanitize_text_field( $search ) );
				$filter = '';
				$args = array( $branch_id, $tenant_id );
				if ( '' !== $search ) {
					$like = '%' . $this->db->esc_like( $search ) . '%';
					$filter = ' AND (d.sku LIKE %s OR d.barcode LIKE %s OR d.name LIKE %s OR d.generic_name LIKE %s)';
					$args = array_merge( $args, array( $like, $like, $like, $like ) );
				}
				$args[] = 150;
				$rows = $this->db->get_results( $this->db->prepare( "SELECT d.id,d.sku,d.barcode,d.name,d.generic_name,d.strength,d.dosage_form,d.pack_size,d.category,d.manufacturer,d.unit_of_measure,d.requires_prescription,d.is_controlled,d.cost_price_minor,d.selling_price_minor,d.reorder_level,d.status,COALESCE(SUM(sb.quantity_available),0) quantity_available FROM {$this->p}drugs d LEFT JOIN {$this->p}stock_balances sb ON sb.tenant_id=d.tenant_id AND sb.drug_id=d.id AND sb.branch_id=%d WHERE d.tenant_id=%d{$filter} GROUP BY d.id,d.sku,d.barcode,d.name,d.generic_name,d.strength,d.dosage_form,d.pack_size,d.category,d.manufacturer,d.unit_of_measure,d.requires_prescription,d.is_controlled,d.cost_price_minor,d.selling_price_minor,d.reorder_level,d.status ORDER BY d.status DESC,d.name LIMIT %d", ...$args ), ARRAY_A );
				$facts = array( 'medicines' => count( $rows ), 'prescription_items' => count( array_filter( $rows, static fn( $row ) => (int) $row['requires_prescription'] === 1 ) ), 'categories' => count( array_unique( array_filter( array_column( $rows, 'category' ) ) ) ) );
				break;
		}

		return array(
			'scope' => $scope,
			'view' => $view,
			'summary' => $summary,
			'rows' => $rows ?: array(),
			'facts' => $facts,
		);
	}

	private function validate_receipt_item( $tenant_id, array $item, $received_date ) {
		$expiry = sanitize_text_field( $item['expiry_date'] ?? '' );
		return $this->owns( 'drugs', $tenant_id, (int) ( $item['drug_id'] ?? 0 ) ) && '' !== sanitize_text_field( $item['batch_number'] ?? '' ) && (float) ( $item['quantity'] ?? 0 ) > 0 && (int) ( $item['unit_cost_minor'] ?? -1 ) >= 0 && $this->valid_date( $expiry ) && $expiry > $received_date;
	}

	private function owns_active_branch( $tenant_id, $branch_id ) {
		return (bool) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}branches WHERE id=%d AND tenant_id=%d AND is_active=1", $branch_id, $tenant_id ) );
	}

	private function reference_number( $prefix ) {
		return sanitize_key( $prefix ) . '-' . gmdate( 'Ymd-His' ) . '-' . strtoupper( wp_generate_password( 5, false, false ) );
	}

	private function must_update_quantity( $table, $delta, array $scope, $now ) {
		$allowed = array( 'batches', 'stock_balances' );
		if ( ! in_array( $table, $allowed, true ) ) {
			throw new \RuntimeException( 'Invalid quantity table.' );
		}
		$where = array();
		$params = array( $delta, $now );
		foreach ( $scope as $column => $value ) {
			if ( ! in_array( $column, array( 'id', 'tenant_id', 'branch_id', 'drug_id' ), true ) ) { throw new \RuntimeException( 'Invalid scope.' ); }
			$where[] = "{$column}=%d";
			$params[] = (int) $value;
		}
		$sql = "UPDATE {$this->p}{$table} SET quantity_available=quantity_available+%f,updated_at=%s WHERE " . implode( ' AND ', $where ) . ' AND quantity_available+%f>=0';
		$params[] = $delta;
		$updated = $this->db->query( $this->db->prepare( $sql, $params ) );
		if ( 1 !== $updated ) { throw new \DomainException( 'quantity_update_failed' ); }
	}

	private function upsert_balance( $tenant_id, $branch_id, $drug_id, $delta, $now ) {
		$this->db->query( $this->db->prepare( "INSERT INTO {$this->p}stock_balances (tenant_id,branch_id,drug_id,quantity_available,updated_at) VALUES (%d,%d,%d,%f,%s) ON DUPLICATE KEY UPDATE quantity_available=quantity_available+VALUES(quantity_available),updated_at=VALUES(updated_at)", $tenant_id, $branch_id, $drug_id, $delta, $now ) );
		if ( $this->db->last_error ) { throw new \RuntimeException( $this->db->last_error ); }
	}

	private function audit( $tenant_id, $action, $object_type, $object_id, array $details ) {
		do_action( 'pharmasure_audit_log', array( 'tenant_id' => (int) $tenant_id, 'action' => $action, 'object_type' => $object_type, 'object_id' => (int) $object_id, 'status' => 'success', 'details' => $details ) );
	}

	private function owns( $table, $tenant_id, $id ) { return (bool) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}{$table} WHERE id=%d AND tenant_id=%d", $id, $tenant_id ) ); }
	private function get_tenant_row( $table, $tenant_id, $id ) { return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->p}{$table} WHERE id=%d AND tenant_id=%d", $id, $tenant_id ), ARRAY_A ); }
	private function valid_date( $date ) { $d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date ); return $d && $d->format( 'Y-m-d' ) === $date; }
	private function must_insert( $table, array $row ) { if ( false === $this->db->insert( $this->p . $table, $row ) ) { throw new \RuntimeException( $this->db->last_error ); } }
}
