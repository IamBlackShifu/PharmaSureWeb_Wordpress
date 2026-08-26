<?php
namespace PharmaSure\Clinical\Rest;

use PharmaSure\Core\LicenseManager;
use PharmaSure\Core\TenantContext;

final class ClinicalAccess {
	public static function authorize( $capability, $write = false ) {
		if ( ! current_user_can( $capability ) ) {
			return new \WP_Error( 'clinical_forbidden', 'Your pharmacy role does not permit this clinical action.', array( 'status' => 403 ) );
		}
		$tenant_id = (int) TenantContext::instance()->get_tenant_id();
		if ( ! $tenant_id ) {
			return new \WP_Error( 'tenant_context_required', 'No authorized tenant context is active.', array( 'status' => 403 ) );
		}
		$license = ( new LicenseManager( $tenant_id ) )->enforce_entitlement( 'clinical' );
		if ( is_wp_error( $license ) ) { return $license; }
		$limit = (int) ( $license['quotas']['clinical_writes_monthly'] ?? 0 );
		if ( $write && $limit > 0 ) {
			global $wpdb;
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ps_audit_events WHERE tenant_id=%d AND event_type LIKE %s AND created_at>=UTC_DATE()-INTERVAL (DAY(UTC_DATE())-1) DAY", $tenant_id, 'clinical.%' ) );
			if ( $used >= $limit ) { return new \WP_Error( 'clinical_write_quota_exceeded', 'The monthly clinical write quota has been reached.', array( 'status' => 429 ) ); }
		}
		return true;
	}

	public static function scope( $request ) {
		$context = TenantContext::instance();
		$tenant_id = (int) $context->get_tenant_id();
		$branch_id = (int) $context->get_branch_id();
		if ( ! $tenant_id ) { return new \WP_Error( 'tenant_context_required', 'No authorized tenant context is active.', array( 'status' => 403 ) ); }
		if ( ! $branch_id ) {
			$branch_id = absint( $request->get_header( 'X-PharmaSure-Branch' ) );
			if ( ! $branch_id || ! $context->set_branch( $branch_id ) ) { return new \WP_Error( 'branch_context_required', 'Select an authorized working branch.', array( 'status' => 403 ) ); }
		}
		return array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id );
	}
}
