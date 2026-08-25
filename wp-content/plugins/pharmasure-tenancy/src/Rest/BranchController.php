<?php
namespace PharmaSure\Tenancy\Rest;

use PharmaSure\Core\TenantContext;
use PharmaSure\Tenancy\Services\BranchService;

class BranchController {
	private $service;

	public function __construct() {
		$this->service = new BranchService();
	}

	public static function register_routes() {
		$controller = new self();

		register_rest_route(
			'pharmasure/v1',
			'/branches',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $controller, 'list_branches' ],
				'permission_callback' => function () use ( $controller ) {
					return $controller->has_tenant_context();
				},
			]
		);

		register_rest_route(
			'pharmasure/v1',
			'/branches',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $controller, 'create_branch' ],
				'permission_callback' => function () {
					return current_user_can( 'pharmasure_manage_tenant' );
				},
			]
		);

		register_rest_route(
			'pharmasure/v1',
			'/branches/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $controller, 'update_branch' ],
				'permission_callback' => function () {
					return current_user_can( 'pharmasure_manage_tenant' );
				},
			]
		);
	}

	public function list_branches( $request ) {
		$tenant_id = (int) TenantContext::instance()->get_tenant_id();
		$branches  = $this->service->list_branches( $tenant_id );
		return rest_ensure_response( $branches );
	}

	public function create_branch( $request ) {
		$params    = $request->get_json_params();
		$tenant_id = (int) TenantContext::instance()->get_tenant_id();

		if ( ! $tenant_id ) {
			return new \WP_Error( 'tenant_context_required', 'An authorized tenant context is required.', [ 'status' => 403 ] );
		}

		$result = $this->service->create_branch( $tenant_id, $params );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( $result->get_error_data(), 400 );
		}

		return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
	}

	public function update_branch( $request ) {
		$branch_id = intval( $request->get_param( 'id' ) );
		if ( ! TenantContext::instance()->can_access_branch( $branch_id ) && ! current_user_can( 'manage_network_options' ) ) {
			return new \WP_Error( 'forbidden_branch', 'You are not authorized for this branch.', [ 'status' => 403 ] );
		}
		$params    = $request->get_json_params();
		$result    = $this->service->update_branch( $branch_id, $params );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( $result->get_error_data(), 400 );
		}

		return rest_ensure_response( $result );
	}

	public function has_tenant_context() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', 'Authentication is required.', [ 'status' => 401 ] );
		}
		return TenantContext::instance()->get_tenant_id()
			? true
			: new \WP_Error( 'tenant_context_required', 'An authorized tenant context is required.', [ 'status' => 403 ] );
	}
}
