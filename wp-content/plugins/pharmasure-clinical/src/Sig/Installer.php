<?php
namespace PharmaSure\Clinical\Sig;

final class Installer {
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$p = $wpdb->prefix . 'ps_'; $collate = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$p}sig_definitions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			code VARCHAR(30) NOT NULL,
			category VARCHAR(30) NOT NULL,
			payload LONGTEXT NOT NULL,
			language VARCHAR(10) NOT NULL DEFAULT 'en',
			status VARCHAR(30) NOT NULL DEFAULT 'draft',
			approval_status VARCHAR(30) NOT NULL DEFAULT 'pending',
			version INT UNSIGNED NOT NULL DEFAULT 1,
			created_by BIGINT UNSIGNED NOT NULL,
			approved_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			approved_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY tenant_code_version (tenant_id,code,version),
			KEY tenant_status (tenant_id,status)
		) {$collate};" );
		dbDelta( "CREATE TABLE {$p}sig_instructions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			branch_id BIGINT UNSIGNED NOT NULL,
			prescription_item_id BIGINT UNSIGNED NOT NULL,
			original_sig TEXT NOT NULL,
			original_prescription TEXT NOT NULL,
			structured LONGTEXT NOT NULL,
			generated_directions TEXT NOT NULL,
			verified_directions TEXT NOT NULL,
			calculation LONGTEXT NOT NULL,
			reconciliation_reason TEXT NULL,
			quantity_override_reason TEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			verified_by BIGINT UNSIGNED NULL,
			verified_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY tenant_item (tenant_id,prescription_item_id),
			KEY tenant_branch (tenant_id,branch_id)
		) {$collate};" );
		dbDelta( "CREATE TABLE {$p}sig_templates (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			name VARCHAR(100) NOT NULL,
			structured LONGTEXT NOT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'draft',
			created_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY tenant_owner (tenant_id,owner_id)
		) {$collate};" );
		foreach ( ['sig_definitions', 'sig_instructions', 'sig_templates'] as $table ) {
			if ( $p . $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . $table ) ) ) { return; }
		}
		update_option( 'pharmasure_sig_db_version', '1', false );
	}
}
