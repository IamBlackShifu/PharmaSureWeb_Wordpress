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
			status VARCHAR(30) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY tenant_patient_number (tenant_id, patient_number),
			KEY tenant_branch (tenant_id, branch_id),
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
			approved_at DATETIME NULL,
			approved_by_user BIGINT UNSIGNED NULL,
			dispensed_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY (id),
			KEY tenant_status (tenant_id, status),
			KEY tenant_patient (tenant_id, patient_id),
			KEY branch_date (branch_id, prescription_date)
		) {$collate};";

		$sql[] = "CREATE TABLE {$prefix}prescription_items (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
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
			PRIMARY KEY (id),
			KEY prescription_id (prescription_id),
			KEY drug_id (drug_id)
		) {$collate};";

		$sql[] = "CREATE TABLE {$prefix}sales (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			branch_id BIGINT UNSIGNED NOT NULL,
			patient_id BIGINT UNSIGNED NULL,
			prescription_id BIGINT UNSIGNED NULL,
			total_amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(30) NOT NULL DEFAULT 'completed',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY prescription_sale (tenant_id, prescription_id),
			KEY tenant_branch (tenant_id, branch_id),
			KEY tenant_patient (tenant_id, patient_id)
		) {$collate};";

		dbDelta( $sql );
		update_site_option( 'pharmasure_clinical_db_version', DB_VERSION );
	}
}
