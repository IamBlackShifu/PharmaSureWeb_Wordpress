<?php
namespace PharmaSure\PrintModule;

final class Installer {
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$p = $wpdb->prefix . 'ps_';
		$c = $wpdb->get_charset_collate();
		dbDelta( [
			"CREATE TABLE {$p}printer_profiles (
			 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			 tenant_id BIGINT UNSIGNED NOT NULL,
			 branch_id BIGINT UNSIGNED NULL,
			 name VARCHAR(150) NOT NULL,
			 adapter VARCHAR(30) NOT NULL DEFAULT 'browser',
			 paper_size VARCHAR(30) NOT NULL DEFAULT 'a4',
			 settings LONGTEXT NULL,
			 is_default TINYINT(1) NOT NULL DEFAULT 0,
			 is_active TINYINT(1) NOT NULL DEFAULT 1,
			 created_at DATETIME NOT NULL,
			 updated_at DATETIME NULL,
			 PRIMARY KEY  (id),
			 KEY tenant_branch (tenant_id, branch_id),
			 KEY active (is_active)
			) {$c};",
			"CREATE TABLE {$p}print_templates (
			 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			 tenant_id BIGINT UNSIGNED NULL,
			 document_type VARCHAR(60) NOT NULL,
			 name VARCHAR(150) NOT NULL,
			 template_html LONGTEXT NOT NULL,
			 version INT UNSIGNED NOT NULL DEFAULT 1,
			 is_default TINYINT(1) NOT NULL DEFAULT 0,
			 is_active TINYINT(1) NOT NULL DEFAULT 1,
			 created_at DATETIME NOT NULL,
			 updated_at DATETIME NULL,
			 PRIMARY KEY  (id),
			 KEY tenant_document (tenant_id, document_type),
			 KEY active (is_active)
			) {$c};",
			"CREATE TABLE {$p}print_jobs (
			 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			 tenant_id BIGINT UNSIGNED NOT NULL,
			 branch_id BIGINT UNSIGNED NULL,
			 document_type VARCHAR(60) NOT NULL,
			 entity_id BIGINT UNSIGNED NOT NULL,
			 adapter VARCHAR(30) NOT NULL DEFAULT 'browser',
			 status VARCHAR(30) NOT NULL DEFAULT 'queued',
			 requested_by BIGINT UNSIGNED NOT NULL,
			 correlation_id VARCHAR(100) NULL,
			 is_reprint TINYINT(1) NOT NULL DEFAULT 0,
			 attempts INT UNSIGNED NOT NULL DEFAULT 0,
			 error_message TEXT NULL,
			 created_at DATETIME NOT NULL,
			 completed_at DATETIME NULL,
			 PRIMARY KEY  (id),
			 KEY tenant_status (tenant_id, status),
			 KEY entity_lookup (tenant_id, document_type, entity_id),
			 KEY created_at (created_at)
			) {$c};"
		] );
		update_site_option( 'pharmasure_print_db_version', DB_VERSION );
	}
}
