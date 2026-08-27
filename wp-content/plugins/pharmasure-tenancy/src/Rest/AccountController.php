<?php
namespace PharmaSure\Tenancy\Rest;

use PharmaSure\Core\TenantContext;
use PharmaSure\Core\LicenseManager;
use PharmaSure\Tenancy\Services\AccountService;

final class AccountController {
	private $service;
	public function __construct(){ $this->service=new AccountService(); }
	public static function register_routes(){ $s=new self();register_rest_route('pharmasure/v1','/accounts/workspace',array('methods'=>\WP_REST_Server::READABLE,'callback'=>array($s,'workspace'),'permission_callback'=>array($s,'can_manage')));register_rest_route('pharmasure/v1','/accounts',array('methods'=>\WP_REST_Server::CREATABLE,'callback'=>array($s,'create'),'permission_callback'=>array($s,'can_manage')));register_rest_route('pharmasure/v1','/accounts/(?P<id>\d+)',array('methods'=>\WP_REST_Server::EDITABLE,'callback'=>array($s,'update'),'permission_callback'=>array($s,'can_manage')));register_rest_route('pharmasure/v1','/account/security',array('methods'=>\WP_REST_Server::CREATABLE,'callback'=>array($s,'security'),'permission_callback'=>array($s,'can_access_account'))); }
	public function can_manage(){if(!current_user_can('pharmasure_manage_users')){return new \WP_Error('forbidden','Tenant account administration permission is required.',array('status'=>403));}$tenant=(int)TenantContext::instance()->get_tenant_id();if(!$tenant){return new \WP_Error('tenant_context_required','No authorized tenant context is active.',array('status'=>403));}$result=(new LicenseManager($tenant))->enforce_entitlement('accounts');return is_wp_error($result)?$result:true;}
	public function can_access_account(){
		$tenant=(int)TenantContext::instance()->get_tenant_id();$user_id=get_current_user_id();if(!$tenant||!$user_id){return new \WP_Error('tenant_context_required','An active tenant account is required.',array('status'=>403));}
		global $wpdb;$active=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ps_tenant_memberships WHERE tenant_id=%d AND user_id=%d AND is_active=1",$tenant,$user_id));return $active?true:new \WP_Error('forbidden','The tenant account is not active.',array('status'=>403));
	}
	public function workspace(){ $tenant=$this->tenant();return is_wp_error($tenant)?$tenant:rest_ensure_response($this->service->workspace($tenant)); }
	public function create($r){$tenant=$this->tenant();if(is_wp_error($tenant)){return $tenant;}$result=$this->service->create($tenant,(array)$r->get_json_params(),get_current_user_id());return is_wp_error($result)?$result:new \WP_REST_Response(array('success'=>true,'data'=>$result),201);}
	public function update($r){$tenant=$this->tenant();if(is_wp_error($tenant)){return $tenant;}$result=$this->service->update($tenant,absint($r['id']),(array)$r->get_json_params(),get_current_user_id());return is_wp_error($result)?$result:rest_ensure_response(array('success'=>true,'data'=>$result));}
	public function security($r){
		$data=(array)$r->get_json_params();$current=(string)($data['current_password']??'');$new=(string)($data['new_password']??'');$confirm=(string)($data['confirm_password']??'');$user=wp_get_current_user();
		if(!$current||!wp_check_password($current,$user->user_pass,$user->ID)){return new \WP_Error('current_password_invalid','The current password is incorrect.',array('status'=>422));}
		if($new!==$confirm||strlen($new)<12||!preg_match('/[A-Z]/',$new)||!preg_match('/[a-z]/',$new)||!preg_match('/\d/',$new)){return new \WP_Error('new_password_invalid','Use at least 12 characters with upper-case, lower-case and numeric characters, and confirm it exactly.',array('status'=>422));}
		wp_set_password($new,$user->ID);if(!defined('WP_CLI')||!WP_CLI){wp_set_auth_cookie($user->ID,false,is_ssl());}delete_user_meta($user->ID,'default_password_nag');do_action('pharmasure_audit_log',array('tenant_id'=>TenantContext::instance()->get_tenant_id(),'actor_id'=>$user->ID,'action'=>'account.password_changed','object_type'=>'user','object_id'=>$user->ID,'status'=>'success'));
		return rest_ensure_response(array('success'=>true,'message'=>'Password changed successfully.'));
	}
	private function tenant(){ $tenant=(int)TenantContext::instance()->get_tenant_id();return $tenant?:new \WP_Error('tenant_context_required','No authorized tenant context is active.',array('status'=>403)); }
}
