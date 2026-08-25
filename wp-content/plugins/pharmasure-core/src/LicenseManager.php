<?php
/**
 * License Manager - Robust, cryptographically-signed license validation
 * 
 * This implements:
 * - Server-side authoritative validation
 * - Asymmetric cryptographic signing
 * - Anti-tampering checks
 * - Feature/quota enforcement
 * - Grace period and expiry policies
 * 
 * @package PharmaSure_Core
 */

namespace PharmaSure\Core;

class LicenseManager {
    private $tenant_id;
    private $signing_key_id = 'pharmasure-v1';
    private $cache_ttl = 3600; // 1 hour server-side cache
    
    public function __construct( $tenant_id ) {
        $this->tenant_id = (int) $tenant_id;
    }

    /**
     * Validate license and return feature entitlements
     * 
     * For offline clients, returns signed token. For online, validates against DB.
     * 
     * @param string|null $offline_token Optional offline license token
     * @return array License data or WP_Error
     */
    public function validate_license( $offline_token = null ) {
        // An explicitly supplied token must be validated before consulting the
        // online licence cache. Explicit tokens fail closed: a bad signature,
        // tenant mismatch, revoked token, or invalid claim must never be masked
        // by an otherwise valid database licence.
        if ( $offline_token ) {
            return $this->validate_offline_token( $offline_token );
        }

        // Check server-side cache for normal online validation.
        $cached = $this->get_cached_license();
        if ( ! empty( $cached ) ) {
            return $cached;
        }

        // Default: validate from authoritative database
        return $this->validate_from_database();
    }

    /**
     * Validate license from authoritative database source
     * 
     * @return array|WP_Error
     */
    private function validate_from_database() {
        global $wpdb;
        $table = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'licences';
        
        $license = $wpdb->get_row( $wpdb->prepare(
            "SELECT l.*, p.name as plan_name, p.plan_tier 
             FROM {$table} l
             LEFT JOIN {$wpdb->prefix}" . PHARMASURE_TABLE_PREFIX . "plans p ON l.plan_id = p.id
             WHERE l.tenant_id = %d 
             AND l.status IN ('active', 'trial', 'grace')
             AND (l.expires_at IS NULL OR l.expires_at > NOW())
             ORDER BY l.created_at DESC 
             LIMIT 1",
            $this->tenant_id
        ), ARRAY_A );

        if ( ! $license ) {
            return new \WP_Error( 'license_not_found', 'No active license found for tenant' );
        }

        // Check if expired or grace period
        $status = $this->determine_license_status( $license );
        $license['status'] = $status;

        // Load entitlements
        $license['entitlements'] = $this->get_license_entitlements( $license['id'] );
        $license['quotas'] = $this->get_license_quotas( $license['id'] );

        // Cache for this request's tenant context
        $this->cache_license( $license );

        return $license;
    }

    /**
     * Validate cryptographically-signed offline token
     * 
     * Offline tokens are JWE (JSON Web Encryption) with:
     * - tenant_id
     * - device_id
     * - entitlements
     * - issued_at
     * - expires_at
     * - key_id (for key rotation)
     * 
     * @param string $token
     * @return array|WP_Error
     */
    private function validate_offline_token( $token ) {
        try {
            // Parse JWT structure
            $parts = explode( '.', $token );
            if ( count( $parts ) !== 3 ) {
                return new \WP_Error( 'invalid_token_format', 'Invalid token format' );
            }

            // Decode header
            $header = json_decode( base64_decode( strtr( $parts[0], '-_', '+/' ) ), true );
            if ( ! isset( $header['kid'] ) || $header['kid'] !== $this->signing_key_id ) {
                return new \WP_Error( 'invalid_key_id', 'Token signed with unknown key' );
            }

            // Decode payload
            $payload = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
            
            // Verify tenant matches
            if ( (int) $payload['tenant_id'] !== $this->tenant_id ) {
                return new \WP_Error( 'tenant_mismatch', 'Token tenant does not match context' );
            }

            // Verify timestamp
            $now = time();
            if ( $payload['issued_at'] > $now ) {
                return new \WP_Error( 'token_not_yet_valid', 'Token issued in future' );
            }
            if ( $payload['expires_at'] < $now ) {
                return new \WP_Error( 'token_expired', 'Offline license token expired' );
            }

            // Verify signature
            $public_key = $this->get_public_key( $header['kid'] );
            if ( ! $public_key ) {
                return new \WP_Error( 'key_not_found', 'Public key not found' );
            }

            $signature_valid = $this->verify_signature(
                "{$parts[0]}.{$parts[1]}",
                base64_decode( strtr( $parts[2], '-_', '+/' ) ),
                $public_key
            );

            if ( ! $signature_valid ) {
                return new \WP_Error( 'invalid_signature', 'Token signature verification failed' );
            }

            // Validate against revocation list
            if ( $this->is_token_revoked( $token ) ) {
                return new \WP_Error( 'token_revoked', 'Token has been revoked' );
            }

            return [
                'id' => $payload['license_id'],
                'tenant_id' => $payload['tenant_id'],
                'device_id' => $payload['device_id'] ?? null,
                'status' => 'offline',
                'entitlements' => $payload['entitlements'] ?? [],
                'quotas' => $payload['quotas'] ?? [],
                'offline_grace_until' => $payload['offline_grace_until'] ?? null,
                'source' => 'offline_token',
            ];
        } catch ( \Exception $e ) {
            return new \WP_Error( 'token_validation_error', $e->getMessage() );
        }
    }

