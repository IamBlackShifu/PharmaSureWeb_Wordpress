<?php
namespace PharmaSure\Offline;

final class Installer {
	public static function install( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_current_site();
				restore_current_blog();
			}
			return;
		}
		self::install_current_site();
	}

	public static function install_for_site( $site ) {
		$site_id = is_object( $site ) ? (int) $site->blog_id : (int) $site;
		if ( ! $site_id ) {
			return;
		}
		switch_to_blog( $site_id );
		self::install_current_site();
		restore_current_blog();
	}

	public static function is_current_site_installed() {
		global $wpdb;
		$table = $wpdb->prefix . 'ps_offline_devices';
		return DB_VERSION === get_option( 'pharmasure_offline_db_version' ) && $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private static function install_current_site() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$prefix = $wpdb->prefix . 'ps_';
		$collation = $wpdb->get_charset_collate();

		dbDelta( \PharmaSure\Core\DatabaseMigrations::normalize_dbdelta_sql( "CREATE TABLE {$prefix}offline_devices (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			branch_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			client_id VARCHAR(100) NOT NULL,
			device_name VARCHAR(150) NOT NULL,
			encrypted_secret LONGTEXT NOT NULL,
			secret_fingerprint CHAR(16) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			last_seen_at DATETIME NULL,
			expires_at DATETIME NOT NULL,
			revoked_at DATETIME NULL,
			revoked_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY client_id (client_id),
			KEY tenant_branch_status (tenant_id,branch_id,status),
			KEY user_status (tenant_id,user_id,status)
		) $collation" ) );

		dbDelta( \PharmaSure\Core\DatabaseMigrations::normalize_dbdelta_sql( "CREATE TABLE {$prefix}offline_nonces (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			device_id BIGINT UNSIGNED NOT NULL,
			nonce VARCHAR(100) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY device_nonce (device_id,nonce),
			KEY created_at (created_at)
		) $collation" ) );

		dbDelta( \PharmaSure\Core\DatabaseMigrations::normalize_dbdelta_sql( "CREATE TABLE {$prefix}offline_mutations (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			branch_id BIGINT UNSIGNED NOT NULL,
			device_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			client_mutation_id VARCHAR(100) NOT NULL,
			mutation_type VARCHAR(50) NOT NULL,
			base_version VARCHAR(100) NULL,
			payload LONGTEXT NOT NULL,
			payload_hash CHAR(64) NOT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'received',
			attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
			next_attempt_at DATETIME NULL,
			conflict_code VARCHAR(100) NULL,
			conflict_details TEXT NULL,
			server_reference_type VARCHAR(50) NULL,
			server_reference_id BIGINT UNSIGNED NULL,
			received_at DATETIME NOT NULL,
			processed_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY device_mutation (tenant_id,device_id,client_mutation_id),
			KEY branch_status (tenant_id,branch_id,status),
			KEY tenant_created (tenant_id,received_at),
			KEY due (status,next_attempt_at)
		) $collation" ) );

		update_option( 'pharmasure_offline_db_version', DB_VERSION, false );
	}
}
