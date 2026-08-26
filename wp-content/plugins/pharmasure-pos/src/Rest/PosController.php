<?php
namespace PharmaSure\POS\Rest;

use PharmaSure\Core\TenantContext;
use PharmaSure\Core\LicenseManager;
use PharmaSure\POS\Services\PosService;

final class PosController {
	private $service;
	public function __construct() { $this->service = new PosService(); }

	public static function register_routes() {
		$self = new self();
		register_rest_route( 'pharmasure/v1', '/pos/workspace', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $self, 'workspace' ), 'permission_callback' => array( $self, 'can_pos' ) ) );
		register_rest_route( 'pharmasure/v1', '/pos/tills', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'create_till' ), 'permission_callback' => array( $self, 'can_manage_tills' ) ) );
		register_rest_route( 'pharmasure/v1', '/pos/sessions/open', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'open_session' ), 'permission_callback' => array( $self, 'can_pos_write' ) ) );
		register_rest_route( 'pharmasure/v1', '/pos/sessions/(?P<id>\d+)/close', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'close_session' ), 'permission_callback' => array( $self, 'can_pos_write' ) ) );
		register_rest_route( 'pharmasure/v1', '/pos/sessions/(?P<id>\d+)/approve-variance', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'approve_variance' ), 'permission_callback' => array( $self, 'can_approve_variance' ) ) );
		register_rest_route( 'pharmasure/v1', '/pos/checkout', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'checkout' ), 'permission_callback' => array( $self, 'can_pos_write' ) ) );
		register_rest_route( 'pharmasure/v1', '/pos/products', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $self, 'products' ), 'permission_callback' => array( $self, 'can_pos' ), 'args' => array( 'q' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ) ) );
		register_rest_route( 'pharmasure/v1', '/pos/pricing-policy', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $self, 'pricing_policy' ), 'permission_callback' => array( $self, 'can_pos' ) ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'configure_pricing' ), 'permission_callback' => array( $self, 'can_manage_tills' ) ),
		) );
		register_rest_route( 'pharmasure/v1', '/pos/holds', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $self, 'holds' ), 'permission_callback' => array( $self, 'can_pos' ) ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'hold' ), 'permission_callback' => array( $self, 'can_pos_write' ) ),
		) );
		register_rest_route( 'pharmasure/v1', '/pos/holds/(?P<id>\d+)/cancel', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'cancel_hold' ), 'permission_callback' => array( $self, 'can_pos_write' ) ) );
		register_rest_route( 'pharmasure/v1', '/pos/sales/(?P<id>\d+)/refund', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'refund' ), 'permission_callback' => array( $self, 'can_refund' ) ) );
		register_rest_route( 'pharmasure/v1', '/pos/sales/(?P<id>\d+)/void', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'void_sale' ), 'permission_callback' => array( $self, 'can_void' ) ) );
	}

	public function can_pos() { return $this->authorize( 'pharmasure_access_pos', 'POS access is required.' ); }
	public function can_pos_write() { return $this->authorize( 'pharmasure_access_pos', 'POS access is required.', true ); }
	public function can_manage_tills() { return $this->authorize( 'pharmasure_manage_tills', 'Till management access is required.', true ); }
	public function can_refund() { return $this->authorize( 'pharmasure_refund_sales', 'Refund permission is required.', true ); }
	public function can_void() { return $this->authorize( 'pharmasure_void_sales', 'Void permission is required.', true ); }
	public function can_approve_variance() { return $this->authorize( 'pharmasure_approve_till_variance', 'Till variance approval permission is required.', true ); }

	public function create_till( $request ) { $scope = $this->scope( $request ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->create_till( $scope['tenant_id'], $scope['branch_id'], (array) $request->get_json_params() ), 201 ); }
	public function workspace( $request ) { $scope = $this->scope( $request ); if ( is_wp_error( $scope ) ) { return $scope; } $result = $this->service->workspace( $scope['tenant_id'], $scope['branch_id'], get_current_user_id() ); if ( is_wp_error( $result ) ) { return $result; } $result['permissions'] = array( 'discount' => current_user_can( 'pharmasure_apply_pos_discounts' ), 'refund' => current_user_can( 'pharmasure_refund_sales' ), 'void' => current_user_can( 'pharmasure_void_sales' ), 'manage_tills' => current_user_can( 'pharmasure_manage_tills' ), 'print' => current_user_can( 'pharmasure_print_documents' ) ); return rest_ensure_response( $result ); }
	public function open_session( $request ) { $scope = $this->scope( $request ); $body = (array) $request->get_json_params(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->open_session( $scope['tenant_id'], $scope['branch_id'], absint( $body['till_id'] ?? 0 ), get_current_user_id(), (int) ( $body['opening_float_minor'] ?? 0 ) ), 201 ); }
	public function close_session( $request ) { $scope = $this->scope( $request ); $body = (array) $request->get_json_params(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->close_session( $scope['tenant_id'], $scope['branch_id'], absint( $request['id'] ), get_current_user_id(), (int) ( $body['counted_cash_minor'] ?? -1 ) ), 200 ); }
	public function approve_variance( $request ) { $scope = $this->scope( $request ); $body = (array) $request->get_json_params(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->approve_variance( $scope['tenant_id'], $scope['branch_id'], absint( $request['id'] ), get_current_user_id(), $body['reason'] ?? '' ), 200 ); }
	public function checkout( $request ) { $scope = $this->scope( $request ); $body = (array) $request->get_json_params(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->checkout( $scope['tenant_id'], $scope['branch_id'], absint( $body['till_session_id'] ?? 0 ), get_current_user_id(), $body, TenantContext::instance()->get_correlation_id(), current_user_can( 'pharmasure_apply_pos_discounts' ) ), 201 ); }
	public function products( $request ) { $scope = $this->scope( $request ); return is_wp_error( $scope ) ? $scope : rest_ensure_response( array( 'data' => $this->service->search_products( $scope['tenant_id'], $scope['branch_id'], $request->get_param( 'q' ) ) ) ); }
	public function configure_pricing( $request ) { $scope = $this->scope( $request ); $body = (array) $request->get_json_params(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->configure_pricing( $scope['tenant_id'], $scope['branch_id'], (int) ( $body['tax_rate_bps'] ?? 0 ), (int) ( $body['max_discount_bps'] ?? 0 ) ), 200 ); }
	public function pricing_policy( $request ) { $scope = $this->scope( $request ); return is_wp_error( $scope ) ? $scope : rest_ensure_response( array( 'data' => $this->service->pricing_policy( $scope['tenant_id'], $scope['branch_id'] ) ) ); }
	public function hold( $request ) { $scope = $this->scope( $request ); $body = (array) $request->get_json_params(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->hold_sale( $scope['tenant_id'], $scope['branch_id'], absint( $body['till_session_id'] ?? 0 ), get_current_user_id(), $body ), 201 ); }
	public function holds( $request ) { $scope = $this->scope( $request ); return is_wp_error( $scope ) ? $scope : rest_ensure_response( array( 'data' => $this->service->list_holds( $scope['tenant_id'], $scope['branch_id'] ) ) ); }
	public function cancel_hold( $request ) { $scope = $this->scope( $request ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->cancel_hold( $scope['tenant_id'], $scope['branch_id'], absint( $request['id'] ), get_current_user_id() ), 200 ); }
	public function refund( $request ) { $scope = $this->scope( $request ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->refund_sale( $scope['tenant_id'], $scope['branch_id'], absint( $request['id'] ), get_current_user_id(), (array) $request->get_json_params(), TenantContext::instance()->get_correlation_id() ), 201 ); }
	public function void_sale( $request ) { $scope = $this->scope( $request ); $body = (array) $request->get_json_params(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->void_sale( $scope['tenant_id'], $scope['branch_id'], absint( $request['id'] ), get_current_user_id(), sanitize_text_field( $body['reason'] ?? '' ), TenantContext::instance()->get_correlation_id() ), 201 ); }

	private function authorize( $capability, $message, $write = false ) {
		if ( ! current_user_can( $capability ) ) { return new \WP_Error( 'forbidden', $message, array( 'status' => 403 ) ); }
		$tenant_id = (int) TenantContext::instance()->get_tenant_id();
		if ( ! $tenant_id ) { return new \WP_Error( 'tenant_context_required', 'No authorized tenant context is active.', array( 'status' => 403 ) ); }
		$license = ( new LicenseManager( $tenant_id ) )->enforce_entitlement( 'pos' );
		if ( is_wp_error( $license ) ) { return $license; }
		$limit = (int) ( $license['quotas']['pos_writes_monthly'] ?? 0 );
		if ( $write && $limit > 0 ) {
			global $wpdb;
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ps_audit_events WHERE tenant_id=%d AND event_type LIKE %s AND created_at>=UTC_DATE()-INTERVAL (DAY(UTC_DATE())-1) DAY", $tenant_id, 'pos.%' ) );
			if ( $used >= $limit ) { return new \WP_Error( 'pos_write_quota_exceeded', 'The monthly point-of-sale write quota has been reached.', array( 'status' => 429 ) ); }
		}
		return true;
	}

	private function scope( $request ) {
		$context = TenantContext::instance(); $tenant_id = (int) $context->get_tenant_id(); $branch_id = (int) $context->get_branch_id();
		if ( ! $tenant_id ) { return new \WP_Error( 'tenant_context_required', 'No authorized tenant context is active.', array( 'status' => 403 ) ); }
		if ( ! $branch_id ) { $branch_id = absint( $request->get_header( 'X-PharmaSure-Branch' ) ); if ( ! $branch_id || ! $context->set_branch( $branch_id ) ) { return new \WP_Error( 'branch_context_required', 'Supply an authorized branch in X-PharmaSure-Branch.', array( 'status' => 403 ) ); } }
		return array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id );
	}

	private function respond( $result, $status ) { return is_wp_error( $result ) ? $result : new \WP_REST_Response( array( 'success' => true, 'data' => $result ), $status ); }
}
