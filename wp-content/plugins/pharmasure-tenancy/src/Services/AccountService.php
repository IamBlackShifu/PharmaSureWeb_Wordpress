<?php
namespace PharmaSure\Tenancy\Services;

use PharmaSure\Core\TenantContext;
use PharmaSure\Core\LicenseManager;

final class AccountService {
	private $db;
	private $p;
	private const ROLES = array( 'owner', 'manager', 'pharmacist', 'cashier', 'inventory_clerk', 'auditor' );

	public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix . 'ps_'; }

	public function workspace( $tenant ) {
		$members = $this->db->get_results( $this->db->prepare(
			"SELECT m.id,m.user_id,m.role,m.is_admin,m.is_active,m.invited_at,m.activated_at,m.created_at,u.user_login,u.user_email,u.display_name FROM {$this->p}tenant_memberships m INNER JOIN {$this->db->users} u ON u.ID=m.user_id WHERE m.tenant_id=%d ORDER BY m.is_active DESC,m.is_admin DESC,u.display_name,m.id",
			$tenant
		), ARRAY_A );
		$membership_ids = array_map( 'intval', array_column( $members, 'id' ) );
		$assignments = array();
		if ( $membership_ids ) {
			$marks = implode( ',', array_fill( 0, count( $membership_ids ), '%d' ) );
			$sql = $this->db->prepare( "SELECT mb.membership_id,b.id,b.name,b.code FROM {$this->p}membership_branches mb INNER JOIN {$this->p}branches b ON b.id=mb.branch_id AND b.tenant_id=%d WHERE mb.membership_id IN ({$marks}) ORDER BY b.name", $tenant, ...$membership_ids );
			foreach ( $this->db->get_results( $sql, ARRAY_A ) as $row ) { $assignments[ (int) $row['membership_id'] ][] = array( 'id'=>(int)$row['id'], 'name'=>$row['name'], 'code'=>$row['code'] ); }
		}
		foreach ( $members as &$member ) { $member['id']=(int)$member['id'];$member['user_id']=(int)$member['user_id'];$member['is_admin']=(int)$member['is_admin'];$member['is_active']=(int)$member['is_active'];$member['branches']=$assignments[$member['id']]??array(); } unset($member);
		$branches=$this->db->get_results($this->db->prepare("SELECT id,name,code,is_default FROM {$this->p}branches WHERE tenant_id=%d AND is_active=1 ORDER BY is_default DESC,name",$tenant),ARRAY_A);
		$tenant_row=$this->db->get_row($this->db->prepare("SELECT trading_name FROM {$this->p}tenants WHERE id=%d LIMIT 1",$tenant),ARRAY_A);
		return array('scope'=>array('pharmacy'=>$tenant_row['trading_name']??'Pharmacy'),'members'=>$members,'branches'=>$branches,'roles'=>self::ROLES,'metrics'=>array('total'=>count($members),'active'=>count(array_filter($members,static fn($m)=>(int)$m['is_active']===1)),'administrators'=>count(array_filter($members,static fn($m)=>(int)$m['is_admin']===1)),'branch_restricted'=>count(array_filter($members,static fn($m)=>(int)$m['is_admin']!==1))));
	}

	public function create( $tenant, array $data, $actor ) {
		$licence=(new LicenseManager($tenant))->enforce_entitlement('accounts');if(is_wp_error($licence)){return $licence;}$seat_limit=(int)($licence['quotas']['tenant_user_seats']??0);if($seat_limit>0){$active=(int)$this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->p}tenant_memberships WHERE tenant_id=%d AND is_active=1",$tenant));if($active>=$seat_limit){return new \WP_Error('account_seat_limit','The licensed tenant user-seat limit has been reached.',array('status'=>409));}}
		$email=sanitize_email($data['email']??'');$name=sanitize_text_field($data['display_name']??'');$role=sanitize_key($data['role']??'');$password=(string)($data['temporary_password']??'');$branch_ids=$this->branches($tenant,$data['branch_ids']??array());
		if(!is_email($email)||''===$name||!in_array($role,self::ROLES,true)||strlen($password)<12){return new \WP_Error('invalid_account','A valid email, display name, role and temporary password of at least 12 characters are required.',array('status'=>422));}
		$is_admin=in_array($role,array('owner','manager'),true);if(!$is_admin&&!$branch_ids){return new \WP_Error('branch_assignment_required','Operational accounts require at least one branch in this pharmacy.',array('status'=>422));}
		if(get_user_by('email',$email)){return new \WP_Error('account_identity_in_use','This email already belongs to an account. Cross-pharmacy identity reuse is not permitted.',array('status'=>409));}
		$base=sanitize_user(strstr($email,'@',true),true)?:'pharmacy-user';$login=$base;$suffix=1;while(username_exists($login)){$login=$base.'-'.$suffix++;}
		$user_id=wp_insert_user(array('user_login'=>$login,'user_email'=>$email,'display_name'=>$name,'user_pass'=>$password,'role'=>''));if(is_wp_error($user_id)){return $user_id;}
		$wp_role='pharmasure_'.$role;$site_result=add_user_to_blog(get_current_blog_id(),$user_id,$wp_role);if(is_wp_error($site_result)){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($user_id);return $site_result;}
		$now=current_time('mysql',true);$this->db->query('START TRANSACTION');$ok=$this->db->insert($this->p.'tenant_memberships',array('user_id'=>$user_id,'tenant_id'=>$tenant,'role'=>$role,'is_admin'=>$is_admin?1:0,'is_active'=>1,'invited_at'=>$now,'activated_at'=>$now,'created_at'=>$now));$membership=(int)$this->db->insert_id;
		if($ok){foreach($branch_ids as $branch_id){if(!$this->db->insert($this->p.'membership_branches',array('membership_id'=>$membership,'branch_id'=>$branch_id,'created_at'=>$now))){$ok=false;break;}}}
		if(!$ok){$this->db->query('ROLLBACK');remove_user_from_blog($user_id,get_current_blog_id());require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($user_id);return new \WP_Error('account_not_created','The tenant account could not be saved.',array('status'=>500));}$this->db->query('COMMIT');update_user_meta($user_id,'default_password_nag',true);do_action('pharmasure_audit_log',array('tenant_id'=>$tenant,'actor_id'=>$actor,'action'=>'account.created','object_type'=>'tenant_membership','object_id'=>$membership,'details'=>array('user_id'=>$user_id,'role'=>$role,'branch_ids'=>$branch_ids)));return array('id'=>$membership,'user_id'=>(int)$user_id,'display_name'=>$name,'email'=>$email,'role'=>$role);
	}

	public function update( $tenant, $membership_id, array $data, $actor ) {
		$licence=(new LicenseManager($tenant))->enforce_entitlement('accounts');if(is_wp_error($licence)){return $licence;}
		$row=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}tenant_memberships WHERE id=%d AND tenant_id=%d LIMIT 1",$membership_id,$tenant),ARRAY_A);if(!$row){return new \WP_Error('account_not_found','The account was not found in this pharmacy.',array('status'=>404));}
		$role=sanitize_key($data['role']??$row['role']);$active=!empty($data['is_active'])?1:0;if(!in_array($role,self::ROLES,true)){return new \WP_Error('invalid_account_role','Select a supported pharmacy role.',array('status'=>422));}if((int)$row['user_id']===(int)$actor&&!$active){return new \WP_Error('self_deactivation_blocked','You cannot deactivate your own active session.',array('status'=>409));}
		$seat_limit=(int)($licence['quotas']['tenant_user_seats']??0);if($active&&!(int)$row['is_active']&&$seat_limit>0){$active_count=(int)$this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->p}tenant_memberships WHERE tenant_id=%d AND is_active=1",$tenant));if($active_count>=$seat_limit){return new \WP_Error('account_seat_limit','The licensed tenant user-seat limit has been reached.',array('status'=>409));}}
		$is_admin=in_array($role,array('owner','manager'),true);$branch_ids=$this->branches($tenant,$data['branch_ids']??array());if(!$is_admin&&!$branch_ids){return new \WP_Error('branch_assignment_required','Operational accounts require at least one branch in this pharmacy.',array('status'=>422));}
		if($row['role']==='owner'&&($role!=='owner'||!$active)){ $owners=(int)$this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->p}tenant_memberships WHERE tenant_id=%d AND role='owner' AND is_active=1",$tenant));if($owners<=1){return new \WP_Error('last_owner_protected','Every pharmacy must retain an active owner.',array('status'=>409));}}
		$this->db->query('START TRANSACTION');$ok=false!==$this->db->update($this->p.'tenant_memberships',array('role'=>$role,'is_admin'=>$is_admin?1:0,'is_active'=>$active,'updated_at'=>current_time('mysql',true)),array('id'=>$membership_id,'tenant_id'=>$tenant));if($ok){$ok=false!==$this->db->delete($this->p.'membership_branches',array('membership_id'=>$membership_id));}if($ok){foreach($branch_ids as $branch_id){if(!$this->db->insert($this->p.'membership_branches',array('membership_id'=>$membership_id,'branch_id'=>$branch_id,'created_at'=>current_time('mysql',true)))){$ok=false;break;}}}if(!$ok){$this->db->query('ROLLBACK');return new \WP_Error('account_not_updated','The tenant account could not be updated.',array('status'=>500));}$this->db->query('COMMIT');$user=new \WP_User((int)$row['user_id']);$user->set_role('pharmasure_'.$role);do_action('pharmasure_audit_log',array('tenant_id'=>$tenant,'actor_id'=>$actor,'action'=>'account.access_updated','object_type'=>'tenant_membership','object_id'=>$membership_id,'before_state'=>array('role'=>$row['role'],'is_active'=>(int)$row['is_active']),'after_state'=>array('role'=>$role,'is_active'=>$active,'branch_ids'=>$branch_ids)));return array('id'=>(int)$membership_id,'role'=>$role,'is_active'=>$active);
	}

	private function branches($tenant,$values){$ids=array_values(array_unique(array_filter(array_map('absint',(array)$values))));if(!$ids){return array();}$marks=implode(',',array_fill(0,count($ids),'%d'));$valid=array_map('intval',$this->db->get_col($this->db->prepare("SELECT id FROM {$this->p}branches WHERE tenant_id=%d AND is_active=1 AND id IN ({$marks})",$tenant,...$ids)));sort($ids);sort($valid);return $ids===$valid?$valid:array();}
}
