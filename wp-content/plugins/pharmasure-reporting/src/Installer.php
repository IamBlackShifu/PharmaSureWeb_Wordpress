<?php
namespace PharmaSure\Reporting;
final class Installer {
	public static function install() { global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php'; $p=$wpdb->prefix.'ps_'; $c=$wpdb->get_charset_collate();
		dbDelta( \PharmaSure\Core\DatabaseMigrations::normalize_dbdelta_sql( "CREATE TABLE {$p}report_schedules (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,branch_id BIGINT UNSIGNED NULL,name VARCHAR(150) NOT NULL,report_type VARCHAR(30) NOT NULL,format VARCHAR(10) NOT NULL DEFAULT 'csv',frequency VARCHAR(20) NOT NULL,recipients TEXT NOT NULL,last_run_at DATETIME NULL,next_run_at DATETIME NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',created_by BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL,updated_at DATETIME NULL,PRIMARY KEY  (id),KEY due (status,next_run_at),KEY tenant_branch (tenant_id,branch_id)) $c" ) );
		dbDelta( \PharmaSure\Core\DatabaseMigrations::normalize_dbdelta_sql( "CREATE TABLE {$p}report_runs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,tenant_id BIGINT UNSIGNED NOT NULL,schedule_id BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL,period_from DATE NOT NULL,period_to DATE NOT NULL,row_count INT UNSIGNED NOT NULL DEFAULT 0,error_message VARCHAR(500) NULL,started_at DATETIME NOT NULL,finished_at DATETIME NULL,PRIMARY KEY  (id),KEY schedule_runs (tenant_id,schedule_id,id),KEY status_started (status,started_at)) $c" ) );
		update_site_option( 'pharmasure_reporting_db_version', DB_VERSION );
	}
}
