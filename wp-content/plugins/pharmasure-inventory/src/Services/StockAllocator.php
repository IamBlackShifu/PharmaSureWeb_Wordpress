<?php
namespace PharmaSure\Inventory\Services;

/** Transaction-aware FEFO stock allocation shared by dispensing and POS. */
final class StockAllocator {
	private $db;
	private $p;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
		$this->p = $wpdb->prefix . 'ps_';
	}

	/**
	 * Consume stock from the earliest-expiring eligible batches.
	 *
	 * The caller must own the surrounding database transaction. Rows are
	 * locked until that transaction commits or rolls back.
	 */
	public function consume_fefo( $tenant_id, $branch_id, $drug_id, $quantity, $reference_type, $reference_id, $correlation_id, $reason = 'Stock consumed' ) {
		$tenant_id = (int) $tenant_id;
		$branch_id = (int) $branch_id;
		$drug_id = (int) $drug_id;
		$quantity = (float) $quantity;
		$reference_type = sanitize_key( $reference_type );
		$reference_id = (int) $reference_id;

		if ( ! $tenant_id || ! $branch_id || ! $drug_id || $quantity <= 0 || ! $reference_type || ! $reference_id ) {
			return new \WP_Error( 'invalid_stock_request', 'A scoped drug, positive quantity and transaction reference are required.', array( 'status' => 422 ) );
		}

		$drug_exists = $this->db->get_var( $this->db->prepare(
			"SELECT id FROM {$this->p}drugs WHERE id=%d AND tenant_id=%d AND status='active'",
			$drug_id,
			$tenant_id
		) );
		if ( ! $drug_exists ) {
			return new \WP_Error( 'invalid_drug_scope', 'Drug is not active in the current tenant.', array( 'status' => 403 ) );
		}

		$today = gmdate( 'Y-m-d' );
		$batches = $this->db->get_results( $this->db->prepare(
			"SELECT id,batch_number,expiry_date,quantity_available,unit_cost_minor FROM {$this->p}batches
			 WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d AND status='active'
			 AND expiry_date >= %s AND quantity_available > 0
			 ORDER BY expiry_date ASC,id ASC FOR UPDATE",
			$tenant_id,
			$branch_id,
			$drug_id,
			$today
		), ARRAY_A );

		$available = array_sum( array_map( static fn( $batch ) => (float) $batch['quantity_available'], $batches ) );
		if ( $available + 0.000001 < $quantity ) {
			return new \WP_Error( 'insufficient_stock', 'Insufficient unexpired stock is available in this branch.', array( 'status' => 409, 'drug_id' => $drug_id, 'requested' => $quantity, 'available' => $available ) );
		}

		$remaining = $quantity;
		$allocations = array();
		$now = current_time( 'mysql', true );
		foreach ( $batches as $batch ) {
			if ( $remaining <= 0.000001 ) { break; }
			$take = min( $remaining, (float) $batch['quantity_available'] );
			$updated = $this->db->query( $this->db->prepare(
				"UPDATE {$this->p}batches SET quantity_available=quantity_available-%f,updated_at=%s
				 WHERE id=%d AND tenant_id=%d AND branch_id=%d AND quantity_available >= %f",
				$take,
				$now,
				$batch['id'],
				$tenant_id,
				$branch_id,
				$take
			) );
			if ( 1 !== $updated ) {
				return new \WP_Error( 'stock_changed', 'Stock changed while it was being allocated. Retry the transaction.', array( 'status' => 409 ) );
			}

			$inserted = $this->db->insert( $this->p . 'stock_movements', array(
				'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'drug_id' => $drug_id, 'batch_id' => (int) $batch['id'],
				'movement_type' => 'dispensing' === $reference_type ? 'dispensing' : 'sale', 'quantity_delta' => -$take,
				'unit_cost_minor' => (int) $batch['unit_cost_minor'],
				'reference_type' => $reference_type, 'reference_id' => $reference_id, 'reason' => sanitize_text_field( $reason ),
				'correlation_id' => sanitize_text_field( $correlation_id ), 'created_at' => $now, 'created_by' => get_current_user_id(),
			) );
			if ( false === $inserted ) {
				return new \WP_Error( 'movement_failed', 'The stock movement could not be recorded.', array( 'status' => 500 ) );
			}
			$allocations[] = array( 'batch_id' => (int) $batch['id'], 'batch_number' => $batch['batch_number'], 'expiry_date' => $batch['expiry_date'], 'quantity' => $take, 'unit_cost_minor' => (int) $batch['unit_cost_minor'], 'cost_amount_minor' => (int) round( $take * (int) $batch['unit_cost_minor'] ) );
			$remaining -= $take;
		}

		$balance_updated = $this->db->query( $this->db->prepare(
			"UPDATE {$this->p}stock_balances SET quantity_available=quantity_available-%f,updated_at=%s
			 WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d AND quantity_available >= %f",
			$quantity,
			$now,
			$tenant_id,
			$branch_id,
			$drug_id,
			$quantity
		) );
		if ( 1 !== $balance_updated ) {
			return new \WP_Error( 'stock_balance_changed', 'The branch stock balance is insufficient or inconsistent.', array( 'status' => 409 ) );
		}

		return $allocations;
	}
}
