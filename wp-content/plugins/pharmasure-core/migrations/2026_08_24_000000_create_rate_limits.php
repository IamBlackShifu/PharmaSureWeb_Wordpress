<?php
namespace PharmaSure\Migrations;

final class CreateRateLimits {
	public function up() {
		global $wpdb; $table=$wpdb->prefix.PHARMASURE_TABLE_PREFIX.'rate_limits';$charset=$wpdb->get_charset_collate();
		require_once ABSPATH.'wp-admin/includes/upgrade.php';
		dbDelta("CREATE TABLE $table (bucket_hash CHAR(64) NOT NULL,window_start BIGINT UNSIGNED NOT NULL,request_count INT UNSIGNED NOT NULL DEFAULT 1,expires_at DATETIME NOT NULL,PRIMARY KEY  (bucket_hash,window_start),KEY expires_at (expires_at)) $charset");
	}
}
