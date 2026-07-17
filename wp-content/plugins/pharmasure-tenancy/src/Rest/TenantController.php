<?php
namespace PharmaSure\Tenancy\Rest;

use PharmaSure\Tenancy\Services\TenantService;

class TenantController {
	private $service;

	public function __construct() {
		$this->service = new TenantService();
	}

	public static function register_routes() {
		$controller = new self();

		register_rest_route(
			'pharmasure/v1',
			'/tenants',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $controller, 'list_tenants' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		register_rest_route(
			'pharmasure/v1',
			'/tenants',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $controller, 'create_tenant' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		register_rest_route(
			'pharmasure/v1',
			'/tenants/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $controller, 'get_tenant' ],
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			]
		);

		register_rest_route(
			'pharmasure/v1',
			'/tenants/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $controller, 'update_tenant' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);
	}

	public function list_tenants( $request ) {
		$params = $request->get_query_params();
		$result = $this->service->list_tenants( $params );

		return rest_ensure_response( $result );
	}

	public function create_tenant( $request ) {
		$params = $request->get_json_params();
		$result = $this->service->create_tenant( $params );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( $result->get_error_data(), 400 );
		}

		return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
	}

	public function get_tenant( $request ) {
		$tenant_id = intval( $request->get_param( 'id' ) );
		$tenant    = $this->service->get_tenant( $tenant_id );

		if ( ! $tenant ) {
			return new \WP_REST_Response( [ 'error' => 'Not found' ], 404 );
		}

		return rest_ensure_response( $tenant );
	}

	public function update_tenant( $request ) {
		$tenant_id = intval( $request->get_param( 'id' ) );
		$params    = $request->get_json_params();
		$result    = $this->service->update_tenant( $tenant_id, $params );

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( $result->get_error_data(), 400 );
		}

		return rest_ensure_response( $result );
	}
}
