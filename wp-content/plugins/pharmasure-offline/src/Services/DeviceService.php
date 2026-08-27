<?php
namespace PharmaSure\Offline\Services;

use PharmaSure\Core\LicenseManager;
use PharmaSure\Integrations\Services\CredentialVault;

final class DeviceService {
	private $db;
	private $p;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
		$this->p = $wpdb->prefix . 'ps_';
	}

	public function register( $tenant, $branch, $user, array $data ) {
		$name = sanitize_text_field( $data['device_name'] ?? '' );
		$days = min( 90, max( 1, (int) ( $data['expires_in_days'] ?? 30 ) ) );
		if ( ! $tenant || ! $branch || ! $user || ! $name ) {
			return new \WP_Error( 'invalid_device', 'Tenant, branch, user and device name are required.', array( 'status' => 422 ) );
		}
		$membership = (int) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}tenant_memberships WHERE tenant_id=%d AND user_id=%d AND is_active=1 LIMIT 1", (int) $tenant, (int) $user ) );
		$valid_branch = (int) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}branches WHERE tenant_id=%d AND id=%d AND is_active=1 LIMIT 1", (int) $tenant, (int) $branch ) );
		if ( ! $membership || ! $valid_branch ) {
			return new \WP_Error( 'device_scope_invalid', 'The device owner and branch must belong to the active tenant.', array( 'status' => 403 ) );
		}
		$license = ( new LicenseManager( (int) $tenant ) )->enforce_entitlement( 'offline' );
		if ( is_wp_error( $license ) ) {
			return $license;
		}
		$limit = (int) ( $license['quotas']['offline_devices'] ?? 0 );
		if ( $limit > 0 ) {
			$active = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->p}offline_devices WHERE tenant_id=%d AND status='active'", (int) $tenant ) );
			if ( $active >= $limit ) {
				return new \WP_Error( 'offline_device_limit', 'The licensed offline-device limit has been reached.', array( 'status' => 409 ) );
			}
		}

		$client = 'psd_' . bin2hex( random_bytes( 16 ) );
		$secret = bin2hex( random_bytes( 32 ) );
		$encrypted = ( new CredentialVault() )->encrypt( array( 'device_secret' => $secret ) );
		if ( is_wp_error( $encrypted ) ) {
			return $encrypted;
		}
		$expires = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * $days );
		$this->db->insert(
			$this->p . 'offline_devices',
			array(
				'tenant_id'         => (int) $tenant,
				'branch_id'         => (int) $branch,
				'user_id'           => (int) $user,
				'client_id'         => $client,
				'device_name'       => $name,
				'encrypted_secret'  => $encrypted,
				'secret_fingerprint'=> substr( hash( 'sha256', $secret ), 0, 16 ),
				'status'            => 'active',
				'expires_at'        => $expires,
				'created_at'        => current_time( 'mysql', true ),
			)
		);
		if ( ! $this->db->insert_id ) {
			return new \WP_Error( 'device_not_registered', 'Device could not be registered.', array( 'status' => 500 ) );
		}
		$id = (int) $this->db->insert_id;
		do_action( 'pharmasure_audit_log', array( 'tenant_id' => (int) $tenant, 'actor_id' => (int) $user, 'action' => 'offline.device_registered', 'object_type' => 'offline_device', 'object_id' => $id, 'details' => array( 'branch_id' => (int) $branch, 'device_name' => $name, 'expires_in_days' => $days ) ) );
		return array( 'id' => $id, 'client_id' => $client, 'client_secret' => $secret, 'expires_at' => gmdate( 'c', strtotime( $expires . ' UTC' ) ), 'warning' => 'The client secret is shown once and cannot be recovered.' );
	}

	public function revoke( $tenant, $id, $actor ) {
		$membership = (int) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->p}tenant_memberships WHERE tenant_id=%d AND user_id=%d AND is_active=1 LIMIT 1", (int) $tenant, (int) $actor ) );
		if ( ! $membership ) {
			return new \WP_Error( 'device_scope_invalid', 'The acting user does not belong to the active tenant.', array( 'status' => 403 ) );
		}
		$ok = $this->db->update( $this->p . 'offline_devices', array( 'status' => 'revoked', 'revoked_at' => current_time( 'mysql', true ), 'revoked_by' => (int) $actor ), array( 'id' => (int) $id, 'tenant_id' => (int) $tenant, 'status' => 'active' ) );
		if ( 1 !== $ok ) {
			return new \WP_Error( 'device_not_active', 'Active device not found.', array( 'status' => 404 ) );
		}
		do_action( 'pharmasure_audit_log', array( 'tenant_id' => (int) $tenant, 'actor_id' => (int) $actor, 'action' => 'offline.device_revoked', 'object_type' => 'offline_device', 'object_id' => (int) $id ) );
		return array( 'id' => (int) $id, 'status' => 'revoked' );
	}

	public function authenticate( $client, $timestamp, $nonce, $body, $signature ) {
		$device = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->p}offline_devices WHERE client_id=%s", sanitize_text_field( $client ) ), ARRAY_A );
		if ( ! $device || 'active' !== $device['status'] || strtotime( $device['expires_at'] . ' UTC' ) <= time() || abs( time() - (int) $timestamp ) > 300 || ! preg_match( '/^[A-Za-z0-9_-]{16,100}$/', (string) $nonce ) ) {
			$this->alert( $device, 'offline_device_auth_failed', 'Rejected offline device authentication.' );
			return new \WP_Error( 'device_auth_failed', 'Device, timestamp or nonce is invalid.', array( 'status' => 401 ) );
		}
		$secret = ( new CredentialVault() )->decrypt( $device['encrypted_secret'] );
		if ( is_wp_error( $secret ) ) {
			$this->alert( $device, 'offline_device_auth_failed', 'Device credential could not be verified.' );
			return new \WP_Error( 'device_auth_failed', 'Device credential could not be verified.', array( 'status' => 401 ) );
		}
		$expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . hash( 'sha256', $body ), $secret['device_secret'] );
		if ( ! hash_equals( $expected, (string) $signature ) ) {
			$this->alert( $device, 'offline_signature_failure', 'Rejected offline device signature.' );
			return new \WP_Error( 'device_auth_failed', 'Device signature is invalid.', array( 'status' => 401 ) );
		}
		$insert = $this->db->query( $this->db->prepare( "INSERT IGNORE INTO {$this->p}offline_nonces (device_id,nonce,created_at) VALUES (%d,%s,%s)", $device['id'], $nonce, current_time( 'mysql', true ) ) );
		if ( 1 !== $insert ) {
			$this->alert( $device, 'offline_replay_attempt', 'Rejected reuse of an offline request nonce.' );
			return new \WP_Error( 'device_replay', 'This signed device request was already used.', array( 'status' => 409 ) );
		}
		$this->db->update( $this->p . 'offline_devices', array( 'last_seen_at' => current_time( 'mysql', true ) ), array( 'id' => $device['id'], 'tenant_id' => $device['tenant_id'] ) );
		return $device;
	}

	private function alert( $device, $type, $description ) {
		( new SecurityAlertService() )->record( (int) ( $device['tenant_id'] ?? 0 ), (int) ( $device['user_id'] ?? 0 ), $type, $description );
	}
}
