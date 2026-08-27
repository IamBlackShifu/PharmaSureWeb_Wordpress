<?php
namespace PharmaSure\Offline\Services;

use PharmaSure\Core\LicenseManager;

final class MutationService {
	private const TYPES = array( 'pos.checkout', 'prescription.dispense', 'stock.receive', 'payment.capture' );
	private $db;
	private $p;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
		$this->p = $wpdb->prefix . 'ps_';
	}

	public function receive( array $device, array $data ) {
		$client = sanitize_text_field( $data['client_mutation_id'] ?? '' );
		$type = str_replace( '_', '.', sanitize_key( str_replace( '.', '_', $data['mutation_type'] ?? '' ) ) );
		$payload = (array) ( $data['payload'] ?? array() );
		if ( ! $client || ! in_array( $type, self::TYPES, true ) || ! $payload ) {
			return new \WP_Error( 'invalid_mutation', 'Client mutation ID, supported type and payload are required.', array( 'status' => 422 ) );
		}

		$canonical = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$hash = hash( 'sha256', $canonical );
		$existing = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->p}offline_mutations WHERE tenant_id=%d AND device_id=%d AND client_mutation_id=%s", $device['tenant_id'], $device['id'], $client ), ARRAY_A );
		if ( $existing ) {
			if ( $existing['payload_hash'] !== $hash || $existing['mutation_type'] !== $type ) {
				return new \WP_Error( 'mutation_id_conflict', 'Client mutation ID was reused with different content.', array( 'status' => 409 ) );
			}
			$existing['idempotent_replay'] = true;
			return $existing;
		}

		$license = ( new LicenseManager( (int) $device['tenant_id'] ) )->enforce_entitlement( 'offline' );
		if ( is_wp_error( $license ) ) {
			return $license;
		}
		$limit = (int) ( $license['quotas']['offline_mutations_monthly'] ?? 0 );
		if ( $limit > 0 ) {
			$used = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->p}offline_mutations WHERE tenant_id=%d AND received_at>=UTC_DATE()-INTERVAL (DAY(UTC_DATE())-1) DAY", $device['tenant_id'] ) );
			if ( $used >= $limit ) {
				return new \WP_Error( 'offline_mutation_quota_exceeded', 'The monthly offline-mutation quota has been reached.', array( 'status' => 429 ) );
			}
		}

		$this->db->insert(
			$this->p . 'offline_mutations',
			array(
				'tenant_id'        => (int) $device['tenant_id'],
				'branch_id'        => (int) $device['branch_id'],
				'device_id'        => (int) $device['id'],
				'user_id'          => (int) $device['user_id'],
				'client_mutation_id'=> $client,
				'mutation_type'    => $type,
				'base_version'     => sanitize_text_field( $data['base_version'] ?? '' ) ?: null,
				'payload'          => $canonical,
				'payload_hash'     => $hash,
				'status'           => 'requires_online_replay',
				'conflict_code'    => 'authoritative_transaction_required',
				'conflict_details' => 'Stock, dispensing, sales and payments must be replayed through the authoritative online service before application.',
				'received_at'      => current_time( 'mysql', true ),
			)
		);
		if ( ! $this->db->insert_id ) {
			return new \WP_Error( 'mutation_not_received', 'Offline mutation could not be stored.', array( 'status' => 500 ) );
		}
		$id = (int) $this->db->insert_id;
		do_action( 'pharmasure_audit_log', array( 'tenant_id' => (int) $device['tenant_id'], 'actor_id' => (int) $device['user_id'], 'action' => 'offline.mutation_received', 'object_type' => 'offline_mutation', 'object_id' => $id, 'details' => array( 'branch_id' => (int) $device['branch_id'], 'mutation_type' => $type, 'device_id' => (int) $device['id'] ) ) );
		return array( 'id' => $id, 'status' => 'requires_online_replay', 'conflict_code' => 'authoritative_transaction_required' );
	}
}
