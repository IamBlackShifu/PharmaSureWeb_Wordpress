<?php
namespace PharmaSure\Offline\Services;

final class SecurityAlertService {
	private $db;
	private $table;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
		$this->table = $wpdb->prefix . 'ps_security_events';
	}

	public function record($tenant_id, $user_id, $event_type, $description) {
		$tenant_id = absint($tenant_id);
		$user_id = absint($user_id);
		$event_type = sanitize_key($event_type);
		$ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'system');
		$user_agent = sanitize_text_field(substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'background-worker'), 0, 255));
		if ($tenant_id) {
			$count = (int)$this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE tenant_id=%d AND event_type=%s AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)", $tenant_id, $event_type));
		} else {
			$count = (int)$this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE tenant_id IS NULL AND event_type=%s AND ip_address=%s AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)", $event_type, $ip));
		}
		$severity = $count >= 4 ? 'critical' : ($count >= 2 ? 'warning' : 'info');
		$this->db->insert($this->table, array(
			'tenant_id' => $tenant_id ?: null,
			'user_id' => $user_id ?: null,
			'event_type' => $event_type,
			'severity' => $severity,
			'description' => sanitize_text_field($description),
			'ip_address' => $ip,
			'user_agent' => $user_agent,
			'is_resolved' => 0,
			'created_at' => current_time('mysql', true),
		));
		return array('severity' => $severity, 'occurrences' => $count + 1);
	}
}