    /**
     * Determine license status (active, grace, suspended, expired)
     * 
     * @param array $license
     * @return string
     */
    private function determine_license_status( $license ) {
        if ( $license['status'] === 'suspended' ) {
            return 'suspended';
        }
        
        if ( $license['status'] === 'revoked' ) {
            return 'revoked';
        }

        $now = new \DateTime( 'UTC' );
        
        if ( $license['expires_at'] ) {
            $expires = new \DateTime( $license['expires_at'] );
            $grace_days = (int) ( $license['grace_period_days'] ?? 14 );
            $grace_until = ( clone $expires )->modify( "+{$grace_days} days" );
            
            if ( $now > $grace_until ) {
                return 'expired';
            } elseif ( $now > $expires ) {
                return 'grace';
            }
        }

        if ( $license['status'] === 'past_due' ) {
            return 'past_due';
        }

        return $license['status'] === 'trial' ? 'trial' : 'active';
    }

	/**
	 * Fail closed unless the tenant has a valid licence and entitlement.
	 *
	 * @return array|\WP_Error Validated licence or authorization error.
	 */
	public function enforce_entitlement( $entitlement_key, $offline_token = null ) {
		$entitlement_key = sanitize_key( $entitlement_key );
		$license = $this->validate_license( $offline_token );
		if ( is_wp_error( $license ) ) {
			return $license;
		}
		if ( '' === $entitlement_key || ! in_array( $entitlement_key, $license['entitlements'] ?? array(), true ) ) {
			do_action( 'pharmasure_audit_log', array( 'tenant_id' => $this->tenant_id, 'action' => 'licence.entitlement_denied', 'object_type' => 'entitlement', 'status' => 'failure', 'details' => array( 'entitlement' => $entitlement_key, 'licence_id' => (int) ( $license['id'] ?? 0 ) ) ) );
			return new \WP_Error( 'entitlement_required', 'The current licence does not include this feature.', array( 'status' => 403 ) );
		}
		return $license;
	}

