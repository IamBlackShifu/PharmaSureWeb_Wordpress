<?php
namespace PharmaSure\Claims\Rest;

use PharmaSure\Core\LicenseManager;
use PharmaSure\Core\TenantContext;

final class ClaimsAccess {
	public static function authorize( $capability, $write = false ) {
		if ( ! current_user_can( $capability ) ) { return new \WP_Error( 'claims_forbidden', 'Your pharmacy role does not permit this claims action.', array( 'status' => 403 ) ); }
		$tenant_id = (int) TenantContext::instance()->get_tenant_id();
		if ( ! $tenant_id ) { return new \WP_Error( 'tenant_context_required', 'No authorized tenant context is active.', array( 'status' => 403 ) ); }
		$license = ( new LicenseManager( $tenant_id ) )->enforce_entitlement( 'claims' );
		if ( is_wp_error( $license ) ) { return $license; }
		$limit = (int) ( $license['quotas']['claims_writes_monthly'] ?? 0 );
		if ( $write && $limit > 0 ) {
			global $wpdb;
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ps_audit_events WHERE tenant_id=%d AND event_type LIKE %s AND created_at>=UTC_DATE()-INTERVAL (DAY(UTC_DATE())-1) DAY", $tenant_id, 'claims.%' ) );
			if ( $used >= $limit ) { return new \WP_Error( 'claims_write_quota_exceeded', 'The monthly claims write quota has been reached.', array( 'status' => 429 ) ); }
		}
		return true;
	}
}
