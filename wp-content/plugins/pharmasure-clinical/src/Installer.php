<?php
namespace PharmaSure\Clinical;

final class Installer {
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$prefix = $wpdb->prefix . 'ps_';
		$collate = $wpdb->get_charset_collate();

		$sql = [];
		$sql[] = "CREATE TABLE {$prefix}patients (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			branch_id BIGINT UNSIGNED NOT NULL,
			patient_number VARCHAR(100) NOT NULL,
			first_name VARCHAR(100) NOT NULL,
			last_name VARCHAR(100) NOT NULL,
			date_of_birth DATE NULL,
			phone VARCHAR(30) NULL,
			email VARCHAR(255) NULL,
			address TEXT NULL,
			allergies TEXT NULL,
			medical_conditions TEXT NULL,
			current_medications TEXT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY tenant_patient_number (tenant_id, patient_number),
			KEY tenant_branch (tenant_id, branch_id),
			KEY tenant_status (tenant_id, status),
			KEY tenant_created (tenant_id, created_at),
			KEY patient_name (tenant_id, last_name, first_name)
		) {$collate};";

		$sql[] = "CREATE TABLE {$prefix}prescriptions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			branch_id BIGINT UNSIGNED NOT NULL,
			patient_id BIGINT UNSIGNED NOT NULL,
			prescriber VARCHAR(255) NOT NULL,
			prescription_date DATE NOT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'draft',
			notes TEXT NULL,
			review_notes TEXT NULL,
			rejection_reason VARCHAR(500) NULL,
			allergies_checked TINYINT(1) NOT NULL DEFAULT 0,
			interactions_checked TINYINT(1) NOT NULL DEFAULT 0,
			dose_checked TINYINT(1) NOT NULL DEFAULT 0,
			controlled_drug_attested TINYINT(1) NOT NULL DEFAULT 0,
			counselling_notes TEXT NULL,
			approved_at DATETIME NULL,
			approved_by_user BIGINT UNSIGNED NULL,
			reviewed_at DATETIME NULL,
			reviewed_by_user BIGINT UNSIGNED NULL,
			dispensed_at DATETIME NULL,
			dispensed_by_user BIGINT UNSIGNED NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY tenant_status (tenant_id, status),
			KEY tenant_created (tenant_id, created_at),
			KEY tenant_patient (tenant_id, patient_id),
			KEY branch_date (branch_id, prescription_date)
		) {$collate};";

		$sql[] = "CREATE TABLE {$prefix}prescription_items (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			branch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			prescription_id BIGINT UNSIGNED NOT NULL,
			drug_id BIGINT UNSIGNED NULL,
			drug_name VARCHAR(255) NOT NULL,
			strength VARCHAR(100) NULL,
			dose VARCHAR(100) NOT NULL,
			route VARCHAR(100) NULL,
			frequency VARCHAR(100) NOT NULL,
			duration VARCHAR(100) NULL,
			quantity DECIMAL(15,3) NOT NULL,
			repeats INT UNSIGNED NOT NULL DEFAULT 0,
			is_controlled TINYINT(1) NOT NULL DEFAULT 0,
			unit_price_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(30) NOT NULL DEFAULT 'active',
			created_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY tenant_prescription (tenant_id, prescription_id),
			KEY tenant_drug (tenant_id, drug_id),
			KEY tenant_created (tenant_id, created_at),
			KEY tenant_status (tenant_id, status)
		) {$collate};";

		$sql[] = "CREATE TABLE {$prefix}sales (
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
			PRIMARY KEY (id),
			UNIQUE KEY prescription_sale (tenant_id, prescription_id),
			UNIQUE KEY tenant_idempotency (tenant_id, idempotency_key),
			UNIQUE KEY branch_receipt (tenant_id, branch_id, receipt_number),
			KEY tenant_branch (tenant_id, branch_id),
			KEY tenant_patient (tenant_id, patient_id),
			KEY tenant_created (tenant_id, created_at),
			KEY tenant_status (tenant_id, status)
		) {$collate};";

		$sql[] = "CREATE TABLE {$prefix}dispensing_checks (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			branch_id BIGINT UNSIGNED NOT NULL,
			prescription_id BIGINT UNSIGNED NOT NULL,
			patient_id BIGINT UNSIGNED NOT NULL,
			allergies_checked TINYINT(1) NOT NULL DEFAULT 0,
			interactions_checked TINYINT(1) NOT NULL DEFAULT 0,
			dose_checked TINYINT(1) NOT NULL DEFAULT 0,
			counselling_provided TINYINT(1) NOT NULL DEFAULT 0,
			counselling_notes TEXT NOT NULL,
			controlled_witness_user_id BIGINT UNSIGNED NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'completed',
			created_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY tenant_prescription (tenant_id, prescription_id),
			KEY tenant_created (tenant_id, created_at),
			KEY tenant_status (tenant_id, status)
		) {$collate};";

		$sql[] = "CREATE TABLE {$prefix}controlled_dispense_register (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			branch_id BIGINT UNSIGNED NOT NULL,
			prescription_id BIGINT UNSIGNED NOT NULL,
			prescription_item_id BIGINT UNSIGNED NOT NULL,
			drug_id BIGINT UNSIGNED NOT NULL,
			patient_id BIGINT UNSIGNED NOT NULL,
			register_reference VARCHAR(64) NOT NULL,
			quantity DECIMAL(15,3) NOT NULL,
			pharmacist_user_id BIGINT UNSIGNED NOT NULL,
			witness_user_id BIGINT UNSIGNED NOT NULL,
			notes VARCHAR(500) NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'completed',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY tenant_reference (tenant_id, register_reference),
			KEY tenant_prescription (tenant_id, prescription_id),
			KEY tenant_created (tenant_id, created_at),
			KEY tenant_status (tenant_id, status)
		) {$collate};";

		foreach ( $sql as $statement ) {
			dbDelta( \PharmaSure\Core\DatabaseMigrations::normalize_dbdelta_sql( $statement ) );
		}
		$item_table = $prefix . 'prescription_items';
		$item_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$item_table}", 0 );
		if ( in_array( 'tenant_id', $item_columns, true ) && in_array( 'branch_id', $item_columns, true ) ) {
			$wpdb->query( "UPDATE {$item_table} i JOIN {$prefix}prescriptions p ON p.id=i.prescription_id SET i.tenant_id=p.tenant_id,i.branch_id=p.branch_id,i.created_at=COALESCE(i.created_at,p.created_at) WHERE i.tenant_id=0 OR i.branch_id=0 OR i.created_at IS NULL" );
			$wpdb->query( "ALTER TABLE {$item_table} MODIFY tenant_id BIGINT UNSIGNED NOT NULL,MODIFY branch_id BIGINT UNSIGNED NOT NULL,MODIFY created_at DATETIME NOT NULL" );
		}
		update_site_option( 'pharmasure_clinical_db_version', DB_VERSION );
	}
}
