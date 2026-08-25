<?php
namespace PharmaSure\Claims;

final class Installer {
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$p = $wpdb->prefix . 'ps_'; $c = $wpdb->get_charset_collate();
		$sql = array(
			"CREATE TABLE {$p}insurers (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,name VARCHAR(200) NOT NULL,code VARCHAR(50) NOT NULL,
				payer_type VARCHAR(30) NOT NULL DEFAULT 'medical_aid',submission_mode VARCHAR(20) NOT NULL DEFAULT 'manual',adapter_key VARCHAR(100) NULL,
				contact_email VARCHAR(255) NULL,contact_phone VARCHAR(50) NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL,updated_at DATETIME NULL,
				PRIMARY KEY  (id),UNIQUE KEY tenant_code (tenant_id,code),KEY tenant_status (tenant_id,status)
			) $c",
			"CREATE TABLE {$p}insurer_schemes (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,insurer_id BIGINT UNSIGNED NOT NULL,name VARCHAR(200) NOT NULL,code VARCHAR(50) NOT NULL,
				copay_bps INT UNSIGNED NOT NULL DEFAULT 0,annual_limit_minor BIGINT UNSIGNED NULL,authorization_required TINYINT(1) NOT NULL DEFAULT 0,status VARCHAR(20) NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL,updated_at DATETIME NULL,
				PRIMARY KEY  (id),UNIQUE KEY insurer_code (tenant_id,insurer_id,code),KEY tenant_status (tenant_id,status)
			) $c",
			"CREATE TABLE {$p}patient_covers (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,patient_id BIGINT UNSIGNED NOT NULL,insurer_id BIGINT UNSIGNED NOT NULL,scheme_id BIGINT UNSIGNED NOT NULL,
				member_number VARCHAR(100) NOT NULL,dependent_code VARCHAR(50) NULL,valid_from DATE NOT NULL,valid_to DATE NULL,authorization_number VARCHAR(100) NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',created_at DATETIME NOT NULL,updated_at DATETIME NULL,
				PRIMARY KEY  (id),UNIQUE KEY member_cover (tenant_id,insurer_id,member_number,dependent_code),KEY patient_status (tenant_id,patient_id,status)
			) $c",
			"CREATE TABLE {$p}claims (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,branch_id BIGINT UNSIGNED NOT NULL,patient_id BIGINT UNSIGNED NOT NULL,patient_cover_id BIGINT UNSIGNED NOT NULL,
				insurer_id BIGINT UNSIGNED NOT NULL,scheme_id BIGINT UNSIGNED NOT NULL,sale_id BIGINT UNSIGNED NOT NULL,prescription_id BIGINT UNSIGNED NULL,claim_number VARCHAR(100) NOT NULL,payer_reference VARCHAR(150) NULL,
				service_date DATE NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'draft',claimed_amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,approved_amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
				rejected_amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,paid_amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,writeoff_amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,authorization_number VARCHAR(100) NULL,
				rejection_code VARCHAR(50) NULL,rejection_reason VARCHAR(500) NULL,submission_mode VARCHAR(20) NOT NULL DEFAULT 'manual',idempotency_key VARCHAR(100) NOT NULL,submitted_at DATETIME NULL,created_by BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL,updated_at DATETIME NULL,
				PRIMARY KEY  (id),UNIQUE KEY tenant_claim_number (tenant_id,claim_number),UNIQUE KEY tenant_idempotency (tenant_id,idempotency_key),UNIQUE KEY sale_claim (tenant_id,sale_id),KEY branch_status (tenant_id,branch_id,status),KEY payer_reference (tenant_id,insurer_id,payer_reference)
			) $c",
			"CREATE TABLE {$p}claim_items (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,claim_id BIGINT UNSIGNED NOT NULL,sale_item_id BIGINT UNSIGNED NOT NULL,drug_id BIGINT UNSIGNED NOT NULL,description VARCHAR(255) NOT NULL,
				quantity DECIMAL(18,3) NOT NULL,unit_price_minor BIGINT UNSIGNED NOT NULL,claimed_amount_minor BIGINT UNSIGNED NOT NULL,approved_amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,paid_amount_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),UNIQUE KEY claim_sale_item (claim_id,sale_item_id),KEY tenant_drug (tenant_id,drug_id)
			) $c",
			"CREATE TABLE {$p}claim_events (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,claim_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(50) NOT NULL,from_status VARCHAR(30) NULL,to_status VARCHAR(30) NULL,reason VARCHAR(500) NULL,payload LONGTEXT NULL,actor_id BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),KEY claim_history (tenant_id,claim_id,id)
			) $c",
			"CREATE TABLE {$p}remittances (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,insurer_id BIGINT UNSIGNED NOT NULL,reference VARCHAR(150) NOT NULL,received_date DATE NOT NULL,total_paid_minor BIGINT UNSIGNED NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'reconciled',idempotency_key VARCHAR(100) NOT NULL,created_by BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),UNIQUE KEY tenant_reference (tenant_id,insurer_id,reference),UNIQUE KEY tenant_idempotency (tenant_id,idempotency_key)
			) $c",
			"CREATE TABLE {$p}remittance_items (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,remittance_id BIGINT UNSIGNED NOT NULL,claim_id BIGINT UNSIGNED NOT NULL,payer_claim_reference VARCHAR(150) NULL,paid_amount_minor BIGINT UNSIGNED NOT NULL,adjustment_amount_minor BIGINT NOT NULL DEFAULT 0,rejection_code VARCHAR(50) NULL,rejection_reason VARCHAR(500) NULL,
				PRIMARY KEY  (id),UNIQUE KEY remittance_claim (remittance_id,claim_id),KEY tenant_claim (tenant_id,claim_id)
			) $c",
		);
		foreach ( $sql as $statement ) { dbDelta( \PharmaSure\Core\DatabaseMigrations::normalize_dbdelta_sql( $statement ) ); }
		update_site_option( 'pharmasure_claims_db_version', DB_VERSION );
	}
}
