<?php
namespace PharmaSure\Tenancy\Rest;

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
			'/tenants/(?P<tenant_id>\d+)/branches',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $controller, 'list_branches' ],
				'permission_callback' => function () {
					return is_user_logged_in();
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
		$tenant_id = intval( $request->get_param( 'tenant_id' ) );
		$branches  = $this->service->list_branches( $tenant_id );
		return rest_ensure_response( $branches );
	}

	public function create_branch( $request ) {
		$params    = $request->get_json_params();
		$tenant_id = intval( $params['tenant_id'] ?? 0 );

		if ( ! $tenant_id ) {
			return new \WP_REST_Response( [ 'error' => 'tenant_id required' ], 400 );
		}

		$result = $this->service->create_branch( $tenant_id, $params );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( $result->get_error_data(), 400 );
		}

		return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
	}

	public function update_branch( $request ) {
		$branch_id = intval( $request->get_param( 'id' ) );
		$params    = $request->get_json_params();
		$result    = $this->service->update_branch( $branch_id, $params );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( $result->get_error_data(), 400 );
		}

		return rest_ensure_response( $result );
	}
}
