<?php
namespace PharmaSure\PrintModule\Rest;

use PharmaSure\Core\TenantContext;
use PharmaSure\PrintModule\Services\PrintService;

final class PrintController {
	private $service;
	public function __construct() { $this->service = new PrintService(); }

	public static function register_routes() {
		$self = new self();
		register_rest_route( 'pharmasure/v1', '/print/jobs', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $self, 'list_jobs' ), 'permission_callback' => array( $self, 'can_print' ) ),
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'create_job' ), 'permission_callback' => array( $self, 'can_print' ) ),
		) );
		register_rest_route( 'pharmasure/v1', '/print/jobs/(?P<id>\d+)', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $self, 'get_job' ), 'permission_callback' => array( $self, 'can_print' ) ) );
	}

	public function can_print() { return current_user_can( 'pharmasure_print_documents' ) ? true : new \WP_Error( 'forbidden', 'Printing permission is required.', array( 'status' => 403 ) ); }

	public function create_job( $request ) {
		$scope = $this->scope( $request );
		if ( is_wp_error( $scope ) ) { return $scope; }
		$context = TenantContext::instance();
		$body = (array) $request->get_json_params();
		$result = $this->service->create_job( $scope['tenant_id'], $body['document_type'] ?? '', absint( $body['entity_id'] ?? 0 ), get_current_user_id(), $context->get_correlation_id(), $scope['branch_id'] );
		if ( is_wp_error( $result ) ) { return $result; }
		$result['print_url'] = \PharmaSure\PrintModule\BrowserPrintController::print_url( $result['id'] );
		return new \WP_REST_Response( array( 'success' => true, 'data' => $result ), 201 );
	}

	public function list_jobs( $request ) {
		$scope = $this->scope( $request );
		return is_wp_error( $scope ) ? $scope : rest_ensure_response( array( 'data' => $this->service->list_jobs( $scope['tenant_id'], 50, $scope['branch_id'] ) ) );
	}

	public function get_job( $request ) {
		$scope = $this->scope( $request );
		if ( is_wp_error( $scope ) ) { return $scope; }
		$job = $this->service->get_job( $scope['tenant_id'], absint( $request['id'] ), $scope['branch_id'] );
		return $job ? rest_ensure_response( $job ) : new \WP_Error( 'not_found', 'Print job not found.', array( 'status' => 404 ) );
	}

	private function scope( $request ) {
		$context = TenantContext::instance();
		$tenant_id = (int) $context->get_tenant_id();
		$branch_id = (int) $context->get_branch_id();
		if ( ! $tenant_id ) { return new \WP_Error( 'tenant_context_required', 'No authorized tenant context is active.', array( 'status' => 403 ) ); }
		if ( ! $branch_id ) {
			$branch_id = absint( $request->get_header( 'X-PharmaSure-Branch' ) );
			if ( ! $branch_id || ! $context->set_branch( $branch_id ) ) {
				return new \WP_Error( 'branch_context_required', 'Supply an authorized branch in X-PharmaSure-Branch.', array( 'status' => 403 ) );
			}
		}
		return array( 'tenant_id' => $tenant_id, 'branch_id' => $branch_id );
	}
}