    /**
     * Get license entitlements from database
     * 
     * @param int $license_id
     * @return array
     */
    private function get_license_entitlements( $license_id ) {
        global $wpdb;
        $table = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'licence_entitlements';
        
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT entitlement_key FROM $table WHERE licence_id = %d AND is_active = 1",
            $license_id
        ) );
    }

    /**
     * Get license quotas from database
     * 
     * @param int $license_id
     * @return array
     */
    private function get_license_quotas( $license_id ) {
        global $wpdb;
        $table = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'licence_quotas';
        
        $quotas = $wpdb->get_results( $wpdb->prepare(
            "SELECT quota_key, limit_value FROM $table WHERE licence_id = %d",
            $license_id
        ) );

        $result = [];
        foreach ( $quotas as $quota ) {
            $result[ $quota->quota_key ] = (int) $quota->limit_value;
        }
        return $result;
    }

    /**
     * Get cached license validation
     * 
     * @return array|false
     */
    private function get_cached_license() {
        $cache_key = "pharmasure_license_{$this->tenant_id}";
        $cached = wp_cache_get( $cache_key );
        
        if ( $cached ) {
            // Verify not expired
            if ( isset( $cached['cache_expires_at'] ) && time() < $cached['cache_expires_at'] ) {
                return $cached;
            }
            wp_cache_delete( $cache_key );
        }

        return false;
    }

    /**
     * Cache license validation result
     * 
     * @param array $license
     * @return void
     */
    private function cache_license( $license ) {
        $cache_key = "pharmasure_license_{$this->tenant_id}";
        $license['cache_expires_at'] = time() + $this->cache_ttl;
        wp_cache_set( $cache_key, $license, '', $this->cache_ttl );
    }

    /**
     * Verify cryptographic signature
     * 
     * @param string $data
     * @param string $signature
     * @param string $public_key
     * @return bool
     */
    private function verify_signature( $data, $signature, $public_key ) {
        return openssl_verify( $data, $signature, $public_key, OPENSSL_ALGO_SHA256 ) === 1;
    }

    /**
     * Get public key for token verification
     * 
     * @param string $key_id
     * @return string|false
     */
    private function get_public_key( $key_id ) {
        global $wpdb;
        $table = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'licence_signing_keys';
        
        $key_data = $wpdb->get_row( $wpdb->prepare(
            "SELECT public_key FROM $table WHERE key_id = %s AND is_active = 1",
            $key_id
        ) );

        return $key_data ? $key_data->public_key : false;
    }

    /**
     * Check if token is in revocation list
     * 
     * @param string $token
     * @return bool
     */
    private function is_token_revoked( $token ) {
        global $wpdb;
        $table = $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'token_revocation_list';
        
        $token_hash = hash( 'sha256', $token );
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE token_hash = %s",
            $token_hash
        ) );

        return ! empty( $exists );
    }

    /**
     * Issue offline license token for client device
     * 
     * For PWA/offline support. Returns a cryptographically-signed JWT.
     * 
     * @param int $device_id
     * @param int $valid_days
     * @return string|WP_Error
     */
    public function issue_offline_token( $device_id, $valid_days = 30 ) {
        $license = $this->validate_license();
        if ( is_wp_error( $license ) ) {
            return $license;
        }

        $now = time();
        $expires = $now + ( $valid_days * 86400 );

        $payload = [
            'license_id' => $license['id'],
            'tenant_id' => $this->tenant_id,
            'device_id' => $device_id,
            'entitlements' => $license['entitlements'],
            'quotas' => $license['quotas'],
            'issued_at' => $now,
            'expires_at' => $expires,
            'offline_grace_until' => $now + ( 7 * 86400 ), // 7-day offline grace
        ];

        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => $this->signing_key_id,
        ];

        try {
            $token = $this->create_jwt( $header, $payload );
            
            // Log token issuance for audit
            $this->log_token_issuance( $device_id, $token );
            
            return $token;
        } catch ( \Exception $e ) {
            return new \WP_Error( 'token_issuance_failed', $e->getMessage() );
        }
    }

    /**
     * Create signed JWT token
     * 
     * @param array $header
     * @param array $payload
     * @return string
     */
    private function create_jwt( $header, $payload ) {
        $header_encoded = rtrim( strtr( base64_encode( json_encode( $header ) ), '+/', '-_' ), '=' );
        $payload_encoded = rtrim( strtr( base64_encode( json_encode( $payload ) ), '+/', '-_' ), '=' );
        $data_to_sign = "{$header_encoded}.{$payload_encoded}";

        $private_key = $this->get_private_key();
        if ( ! openssl_sign( $data_to_sign, $signature, $private_key, OPENSSL_ALGO_SHA256 ) ) {
            throw new \RuntimeException( 'Unable to sign offline license token' );
        }
        
        $signature_encoded = rtrim( strtr( base64_encode( $signature ), '+/', '-_' ), '=' );
        
        return "{$data_to_sign}.{$signature_encoded}";
    }

    /**
     * Get private key for token signing (server-only)
     * 
     * @return resource|string
     */
    private function get_private_key() {
        $key_pem = get_option( 'pharmasure_license_signing_key_private' );
        if ( ! $key_pem ) {
            throw new \RuntimeException( 'License signing key not configured' );
        }

        $private_key = openssl_pkey_get_private( $key_pem );
        if ( ! $private_key ) {
            throw new \RuntimeException( 'License signing key is invalid' );
        }

        return $private_key;
    }

    /**
     * Log token issuance for audit trail
     * 
     * @param int $device_id
     * @param string $token
     * @return void
     */
    private function log_token_issuance( $device_id, $token ) {
        do_action( 'pharmasure_audit_log', [
            'tenant_id'  => $this->tenant_id,
            'actor_id'   => get_current_user_id(),
            'event_type' => 'license.token_issued',
            'entity_type'=> 'license_token',
            'entity_id'  => $device_id,
            'details'    => [
                'device_id'  => $device_id,
                'token_hash' => hash( 'sha256', $token ),
            ],
        ] );
    }

    /**
     * Get user IP address
     * 
     * @return string
     */
    private function get_user_ip() {
        if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            return explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}
