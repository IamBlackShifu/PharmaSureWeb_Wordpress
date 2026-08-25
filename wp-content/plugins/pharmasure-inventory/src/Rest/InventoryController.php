<?php
namespace PharmaSure\Inventory\Rest;

use PharmaSure\Core\TenantContext;
use PharmaSure\Core\LicenseManager;
use PharmaSure\Inventory\Services\InventoryService;

final class InventoryController {
	private $service;
	public function __construct() { $this->service = new InventoryService(); }

	public static function register_routes() {
		$self = new self();
		register_rest_route( 'pharmasure/v1', '/inventory/drugs', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $self, 'list_drugs' ), 'permission_callback' => array( $self, 'can_view' ), 'args' => array( 'search' => array( 'sanitize_callback' => 'sanitize_text_field' ), 'page' => array( 'sanitize_callback' => 'absint', 'default' => 1 ), 'per_page' => array( 'sanitize_callback' => 'absint', 'default' => 20 ) ) ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'create_drug' ), 'permission_callback' => array( $self, 'can_manage' ) ),
		) );
		register_rest_route( 'pharmasure/v1', '/inventory/drugs/(?P<id>\d+)', array(
			array( 'methods' => 'PATCH', 'callback' => array( $self, 'update_drug' ), 'permission_callback' => array( $self, 'can_manage' ) ),
			array( 'methods' => \WP_REST_Server::DELETABLE, 'callback' => array( $self, 'archive_drug' ), 'permission_callback' => array( $self, 'can_manage' ) ),
		) );
		register_rest_route( 'pharmasure/v1', '/inventory/suppliers', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $self, 'list_suppliers' ), 'permission_callback' => array( $self, 'can_view' ) ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'create_supplier' ), 'permission_callback' => array( $self, 'can_manage' ) ),
		) );
		register_rest_route( 'pharmasure/v1', '/inventory/suppliers/(?P<id>\d+)', array(
			array( 'methods' => 'PATCH', 'callback' => array( $self, 'update_supplier' ), 'permission_callback' => array( $self, 'can_manage' ) ),
			array( 'methods' => \WP_REST_Server::DELETABLE, 'callback' => array( $self, 'archive_supplier' ), 'permission_callback' => array( $self, 'can_manage' ) ),
		) );
		register_rest_route( 'pharmasure/v1', '/inventory/stock', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $self, 'stock' ), 'permission_callback' => array( $self, 'can_view' ) ) );
		register_rest_route( 'pharmasure/v1', '/inventory/workspace', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( $self, 'workspace' ),
			'permission_callback' => array( $self, 'can_view' ),
			'args' => array(
				'view' => array( 'sanitize_callback' => 'sanitize_key', 'default' => 'catalogue', 'validate_callback' => static fn( $value ) => in_array( $value, array( 'catalogue', 'batches', 'receipts', 'movements', 'low-stock', 'expiry', 'suppliers' ), true ) ),
				'q' => array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
			),
		) );
		register_rest_route( 'pharmasure/v1', '/inventory/receipts', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'receive' ), 'permission_callback' => array( $self, 'can_receive' ) ) );
		register_rest_route( 'pharmasure/v1', '/inventory/options', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $self, 'options' ), 'permission_callback' => array( $self, 'can_view' ) ) );
		register_rest_route( 'pharmasure/v1', '/inventory/adjustments', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'adjust' ), 'permission_callback' => array( $self, 'can_receive' ) ) );
		register_rest_route( 'pharmasure/v1', '/inventory/transfers', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'transfer' ), 'permission_callback' => array( $self, 'can_receive' ) ) );
		register_rest_route( 'pharmasure/v1', '/inventory/batches/(?P<id>\d+)/status', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'batch_status' ), 'permission_callback' => array( $self, 'can_receive' ) ) );
	}

	public function can_view() { return $this->authorize( 'pharmasure_view_inventory', 'Inventory access is required.' ); }
	public function can_manage() { return $this->authorize( 'pharmasure_manage_inventory', 'Inventory management access is required.' ); }
	public function can_receive() { return $this->authorize( 'pharmasure_manage_stock', 'Stock management access is required.' ); }

	public function list_drugs( $request ) { $scope = $this->scope(); return is_wp_error( $scope ) ? $scope : rest_ensure_response( $this->service->list_drugs( $scope['tenant_id'], $request->get_params() ) ); }
	public function create_drug( $request ) { $scope = $this->scope(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->create_drug( $scope['tenant_id'], (array) $request->get_json_params() ), 201 ); }
	public function update_drug( $request ) { $scope = $this->scope(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->update_drug( $scope['tenant_id'], absint( $request['id'] ), (array) $request->get_json_params() ), 200 ); }
	public function archive_drug( $request ) { $scope = $this->scope(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->archive_drug( $scope['tenant_id'], absint( $request['id'] ) ), 200 ); }
	public function list_suppliers() { $scope = $this->scope(); return is_wp_error( $scope ) ? $scope : rest_ensure_response( $this->service->list_suppliers( $scope['tenant_id'] ) ); }
	public function create_supplier( $request ) { $scope = $this->scope(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->create_supplier( $scope['tenant_id'], (array) $request->get_json_params() ), 201 ); }
	public function update_supplier( $request ) { $scope = $this->scope(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->update_supplier( $scope['tenant_id'], absint( $request['id'] ), (array) $request->get_json_params() ), 200 ); }
	public function archive_supplier( $request ) { $scope = $this->scope(); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->archive_supplier( $scope['tenant_id'], absint( $request['id'] ) ), 200 ); }
	public function stock( $request ) { $scope = $this->scope( $request, true ); return is_wp_error( $scope ) ? $scope : rest_ensure_response( array( 'data' => $this->service->stock_summary( $scope['tenant_id'], $scope['branch_id'] ), 'branch_id' => $scope['branch_id'] ) ); }
	public function workspace( $request ) { $scope = $this->scope( $request, true ); if ( is_wp_error( $scope ) ) { return $scope; } $result = $this->service->workspace( $scope['tenant_id'], $scope['branch_id'], sanitize_key( $request->get_param( 'view' ) ), sanitize_text_field( $request->get_param( 'q' ) ) ); return is_wp_error( $result ) ? $result : rest_ensure_response( $result ); }
	public function receive( $request ) { $scope = $this->scope( $request, true ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->receive_stock( $scope['tenant_id'], $scope['branch_id'], (array) $request->get_json_params(), TenantContext::instance()->get_correlation_id() ), 201 ); }
	public function options( $request ) { $scope = $this->scope( $request, true ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->command_options( $scope['tenant_id'], $scope['branch_id'] ), 200 ); }
	public function adjust( $request ) { $scope = $this->scope( $request, true ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->adjust_stock( $scope['tenant_id'], $scope['branch_id'], (array) $request->get_json_params(), TenantContext::instance()->get_correlation_id() ), 201 ); }
	public function transfer( $request ) { $scope = $this->scope( $request, true ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->transfer_stock( $scope['tenant_id'], $scope['branch_id'], (array) $request->get_json_params(), TenantContext::instance()->get_correlation_id() ), 201 ); }
	public function batch_status( $request ) { $scope = $this->scope( $request, true ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->change_batch_status( $scope['tenant_id'], $scope['branch_id'], absint( $request['id'] ), (array) $request->get_json_params(), TenantContext::instance()->get_correlation_id() ), 200 ); }

	private function authorize( $capability, $message ) {
		if ( ! current_user_can( $capability ) ) {
			return new \WP_Error( 'forbidden', $message, array( 'status' => 403 ) );
		}
		$tenant_id = (int) TenantContext::instance()->get_tenant_id();
		if ( ! $tenant_id ) {
			return new \WP_Error( 'tenant_context_required', 'No authorized tenant context is active.', array( 'status' => 403 ) );
		}
		return ( new LicenseManager( $tenant_id ) )->enforce_entitlement( 'inventory' );
	}

	private function scope( $request = null, $branch_required = false ) {
		$context = TenantContext::instance(); $tenant_id = (int) $context->get_tenant_id();
		if ( ! $tenant_id ) { return new \WP_Error( 'tenant_context_required', 'No authorized tenant context is active.', array( 'status' => 403 ) ); }
		$branch_id = (int) $context->get_branch_id();
		if ( $branch_required && ! $branch_id ) {
			$requested = $request ? absint( $request->get_header( 'X-PharmaSure-Branch' ) ) : 0;
			if ( ! $requested || ! $context->set_branch( $requested ) ) { return new \WP_Error( 'branch_context_required', 'Supply an authorized branch in X-PharmaSure-Branch.', array( 'status' => 400 ) ); }
			$branch_id = (int) $context->get_branch_id();
		}
		return array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id );
	}

	private function respond( $result, $status ) {
		if ( is_wp_error( $result ) ) { return $result; }
		return new \WP_REST_Response( array( 'success' => true, 'data' => $result ), $status );
	}
}
