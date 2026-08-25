<?php
namespace PharmaSure\Inventory;

final class Installer {
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$p = $wpdb->prefix . 'ps_';
		$c = $wpdb->get_charset_collate();

		$sql = array(
			"CREATE TABLE {$p}drugs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				tenant_id BIGINT UNSIGNED NOT NULL,
				sku VARCHAR(100) NOT NULL,
				barcode VARCHAR(100) NULL,
				name VARCHAR(255) NOT NULL,
				generic_name VARCHAR(255) NULL,
				strength VARCHAR(100) NULL,
				dosage_form VARCHAR(100) NULL,
				pack_size VARCHAR(100) NULL,
				unit_of_measure VARCHAR(50) NOT NULL DEFAULT 'unit',
				category VARCHAR(150) NULL,
				manufacturer VARCHAR(255) NULL,
				requires_prescription TINYINT(1) NOT NULL DEFAULT 0,
				is_controlled TINYINT(1) NOT NULL DEFAULT 0,
				cost_price_minor BIGINT NOT NULL DEFAULT 0,
				selling_price_minor BIGINT NOT NULL DEFAULT 0,
				reorder_level DECIMAL(18,3) NOT NULL DEFAULT 0,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				created_by BIGINT UNSIGNED NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY tenant_sku (tenant_id,sku),
				KEY tenant_barcode (tenant_id,barcode),
				KEY tenant_status (tenant_id,status),
				KEY tenant_created (tenant_id,created_at)
			) $c",
			"CREATE TABLE {$p}suppliers (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL,
				name VARCHAR(255) NOT NULL, contact_name VARCHAR(255) NULL, phone VARCHAR(50) NULL,
				email VARCHAR(255) NULL, tax_number VARCHAR(100) NULL, payment_terms VARCHAR(255) NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL, created_by BIGINT UNSIGNED NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY tenant_name (tenant_id,name),
				KEY tenant_status (tenant_id,status),
				KEY tenant_created (tenant_id,created_at)
			) $c",
			"CREATE TABLE {$p}stock_receipts (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL,
				branch_id BIGINT UNSIGNED NOT NULL, supplier_id BIGINT UNSIGNED NOT NULL,
				purchase_reference VARCHAR(150) NULL, received_date DATE NOT NULL, notes TEXT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'completed', created_at DATETIME NOT NULL,
				created_by BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY  (id),
				KEY tenant_branch_date (tenant_id,branch_id,received_date),
				KEY tenant_created (tenant_id,created_at),
				KEY tenant_status (tenant_id,status),
				KEY supplier_id (supplier_id)
			) $c",
			"CREATE TABLE {$p}stock_receipt_lines (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL,
				receipt_id BIGINT UNSIGNED NOT NULL,
				drug_id BIGINT UNSIGNED NOT NULL, batch_number VARCHAR(100) NOT NULL,
				quantity DECIMAL(18,3) NOT NULL, unit_cost_minor BIGINT NOT NULL,
				selling_price_minor BIGINT NOT NULL DEFAULT 0, manufacture_date DATE NULL, expiry_date DATE NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'completed', created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY tenant_receipt (tenant_id,receipt_id),
				KEY tenant_drug (tenant_id,drug_id),
				KEY tenant_created (tenant_id,created_at),
				KEY tenant_status (tenant_id,status)
			) $c",
			"CREATE TABLE {$p}batches (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				tenant_id BIGINT UNSIGNED NOT NULL,
				branch_id BIGINT UNSIGNED NOT NULL,
				drug_id BIGINT UNSIGNED NOT NULL,
				supplier_id BIGINT UNSIGNED NULL,
				batch_number VARCHAR(100) NOT NULL,
				manufacture_date DATE NULL,
				expiry_date DATE NOT NULL,
				quantity_received DECIMAL(18,3) NOT NULL DEFAULT 0,
				quantity_available DECIMAL(18,3) NOT NULL DEFAULT 0,
				unit_cost_minor BIGINT NOT NULL DEFAULT 0,
				selling_price_minor BIGINT NOT NULL DEFAULT 0,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY branch_drug_batch (tenant_id,branch_id,drug_id,batch_number),
				KEY fefo (tenant_id,branch_id,drug_id,expiry_date,quantity_available),
				KEY tenant_created (tenant_id,created_at),
				KEY tenant_status (tenant_id,status)
			) $c",
			"CREATE TABLE {$p}stock_balances (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL,
				branch_id BIGINT UNSIGNED NOT NULL, drug_id BIGINT UNSIGNED NOT NULL,
				quantity_available DECIMAL(18,3) NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY branch_drug (tenant_id,branch_id,drug_id),
				KEY branch_stock (tenant_id,branch_id,quantity_available),
				KEY tenant_updated (tenant_id,updated_at)
			) $c",
			"CREATE TABLE {$p}stock_movements (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL,
				branch_id BIGINT UNSIGNED NOT NULL, drug_id BIGINT UNSIGNED NOT NULL, batch_id BIGINT UNSIGNED NULL,
				movement_type VARCHAR(30) NOT NULL, quantity_delta DECIMAL(18,3) NOT NULL,
				unit_cost_minor BIGINT NULL,
				reference_type VARCHAR(50) NOT NULL, reference_id BIGINT UNSIGNED NOT NULL,
				reason VARCHAR(255) NULL, correlation_id VARCHAR(100) NULL, created_at DATETIME NOT NULL,
				created_by BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY  (id),
				KEY tenant_branch_created (tenant_id,branch_id,created_at),
				KEY tenant_created (tenant_id,created_at),
				KEY tenant_status (tenant_id,movement_type),
				KEY drug_created (tenant_id,drug_id,created_at),
				KEY reference_lookup (reference_type,reference_id)
			) $c",
			"CREATE TABLE {$p}stock_adjustments (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL,
				branch_id BIGINT UNSIGNED NOT NULL, adjustment_number VARCHAR(64) NOT NULL,
				reason_code VARCHAR(30) NOT NULL, notes VARCHAR(255) NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'completed', created_at DATETIME NOT NULL,
				created_by BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY tenant_adjustment_number (tenant_id,adjustment_number),
				KEY tenant_branch_created (tenant_id,branch_id,created_at),
				KEY tenant_created (tenant_id,created_at),
				KEY tenant_status (tenant_id,status)
			) $c",
			"CREATE TABLE {$p}stock_adjustment_lines (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL,
				adjustment_id BIGINT UNSIGNED NOT NULL, drug_id BIGINT UNSIGNED NOT NULL,
				batch_id BIGINT UNSIGNED NOT NULL, quantity_delta DECIMAL(18,3) NOT NULL,
				unit_cost_minor BIGINT NOT NULL DEFAULT 0, reason VARCHAR(255) NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'completed', created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY tenant_adjustment (tenant_id,adjustment_id),
				KEY tenant_drug_created (tenant_id,drug_id,created_at),
				KEY tenant_created (tenant_id,created_at),
				KEY tenant_status (tenant_id,status)
			) $c",
			"CREATE TABLE {$p}stock_transfers (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL,
				from_branch_id BIGINT UNSIGNED NOT NULL, to_branch_id BIGINT UNSIGNED NOT NULL,
				transfer_number VARCHAR(64) NOT NULL, notes VARCHAR(255) NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'completed', created_at DATETIME NOT NULL,
				created_by BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY tenant_transfer_number (tenant_id,transfer_number),
				KEY tenant_from_created (tenant_id,from_branch_id,created_at),
				KEY tenant_to_created (tenant_id,to_branch_id,created_at),
				KEY tenant_created (tenant_id,created_at),
				KEY tenant_status (tenant_id,status)
			) $c",
			"CREATE TABLE {$p}stock_transfer_lines (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL,
				transfer_id BIGINT UNSIGNED NOT NULL, drug_id BIGINT UNSIGNED NOT NULL,
				source_batch_id BIGINT UNSIGNED NOT NULL, destination_batch_id BIGINT UNSIGNED NOT NULL,
				quantity DECIMAL(18,3) NOT NULL, unit_cost_minor BIGINT NOT NULL DEFAULT 0,
				selling_price_minor BIGINT NOT NULL DEFAULT 0, batch_number VARCHAR(100) NOT NULL,
				expiry_date DATE NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'completed',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY tenant_transfer (tenant_id,transfer_id),
				KEY tenant_drug_created (tenant_id,drug_id,created_at),
				KEY tenant_created (tenant_id,created_at),
				KEY tenant_status (tenant_id,status)
			) $c",
			"CREATE TABLE {$p}batch_dispositions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL,
				branch_id BIGINT UNSIGNED NOT NULL, batch_id BIGINT UNSIGNED NOT NULL,
				previous_status VARCHAR(20) NOT NULL, new_status VARCHAR(20) NOT NULL,
				quantity_affected DECIMAL(18,3) NOT NULL DEFAULT 0, reason VARCHAR(255) NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'completed', created_at DATETIME NOT NULL,
				created_by BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY  (id),
				KEY tenant_batch (tenant_id,batch_id),
				KEY tenant_branch_created (tenant_id,branch_id,created_at),
				KEY tenant_created (tenant_id,created_at),
				KEY tenant_status (tenant_id,status)
			) $c",
		);

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
		// Legacy receipt lines pre-date direct tenant scoping. Backfill them from
		// their immutable parent before any tenant-scoped line query is used.
		$wpdb->query( "UPDATE {$p}stock_receipt_lines l JOIN {$p}stock_receipts r ON r.id=l.receipt_id SET l.tenant_id=r.tenant_id,l.created_at=r.created_at WHERE l.tenant_id=0" );
		// Conservative legacy backfill: receipt-line costs are immutable and
		// therefore safe to restore. Ambiguous historical sale costs stay NULL.
		$wpdb->query( "UPDATE {$p}stock_movements m JOIN {$p}stock_receipt_lines l ON l.tenant_id=m.tenant_id AND m.reference_type='stock_receipt' AND l.receipt_id=m.reference_id AND l.drug_id=m.drug_id JOIN {$p}batches b ON b.tenant_id=m.tenant_id AND b.id=m.batch_id AND b.batch_number=l.batch_number SET m.unit_cost_minor=l.unit_cost_minor WHERE m.unit_cost_minor IS NULL AND m.movement_type='receipt'" );
		update_site_option( 'pharmasure_inventory_db_version', DB_VERSION );
	}
}
