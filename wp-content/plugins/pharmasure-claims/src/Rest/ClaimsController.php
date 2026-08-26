<?php
namespace PharmaSure\Claims\Rest;

use PharmaSure\Claims\Services\ClaimsService;
use PharmaSure\Claims\Services\ClaimDocumentService;
use PharmaSure\Claims\Services\ClaimsWorkspaceService;
use PharmaSure\Core\TenantContext;

final class ClaimsController {
	private $service;
	public function __construct() { $this->service = new ClaimsService(); }
	public static function register_routes() {
		$s = new self();
		register_rest_route( 'pharmasure/v1', '/claims/workspace', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $s, 'workspace' ), 'permission_callback' => array( $s, 'can_view' ) ) );
		register_rest_route( 'pharmasure/v1', '/claims/insurers', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $s, 'insurer' ), 'permission_callback' => array( $s, 'can_insurers' ) ) );
		register_rest_route( 'pharmasure/v1', '/claims/insurers/(?P<id>\d+)/schemes', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $s, 'scheme' ), 'permission_callback' => array( $s, 'can_insurers' ) ) );
		register_rest_route( 'pharmasure/v1', '/claims/covers', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $s, 'cover' ), 'permission_callback' => array( $s, 'can_insurers' ) ) );
		register_rest_route( 'pharmasure/v1', '/claims', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $s, 'prepare' ), 'permission_callback' => array( $s, 'can_prepare' ) ) );
		register_rest_route( 'pharmasure/v1', '/claims/(?P<id>\d+)', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $s, 'get' ), 'permission_callback' => array( $s, 'can_prepare' ) ) );
		register_rest_route( 'pharmasure/v1', '/claims/(?P<id>\d+)/validate', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $s, 'validate_claim' ), 'permission_callback' => array( $s, 'can_prepare' ) ) );
		register_rest_route( 'pharmasure/v1', '/claims/(?P<id>\d+)/prepare-submission', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $s, 'prepare_submission' ), 'permission_callback' => array( $s, 'can_submit' ) ) );
		register_rest_route( 'pharmasure/v1', '/claims/(?P<id>\d+)/submitted', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $s, 'submitted' ), 'permission_callback' => array( $s, 'can_submit' ) ) );
		register_rest_route( 'pharmasure/v1', '/claims/(?P<id>\d+)/adjudicate', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $s, 'adjudicate' ), 'permission_callback' => array( $s, 'can_submit' ) ) );
		register_rest_route( 'pharmasure/v1', '/claims/(?P<id>\d+)/correct', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $s, 'correct' ), 'permission_callback' => array( $s, 'can_prepare' ) ) );
		register_rest_route( 'pharmasure/v1', '/claims/remittances', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $s, 'remittance' ), 'permission_callback' => array( $s, 'can_reconcile' ) ) );
		register_rest_route( 'pharmasure/v1', '/claims/(?P<id>\d+)/write-off', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $s, 'writeoff' ), 'permission_callback' => array( $s, 'can_reconcile' ) ) );
		register_rest_route( 'pharmasure/v1', '/claims/(?P<id>\d+)/print', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $s, 'claim_print' ), 'permission_callback' => array( $s, 'can_prepare' ) ) );
		register_rest_route( 'pharmasure/v1', '/claims/remittances/(?P<id>\d+)/print', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $s, 'remittance_print' ), 'permission_callback' => array( $s, 'can_reconcile' ) ) );
	}
	public function can_view() { return ClaimsAccess::authorize( 'pharmasure_prepare_claims', false ); }
	public function can_insurers() { return ClaimsAccess::authorize( 'pharmasure_manage_insurers', true ); }
	public function can_prepare( $request = null ) { return ClaimsAccess::authorize( 'pharmasure_prepare_claims', ! $request || 'GET' !== $request->get_method() ); }
	public function can_submit() { return ClaimsAccess::authorize( 'pharmasure_submit_claims', true ); }
	public function can_reconcile() { return ClaimsAccess::authorize( 'pharmasure_reconcile_claims', true ); }
	public function workspace( $r ) { $scope = $this->scope( $r ); if ( is_wp_error( $scope ) ) { return $scope; } $result = ( new ClaimsWorkspaceService() )->workspace( $scope['tenant_id'], $scope['branch_id'], $r->get_param( 'status' ) ?: '', $r->get_param( 'q' ) ?: '' ); if ( is_wp_error( $result ) ) { return $result; } $result['permissions'] = array( 'prepare' => current_user_can( 'pharmasure_prepare_claims' ), 'submit' => current_user_can( 'pharmasure_submit_claims' ), 'reconcile' => current_user_can( 'pharmasure_reconcile_claims' ), 'manage_insurers' => current_user_can( 'pharmasure_manage_insurers' ) ); return rest_ensure_response( $result ); }
	public function insurer( $r ) { $scope = $this->tenant(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->create_insurer( $scope, (array) $r->get_json_params(), get_current_user_id() ), 201 ); }
	public function scheme( $r ) { $scope = $this->tenant(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->create_scheme( $scope, absint( $r['id'] ), (array) $r->get_json_params(), get_current_user_id() ), 201 ); }
	public function cover( $r ) { $scope = $this->tenant(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->create_cover( $scope, (array) $r->get_json_params(), get_current_user_id() ), 201 ); }
	public function prepare( $r ) { $scope = $this->scope( $r ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->prepare_claim( $scope['tenant_id'], $scope['branch_id'], (array) $r->get_json_params(), get_current_user_id() ), 201 ); }
	public function get( $r ) { $scope = $this->scope( $r ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->get_claim( $scope['tenant_id'], $scope['branch_id'], absint( $r['id'] ) ), 200 ); }
	public function validate_claim( $r ) { $scope = $this->scope( $r ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->validate_claim( $scope['tenant_id'], $scope['branch_id'], absint( $r['id'] ), get_current_user_id() ), 200 ); }
	public function prepare_submission( $r ) { $scope = $this->scope( $r ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->prepare_submission( $scope['tenant_id'], $scope['branch_id'], absint( $r['id'] ), get_current_user_id() ), 200 ); }
	public function submitted( $r ) { $scope = $this->scope( $r ); $b = (array) $r->get_json_params(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->mark_submitted( $scope['tenant_id'], $scope['branch_id'], absint( $r['id'] ), $b['payer_reference'] ?? '', get_current_user_id() ), 200 ); }
	public function adjudicate( $r ) { $scope = $this->scope( $r ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->adjudicate( $scope['tenant_id'], $scope['branch_id'], absint( $r['id'] ), (array) $r->get_json_params(), get_current_user_id() ), 200 ); }
	public function correct( $r ) { $scope = $this->scope( $r ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->correct_claim( $scope['tenant_id'], $scope['branch_id'], absint( $r['id'] ), (array) $r->get_json_params(), get_current_user_id() ), 200 ); }
	public function remittance( $r ) { $scope = $this->scope( $r ); if ( is_wp_error( $scope ) ) { return $scope; } $body = (array) $r->get_json_params(); $body['_authorized_branch_id'] = $scope['branch_id']; return $this->respond( $this->service->reconcile( $scope['tenant_id'], $body, get_current_user_id() ), 201 ); }
	public function writeoff( $r ) { $scope = $this->scope( $r ); $b = (array) $r->get_json_params(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->write_off( $scope['tenant_id'], $scope['branch_id'], absint( $r['id'] ), $b['amount_minor'] ?? 0, $b['reason'] ?? '', get_current_user_id() ), 200 ); }
	public function claim_print( $r ) { $scope = $this->scope( $r ); if ( is_wp_error( $scope ) ) { return $scope; } $html = ( new ClaimDocumentService() )->claim_form( $scope['tenant_id'], $scope['branch_id'], absint( $r['id'] ) ); return is_wp_error( $html ) ? $html : new \WP_REST_Response( $html, 200, array( 'Content-Type' => 'text/html; charset=utf-8' ) ); }
	public function remittance_print( $r ) { $tenant = $this->tenant(); if ( is_wp_error( $tenant ) ) { return $tenant; } $html = ( new ClaimDocumentService() )->remittance( $tenant, absint( $r['id'] ) ); return is_wp_error( $html ) ? $html : new \WP_REST_Response( $html, 200, array( 'Content-Type' => 'text/html; charset=utf-8' ) ); }
	private function tenant() { $id = (int) TenantContext::instance()->get_tenant_id(); return $id ?: new \WP_Error( 'tenant_context_required', 'No authorized tenant context is active.', array( 'status' => 403 ) ); }
	private function scope( $r ) { $tenant = $this->tenant(); if ( is_wp_error( $tenant ) ) { return $tenant; } $context = TenantContext::instance(); $branch = (int) $context->get_branch_id(); if ( ! $branch ) { $branch = absint( $r->get_header( 'X-PharmaSure-Branch' ) ); if ( ! $branch || ! $context->set_branch( $branch ) ) { return new \WP_Error( 'branch_context_required', 'Supply an authorized branch in X-PharmaSure-Branch.', array( 'status' => 403 ) ); } } return array( 'tenant_id' => $tenant, 'branch_id' => $branch ); }
	private function respond( $result, $status ) { return is_wp_error( $result ) ? $result : new \WP_REST_Response( array( 'success' => true, 'data' => $result ), $status ); }
}
