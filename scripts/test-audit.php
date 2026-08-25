<?php
/** Canonical audit persistence integration test. Run with wp eval-file. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

global $wpdb;
$table = $wpdb->prefix . 'ps_audit_events';
$marker = 'audit-test-' . wp_generate_uuid4();
$pass = 0;
$fail = 0;
$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) {
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$condition ? ++$pass : ++$fail;
};

do_action( 'pharmasure_audit_log', array(
	'event_type'    => 'security.audit_test',
	'correlation_id'=> $marker,
	'tenant_id'     => 999999,
	'actor_id'      => 1,
	'object_type'   => 'test_record',
	'object_id'     => 42,
	'details'       => array( 'safe' => 'visible', 'password' => 'never-store-me', 'nested' => array( 'api_key' => 'secret' ) ),
) );

$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE correlation_id=%s", $marker ), ARRAY_A );
$details = $row ? json_decode( $row['details'], true ) : array();
$assert( ! empty( $row ), 'shared hook persists an audit event' );
$assert( 'security.audit_test' === ( $row['event_type'] ?? '' ), 'event type is normalized consistently' );
$assert( 'audit_test' === ( $row['action'] ?? '' ), 'action is derived from the event type' );
$assert( 'test_record' === ( $row['entity_type'] ?? '' ) && 42 === (int) ( $row['entity_id'] ?? 0 ), 'legacy object keys map to canonical entity columns' );
$assert( 'visible' === ( $details['safe'] ?? '' ), 'non-sensitive audit detail is retained' );
$assert( '[REDACTED]' === ( $details['password'] ?? '' ), 'password is redacted' );
$assert( '[REDACTED]' === ( $details['nested']['api_key'] ?? '' ), 'nested secrets are redacted' );

if ( $row ) { $wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) ); }
echo "Audit tests: {$pass} passed, {$fail} failed.\n";
if ( $fail ) { throw new RuntimeException( 'Audit tests failed.' ); }
