<?php
namespace PharmaSure\Offline\Rest;

use PharmaSure\Core\LicenseManager;
use PharmaSure\Core\TenantContext;
use PharmaSure\Offline\Services\DeviceService;
use PharmaSure\Offline\Services\MutationService;
use PharmaSure\Offline\Services\OperationsService;
use PharmaSure\Offline\Services\ReplayService;

final class OfflineController {
	public static function register_routes() {
		$controller = new self();
		register_rest_route( 'pharmasure/v1', '/offline/workspace', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $controller, 'workspace' ), 'permission_callback' => array( $controller, 'can_view' ) ) );
		register_rest_route( 'pharmasure/v1', '/offline/mutations/(?P<id>\d+)', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $controller, 'mutation_detail' ), 'permission_callback' => array( $controller, 'can_view' ) ) );
		register_rest_route( 'pharmasure/v1', '/offline/devices', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $controller, 'register_device' ), 'permission_callback' => array( $controller, 'can_manage' ) ) );
		register_rest_route( 'pharmasure/v1', '/offline/devices/(?P<id>\d+)/revoke', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $controller, 'revoke' ), 'permission_callback' => array( $controller, 'can_manage' ) ) );
		register_rest_route( 'pharmasure/v1', '/offline/mutations/(?P<id>\d+)/replay', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $controller, 'replay' ), 'permission_callback' => array( $controller, 'can_resolve' ) ) );
		register_rest_route( 'pharmasure/v1', '/offline/mutations/(?P<id>\d+)/discard', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $controller, 'discard' ), 'permission_callback' => array( $controller, 'can_resolve' ) ) );
		register_rest_route( 'pharmasure/v1', '/offline/mutations/(?P<id>\d+)/rebase', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $controller, 'rebase' ), 'permission_callback' => array( $controller, 'can_resolve' ) ) );
		register_rest_route( 'pharmasure/v1', '/offline/security-alerts/(?P<id>\d+)/resolve', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $controller, 'resolve_alert' ), 'permission_callback' => array( $controller, 'can_resolve' ) ) );
		register_rest_route( 'pharmasure/v1', '/offline/snapshot', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $controller, 'snapshot' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'pharmasure/v1', '/offline/mutations', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $controller, 'mutation' ), 'permission_callback' => '__return_true' ) );
	}

	public function can_view() {
		return $this->authorize( 'pharmasure_view_offline_operations', 'Offline operations permission is required.' );
	}

	public function can_manage() {
		return $this->authorize( 'pharmasure_manage_offline_devices', 'Offline-device management permission is required.' );
	}

	public function can_resolve() {
		return $this->authorize( 'pharmasure_resolve_offline_conflicts', 'Offline conflict-resolution permission is required.' );
	}

	public function workspace( $request ) {
		$scope = $this->scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		$status = sanitize_key( $request['status'] ?? '' );
		$operations = new OperationsService();
		$monitor = $operations->monitor( $scope['tenant_id'], $scope['branch_id'] );
		global $wpdb;
		$tenant = $wpdb->get_row( $wpdb->prepare( "SELECT trading_name FROM {$wpdb->prefix}ps_tenants WHERE id=%d LIMIT 1", $scope['tenant_id'] ), ARRAY_A );
		$branch = $wpdb->get_row( $wpdb->prepare( "SELECT name,code FROM {$wpdb->prefix}ps_branches WHERE tenant_id=%d AND id=%d LIMIT 1", $scope['tenant_id'], $scope['branch_id'] ), ARRAY_A );
		$counts = $monitor['counts'];
		$pending = (int) ( $counts['requires_online_replay'] ?? 0 ) + (int) ( $counts['retry'] ?? 0 ) + (int) ( $counts['processing'] ?? 0 );
		$critical = count( array_filter( $monitor['security_alerts'], static fn( $alert ) => 'critical' === $alert['severity'] && ! (int) $alert['is_resolved'] ) );

		return rest_ensure_response(
			array(
				'scope' => array(
					'pharmacy'   => $tenant['trading_name'] ?? 'Pharmacy',
					'branch'     => $branch['name'] ?? 'Current branch',
					'branch_code'=> $branch['code'] ?? '',
				),
				'metrics' => array(
					'pending'         => $pending,
					'conflicts'       => (int) ( $counts['conflict'] ?? 0 ),
					'active_devices'  => count( array_filter( $monitor['devices'], static fn( $device ) => 'active' === $device['status'] ) ),
					'critical_alerts' => $critical,
				),
				'counts'            => $counts,
				'oldest_pending_at' => $monitor['oldest_pending_at'],
				'mutations'         => $operations->mutations( $scope['tenant_id'], $scope['branch_id'], $status, 100 ),
				'devices'           => $monitor['devices'],
				'security_alerts'   => $monitor['security_alerts'],
				'permissions'       => array(
					'manage_devices'   => current_user_can( 'pharmasure_manage_offline_devices' ),
					'resolve_conflicts'=> current_user_can( 'pharmasure_resolve_offline_conflicts' ),
				),
			)
		);
	}

	public function mutation_detail( $request ) {
		$scope = $this->scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		$result = ( new OperationsService() )->mutation( $scope['tenant_id'], absint( $request['id'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( (int) $result['branch_id'] !== $scope['branch_id'] ) {
			return new \WP_Error( 'mutation_not_found', 'Mutation not found.', array( 'status' => 404 ) );
		}
		unset( $result['tenant_id'], $result['payload_hash'] );
		return rest_ensure_response( array( 'data' => $result ) );
	}

	public function register_device( $request ) {
		$scope = $this->scope( $request );
		return is_wp_error( $scope ) ? $scope : $this->respond( ( new DeviceService() )->register( $scope['tenant_id'], $scope['branch_id'], get_current_user_id(), (array) $request->get_json_params() ), 201 );
	}

	public function revoke( $request ) {
		$scope = $this->scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		$result = ( new OperationsService() )->device( $scope['tenant_id'], absint( $request['id'] ) );
		if ( is_wp_error( $result ) || (int) $result['branch_id'] !== $scope['branch_id'] ) {
			return new \WP_Error( 'device_not_active', 'Active device not found.', array( 'status' => 404 ) );
		}
		return $this->respond( ( new DeviceService() )->revoke( $scope['tenant_id'], absint( $request['id'] ), get_current_user_id() ), 200 );
	}

	public function replay( $request ) {
		return $this->mutation_action( $request, 'replay' );
	}

	public function discard( $request ) {
		return $this->mutation_action( $request, 'discard' );
	}

	public function rebase( $request ) {
		return $this->mutation_action( $request, 'rebase' );
	}

	public function resolve_alert( $request ) {
		$scope = $this->scope( $request );
		return is_wp_error( $scope ) ? $scope : $this->respond( ( new OperationsService() )->resolve_alert( $scope['tenant_id'], absint( $request['id'] ), get_current_user_id() ), 200 );
	}

	public function mutation( $request ) {
		$body = $request->get_body();
		$device = ( new DeviceService() )->authenticate( $request->get_header( 'X-PharmaSure-Device' ), $request->get_header( 'X-PharmaSure-Timestamp' ), $request->get_header( 'X-PharmaSure-Nonce' ), $body, $request->get_header( 'X-PharmaSure-Signature' ) );
		if ( is_wp_error( $device ) ) {
			return $device;
		}
		$entitlement = ( new LicenseManager( (int) $device['tenant_id'] ) )->enforce_entitlement( 'offline' );
		if ( is_wp_error( $entitlement ) ) {
			return $entitlement;
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'invalid_json', 'A JSON mutation envelope is required.', array( 'status' => 400 ) );
		}
		return $this->respond( ( new MutationService() )->receive( $device, $data ), 202 );
	}

	public function snapshot( $request ) {
		$body = $request->get_body();
		$device = ( new DeviceService() )->authenticate( $request->get_header( 'X-PharmaSure-Device' ), $request->get_header( 'X-PharmaSure-Timestamp' ), $request->get_header( 'X-PharmaSure-Nonce' ), $body, $request->get_header( 'X-PharmaSure-Signature' ) );
		if ( is_wp_error( $device ) ) {
			return $device;
		}
		$entitlement = ( new LicenseManager( (int) $device['tenant_id'] ) )->enforce_entitlement( 'offline' );
		if ( is_wp_error( $entitlement ) ) {
			return $entitlement;
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'invalid_json', 'A JSON snapshot request is required.', array( 'status' => 400 ) );
		}
		return $this->respond( ( new ReplayService() )->snapshot( $device, $data['mutation_type'] ?? '', (array) ( $data['payload'] ?? array() ) ), 200 );
	}

	private function mutation_action( $request, $action ) {
		$scope = $this->scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}
		$service = new OperationsService();
		$mutation = $service->mutation( $scope['tenant_id'], absint( $request['id'] ) );
		if ( is_wp_error( $mutation ) || (int) $mutation['branch_id'] !== $scope['branch_id'] ) {
			return new \WP_Error( 'mutation_not_found', 'Mutation not found.', array( 'status' => 404 ) );
		}
		$body = (array) $request->get_json_params();
		$result = match ( $action ) {
			'replay' => $service->replay( $scope['tenant_id'], absint( $request['id'] ), get_current_user_id() ),
			'discard'=> $service->discard( $scope['tenant_id'], absint( $request['id'] ), get_current_user_id(), $body['reason'] ?? '' ),
			'rebase' => $service->rebase( $scope['tenant_id'], absint( $request['id'] ), get_current_user_id(), $body['reason'] ?? '' ),
		};
		return $this->respond( $result, 200 );
	}

	private function authorize( $capability, $message ) {
		if ( ! current_user_can( $capability ) ) {
			return new \WP_Error( 'forbidden', $message, array( 'status' => 403 ) );
		}
		$tenant = (int) TenantContext::instance()->get_tenant_id();
		if ( ! $tenant ) {
			return new \WP_Error( 'tenant_context_required', 'No authorized tenant context is active.', array( 'status' => 403 ) );
		}
		$license = ( new LicenseManager( $tenant ) )->enforce_entitlement( 'offline' );
		return is_wp_error( $license ) ? $license : true;
	}

	private function scope( $request ) {
		$context = TenantContext::instance();
		$tenant = (int) $context->get_tenant_id();
		$branch = (int) $context->get_branch_id();
		if ( ! $branch ) {
			$requested = absint( $request->get_header( 'X-PharmaSure-Branch' ) );
			if ( $requested && $context->set_branch( $requested ) ) {
				$branch = $requested;
			}
		}
		if ( ! $tenant || ! $branch || ! $context->can_access_branch( $branch ) ) {
			return new \WP_Error( 'scope_required', 'An authorized tenant and working branch are required.', array( 'status' => 403 ) );
		}
		return array( 'tenant_id' => $tenant, 'branch_id' => $branch );
	}

	private function respond( $value, $status ) {
		return is_wp_error( $value ) ? $value : new \WP_REST_Response( array( 'success' => true, 'data' => $value ), $status );
	}
}
