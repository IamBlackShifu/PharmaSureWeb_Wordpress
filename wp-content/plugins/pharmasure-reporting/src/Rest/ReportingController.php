<?php
namespace PharmaSure\Reporting\Rest;

use PharmaSure\Core\TenantContext;
use PharmaSure\Reporting\Services\ReportService;

final class ReportingController {
	private $service;
	public function __construct() { $this->service = new ReportService(); }
	public static function register_routes() {
		$s = new self();
		register_rest_route( 'pharmasure/v1', '/reports/dashboard', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $s, 'dashboard' ), 'permission_callback' => array( $s, 'can_view' ) ) );
		register_rest_route( 'pharmasure/v1', '/reports/(?P<type>sales|margin|tenders|inventory|movements|claims|clinical|audit|security)', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $s, 'report' ), 'permission_callback' => array( $s, 'can_view' ) ) );
		register_rest_route( 'pharmasure/v1', '/reports/(?P<type>sales|margin|tenders|inventory|movements|claims|clinical|audit|security)/export', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $s, 'export' ), 'permission_callback' => array( $s, 'can_export' ) ) );
		register_rest_route( 'pharmasure/v1', '/reports/schedules', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $s, 'schedule' ), 'permission_callback' => array( $s, 'can_export' ) ) );
	}
	public function can_view() { return current_user_can( 'pharmasure_view_reports' ) ? true : new \WP_Error( 'forbidden', 'Reporting permission is required.', array( 'status' => 403 ) ); }
	public function can_export() { return current_user_can( 'pharmasure_export_data' ) ? true : new \WP_Error( 'forbidden', 'Export permission is required.', array( 'status' => 403 ) ); }
	public function dashboard( $r ) { $scope = $this->scope( $r ); if ( is_wp_error( $scope ) ) { return $scope; } $result = $this->service->dashboard( $scope['tenant_id'], $scope['branch_id'], $r['from'], $r['to'] ); return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'data' => $result ) ); }
	public function report( $r ) { if ( in_array( $r['type'], array( 'audit','security' ), true ) && ! current_user_can( 'pharmasure_view_audit_log' ) ) { return new \WP_Error( 'forbidden', 'Audit-log permission is required.', array( 'status'=>403 ) ); } $scope = $this->scope( $r ); if ( is_wp_error( $scope ) ) { return $scope; } $result = $this->service->report( $r['type'], $scope['tenant_id'], $scope['branch_id'], $r['from'], $r['to'] ); return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'data' => $result ) ); }
	public function export( $r ) { if(in_array($r['type'],array('audit','security'),true)&&!current_user_can('pharmasure_view_audit_log')){return new \WP_Error('forbidden','Audit-log permission is required.',array('status'=>403));}$scope=$this->scope($r);if(is_wp_error($scope)){return $scope;}$format=sanitize_key($r['format']?:'csv');$service=new \PharmaSure\Reporting\Services\ExportService();$result=$service->generate($format,$r['type'],$scope['tenant_id'],$scope['branch_id'],$r['from'],$r['to']);return is_wp_error($result)?$result:rest_ensure_response(array('data'=>array('filename'=>'pharmasure-'.$r['type'].'-'.gmdate('Ymd').'.'.$format,'mime_type'=>$service->mime($format),'content_base64'=>base64_encode($result)))); }
	public function schedule( $r ) { $body=(array)$r->get_json_params();if(in_array(sanitize_key($body['report_type']??''),array('audit','security'),true)&&!current_user_can('pharmasure_view_audit_log')){return new \WP_Error('forbidden','Audit-log permission is required.',array('status'=>403));}$scope=$this->scope($r);return is_wp_error($scope)?$scope:$this->respond((new \PharmaSure\Reporting\Services\ScheduleService())->create($scope['tenant_id'],$scope['branch_id'],$body,get_current_user_id()),201); }
	private function respond($result,$status){return is_wp_error($result)?$result:new \WP_REST_Response(array('success'=>true,'data'=>$result),$status);}
	private function scope( $r ) { $context = TenantContext::instance(); $tenant = (int) $context->get_tenant_id(); if ( ! $tenant ) { return new \WP_Error( 'tenant_context_required', 'No authorized tenant context is active.', array( 'status' => 403 ) ); } $branch = (int) $context->get_branch_id(); if ( ! $branch ) { $requested = absint( $r->get_header( 'X-PharmaSure-Branch' ) ); if ( $requested && $context->set_branch( $requested ) ) { $branch = $requested; } elseif ( ! current_user_can( 'pharmasure_manage_tenant' ) ) { return new \WP_Error( 'branch_context_required', 'Supply an authorized branch in X-PharmaSure-Branch.', array( 'status' => 403 ) ); } } return array( 'tenant_id' => $tenant, 'branch_id' => $branch ); }
}
