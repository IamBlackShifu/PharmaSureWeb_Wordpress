<?php
namespace PharmaSure\Clinical\Rest;

use PharmaSure\Clinical\Services\ClinicalWorkspaceService;

final class ClinicalWorkspaceController {
	private $service;
	public function __construct() { $this->service = new ClinicalWorkspaceService(); }

	public static function register_routes() {
		$self = new self();
		register_rest_route( 'pharmasure/v1', '/clinical/workspace', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $self, 'workspace' ), 'permission_callback' => array( $self, 'can_view' ) ) );
		register_rest_route( 'pharmasure/v1', '/clinical/drugs', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $self, 'drugs' ), 'permission_callback' => array( $self, 'can_manage_prescriptions' ) ) );
		register_rest_route( 'pharmasure/v1', '/clinical/patients', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'patient' ), 'permission_callback' => array( $self, 'can_manage_patients' ) ) );
		register_rest_route( 'pharmasure/v1', '/clinical/patients/(?P<id>\d+)', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $self, 'patient_detail' ), 'permission_callback' => array( $self, 'can_view' ) ) );
		register_rest_route( 'pharmasure/v1', '/clinical/prescriptions', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'prescription' ), 'permission_callback' => array( $self, 'can_manage_prescriptions' ) ) );
		register_rest_route( 'pharmasure/v1', '/clinical/prescriptions/(?P<id>\d+)/submit', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'submit' ), 'permission_callback' => array( $self, 'can_manage_prescriptions' ) ) );
		register_rest_route( 'pharmasure/v1', '/clinical/prescriptions/(?P<id>\d+)/review', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'review' ), 'permission_callback' => array( $self, 'can_review' ) ) );
		register_rest_route( 'pharmasure/v1', '/clinical/prescriptions/(?P<id>\d+)/dispense', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $self, 'dispense' ), 'permission_callback' => array( $self, 'can_dispense' ) ) );
	}

	public function can_view() { return ClinicalAccess::authorize( 'pharmasure_view_prescriptions' ); }
	public function can_manage_patients() { return ClinicalAccess::authorize( 'pharmasure_manage_patients', true ); }
	public function can_manage_prescriptions() { return ClinicalAccess::authorize( 'pharmasure_manage_prescriptions', true ); }
	public function can_review() { return ClinicalAccess::authorize( 'pharmasure_review_prescriptions', true ); }
	public function can_dispense() { return ClinicalAccess::authorize( 'pharmasure_dispense_medications', true ); }

	public function workspace( $request ) { $scope = ClinicalAccess::scope( $request ); if ( is_wp_error( $scope ) ) { return $scope; } $result = $this->service->workspace( $scope['tenant_id'], $scope['branch_id'], $request->get_param( 'q' ) ); if ( is_wp_error( $result ) ) { return $result; } $result['permissions'] = array( 'manage_patients' => current_user_can( 'pharmasure_manage_patients' ), 'manage_prescriptions' => current_user_can( 'pharmasure_manage_prescriptions' ), 'review' => current_user_can( 'pharmasure_review_prescriptions' ), 'dispense' => current_user_can( 'pharmasure_dispense_medications' ), 'prepare_claims' => current_user_can( 'pharmasure_prepare_claims' ), 'print' => current_user_can( 'pharmasure_print_documents' ) ); return rest_ensure_response( $result ); }
	public function drugs( $request ) { $scope = ClinicalAccess::scope( $request ); return is_wp_error( $scope ) ? $scope : rest_ensure_response( array( 'data' => $this->service->search_drugs( $scope['tenant_id'], $scope['branch_id'], $request->get_param( 'q' ) ) ) ); }
	public function patient( $request ) { $scope = ClinicalAccess::scope( $request ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->create_patient( $scope['tenant_id'], $scope['branch_id'], (array) $request->get_json_params(), get_current_user_id() ), 201 ); }
	public function patient_detail( $request ) { $scope = ClinicalAccess::scope( $request ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->patient_detail( $scope['tenant_id'], $scope['branch_id'], absint( $request['id'] ) ), 200 ); }
	public function prescription( $request ) { $scope = ClinicalAccess::scope( $request ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->create_prescription( $scope['tenant_id'], $scope['branch_id'], (array) $request->get_json_params(), get_current_user_id() ), 201 ); }
	public function submit( $request ) { $scope = ClinicalAccess::scope( $request ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->submit( $scope['tenant_id'], $scope['branch_id'], absint( $request['id'] ), get_current_user_id() ), 200 ); }
	public function review( $request ) { $scope = ClinicalAccess::scope( $request ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->review( $scope['tenant_id'], $scope['branch_id'], absint( $request['id'] ), (array) $request->get_json_params(), get_current_user_id() ), 200 ); }
	public function dispense( $request ) { $scope = ClinicalAccess::scope( $request ); return is_wp_error( $scope ) ? $scope : $this->respond( $this->service->dispense( $scope['tenant_id'], $scope['branch_id'], absint( $request['id'] ), (array) $request->get_json_params(), get_current_user_id() ), 201 ); }
	private function respond( $result, $status ) { return is_wp_error( $result ) ? $result : new \WP_REST_Response( array( 'success' => true, 'data' => $result ), $status ); }
}
