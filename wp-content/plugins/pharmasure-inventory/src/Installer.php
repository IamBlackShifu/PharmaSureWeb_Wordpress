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
				KEY tenant_status (tenant_id,status)
			) $c",
			"CREATE TABLE {$p}suppliers (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL,
				name VARCHAR(255) NOT NULL, contact_name VARCHAR(255) NULL, phone VARCHAR(50) NULL,
				email VARCHAR(255) NULL, tax_number VARCHAR(100) NULL, payment_terms VARCHAR(255) NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL, created_by BIGINT UNSIGNED NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY tenant_name (tenant_id,name),
				KEY tenant_status (tenant_id,status)
			) $c",
			"CREATE TABLE {$p}stock_receipts (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL,
				branch_id BIGINT UNSIGNED NOT NULL, supplier_id BIGINT UNSIGNED NOT NULL,
				purchase_reference VARCHAR(150) NULL, received_date DATE NOT NULL, notes TEXT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'completed', created_at DATETIME NOT NULL,
				created_by BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY  (id),
				KEY tenant_branch_date (tenant_id,branch_id,received_date),
				KEY supplier_id (supplier_id)
			) $c",
			"CREATE TABLE {$p}stock_receipt_lines (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, receipt_id BIGINT UNSIGNED NOT NULL,
				drug_id BIGINT UNSIGNED NOT NULL, batch_number VARCHAR(100) NOT NULL,
				quantity DECIMAL(18,3) NOT NULL, unit_cost_minor BIGINT NOT NULL,
				selling_price_minor BIGINT NOT NULL DEFAULT 0, manufacture_date DATE NULL, expiry_date DATE NOT NULL,
				PRIMARY KEY  (id),
				KEY receipt_id (receipt_id),
				KEY drug_id (drug_id)
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
				KEY fefo (tenant_id,branch_id,drug_id,expiry_date,quantity_available)
			) $c",
			"CREATE TABLE {$p}stock_balances (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL,
				branch_id BIGINT UNSIGNED NOT NULL, drug_id BIGINT UNSIGNED NOT NULL,
				quantity_available DECIMAL(18,3) NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY branch_drug (tenant_id,branch_id,drug_id),
				KEY branch_stock (tenant_id,branch_id,quantity_available)
			) $c",
			"CREATE TABLE {$p}stock_movements (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL,
				branch_id BIGINT UNSIGNED NOT NULL, drug_id BIGINT UNSIGNED NOT NULL, batch_id BIGINT UNSIGNED NULL,
				movement_type VARCHAR(30) NOT NULL, quantity_delta DECIMAL(18,3) NOT NULL,
				reference_type VARCHAR(50) NOT NULL, reference_id BIGINT UNSIGNED NOT NULL,
				reason VARCHAR(255) NULL, correlation_id VARCHAR(100) NULL, created_at DATETIME NOT NULL,
				created_by BIGINT UNSIGNED NOT NULL,
				PRIMARY KEY  (id),
				KEY tenant_branch_created (tenant_id,branch_id,created_at),
				KEY drug_created (tenant_id,drug_id,created_at),
				KEY reference_lookup (reference_type,reference_id)
			) $c",
		);

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
		update_site_option( 'pharmasure_inventory_db_version', DB_VERSION );
	}
}
