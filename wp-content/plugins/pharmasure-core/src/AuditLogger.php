<?php
/**
 * Canonical persistence and redaction for PharmaSure audit events.
 *
 * @package PharmaSure_Core
 */

namespace PharmaSure\Core;

final class AuditLogger {
    private const REDACTED = '[REDACTED]';

    /** Register the shared audit event consumer. */
    public static function register() {
        add_action( 'pharmasure_audit_log', [ static::class, 'log' ], 10, 1 );
    }

    /**
     * Persist a normalized, append-only audit event.
     *
     * Audit failures are reported through WordPress logging and an action so
     * they do not roll back an already-committed pharmacy transaction.
     *
     * @param array $event Event emitted by a PharmaSure module.
     * @return int|false Inserted event ID, or false on failure.
     */
    public static function log( $event ) {
        global $wpdb;

        if ( ! is_array( $event ) ) {
            static::report_failure( 'Audit event must be an array.', [] );
            return false;
        }

        $event_type = static::normalize_event_type( $event['event_type'] ?? $event['action'] ?? '' );
        if ( '' === $event_type ) {
            static::report_failure( 'Audit event type is required.', $event );
            return false;
        }

        $context        = TenantContext::instance();
        $correlation_id = sanitize_text_field( $event['correlation_id'] ?? $context->get_correlation_id() );
        $status         = 'failure' === ( $event['status'] ?? 'success' ) ? 'failure' : 'success';
        $action         = sanitize_key( $event['action_name'] ?? static::action_from_type( $event_type ) );
        $user_agent     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        $ip_address     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

        $data = [
            'correlation_id' => substr( $correlation_id, 0, 100 ),
            'tenant_id'      => static::nullable_id( $event['tenant_id'] ?? $context->get_tenant_id() ),
            'actor_id'       => static::nullable_id( $event['actor_id'] ?? get_current_user_id() ),
            'event_type'     => substr( $event_type, 0, 100 ),
            'entity_type'    => substr( sanitize_key( $event['entity_type'] ?? $event['object_type'] ?? '' ), 0, 100 ),
            'entity_id'      => static::nullable_id( $event['entity_id'] ?? $event['object_id'] ?? null ),
            'action'         => substr( $action, 0, 50 ),
            'status'         => $status,
            'details'        => static::encode( $event['details'] ?? [] ),
            'before_state'   => static::encode( $event['before_state'] ?? [] ),
            'after_state'    => static::encode( $event['after_state'] ?? [] ),
            'ip_address'     => substr( $ip_address, 0, 45 ),
            'user_agent'     => substr( $user_agent, 0, 500 ),
            'created_at'     => current_time( 'mysql', true ),
        ];

        $inserted = $wpdb->insert( $wpdb->prefix . PHARMASURE_TABLE_PREFIX . 'audit_events', $data );
        if ( false === $inserted ) {
            static::report_failure( 'Audit event could not be persisted: ' . $wpdb->last_error, $event );
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    private static function action_from_type( $event_type ) {
		if ( str_contains( $event_type, '.' ) ) {
			return (string) explode( '.', $event_type, 2 )[1];
		}
        $parts = preg_split( '/[._-]+/', $event_type );
        return (string) end( $parts );
    }

    private static function normalize_event_type( $value ) {
        $value = strtolower( sanitize_text_field( (string) $value ) );
        return preg_replace( '/[^a-z0-9._-]/', '', $value );
    }

    private static function nullable_id( $value ) {
        $value = (int) $value;
        return $value > 0 ? $value : null;
    }

    private static function encode( $value ) {
        $encoded = wp_json_encode( static::redact( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return false === $encoded ? '{}' : $encoded;
    }

    private static function redact( $value, $key = '' ) {
        $sensitive = apply_filters( 'pharmasure_audit_sensitive_keys', [
            'password', 'passwd', 'passphrase', 'secret', 'client_secret', 'api_key',
            'access_token', 'refresh_token', 'offline_token', 'authorization', 'cookie',
            'private_key', 'card_number', 'cvv', 'pin',
        ] );

        if ( in_array( strtolower( (string) $key ), $sensitive, true ) ) {
            return self::REDACTED;
        }

        if ( is_array( $value ) ) {
            $clean = [];
            foreach ( $value as $child_key => $child_value ) {
                $clean[ $child_key ] = static::redact( $child_value, $child_key );
            }
            return $clean;
        }

        if ( is_object( $value ) ) {
            return static::redact( get_object_vars( $value ), $key );
        }

        return is_scalar( $value ) || null === $value ? $value : (string) $value;
    }

    private static function report_failure( $message, $event ) {
        error_log( 'PharmaSure audit failure: ' . $message );
        do_action( 'pharmasure_audit_failure', $message, $event );
    }
}
