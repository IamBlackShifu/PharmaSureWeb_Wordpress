<?php
namespace PharmaSure\POS;

final class Installer {
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$p = $wpdb->prefix . 'ps_';
		$c = $wpdb->get_charset_collate();
		$sql = array(
			"CREATE TABLE {$p}tills (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,branch_id BIGINT UNSIGNED NOT NULL,
				name VARCHAR(100) NOT NULL,code VARCHAR(50) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL,created_by BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY  (id),UNIQUE KEY branch_code (tenant_id,branch_id,code),KEY tenant_branch (tenant_id,branch_id,status)
			) $c",
			"CREATE TABLE {$p}till_sessions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,branch_id BIGINT UNSIGNED NOT NULL,till_id BIGINT UNSIGNED NOT NULL,
				cashier_id BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'open',opening_float_minor BIGINT NOT NULL DEFAULT 0,
				expected_cash_minor BIGINT NULL,counted_cash_minor BIGINT NULL,variance_minor BIGINT NULL,variance_status VARCHAR(20) NOT NULL DEFAULT 'not_required',variance_reason VARCHAR(500) NULL,variance_approved_by BIGINT UNSIGNED NULL,variance_approved_at DATETIME NULL,opened_at DATETIME NOT NULL,closed_at DATETIME NULL,
				PRIMARY KEY  (id),KEY open_till (tenant_id,branch_id,till_id,status),KEY cashier_status (tenant_id,cashier_id,status)
			) $c",
			"CREATE TABLE {$p}document_sequences (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,branch_id BIGINT UNSIGNED NOT NULL,document_type VARCHAR(30) NOT NULL,next_value BIGINT UNSIGNED NOT NULL DEFAULT 1,
				PRIMARY KEY  (id),UNIQUE KEY branch_document (tenant_id,branch_id,document_type)
			) $c",
			"CREATE TABLE {$p}sales (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				tenant_id BIGINT UNSIGNED NOT NULL,
				branch_id BIGINT UNSIGNED NOT NULL,
				till_session_id BIGINT UNSIGNED NULL,
				patient_id BIGINT UNSIGNED NULL,
				prescription_id BIGINT UNSIGNED NULL,
				receipt_number VARCHAR(100) NULL,
				idempotency_key VARCHAR(100) NULL,
				subtotal_amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
				discount_amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
				discount_reason VARCHAR(255) NULL,
				tax_amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
				total_amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
				status VARCHAR(30) NOT NULL DEFAULT 'completed',
				cashier_id BIGINT UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY prescription_sale (tenant_id,prescription_id),
				UNIQUE KEY tenant_idempotency (tenant_id,idempotency_key),
				UNIQUE KEY branch_receipt (tenant_id,branch_id,receipt_number),
				KEY tenant_branch (tenant_id,branch_id),
				KEY tenant_patient (tenant_id,patient_id),
				KEY session_sales (till_session_id,status)
			) $c",
			"CREATE TABLE {$p}sale_items (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,branch_id BIGINT UNSIGNED NOT NULL,sale_id BIGINT UNSIGNED NOT NULL,drug_id BIGINT UNSIGNED NOT NULL,
				description VARCHAR(255) NOT NULL,quantity DECIMAL(18,3) NOT NULL,unit_price_minor BIGINT UNSIGNED NOT NULL,line_total_minor BIGINT UNSIGNED NOT NULL,cost_amount_minor BIGINT NULL,
				PRIMARY KEY  (id),KEY sale_id (sale_id),KEY tenant_drug (tenant_id,drug_id)
			) $c",
			"CREATE TABLE {$p}sale_payments (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,branch_id BIGINT UNSIGNED NOT NULL,sale_id BIGINT UNSIGNED NOT NULL,
				method VARCHAR(30) NOT NULL,amount_minor BIGINT UNSIGNED NOT NULL,currency CHAR(3) NOT NULL DEFAULT 'USD',external_reference VARCHAR(150) NULL,status VARCHAR(20) NOT NULL,created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),KEY sale_id (sale_id),KEY tenant_method (tenant_id,branch_id,method)
			) $c",
			"CREATE TABLE {$p}sale_holds (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,branch_id BIGINT UNSIGNED NOT NULL,till_session_id BIGINT UNSIGNED NOT NULL,
				reference VARCHAR(100) NOT NULL,cart_data LONGTEXT NOT NULL,notes VARCHAR(500) NULL,status VARCHAR(20) NOT NULL DEFAULT 'held',held_by BIGINT UNSIGNED NOT NULL,held_at DATETIME NOT NULL,cancelled_at DATETIME NULL,
				PRIMARY KEY  (id),UNIQUE KEY tenant_reference (tenant_id,reference),KEY branch_status (tenant_id,branch_id,status)
			) $c",
			"CREATE TABLE {$p}refunds (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,branch_id BIGINT UNSIGNED NOT NULL,sale_id BIGINT UNSIGNED NOT NULL,kind VARCHAR(20) NOT NULL DEFAULT 'refund',idempotency_key VARCHAR(100) NOT NULL,
				amount_minor BIGINT UNSIGNED NOT NULL,reason VARCHAR(500) NOT NULL,status VARCHAR(30) NOT NULL,created_by BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),UNIQUE KEY tenant_idempotency (tenant_id,idempotency_key),KEY sale_refunds (tenant_id,sale_id,status),KEY branch_created (tenant_id,branch_id,created_at)
			) $c",
			"CREATE TABLE {$p}refund_items (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,refund_id BIGINT UNSIGNED NOT NULL,sale_id BIGINT UNSIGNED NOT NULL,drug_id BIGINT UNSIGNED NOT NULL,batch_id BIGINT UNSIGNED NOT NULL,
				quantity DECIMAL(18,3) NOT NULL,disposition VARCHAR(20) NOT NULL,amount_minor BIGINT UNSIGNED NOT NULL,cost_amount_minor BIGINT NULL,
				PRIMARY KEY  (id),KEY refund_id (refund_id),KEY sale_drug_batch (tenant_id,sale_id,drug_id,batch_id)
			) $c",
			"CREATE TABLE {$p}refund_payments (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,refund_id BIGINT UNSIGNED NOT NULL,sale_payment_id BIGINT UNSIGNED NOT NULL,method VARCHAR(30) NOT NULL,amount_minor BIGINT UNSIGNED NOT NULL,status VARCHAR(30) NOT NULL,external_reference VARCHAR(150) NULL,
				PRIMARY KEY  (id),KEY refund_id (refund_id),KEY original_payment (sale_payment_id)
			) $c",
		);
		foreach ( $sql as $statement ) { dbDelta( \PharmaSure\Core\DatabaseMigrations::normalize_dbdelta_sql( $statement ) ); }
		// Backfill only from immutable movement snapshots. Rows whose source
		// movements predate cost capture remain NULL and are flagged in reports.
		$wpdb->query( "UPDATE {$p}sale_items si JOIN (SELECT tenant_id,reference_id sale_id,drug_id,SUM(ROUND(ABS(quantity_delta)*unit_cost_minor)) cost_minor FROM {$p}stock_movements WHERE reference_type='sale' AND quantity_delta<0 AND unit_cost_minor IS NOT NULL GROUP BY tenant_id,reference_id,drug_id) c ON c.tenant_id=si.tenant_id AND c.sale_id=si.sale_id AND c.drug_id=si.drug_id SET si.cost_amount_minor=c.cost_minor WHERE si.cost_amount_minor IS NULL" );
		$wpdb->query( "UPDATE {$p}refund_items ri JOIN {$p}stock_movements m ON m.tenant_id=ri.tenant_id AND m.reference_type='sale' AND m.reference_id=ri.sale_id AND m.drug_id=ri.drug_id AND m.batch_id=ri.batch_id SET ri.cost_amount_minor=ROUND(ri.quantity*m.unit_cost_minor) WHERE ri.cost_amount_minor IS NULL AND m.unit_cost_minor IS NOT NULL" );
		update_site_option( 'pharmasure_pos_db_version', DB_VERSION );
	}
}
