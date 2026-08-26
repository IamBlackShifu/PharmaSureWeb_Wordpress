<?php
namespace PharmaSure\Tenancy\Rest;

use PharmaSure\Core\TenantContext;
use PharmaSure\Core\LicenseManager;
use PharmaSure\Tenancy\Services\AccountService;

final class AccountController {
	private $service;
	public function __construct(){ $this->service=new AccountService(); }
	public static function register_routes(){ $s=new self();register_rest_route('pharmasure/v1','/accounts/workspace',array('methods'=>\WP_REST_Server::READABLE,'callback'=>array($s,'workspace'),'permission_callback'=>array($s,'can_manage')));register_rest_route('pharmasure/v1','/accounts',array('methods'=>\WP_REST_Server::CREATABLE,'callback'=>array($s,'create'),'permission_callback'=>array($s,'can_manage')));register_rest_route('pharmasure/v1','/accounts/(?P<id>\d+)',array('methods'=>\WP_REST_Server::EDITABLE,'callback'=>array($s,'update'),'permission_callback'=>array($s,'can_manage'))); }
	public function can_manage(){if(!current_user_can('pharmasure_manage_users')){return new \WP_Error('forbidden','Tenant account administration permission is required.',array('status'=>403));}$tenant=(int)TenantContext::instance()->get_tenant_id();if(!$tenant){return new \WP_Error('tenant_context_required','No authorized tenant context is active.',array('status'=>403));}$result=(new LicenseManager($tenant))->enforce_entitlement('accounts');return is_wp_error($result)?$result:true;}
	public function workspace(){ $tenant=$this->tenant();return is_wp_error($tenant)?$tenant:rest_ensure_response($this->service->workspace($tenant)); }
	public function create($r){$tenant=$this->tenant();if(is_wp_error($tenant)){return $tenant;}$result=$this->service->create($tenant,(array)$r->get_json_params(),get_current_user_id());return is_wp_error($result)?$result:new \WP_REST_Response(array('success'=>true,'data'=>$result),201);}
	public function update($r){$tenant=$this->tenant();if(is_wp_error($tenant)){return $tenant;}$result=$this->service->update($tenant,absint($r['id']),(array)$r->get_json_params(),get_current_user_id());return is_wp_error($result)?$result:rest_ensure_response(array('success'=>true,'data'=>$result));}
	private function tenant(){ $tenant=(int)TenantContext::instance()->get_tenant_id();return $tenant?:new \WP_Error('tenant_context_required','No authorized tenant context is active.',array('status'=>403)); }
}
